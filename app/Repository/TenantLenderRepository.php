<?php

namespace App\Repository;

use App\Models\CoreModule\TenantCustomer;
use App\Models\CoreModule\TenantLender;
use App\Models\CoreModule\TenantPerson;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\LazyCollection;

class TenantLenderRepository
{
    public function paginate(int $perPage, ?string $search): LengthAwarePaginator
    {
        return TenantLender::query()
            ->with('person')
            ->withCount('businessLoans')
            ->withCount(['businessLoans as active_loans_count' => fn ($query) => $query->where('is_paid', false)])
            ->withSum(['businessLoans as outstanding_principal' => fn ($query) => $query->where('is_paid', false)], 'principal_balance')
            ->when($search, fn ($query) => $query->where(function ($query) use ($search): void {
                $query->where('code', 'like', "%{$search}%")
                    ->orWhereHas('person', fn ($person) => $person->where('name', 'like', "%{$search}%")
                        ->orWhere('nrc', 'like', "%{$search}%")
                        ->orWhere('email', 'like', "%{$search}%")
                        ->orWhere('phone', 'like', "%{$search}%"));
            }))
            ->orderByDesc('id')
            ->paginate($perPage);
    }

    public function findByCode(string $code, bool $lock = false): ?TenantLender
    {
        $query = TenantLender::query()->with(['person', 'businessLoans.interestType'])
            ->withCount('businessLoans')
            ->withCount(['businessLoans as active_loans_count' => fn ($query) => $query->where('is_paid', false)])
            ->withSum(['businessLoans as outstanding_principal' => fn ($query) => $query->where('is_paid', false)], 'principal_balance')
            ->where('code', $code);
        return ($lock ? $query->lockForUpdate() : $query)->first();
    }

    public function findPerson(int $tenantId, ?string $nrc, ?string $email, ?string $phone): ?TenantPerson
    {
        $values = array_filter(['nrc' => $nrc, 'email' => $email, 'phone' => $phone], fn ($value) => $value !== null && $value !== '');
        if ($values === []) return null;
        return TenantPerson::query()->withoutGlobalScopes()->where('tenant_id', $tenantId)->where(function ($query) use ($values): void {
            foreach ($values as $field => $value) $query->orWhere($field, $value);
        })->first();
    }

    public function createPerson(array $data): TenantPerson { return TenantPerson::query()->create($data); }
    public function updatePerson(TenantPerson $person, array $data): TenantPerson { $person->update($data); return $person->refresh(); }
    public function createLender(array $data): TenantLender { return TenantLender::query()->create($data); }
    public function createCustomer(array $data): TenantCustomer { return TenantCustomer::query()->create($data); }
    public function lenderForPerson(int $personId, bool $withDeleted = false): ?TenantLender { $query = $withDeleted ? TenantLender::withTrashed() : TenantLender::query(); return $query->where('person_id', $personId)->first(); }
    public function customerForPerson(int $personId, bool $withDeleted = false): ?TenantCustomer { $query = $withDeleted ? TenantCustomer::withTrashed() : TenantCustomer::query(); return $query->where('person_id', $personId)->first(); }
    public function restoreLender(TenantLender $lender): TenantLender { $lender->restore(); $lender->update(['is_deleted' => false]); return $lender->refresh(); }
    public function mirrorCustomerIdentity(TenantCustomer $customer, TenantPerson $person): void { $customer->update(['name' => $person->name, 'nrc' => $person->nrc, 'email' => $person->email, 'phone' => $person->phone, 'address' => $person->address, 'note' => $person->note]); }
    public function hasUnpaidLoans(TenantLender $lender): bool { return $lender->businessLoans()->where('is_paid', false)->exists(); }
    public function delete(TenantLender $lender): void { $lender->update(['is_deleted' => true, 'update_key' => $lender->update_key + 1]); $lender->delete(); }

    /**
     * @return LazyCollection<int, TenantLender>
     */
    public function legacyCodeLenders(?int $tenantId = null): LazyCollection
    {
        // Selection of legacy migration codes without applying the request tenant scope
        return TenantLender::query()
            ->withoutGlobalScopes()
            ->withTrashed()
            ->where('code', 'like', 'LDR-%')
            ->when($tenantId !== null, fn ($query) => $query->where('tenant_id', $tenantId))
            ->orderBy('id')
            ->lazyById();
    }

    public function updateCode(TenantLender $lender, string $code): void
    {
        // Replacement of only the legacy code while preserving lender history
        $lender->newQueryWithoutScopes()
            ->whereKey($lender->id)
            ->where('tenant_id', $lender->tenant_id)
            ->update(['code' => $code]);
    }
}
