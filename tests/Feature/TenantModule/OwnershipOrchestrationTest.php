<?php

namespace Tests\Feature\TenantModule;

use App\DTOs\Ownership\Internal\RequestObjects\OwnershipPledgeSource;
use App\Services\TenantModule\Ownership\QuantityService;
use Illuminate\Support\Facades\DB;
use Tests\Support\OwnershipFifoTestCase;

/** Real action compatibility and bounded piece writes, separate from complete loan/sale finances. */
class OwnershipOrchestrationTest extends OwnershipFifoTestCase
{
    public function test_action_cannot_write_a_different_source_kind(): void
    {
        // Previously a pledge action with sale authority could mismatch commitment kind and ledger dimension.
        $goods = $this->fifoGoods();
        $sale = $this->sale($goods, '1.000');
        $quantity = app(QuantityService::class);
        $this->assertNoChange(fn () => $quantity->applyOwnershipQuantityAction($goods['owned']->code, $goods['lot']->code,
            '1.000', $sale, [], 'pledge'));
        $pledge = new OwnershipPledgeSource(tenantId: (int) $this->ownershipFixture['tenant']->id,
            sourceType: 'BusinessLoanCollateral', sourceCode: 'L1', ownedItemCode: $goods['owned']->code,
            lotCode: $goods['lot']->code, quantity: '1.000', currencyCode: $goods['lot']->currency_code, serializedItemCodes: []);
        $this->assertNoChange(fn () => $quantity->applyOwnershipQuantityAction($goods['owned']->code, $goods['lot']->code,
            '1.000', 'L1', [], 'reserve', $pledge));
    }

    public function test_piece_holds_and_episode_endings_use_bounded_batches(): void
    {
        // 501 pieces cross the 500-row boundary. Real receipt/linking establishes every exact identity.
        $serials = array_map(fn ($id) => 'BATCH-'.$id, range(1, 501));
        $goods = $this->fifoGoods(quantity: '501.000', serials: $serials);
        $source = $this->sale($goods, '501.000');
        DB::enableQueryLog();
        DB::flushQueryLog();
        try {
            $this->change($goods, 'reserve', '501.000', $source, $goods['pieces']);
            $this->change($goods, 'consume', '501.000', $source, $goods['pieces']);
            $sql = collect(DB::getQueryLog())->pluck('query')->map(fn ($query) => strtolower($query));
        } finally {
            DB::disableQueryLog();
        }
        $this->assertCount(2, $sql->filter(fn ($query) => str_starts_with($query, 'insert into `ownership_piece_commitments`')));
        $this->assertCount(2, $sql->filter(fn ($query) => str_starts_with($query, 'update `ownership_piece_commitments`')));
        $this->assertCount(2, $sql->filter(fn ($query) => str_starts_with($query, 'update `ownership_piece_memberships`')));
        $this->assertLotBalance($goods, '0.000');
        $this->assertCurrentPieces($goods, []);
    }
}
