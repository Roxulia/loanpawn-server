<?php

namespace App\Repository;

use App\Models\CoreModule\TenantBusinessLoan;
use App\DataObjects\RequestObjects\TenantListFilter;
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

    public function paginate(int $perPage, ?TenantListFilter $filter = null): LengthAwarePaginator
    {
        return TenantBusinessLoan::query()->select(['id', 'tenant_id', 'code', 'update_key', 'lender_id', 'receipt_account_id', 'amount', 'principal_balance', 'apply_interest', 'interest_rate', 'interest_type_id', 'compound_schedule_enabled', 'compound_every', 'compound_every_type', 'next_compound_at', 'last_compounded_at', 'description', 'tag', 'is_paid', 'created_at', 'updated_at'])->with(['lender:id,code,person_id', 'lender.person:id,name', 'receiptAccount:id,code,name,currency_id', 'receiptAccount.currency:id,code,name,symbol', 'interestType:id,name'])->withSum('interestAccruals as total_interest_accrued', 'calculated_interest')->withSum('interestAccruals as total_interest_paid', 'paid_amount')->withSum('interestAccruals as total_interest_compounded', 'compounded_amount')
            ->when($filter?->search, fn ($query, $search) => $query->where(function ($query) use ($search): void {
                $query->where('code', 'like', "%{$search}%")->orWhere('description', 'like', "%{$search}%")
                    ->orWhere('tag', 'like', "%{$search}%")
                    ->orWhereHas('lender.person', fn ($person) => $person->where('name', 'like', "%{$search}%"));
            }))
            ->when($filter?->status, fn ($query, $status) => $query->where('is_paid', $status === 'settled'))
            ->when($filter?->typeId, fn ($query, $id) => $query->where('interest_type_id', $id))
            ->when($filter?->lenderId, fn ($query, $id) => $query->where('lender_id', $id))
            ->when($filter?->applyInterest !== null, fn ($query) => $query->where('apply_interest', $filter->applyInterest))
            ->when($filter?->fromDate, fn ($query, $date) => $query->whereDate('created_at', '>=', $date))
            ->when($filter?->toDate, fn ($query, $date) => $query->whereDate('created_at', '<=', $date))
            ->orderByDesc('id')->paginate($perPage);
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
    public function paginateAccruals(TenantBusinessLoan $loan, int $perPage, int $page): LengthAwarePaginator
    {
        return $loan->interestAccruals()->orderBy('start_period_at')->orderBy('id')->paginate($perPage, ['*'], 'page', $page);
    }
    public function paginatePayments(TenantBusinessLoan $loan, int $perPage, int $page): LengthAwarePaginator
    {
        return $loan->payments()->select(["id", "business_loan_id", "payment_amount", "principal_paid", "interest_paid", "allocation_order", "payment_at"])->orderByDesc('payment_at')->orderByDesc('id')->paginate($perPage, ['*'], 'page', $page);
    }

    public function compoundScheduleTenantIds(): SupportCollection
    {
        return TenantBusinessLoan::query()->withoutGlobalScope('tenant')->where('is_deleted', false)->where('is_paid', false)->where('apply_interest', true)->where('compound_schedule_enabled', true)->whereNotNull('next_compound_at')->distinct()->orderBy('tenant_id')->pluck('tenant_id');
    }

    public function dueScheduledLoans(int $tenantId, CarbonInterface $now): Collection
    {
        return TenantBusinessLoan::query()->withoutGlobalScope('tenant')->with($this->relations())->where('tenant_id', $tenantId)->where('is_deleted', false)->where('is_paid', false)->where('apply_interest', true)->where('compound_schedule_enabled', true)->whereNotNull('next_compound_at')->where('next_compound_at', '<=', $now->utc())->orderBy('next_compound_at')->get();
    }
}
