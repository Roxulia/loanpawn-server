<?php

namespace Tests\Support;

use App\Services\TenantModule\OwnershipMonthlySummaryService;
use Carbon\{Carbon, CarbonImmutable};
use Illuminate\Support\Facades\DB;

/** Real checkpoint tests need committed fixtures because publication owns its transactions. */
abstract class OwnershipMonthlyTestCase extends OwnershipQuantityTestCase
{
    use CommittedOwnershipFixture;

    protected bool $wrapFixtureTransaction = false;

    protected function clock(string $date): void
    {
        // Pin both Carbon variants so service dates and Eloquent recording timestamps agree.
        Carbon::setTestNow(Carbon::parse($date, 'UTC'));
        CarbonImmutable::setTestNow(CarbonImmutable::parse($date, 'UTC'));
    }

    protected function summarize(string $month = '2026-10'): int
    {
        return app(OwnershipMonthlySummaryService::class)->summarizeTenant(
            (int) $this->ownershipFixture['tenant']->id, $month);
    }

    protected function checkpoints(): array
    {
        // Proposed OWN-15 tables are explicit dependencies; absent tables must fail readiness,
        // never become a skipped assertion or a fabricated checkpoint in test memory.
        $state = [];
        $headers = DB::table('ownership_monthly_checkpoints')->where('tenant_id', $this->ownershipFixture['tenant']->id)
            ->orderBy('id')->get();
        $state['ownership_monthly_checkpoints'] = $headers->map(static fn ($row): array => (array) $row)->all();
        $state['ownership_monthly_checkpoint_balances'] = DB::table('ownership_monthly_checkpoint_balances')
            ->whereIn('ownership_monthly_checkpoint_id', $headers->pluck('id'))->orderBy('id')->get()
            ->map(static fn ($row): array => (array) $row)->all();
        return $state;
    }

    protected function checkpoint(array $goods, string $month = '2026-10-01'): object
    {
        return DB::table('ownership_monthly_checkpoints')->where('tenant_id', $this->ownershipFixture['tenant']->id)
            ->where('owned_item_id', $goods['owned']->id)->where('month', $month)->firstOrFail();
    }
}
