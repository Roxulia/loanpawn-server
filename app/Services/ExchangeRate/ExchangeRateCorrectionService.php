<?php

namespace App\Services\ExchangeRate;

use App\Exceptions\InvalidTenantRequest;
use App\Models\CoreModule\ExchangeRateEntry;
use App\Repository\ExchangeRateCorrectionRepository;
use App\Repository\ExchangeRateEntryRepository;
use App\Utility\MessageCode;
use App\Utility\Messages;
use Illuminate\Support\Facades\DB;

class ExchangeRateCorrectionService
{
    public function __construct(private ExchangeRateEntryRepository $entries, private ExchangeRateCorrectionRepository $corrections, private ExchangeRateEntryWriter $writer, private ExchangeRateSummaryService $summaries, private Messages $messages) {}

    public function correct(ExchangeRateEntry $entry, string $rate, string $reason, ?int $tenantUserId, ?int $adminId): ExchangeRateEntry
    {
        if ($entry->is_void) {
            throw new InvalidTenantRequest($this->messages->responseMessage(MessageCode::FinanceRateEntryAlreadyVoid));
        }

        return DB::transaction(function () use ($entry, $rate, $reason, $tenantUserId, $adminId) {
            $locked = ExchangeRateEntry::query()->lockForUpdate()->findOrFail($entry->id);
            $this->voidLocked($locked, $reason, $tenantUserId, $adminId);
            $replacement = $this->writer->create($locked->pair, ['rate' => $rate, 'observed_at' => $locked->observed_at], $locked->tenant_id, $tenantUserId, $adminId);
            $this->corrections->create(['original_entry_id' => $locked->id, 'replacement_entry_id' => $replacement->id, 'tenant_id' => $locked->tenant_id, 'scope_key' => $locked->scope_key, 'action' => 'CORRECT', 'reason' => $reason, 'corrected_by_tenant_user_id' => $tenantUserId, 'corrected_by_platform_admin_id' => $adminId]);

            return $replacement;
        });
    }

    public function void(ExchangeRateEntry $entry, string $reason, ?int $tenantUserId, ?int $adminId): void
    {
        if ($entry->is_void) {
            throw new InvalidTenantRequest($this->messages->responseMessage(MessageCode::FinanceRateEntryAlreadyVoid));
        }
        DB::transaction(function () use ($entry, $reason, $tenantUserId, $adminId) {
            $locked = ExchangeRateEntry::query()->lockForUpdate()->findOrFail($entry->id);
            $this->voidLocked($locked, $reason, $tenantUserId, $adminId);
            $this->corrections->create(['original_entry_id' => $locked->id, 'tenant_id' => $locked->tenant_id, 'scope_key' => $locked->scope_key, 'action' => 'VOID', 'reason' => $reason, 'corrected_by_tenant_user_id' => $tenantUserId, 'corrected_by_platform_admin_id' => $adminId]);
        });
    }

    private function voidLocked(ExchangeRateEntry $entry, string $reason, ?int $tenantUserId, ?int $adminId): void
    {
        $entry->update(['is_void' => true, 'voided_at' => now(), 'void_reason' => $reason, 'voided_by_tenant_user_id' => $tenantUserId, 'voided_by_platform_admin_id' => $adminId]);
        $this->summaries->rebuild($entry->scope_key, $entry->tenant_id, $entry->exchange_rate_pair_id, $entry->effective_date->toDateString());
    }
}
