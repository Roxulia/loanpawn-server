<?php

namespace Tests\Feature\TenantModule;

use App\Repository\OwnershipRepository;
use App\Services\TenantModule\OwnershipService;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Tests\Support\OwnershipFifoTestCase;

/** OWN-18: real source/link/commitment persistence without later public orchestration. */
class OwnershipSourceQueryTest extends OwnershipFifoTestCase
{
    public function test_source_lookup_preserves_canonical_identity_and_tenant_scope(): void
    {
        // A canonical source must not disappear just because a caller expects a different item.
        $goods = $this->fifoGoods(received: false);
        $repository = app(OwnershipRepository::class);
        $tenant = (int) $this->ownershipFixture['tenant']->id;
        $source = $goods['purchase']['line']->code;
        $lot = $repository->findBySourceCode($tenant, 'PURCHASING', 'PurchaseOrderLine', $source, true);
        $this->assertSame($goods['lot']->id, $lot->id);
        $this->assertNull($repository->findBySourceCode($tenant, 'PAWN', 'PurchaseOrderLine', $source));
        $this->assertNull($repository->findBySourceCode($tenant, 'PURCHASING', 'PurchaseReceiptLine', $source));
        $home = $this->ownershipFixture;
        $this->createTenant();
        $this->assertNull($repository->findBySourceCode((int) $this->ownershipFixture['tenant']->id, 'PURCHASING', 'PurchaseOrderLine', $source));
        $this->setTenant($home);

        // Changing the source's expected item cannot allocate the already-recorded source again.
        $other = $this->createItem();
        $goods['purchase']['line']->update(['inventory_item_id' => $other->id]);
        $this->assertNoChange(fn () => app(OwnershipService::class)->acquirePurchaseLine($source, 'source-other-item'));
    }

    public function test_original_receipt_allocation_stays_exhausted_after_consumption(): void
    {
        // Four originally linked units remain four allocated after one is consumed; remaining owned is three.
        $goods = $this->fifoGoods(quantity: '4.000');
        $source = $this->sale($goods, '1.000');
        $this->change($goods, 'reserve', '1.000', $source);
        $this->change($goods, 'consume', '1.000', $source);
        $repository = app(OwnershipRepository::class);
        $tenant = (int) $this->ownershipFixture['tenant']->id;
        $receiptId = (int) $goods['receipt']['receiptLine']->id;
        $this->assertSame('4.000', $repository->receiptAllocatedTotals($tenant, [$receiptId])[$receiptId]->linked);
        $facts = $repository->receiptFacts($tenant, [$goods['lot']->id])[$goods['lot']->id];
        $this->assertSame(['4.000', '3.000'], [$facts->linked, $facts->received]);
        $this->assertLinkRejected($goods['lot'], $goods['receipt'], '0.001');
        $this->createTenant();
        $this->assertTrue($repository->receiptAllocatedTotals((int) $this->ownershipFixture['tenant']->id, [$receiptId])->isEmpty());
    }

    public function test_membership_filters_are_batched_and_exclude_ended_and_unrelated_pieces(): void
    {
        // One receipt holds 25 exact pieces. A two-piece lookup must be one query, not 25 lookups.
        $serials = array_map(fn ($number) => 'QUERY-'.$number, range(1, 25));
        $goods = $this->fifoGoods(quantity: '25.000', serials: $serials);
        $repository = app(OwnershipRepository::class);
        $tenant = (int) $this->ownershipFixture['tenant']->id;
        $all = $repository->currentMemberships($tenant, [$goods['lot']->id]);
        $selected = $all->take(2);
        $linkId = (int) $all->first()->ownership_receipt_link_id;
        $rows = $this->oneQuery(fn () => $repository->currentMemberships($tenant, [$goods['lot']->id],
            ownedItemId: (int) $goods['owned']->id, receiptLinkId: $linkId,
            pieceIds: $selected->pluck('inventory_serialized_item_id')->all()));
        $this->assertSame($selected->pluck('id')->all(), $rows->pluck('id')->all());
        $first = $selected->first();
        $this->assertTrue($repository->endMembership($tenant, $first, CarbonImmutable::now('UTC')));
        $this->assertFalse($repository->endMembership($tenant, $first, CarbonImmutable::now('UTC')->addDay()));
        $this->assertSame(CarbonImmutable::now('UTC')->toDateTimeString(), $first->fresh()->ended_at->toDateTimeString());
        $this->assertCount(24, $repository->currentMemberships($tenant, [$goods['lot']->id]));
        $this->assertCount(25, $this->memberships($goods));
        $this->assertTrue($repository->currentMemberships($tenant, [$goods['lot']->id], ownedItemId: 0)->isEmpty());
        $this->assertTrue($repository->currentMemberships($tenant, [$goods['lot']->id], receiptLinkId: 0)->isEmpty());
        $this->assertTrue($repository->currentMemberships($tenant, [$goods['lot']->id], pieceIds: [])->isEmpty());
    }

    public function test_commitments_are_filtered_by_item_kind_type_and_source(): void
    {
        // The same source text can exist under SALE and PLEDGE. Neither may supply the other's quantity.
        $goods = $this->fifoGoods(quantity: '6.000');
        $sale = $this->sale($goods, '3.000');
        $other = $this->sale($goods, '1.000');
        $this->change($goods, 'reserve', '2.000', $sale);
        $this->change($goods, 'reserve', '1.000', $other);
        $this->change($goods, 'pledge', '1.000', $sale);
        $repository = app(OwnershipRepository::class);
        $tenant = (int) $this->ownershipFixture['tenant']->id;
        $rows = $this->oneQuery(fn () => $repository->commitments($tenant, [$goods['lot']->id], true,
            ownedItemId: (int) $goods['owned']->id, kind: 'SALE', sourceType: 'SaleOrderLine', sourceCode: $sale));
        $this->assertCount(1, $rows);
        $this->assertSame('2.000', $rows->first()->remaining_quantity);
        $pledged = $repository->commitments($tenant, [$goods['lot']->id], kind: 'PLEDGE',
            sourceType: 'BusinessLoanCollateral', sourceCode: $sale);
        $this->assertCount(1, $pledged);
        $this->assertSame('1.000', $pledged->first()->remaining_quantity);
        $this->assertTrue($repository->commitments($tenant, [$goods['lot']->id], ownedItemId: 0)->isEmpty());
        $this->assertTrue($repository->commitments($tenant, [$goods['lot']->id], kind: 'SALE', sourceType: 'BusinessLoanCollateral', sourceCode: $sale)->isEmpty());
        $this->assertTrue($repository->commitments($tenant, [$goods['lot']->id], sourceCode: 'MISSING')->isEmpty());

        // Exhaustion remains visible as history with exactly zero remaining, never a movement-history sum.
        $this->change($goods, 'consume', '2.000', $sale);
        $rows = $repository->commitments($tenant, [$goods['lot']->id], kind: 'SALE', sourceType: 'SaleOrderLine', sourceCode: $sale);
        $this->assertSame('0.000', $rows->sole()->remaining_quantity);
    }

    public function test_source_capacity_spans_lots_without_loading_other_sources(): void
    {
        // A single line requests three across two lots. Its one existing unit leaves room for only two.
        $first = $this->fifoGoods();
        $second = $this->fifoGoods($first['item']);
        $source = $this->sale($first, '3.000');
        $other = $this->sale($first, '1.000');
        $this->change($first, 'reserve', '1.000', $source);
        $this->change($first, 'reserve', '1.000', $other);
        $this->change($second, 'reserve', '2.000', $source);
        $this->assertNoChange(fn () => $this->change($second, 'reserve', '0.001', $source));
        $rows = app(OwnershipRepository::class)->commitments((int) $this->ownershipFixture['tenant']->id,
            [$first['lot']->id, $second['lot']->id], kind: 'SALE', sourceType: 'SaleOrderLine', sourceCode: $source);
        $this->assertCount(2, $rows);
        $this->assertSame([$source, $source], $rows->pluck('source_code')->all());
    }

    public function test_narrow_persistence_cannot_mutate_foreign_records_and_rolls_back(): void
    {
        // Establish actual membership and a source-bound hold before checking each persistence boundary.
        $goods = $this->fifoGoods(quantity: '1.000', serials: ['PERSIST-A']);
        $source = $this->sale($goods, '1.000');
        $this->change($goods, 'reserve', '1.000', $source, $goods['pieces']);
        $repository = app(OwnershipRepository::class);
        $tenant = (int) $this->ownershipFixture['tenant']->id;
        $member = $repository->currentMemberships($tenant, [$goods['lot']->id])->sole();
        $hold = $repository->pieceCommitments($tenant, [$member->id])->sole();
        $link = $repository->receiptLinks($tenant, [$goods['lot']->id])->sole();
        $commitment = $repository->commitments($tenant, [$goods['lot']->id], kind: 'SALE', sourceCode: $source)->sole();
        $home = $this->ownershipFixture;
        $foreign = $this->createTenant();
        $this->setTenant($home);
        $foreignTenantId = (int) $foreign['tenant']->id;
        $before = $this->receiptState();
        $this->assertFalse($repository->updateCommitment($foreignTenantId, $commitment, '1.000', '0.000'));
        $this->assertFalse($repository->updateReceiptRemaining($foreignTenantId, $link, '0.000'));
        $this->assertFalse($repository->endMembership($foreignTenantId, $member, CarbonImmutable::now('UTC')));
        $this->assertFalse($repository->endPieceCommitment($foreignTenantId, $hold, CarbonImmutable::now('UTC')));
        $this->assertSame($before, $this->receiptState());

        // A caller failure after all four real writes must restore history and current quantities together.
        try {
            DB::transaction(function () use ($repository, $tenant, $member, $hold, $link, $commitment): void {
                $this->assertTrue($repository->updateCommitment($tenant, $commitment, '1.000', '0.000'));
                $this->assertTrue($repository->updateReceiptRemaining($tenant, $link, '0.000'));
                $this->assertTrue($repository->endPieceCommitment($tenant, $hold, CarbonImmutable::now('UTC')));
                $this->assertTrue($repository->endMembership($tenant, $member, CarbonImmutable::now('UTC')));
                throw new RuntimeException('Rollback narrow writes');
            });
        } catch (RuntimeException $error) {
            $this->assertSame('Rollback narrow writes', $error->getMessage());
        }
        $this->assertSame($before, $this->receiptState());
    }

    public function test_database_preserves_one_current_membership_per_piece(): void
    {
        // Repository insertion cannot defeat the existing generated unique current-piece constraint.
        $goods = $this->fifoGoods(quantity: '1.000', serials: ['UNIQUE-MEMBERSHIP']);
        $repository = app(OwnershipRepository::class);
        $tenant = (int) $this->ownershipFixture['tenant']->id;
        $member = $repository->currentMemberships($tenant, [$goods['lot']->id])->sole();
        $before = $this->receiptState();
        try {
            DB::transaction(fn () => $repository->createMembership(['tenant_id' => $tenant,
                'owned_item_id' => $member->owned_item_id, 'acquisition_lot_id' => $member->acquisition_lot_id,
                'ownership_receipt_link_id' => $member->ownership_receipt_link_id,
                'inventory_serialized_item_id' => $member->inventory_serialized_item_id, 'started_at' => CarbonImmutable::now('UTC')]));
            $this->fail('A piece cannot have two current ownership memberships.');
        } catch (QueryException $error) {
            $this->assertSame(1062, (int) $error->errorInfo[1]);
        }
        $this->assertSame($before, $this->receiptState());
    }

    private function oneQuery(callable $action)
    {
        // Measure only the repository action; fixture construction and response assertions are outside it.
        DB::enableQueryLog();
        DB::flushQueryLog();
        try {
            $result = $action();
            $this->assertCount(1, DB::getQueryLog());
            return $result;
        } finally {
            DB::disableQueryLog();
            DB::flushQueryLog();
        }
    }
}
