<?php

namespace App\Services\TenantModule;

use App\DataObjects\RequestObjects\TenantExpenseCreate;
use App\DataObjects\RequestObjects\TenantScheduledExpenseWrite;
use App\DataObjects\ResponseObjects\TenantScheduledExpenseDetail;
use App\DataObjects\ResponseObjects\TenantScheduledExpenseListPage;
use App\DataObjects\ResponseObjects\TenantScheduledExpenseOccurrencePage;
use App\Exceptions\AlreadyUpdatedException;
use App\Exceptions\InvalidTenantRequest;
use App\Models\CoreModule\TenantScheduledExpense;
use App\Models\CoreModule\TenantScheduledExpenseOccurrence;
use App\Repository\TenantScheduledExpenseRepository;
use App\Services\BaseTenantService;
use App\Services\PlatformModule\TenantServices\TenantLicenseService;
use App\Services\TableIdGenerationService;
use App\Services\TenantModule\Accounting\MultiAccountManagement;
use App\Support\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

class TenantScheduledExpenseService extends BaseTenantService
{
    /** Bound catch-up work so one tenant cannot monopolize a scheduler run. */
    private const BATCH_SIZE = 100;

    public function __construct(
        private TenantScheduledExpenseRepository $repository,
        private TenantUserPermissionService $permissionService,
        private TenantLicenseService $licenseService,
        private AccountingDayBusinessClock $clock,
        private TenantAccountingDayService $accountingDayService,
        private TenantExpenseService $expenseService,
        private MultiAccountManagement $accountManagement,
        private TableIdGenerationService $tableIdGenerationService,
    ) {}

    public function list(int $perPage, ?string $search): TenantScheduledExpenseListPage
    {
        $this->permissionService->authorizePermission('list_scheduled_expense');
        return TenantScheduledExpenseListPage::fromPaginator($this->repository->paginate($perPage, $search));
    }

    public function detail(string $code): TenantScheduledExpenseDetail
    {
        $this->permissionService->authorizePermission('list_scheduled_expense');
        return TenantScheduledExpenseDetail::fromModel($this->find($code));
    }

    public function occurrenceHistory(string $code, int $perPage): TenantScheduledExpenseOccurrencePage
    {
        $this->permissionService->authorizePermission('list_scheduled_expense');

        return TenantScheduledExpenseOccurrencePage::fromPaginator(
            $this->repository->paginateOccurrences($this->find($code), $perPage),
        );
    }

    public function create(TenantScheduledExpenseWrite $request): TenantScheduledExpenseDetail
    {
        $this->permissionService->authorizePermission('create_scheduled_expense');
        $tenantId = $this->resolveCurrentTenantId();
        $this->licenseService->ensureTenantHasFeature($tenantId, 'scheduled_expense_management');
        $this->accountManagement->findActiveCurrentTenantAccount($request->accountId);
        $this->ensureRecurrenceDatesMatch($request);
        $this->ensureFutureStart($request, $this->clock->now($tenantId));

        $schedule = DB::transaction(function () use ($request, $tenantId): TenantScheduledExpense {
            $start = CarbonImmutable::parse($request->startDate);
            return $this->repository->create([
                'tenant_id' => $tenantId,
                'code' => $this->tableIdGenerationService->generate('tenant_scheduled_expenses', $start),
                'account_id' => $request->accountId,
                'description' => $request->description,
                'amount' => $request->amount,
                'expense_type_id' => $request->expenseTypeId,
                'recurrence_type' => $request->recurrenceType,
                'start_date' => $request->startDate,
                'scheduled_time' => $request->scheduledTime,
                'end_date' => $request->endDate,
                'weekly_day' => $request->weeklyDay,
                // Keep the requested monthly day even when the first month was clamped.
                'monthly_anchor_day' => $request->monthlyAnchorDay,
                'next_due_date' => $request->startDate,
                'status' => 'active',
                'created_by' => Auth::guard('tenantuser')->id(),
            ]);
        });

        return TenantScheduledExpenseDetail::fromModel($schedule);
    }

    public function update(string $code, TenantScheduledExpenseWrite $request): TenantScheduledExpenseDetail
    {
        $this->permissionService->authorizePermission('update_scheduled_expense');
        $this->accountManagement->findActiveCurrentTenantAccount($request->accountId);
        $this->ensureRecurrenceDatesMatch($request);

        $schedule = DB::transaction(function () use ($code, $request): TenantScheduledExpense {
            $schedule = $this->find($code, true);
            $this->ensureFutureStart($request, $this->clock->now($schedule->tenant_id));
            if ((int) $schedule->update_key !== $request->updateKey) {
                throw new AlreadyUpdatedException('This schedule was already updated. Please refresh.');
            }
            $nextDueDate = $this->firstFutureDate($request, $this->clock->now($schedule->tenant_id));
            return $this->repository->update($schedule, [
                'account_id' => $request->accountId,
                'description' => $request->description,
                'amount' => $request->amount,
                'expense_type_id' => $request->expenseTypeId,
                'recurrence_type' => $request->recurrenceType,
                'start_date' => $request->startDate,
                'scheduled_time' => $request->scheduledTime,
                'end_date' => $request->endDate,
                'weekly_day' => $request->weeklyDay,
                'monthly_anchor_day' => $request->monthlyAnchorDay,
                'next_due_date' => $nextDueDate,
                'status' => $nextDueDate === null ? 'completed' : ($schedule->status === 'completed' ? 'active' : $schedule->status),
                'update_key' => $schedule->update_key + 1,
            ]);
        });

        return TenantScheduledExpenseDetail::fromModel($schedule);
    }

    public function pause(string $code): TenantScheduledExpenseDetail
    {
        return $this->changeStatus($code, 'paused');
    }

    public function resume(string $code): TenantScheduledExpenseDetail
    {
        return $this->changeStatus($code, 'active');
    }

    public function delete(string $code): void
    {
        $this->permissionService->authorizePermission('delete_scheduled_expense');
        DB::transaction(function () use ($code): void {
            $schedule = $this->find($code, true);
            $this->repository->cancelPending($schedule);
            $this->repository->softDelete($schedule);
        });
    }

    public function processDueSchedules(): int
    {
        $processed = 0;
        $context = app(TenantContext::class);
        $originalTenantId = $context->id();

        try {
            // Background jobs have no request middleware, so establish and later
            // restore TenantContext explicitly for every tenant-owned query.
            foreach ($this->repository->activeTenantIds() as $tenantId) {
                $tenantId = (int) $tenantId;
                if (! $this->licenseService->tenantHasFeature($tenantId, 'scheduled_expense_management')) {
                    continue;
                }
                $context->set($tenantId);
                $now = $this->clock->now($tenantId);

                // Snapshot due template data before attempting payment. This
                // keeps missed occurrences stable if the schedule is edited.
                $this->materializeDueOccurrences($tenantId, $now);

                // Automatic accounting-day tenants may only be charged inside
                // today's configured interval while the day is actually open.
                if (! $this->accountingDayService->allowsScheduledFinancialOperation($tenantId, $now)) {
                    continue;
                }
                foreach ($this->repository->pendingOccurrences($tenantId, $now, self::BATCH_SIZE) as $occurrence) {
                    if ($occurrence->due_date->toDateString() === $now->toDateString() && (string) $occurrence->due_time > $now->format('H:i:s')) {
                        continue;
                    }
                    if ($this->payOccurrence($occurrence)) { $processed++; }
                }
            }
        } finally {
            $originalTenantId === null ? $context->clear() : $context->set($originalTenantId);
        }

        return $processed;
    }

    private function materializeDueOccurrences(int $tenantId, CarbonImmutable $now): void
    {
        $remaining = self::BATCH_SIZE;
        foreach ($this->repository->dueSchedules($tenantId, $now->toDateString(), self::BATCH_SIZE) as $schedule) {
            while ($remaining > 0 && $schedule->next_due_date !== null) {
                $due = CarbonImmutable::parse($schedule->next_due_date->toDateString().' '.$schedule->scheduled_time, $now->timezone);
                if ($due->isAfter($now)) { break; }
                DB::transaction(function () use ($schedule, $due): void {
                    // Lock and re-check because another worker may have advanced
                    // this schedule after the initial due-schedule query.
                    $locked = $this->find($schedule->code, true);
                    if ($locked->status !== 'active' || $locked->next_due_date?->toDateString() !== $due->toDateString()) { return; }
                    $this->repository->createOccurrence(
                        ['scheduled_expense_id' => $locked->id, 'due_date' => $due->toDateString(), 'due_time' => $locked->scheduled_time],
                        ['tenant_id' => $locked->tenant_id, 'account_id' => $locked->account_id, 'description' => $locked->description,
                            'amount' => $locked->amount, 'expense_type_id' => $locked->expense_type_id, 'status' => 'pending'],
                    );
                    $next = $this->nextDate($locked, $due);
                    $this->repository->update($locked, ['last_due_date' => $due->toDateString(), 'next_due_date' => $next?->toDateString(),
                        'status' => 'active', 'update_key' => $locked->update_key + 1]);
                });
                $remaining--;
                $schedule = $this->find($schedule->code);
            }
            if ($remaining === 0) { break; }
        }
    }

    private function payOccurrence(TenantScheduledExpenseOccurrence $occurrence): bool
    {
        try {
            DB::transaction(function () use ($occurrence): void {
                $locked = $this->repository->lockOccurrence($occurrence->id);
                if ($locked === null || ! in_array($locked->status, ['pending', 'failed'], true)) { return; }
                $expense = $this->expenseService->createFromSchedule(new TenantExpenseCreate(
                    description: $locked->description, amount: (float) $locked->amount, accountId: $locked->account_id,
                    expenseTypeId: $locked->expense_type_id, idempotencyKey: "scheduled-expense:{$locked->id}",
                ));

                // Expense creation, accounting movement, account deduction, and
                // occurrence completion commit or roll back as a single unit.
                $this->repository->updateOccurrence($locked, ['expense_id' => $expense->id, 'status' => 'paid',
                    'attempt_count' => $locked->attempt_count + 1, 'last_error' => null, 'executed_at' => now()]);
                $schedule = $this->find($locked->schedule->code, true);
                $pendingCount = $this->repository->pendingCount($schedule);
                $this->repository->update($schedule, ['last_paid_at' => now(), 'last_result' => 'paid', 'last_error' => null,
                    'status' => $schedule->next_due_date === null && $pendingCount === 0 ? 'completed' : $schedule->status,
                    'update_key' => $schedule->update_key + 1]);
            });
            return true;
        } catch (Throwable $exception) {
            // Keep failed occurrences retryable; the deterministic idempotency
            // key prevents a retry from creating a duplicate expense.
            Log::error('Scheduled expense payment failed.', ['occurrence_id' => $occurrence->id, 'exception' => $exception]);
            $this->repository->updateOccurrence($occurrence, ['status' => 'failed', 'attempt_count' => $occurrence->attempt_count + 1,
                'last_error' => mb_substr($exception->getMessage(), 0, 2000)]);
            $schedule = $this->find($occurrence->schedule->code);
            $this->repository->update($schedule, ['last_result' => 'failed', 'last_error' => mb_substr($exception->getMessage(), 0, 2000)]);
            return false;
        }
    }

    private function changeStatus(string $code, string $status): TenantScheduledExpenseDetail
    {
        $this->permissionService->authorizePermission('update_scheduled_expense');
        $schedule = DB::transaction(function () use ($code, $status): TenantScheduledExpense {
            $schedule = $this->find($code, true);
            if ($status === 'active' && $schedule->next_due_date === null && (int) $schedule->pending_count === 0) {
                throw new InvalidTenantRequest('A completed one-time schedule cannot be resumed.');
            }
            $nextDueDate = $status === 'active' ? $this->nextDueOnResume($schedule, $this->clock->now($schedule->tenant_id)) : $schedule->next_due_date;
            return $this->repository->update($schedule, ['status' => $status, 'paused_at' => $status === 'paused' ? now() : null,
                'next_due_date' => $nextDueDate,
                'update_key' => $schedule->update_key + 1]);
        });
        return TenantScheduledExpenseDetail::fromModel($schedule);
    }

    private function nextDate(TenantScheduledExpense $schedule, CarbonImmutable $due): ?CarbonImmutable
    {
        // Monthly schedules retain their original day anchor. Shorter months
        // clamp to month-end, then later months return to that anchor.
        $next = match ($schedule->recurrence_type) {
            'daily' => $due->addDay(),
            'weekly' => $due->addWeek(),
            'monthly' => $due->addMonthNoOverflow()->startOfMonth()->day(min((int) $schedule->monthly_anchor_day, $due->addMonthNoOverflow()->daysInMonth)),
            default => null,
        };
        return $next !== null && ($schedule->end_date === null || $next->toDateString() <= $schedule->end_date->toDateString()) ? $next : null;
    }

    private function ensureRecurrenceDatesMatch(TenantScheduledExpenseWrite $request): void
    {
        $start = CarbonImmutable::parse($request->startDate);
        $end = $request->endDate === null ? null : CarbonImmutable::parse($request->endDate);

        if ($request->recurrenceType === 'weekly'
            && ($start->dayOfWeekIso !== $request->weeklyDay || ($end !== null && $end->dayOfWeekIso !== $request->weeklyDay))) {
            throw new InvalidTenantRequest('Weekly schedule boundaries must fall on the selected weekday.');
        }

        if ($request->recurrenceType === 'monthly') {
            $anchor = (int) $request->monthlyAnchorDay;
            // Boundary dates may clamp to month-end, but must still represent the requested anchor.
            $matchesAnchor = fn (CarbonImmutable $date): bool => $date->day === min($anchor, $date->daysInMonth);
            if (! $matchesAnchor($start) || ($end !== null && ! $matchesAnchor($end))) {
                throw new InvalidTenantRequest('Monthly schedule boundaries must match the selected day of month.');
            }
        }
    }

    private function ensureFutureStart(TenantScheduledExpenseWrite $request, CarbonImmutable $now): void
    {
        // Editing a template starts a new future schedule boundary; occurrence snapshots remain immutable.
        $startsAt = CarbonImmutable::parse($request->startDate.' '.$request->scheduledTime, $now->timezone);
        if (! $startsAt->isAfter($now)) {
            throw new InvalidTenantRequest('The schedule start date and time must be in the future.');
        }
    }

    private function firstFutureDate(TenantScheduledExpenseWrite $request, CarbonImmutable $now): ?string
    {
        $date = CarbonImmutable::parse($request->startDate, $now->timezone);
        while ($date->toDateString().' '.$request->scheduledTime < $now->format('Y-m-d H:i')) {
            $date = match ($request->recurrenceType) {
                'daily' => $date->addDay(), 'weekly' => $date->addWeek(),
                // Use the explicit anchor because a February start may have been clamped from day 29-31.
                'monthly' => $date->addMonthNoOverflow()->startOfMonth()->day(min((int) $request->monthlyAnchorDay, $date->addMonthNoOverflow()->daysInMonth)),
                default => null,
            };
            if ($date === null) { return null; }
        }
        return $request->endDate !== null && $date->toDateString() > $request->endDate ? null : $date->toDateString();
    }

    private function nextDueOnResume(TenantScheduledExpense $schedule, CarbonImmutable $now): ?string
    {
        // Paused time is intentionally not backfilled. Already-materialized
        // pending occurrences remain available for payment after resuming.
        $date = $schedule->next_due_date === null ? null : CarbonImmutable::parse($schedule->next_due_date->toDateString(), $now->timezone);
        while ($date !== null && $date->toDateString().' '.substr((string) $schedule->scheduled_time, 0, 5) < $now->format('Y-m-d H:i')) {
            $date = $this->nextDate($schedule, $date);
        }
        return $date?->toDateString();
    }

    private function find(string $code, bool $lock = false): TenantScheduledExpense
    {
        return $this->repository->findByCode($code, $lock) ?? throw new InvalidTenantRequest('Scheduled expense was not found.');
    }
}
