<?php

namespace App\Repository;

use App\Models\CoreModule\TenantBusinessLoan;
use App\Models\CoreModule\TenantBusinessLoanInterestAccrual;
use App\Models\CoreModule\TenantBusinessLoanPayment;
use App\Models\CoreModule\TenantBusinessLoanPaymentAllocation;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Collection as SupportCollection;

class TenantBusinessLoanRepository
{
    private function relations(): array { return ['lender.person', 'receiptAccount.currency', 'interestType', 'interestAccruals', 'payments.paymentAccount.currency']; }

    public function paginate(int $perPage, ?string $search): LengthAwarePaginator
    {
        return TenantBusinessLoan::query()->with($this->relations())
            ->when($search, fn ($query) => $query->where(function ($query) use ($search): void {
                $query->where('code', 'like', "%{$search}%")->orWhere('description', 'like', "%{$search}%")
                    ->orWhere('tag', 'like', "%{$search}%")
                    ->orWhereHas('lender.person', fn ($person) => $person->where('name', 'like', "%{$search}%"));
            }))->orderByDesc('id')->paginate($perPage);
    }

    public function findByCode(string $code, bool $lock = false): ?TenantBusinessLoan
    {
        $query = TenantBusinessLoan::query()->with($this->relations())->where('code', $code);
        return ($lock ? $query->lockForUpdate() : $query)->first();
    }

    public function create(array $data): TenantBusinessLoan { return TenantBusinessLoan::query()->create($data)->load($this->relations()); }
    public function update(TenantBusinessLoan $loan, array $data): TenantBusinessLoan { $loan->update($data); return $loan->refresh()->load($this->relations()); }
    public function createAccrual(array $data): TenantBusinessLoanInterestAccrual { return TenantBusinessLoanInterestAccrual::query()->firstOrCreate(['tenant_id' => $data['tenant_id'], 'business_loan_id' => $data['business_loan_id'], 'start_period_at' => $data['start_period_at']], $data); }
    public function createPayment(array $data): TenantBusinessLoanPayment { return TenantBusinessLoanPayment::query()->create($data); }
    public function createAllocation(array $data): TenantBusinessLoanPaymentAllocation { return TenantBusinessLoanPaymentAllocation::query()->create($data); }
    public function updateAccrual(TenantBusinessLoanInterestAccrual $accrual, array $data): void { $accrual->update($data); }
    public function delete(TenantBusinessLoan $loan): void { $loan->update(['is_deleted' => true]); $loan->delete(); }
    public function hasPayments(TenantBusinessLoan $loan): bool { return $loan->payments()->exists(); }

    public function compoundScheduleTenantIds(): SupportCollection
    {
        return TenantBusinessLoan::query()->withoutGlobalScope('tenant')->where('is_deleted', false)->where('is_paid', false)->where('apply_interest', true)->where('compound_schedule_enabled', true)->whereNotNull('next_compound_at')->distinct()->orderBy('tenant_id')->pluck('tenant_id');
    }

    public function dueScheduledLoans(int $tenantId, CarbonInterface $now): Collection
    {
        return TenantBusinessLoan::query()->withoutGlobalScope('tenant')->with($this->relations())->where('tenant_id', $tenantId)->where('is_deleted', false)->where('is_paid', false)->where('apply_interest', true)->where('compound_schedule_enabled', true)->whereNotNull('next_compound_at')->where('next_compound_at', '<=', $now->utc())->orderBy('next_compound_at')->get();
    }
}
