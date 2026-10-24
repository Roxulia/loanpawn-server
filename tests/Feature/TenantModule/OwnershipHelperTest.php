<?php

namespace Tests\Feature\TenantModule;

use App\DTOs\Ownership\Public\ResponseObjects\OwnershipResult;
use App\DTOs\Ownership\Internal\ResponseObjects\OwnershipMutationResult;
use App\Services\TenantModule\TenantCurrencyService;
use App\Repository\OwnershipRepository;
use App\Services\TenantModule\Ownership\{OperationService, CheckpointService, QuantityValidationService, MovementService};
use App\Exceptions\InvalidTenantRequest;
use Illuminate\Support\Facades\DB;
use Tests\Support\OwnershipFifoTestCase;

/** OWN-20/21 helper boundaries, independent of the later monthly publisher or public endpoints. */
class OwnershipHelperTest extends OwnershipFifoTestCase
{
    public function test_checkpoint_uses_one_balance_scan_for_totals_and_validation(): void
    {
        // Exact totals and overcommitment detection must share one aggregate read of the newly saved children.
        $goods = $this->fifoGoods(received: false);
        DB::enableQueryLog();
        DB::flushQueryLog();
        try {
            $checkpoint = app(CheckpointService::class)->publishMonthlyOwnershipCheckpoint($goods['owned']->id,
                '2026-09', 'UTC', '2026-10-01 00:00:00', 0,
                [['acquisition_lot_id' => $goods['lot']->id, 'owned_quantity' => '3.000',
                    'reserved_quantity' => '1.000', 'pledged_quantity' => '1.000']]);
            $scans = collect(DB::getQueryLog())->pluck('query')->filter(fn ($sql) =>
                str_contains($sql, 'ownership_monthly_checkpoint_balances') && preg_match('/sum\(|exists/i', $sql));
        } finally {
            DB::disableQueryLog();
        }
        $this->assertCount(1, $scans);
        $this->assertSame(['3.000', '1.000', '1.000'],
            [$checkpoint->owned_quantity, $checkpoint->reserved_quantity, $checkpoint->pledged_quantity]);
    }

    public function test_overcommitted_checkpoint_still_rolls_back_all_derived_rows(): void
    {
        // Combining scans must not remove the guard against reserved plus pledged exceeding ownership.
        $goods = $this->fifoGoods(received: false);
        $before = $this->fifoState();
        try {
            app(CheckpointService::class)->publishMonthlyOwnershipCheckpoint($goods['owned']->id,
                '2026-09', 'UTC', '2026-10-01 00:00:00', 0,
                [['acquisition_lot_id' => $goods['lot']->id, 'owned_quantity' => '1.000',
                    'reserved_quantity' => '1.000', 'pledged_quantity' => '1.000']]);
            $this->fail('Overcommitted closings must reject.');
        } catch (InvalidTenantRequest $error) {
            $this->assertNotEmpty($error->getMessage());
        }
        $this->assertSame($before, $this->fifoState());
    }

    public function test_atomic_operation_reuses_its_resolved_audit_target(): void
    {
        // The target is already resolved by the mutation: auditing must not select Ownership again.
        $goods = $this->fifoGoods(received: false);
        $response = new OwnershipResult($goods['owned']->code, (int) $goods['owned']->update_key);
        DB::enableQueryLog();
        DB::flushQueryLog();
        try {
            $result = app(OperationService::class)->runAtomicOwnershipOperation('ownership.helper.audit', null, [],
                fn () => new OwnershipMutationResult($response, $goods['owned']));
            $ownerReads = collect(DB::getQueryLog())->pluck('query')->filter(fn ($sql) =>
                str_starts_with(strtolower($sql), 'select') && str_contains($sql, 'owned_items'));
        } finally {
            DB::disableQueryLog();
        }
        $this->assertSame($response, $result);
        $this->assertCount(0, $ownerReads);
    }

    public function test_currency_identity_validation_uses_one_batch_lookup(): void
    {
        // Distinct visible identities are validated together; repeated IDs must not add queries or false misses.
        $first = $this->ownershipFixture['currency'];
        $second = $first->replicate();
        $second->code = 'Q'.strtoupper(bin2hex(random_bytes(2)));
        $second->save();
        DB::enableQueryLog();
        DB::flushQueryLog();
        try {
            $currencies = app(TenantCurrencyService::class)->findActiveVisibleCurrenciesByIdsForTenant(
                (int) $this->ownershipFixture['tenant']->id, [$second->id, $first->id, $first->id]);
            $reads = collect(DB::getQueryLog())->pluck('query')->filter(fn ($sql) =>
                str_starts_with(strtolower($sql), 'select') && str_contains($sql, 'currencies'));
        } finally {
            DB::disableQueryLog();
        }
        $this->assertCount(2, $currencies);
        $this->assertCount(1, $reads);
        $this->assertEqualsCanonicalizing([$first->id, $second->id], $currencies->modelKeys());
    }

    public function test_fungible_action_does_not_load_serialized_memberships(): void
    {
        // A fungible reservation needs source/receipt/custody facts, but no serialized membership or hold rows.
        $goods = $this->fifoGoods(quantity: '3.000');
        $source = $this->sale($goods, '2.000');
        DB::enableQueryLog();
        DB::flushQueryLog();
        try {
            $this->change($goods, 'reserve', '1.000', $source);
            $pieceQueries = collect(DB::getQueryLog())->pluck('query')->filter(fn ($query) =>
                str_contains($query, 'ownership_piece_memberships') || str_contains($query, 'ownership_piece_commitments'));
        } finally {
            DB::disableQueryLog();
        }
        $this->assertTrue($pieceQueries->isEmpty(), 'Fungible actions must not load serialized Ownership state.');
        $this->assertLotBalance($goods, '3.000', '1.000');
    }

    public function test_internal_replay_returns_without_running_mutation_or_auditing_twice(): void
    {
        // The first operation advances one version. Replay returns its references and lets the caller continue.
        $goods = $this->fifoGoods(received: false);
        $calls = 0;
        $mutation = function (?int $recordId) use ($goods, &$calls): OwnershipResult {
            $calls++;
            $this->assertNotNull($recordId);
            return new OwnershipResult($goods['owned']->code,
                app(OwnershipRepository::class)->advanceVersion($goods['owned']), [$goods['lot']->code]);
        };
        $service = app(OperationService::class);
        $first = $service->runAtomicOwnershipOperation('ownership.helper', 'helper-replay', [], $mutation);
        $state = $this->fifoState();
        $again = $service->runAtomicOwnershipOperation('ownership.helper', 'helper-replay', [], $mutation);
        $this->assertSame(1, $calls);
        $this->assertSame($first->toArray(), $again->toArray());
        $this->assertSame($state, $this->fifoState());
    }

    public function test_failed_operation_rolls_back_and_can_retry_the_same_key(): void
    {
        // An actual version write followed by failure must leave neither state nor a processing key behind.
        $goods = $this->fifoGoods(received: false);
        $before = $this->fifoState();
        try {
            app(OperationService::class)->runAtomicOwnershipOperation('ownership.helper', 'helper-failure', [], function () use ($goods): OwnershipResult {
                app(OwnershipRepository::class)->advanceVersion($goods['owned']);
                throw new \RuntimeException('helper failure');
            });
            $this->fail('Mutation failure must propagate.');
        } catch (\RuntimeException $error) {
            $this->assertSame('helper failure', $error->getMessage());
        }
        $this->assertSame($before, $this->fifoState());
        $result = app(OperationService::class)->runAtomicOwnershipOperation('ownership.helper', 'helper-failure', [],
            fn () => new OwnershipResult($goods['owned']->code, (int) $goods['owned']->fresh()->update_key));
        $this->assertSame($goods['owned']->code, $result->ownedItemCode);
    }

    public function test_forfeiture_uses_pledged_capacity_and_still_requires_its_own_source(): void
    {
        // All ten are pledged: free availability is zero. The exact source may forfeit ten, but not 10.001.
        $validator = app(QuantityValidationService::class);
        $balance = ['owned' => 10000, 'reserved' => 0, 'pledged' => 10000, 'available' => 0];
        $validator->validateQuantityActionBalance('forfeit', 10000, $balance, '10.000');
        foreach (['0.000', '9.999'] as $sourceRemaining) {
            try {
                $validator->validateQuantityActionBalance('forfeit', 10000, $balance, $sourceRemaining);
                $this->fail('Another or insufficient source cannot supply forfeiture capacity.');
            } catch (InvalidTenantRequest $error) {
                $this->assertNotEmpty($error->getMessage());
            }
        }
    }

    public function test_checkpoint_publication_is_atomic_idempotent_and_changes_no_entitlement(): void
    {
        // Publish a known closing, not a computed monthly workflow. Duplicate publication retains the original snapshot.
        $goods = $this->fifoGoods(received: false);
        $before = $this->fifoState();
        $rows = [['acquisition_lot_id' => $goods['lot']->id,
            'owned_quantity' => '3.000', 'reserved_quantity' => '0.000', 'pledged_quantity' => '0.000']];
        $service = app(CheckpointService::class);
        $first = $service->publishMonthlyOwnershipCheckpoint($goods['owned']->id, '2026-09', 'UTC', '2026-10-01 00:00:00', 0, $rows);
        $rows[0]['owned_quantity'] = '2.000';
        $again = $service->publishMonthlyOwnershipCheckpoint($goods['owned']->id, '2026-09', 'UTC', '2026-10-01 00:00:00', 0, $rows);
        $this->assertSame($first->id, $again->id);
        $this->assertSame('3.000', $again->owned_quantity);
        $this->assertNotNull($again->completed_at);
        $after = $this->fifoState();
        foreach (['ownership_monthly_checkpoints', 'ownership_monthly_checkpoint_balances'] as $table) {
            unset($before[$table], $after[$table]);
        }
        $this->assertSame($before, $after);
    }

    public function test_invalid_checkpoint_lot_leaves_no_partial_publication(): void
    {
        // A real different item's lot must reject before any derived rows become visible.
        $goods = $this->fifoGoods(received: false);
        $other = $this->fifoGoods(received: false);
        $before = $this->fifoState();
        try {
            app(CheckpointService::class)->publishMonthlyOwnershipCheckpoint($goods['owned']->id, '2026-09', 'UTC', '2026-10-01 00:00:00', 0,
                [['acquisition_lot_id' => $other['lot']->id, 'owned_quantity' => '1.000',
                    'reserved_quantity' => '0.000', 'pledged_quantity' => '0.000']]);
            $this->fail('A different item cannot contribute a closing lot.');
        } catch (InvalidTenantRequest $error) {
            $this->assertNotEmpty($error->getMessage());
        }
        $this->assertSame($before, $this->fifoState());
    }

    public function test_checkpoint_write_failure_rolls_back_header_and_children(): void
    {
        // Fail after an actual child INSERT so this proves atomic publication, not only pre-validation.
        $goods = $this->fifoGoods(received: false);
        $before = $this->fifoState();
        $armed = true;
        $observed = false;
        DB::listen(function ($query) use (&$armed, &$observed): void {
            if ($armed && str_starts_with(strtolower($query->sql), 'insert into `ownership_monthly_checkpoint_balances`')) {
                $observed = true;
                throw new \RuntimeException('checkpoint child failure');
            }
        });
        try {
            app(CheckpointService::class)->publishMonthlyOwnershipCheckpoint($goods['owned']->id, '2026-09', 'UTC', '2026-10-01 00:00:00', 0,
                [['acquisition_lot_id' => $goods['lot']->id, 'owned_quantity' => '3.000',
                    'reserved_quantity' => '0.000', 'pledged_quantity' => '0.000']]);
            $this->fail('An actual child write must fail.');
        } catch (\RuntimeException $error) {
            $this->assertSame('checkpoint child failure', $error->getMessage());
        } finally {
            $armed = false;
        }
        $this->assertTrue($observed);
        $this->assertSame($before, $this->fifoState());
    }

    public function test_source_commitment_validator_rejects_another_loan(): void
    {
        // Both loans have a valid context, but only L1 owns the persisted hold.
        $goods = $this->fifoGoods(quantity: '3.000');
        $this->change($goods, 'pledge', '2.000', 'L1');
        $tenant = (int) $this->ownershipFixture['tenant']->id;
        $commitment = app(OwnershipRepository::class)->commitments($tenant, [$goods['lot']->id], true,
            kind: 'PLEDGE', sourceType: 'BusinessLoanCollateral', sourceCode: 'L1')->sole();
        $validator = app(QuantityValidationService::class);
        $validator->validateSourceCommitment($commitment, $goods['owned'], $goods['lot'], 'PLEDGE', 'BusinessLoanCollateral', 'L1');
        $this->expectException(InvalidTenantRequest::class);
        $validator->validateSourceCommitment($commitment, $goods['owned'], $goods['lot'], 'PLEDGE', 'BusinessLoanCollateral', 'L2');
    }

    public function test_append_keeps_server_recording_time_separate_from_occurrence(): void
    {
        // A backdated event cannot backdate its server-recorded checkpoint coverage timestamp.
        $goods = $this->fifoGoods(received: false);
        $original = DB::table('ownership_movements')->where('owned_item_id', $goods['owned']->id)->first();
        $data = (array) $original;
        unset($data['id']);
        $data['code'] = 'MH'.bin2hex(random_bytes(8));
        $data['occurred_at'] = '2026-01-01 00:00:00';
        $data['created_at'] = '2026-01-01 00:00:00';
        $movement = app(MovementService::class)->appendOwnershipMovement($data);
        $this->assertSame('2026-10-15 12:00:00', $movement->created_at->format('Y-m-d H:i:s'));
        $this->assertSame('2026-01-01 00:00:00', $movement->occurred_at->format('Y-m-d H:i:s'));
        $this->assertSame('3.000', $movement->owned_delta);
    }
}
