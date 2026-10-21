<?php

namespace Tests\Support;

use App\DataObjects\RequestObjects\OwnershipPledgeSource;
use App\Exceptions\ApiException;
use App\Services\TenantModule\OwnershipService;
use Illuminate\Support\Facades\DB;

/** Shared real-ledger setup for V2 quantity, rollback and checkpoint cases. */
abstract class OwnershipQuantityTestCase extends OwnershipPieceTestCase
{
    protected function linkedGoods(): array
    {
        // Own ten paid units and physically receive/link all ten. No balances are seeded.
        $goods = $this->goods();
        $receipt = $this->receivePurchase($goods['purchase'], '10.000');
        $this->linkReceipt($goods['lot'], $receipt, '10.000');

        return $goods;
    }

    protected function sale(array $goods, string $quantity = '10.000'): string
    {
        // Persist the confirmed caller source before snapshots used by rejection assertions.
        return $this->saleLine($goods['lot'], $goods['item'], $quantity)->code;
    }

    protected function change(array $goods, string $action, string $quantity, string $source, array $codes = []): mixed
    {
        // Exercise real Ownership primitives. Pledge context is resolver-output test data;
        // lender authorization, cash and physical lender custody remain outside Part 2.
        $service = app(OwnershipService::class);
        $args = [$goods['owned']->code, $goods['lot']->code, $quantity];
        return match ($action) {
            'reserve' => $service->reserveSale(...[...$args, $source, 'serializedItemCodes' => $codes]),
            'release' => $service->releaseSaleReservation(...[...$args, $source, 'serializedItemCodes' => $codes]),
            'consume' => $service->consumeSaleReservation(...[...$args, $source, 'serializedItemCodes' => $codes]),
            'pledge' => $service->reservePledge(...[...$args, 'BusinessLoanCollateral', $source,
                'serializedItemCodes' => $codes, 'sourceContext' => new OwnershipPledgeSource(
                    tenantId: (int) $this->ownershipFixture['tenant']->id, sourceType: 'BusinessLoanCollateral',
                    sourceCode: $source, ownedItemCode: $goods['owned']->code, lotCode: $goods['lot']->code,
                    quantity: $quantity, currencyCode: $goods['lot']->currency_code, serializedItemCodes: $codes)]),
            'unpledge' => $service->releasePledge(...[...$args, 'BusinessLoanCollateral', $source, 'serializedItemCodes' => $codes]),
            'forfeit' => $service->reduceForfeiture(...[...$args, 'BusinessLoanCollateral', $source, 'serializedItemCodes' => $codes]),
        };
    }

    protected function assertLotBalance(array $goods, string $owned, string $reserved = '0.000', string $pledged = '0.000'): void
    {
        // Compare literal expectations against both the complete ledger and operational projection.
        // The latter will use checkpoints later and must agree with the independent SQL oracle.
        $rows = DB::table('ownership_movements')->where('tenant_id', $this->ownershipFixture['tenant']->id)
            ->where('acquisition_lot_id', $goods['lot']->id);
        $data = $this->lotData($goods);
        foreach (['owned' => $owned, 'reserved' => $reserved, 'pledged' => $pledged] as $kind => $value) {
            $this->assertSame($value, (string) (clone $rows)->sum($kind.'_delta'));
            $this->assertSame($value, $data[$kind.'_quantity']);
        }
    }

    protected function assertNoChange(callable $action, int $status = 422): void
    {
        // Rejected operations must preserve complete source, custody, membership and ledger rows.
        $before = $this->receiptState();
        try {
            $action();
            $this->fail('The invalid operation must reject.');
        } catch (ApiException $error) {
            $this->assertSame($status, $error->statusCode());
        }
        $this->assertSame($before, $this->receiptState());
    }
}
