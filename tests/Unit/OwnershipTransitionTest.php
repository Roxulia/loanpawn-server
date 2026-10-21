<?php

namespace Tests\Unit;

use App\Exceptions\InvalidTenantRequest;
use App\Models\OwnershipModule\{AcquisitionLot, OwnedItem};
use App\Repository\OwnershipRepository;
use App\Services\PawnModule\CollateralItemService;
use App\Services\PawnModule\LoanContractServices\ExpirationService;
use App\Services\TableIdGenerationService;
use App\Services\TenantModule\{InventoryService, OwnershipService, TenantIdempotencyService};
use App\Support\TenantContext;
use Illuminate\Support\Facades\{Auth, DB};
use Mockery;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\TestCase;

/** OWN-05e: exercise service decisions up to persistence, not real SQL balances or atomicity. */
class OwnershipTransitionTest extends TestCase
{
    public function test_sale_reservation_increases_reserved_only(): void
    {
        // B=(10,0,0), reserve 4: the proposed ledger change represents B=(10,4,0).
        $this->assertMovement('reserve', ['10.000', '0.000', '0.000'], '4.000',
            'SALE_RESERVE', ['0.000', '4.000', '0.000']);
    }

    public function test_sale_release_decreases_reserved_only(): void
    {
        // Independent B=(10,4,0), release 1: proposed result B=(10,3,0).
        $this->assertMovement('releaseSale', ['10.000', '4.000', '0.000'], '1.000',
            'SALE_RELEASE', ['0.000', '-1.000', '0.000']);
    }

    public function test_sale_consumption_reduces_owned_and_reserved_together(): void
    {
        // Independent B=(10,3,0), consume 2: one movement represents B=(8,1,0).
        $this->assertMovement('consume', ['10.000', '3.000', '0.000'], '2.000',
            'SALE_CONSUME', ['-2.000', '-2.000', '0.000']);
    }

    public function test_pledge_increases_pledged_only(): void
    {
        // Independent B=(10,0,0), pledge 4: proposed result B=(10,0,4).
        $this->assertMovement('pledge', ['10.000', '0.000', '0.000'], '4.000',
            'PLEDGE', ['0.000', '0.000', '4.000']);
    }

    public function test_pledge_release_decreases_pledged_only(): void
    {
        // Independent B=(10,0,4), release 1: proposed result B=(10,0,3).
        $this->assertMovement('releasePledge', ['10.000', '0.000', '4.000'], '1.000',
            'RELEASE', ['0.000', '0.000', '-1.000']);
    }

    public function test_forfeiture_reduces_owned_and_pledged_together(): void
    {
        // Independent B=(10,0,3), forfeit 2: proposed result B=(8,0,1).
        $this->assertMovement('forfeit', ['10.000', '0.000', '3.000'], '2.000',
            'FORFEITURE', ['-2.000', '0.000', '-2.000']);
    }

    public function test_fully_pledged_forfeiture_does_not_need_free_quantity(): void
    {
        // Matching forfeiture can exhaust B=(10,0,10) even though unpledged availability is zero.
        $this->assertMovement('forfeit', ['10.000', '0.000', '10.000'], '10.000',
            'FORFEITURE', ['-10.000', '0.000', '-10.000']);
    }

    public function test_exact_available_quantity_can_be_reserved(): void
    {
        // Existing reserved and pledged balances leave exactly 0.001 free; it must remain usable.
        $this->assertMovement('reserve', ['10.000', '4.999', '5.000'], '0.001',
            'SALE_RESERVE', ['0.000', '0.001', '0.000']);
    }

    #[DataProvider('excessQuantities')]
    public function test_excess_quantity_is_rejected_before_movement(string $operation, array $before, string $quantity): void
    {
        // Rejections must not reach ledger persistence, even for a one-thousandth shortfall.
        [$service, $repository] = $this->service($before);
        $repository->shouldNotReceive('createMovement');
        $this->expectException(InvalidTenantRequest::class);
        $this->operate($service, $operation, $quantity);
    }

    #[DataProvider('invalidQuantities')]
    public function test_invalid_quantity_is_rejected_before_movement(string $operation, string $quantity): void
    {
        // Every operation rejects zero, negative and over-precision input without a proposed movement.
        [$service, $repository] = $this->service(['10.000', '3.000', '3.000']);
        $repository->shouldNotReceive('createMovement');
        $this->expectException(InvalidTenantRequest::class);
        $this->operate($service, $operation, $quantity);
    }

    public static function excessQuantities(): array
    {
        // Each row starts independently; no earlier test prepares the next test's state.
        return [
            'reserve shortfall' => ['reserve', ['10.000', '9.000', '0.000'], '1.001'],
            'release reserved excess' => ['releaseSale', ['10.000', '3.000', '0.000'], '3.001'],
            'consume reserved excess' => ['consume', ['10.000', '3.000', '0.000'], '3.001'],
            'pledge shortfall' => ['pledge', ['10.000', '2.000', '7.000'], '1.001'],
            'release pledged excess' => ['releasePledge', ['10.000', '0.000', '3.000'], '3.001'],
            'forfeit pledged excess' => ['forfeit', ['10.000', '0.000', '3.000'], '3.001'],
            'all reserved' => ['reserve', ['10.000', '10.000', '0.000'], '0.001'],
            'all pledged' => ['pledge', ['10.000', '0.000', '10.000'], '0.001'],
        ];
    }

    public static function invalidQuantities(): array
    {
        // Only generate independent input combinations; never calculate expected business results here.
        $cases = [];
        foreach (['reserve', 'releaseSale', 'consume', 'pledge', 'releasePledge', 'forfeit'] as $operation) {
            foreach (['zero' => '0.000', 'negative' => '-0.001', 'precision' => '0.0001'] as $label => $quantity) {
                $cases[$operation.' '.$label] = [$operation, $quantity];
            }
        }

        return $cases;
    }

    private function assertMovement(string $operation, array $before, string $quantity, string $type, array $deltas): void
    {
        // Capture the real service payload and stop at the mocked persistence boundary.
        [$service, $repository] = $this->service($before);
        $stop = new RuntimeException('Movement captured; no persistence performed.');
        $movement = null;
        $repository->shouldReceive('createMovement')->once()->with(1, Mockery::type('array'))
            ->andReturnUsing(function (int $tenantId, array $data) use (&$movement, $stop): never {
                $movement = $data;
                throw $stop;
            });
        try {
            $this->operate($service, $operation, $quantity);
            $this->fail('The operation must reach the movement boundary.');
        } catch (RuntimeException $exception) {
            $this->assertSame($stop, $exception);
        }

        // Assert literal deltas instead of reproducing the transition calculation in test code.
        $this->assertIsArray($movement);
        $this->assertSame($type, $movement['movement_type']);
        $this->assertSame($quantity, $movement['quantity']);
        $this->assertSame($deltas[0], $movement['owned_delta']);
        $this->assertSame($deltas[1], $movement['reserved_delta']);
        $this->assertSame($deltas[2], $movement['pledged_delta']);
        $this->assertSame('SOURCE-A', $movement['source_code']);
    }

    private function service(array $before): array
    {
        // Isolate external ports while executing the real service orchestration and validation.
        app(TenantContext::class)->set(1);
        DB::shouldReceive('transaction')->andReturnUsing(static fn (callable $callback) => $callback());
        DB::shouldReceive('connection')->never();
        DB::shouldReceive('table')->never();
        Auth::shouldReceive('id')->andReturn(1);
        $repository = Mockery::mock(OwnershipRepository::class);
        $item = (new OwnedItem())->forceFill(['id' => 11, 'tenant_id' => 1, 'code' => 'OWN-A']);
        $lot = (new AcquisitionLot())->forceFill(['id' => 21, 'tenant_id' => 1, 'owned_item_id' => 11, 'code' => 'LOT-A']);
        $repository->shouldReceive('ownedItemByCode')->with(1, 'OWN-A', true)->andReturn($item);
        $repository->shouldReceive('lotByCode')->with(1, 11, 'LOT-A', true)->andReturn($lot);
        $repository->shouldReceive('lotBalances')->with(1, 11, 21)
            ->andReturn(['owned' => $before[0], 'reserved' => $before[1], 'pledged' => $before[2]]);
        $idempotency = Mockery::mock(TenantIdempotencyService::class);
        $idempotency->shouldReceive('reserveOptional')->andReturnNull();
        $codes = Mockery::mock(TableIdGenerationService::class);
        $codes->shouldReceive('generateForTenant')->andReturn('MOVE-A');

        // Unconfigured mocks reject attempts to perform unrelated Inventory, Pawn or expiry work.
        return [new OwnershipService($repository, Mockery::mock(InventoryService::class),
            Mockery::mock(CollateralItemService::class), Mockery::mock(ExpirationService::class),
            $idempotency, $codes), $repository];
    }

    private function operate(OwnershipService $service, string $operation, string $quantity): void
    {
        // Adapt public entrypoints only; never invoke private calculation methods through reflection.
        match ($operation) {
            'reserve' => $service->reserveSale('OWN-A', 'LOT-A', $quantity, 'SOURCE-A'),
            'releaseSale' => $service->releaseSaleReservation('OWN-A', 'LOT-A', $quantity, 'SOURCE-A'),
            'consume' => $service->consumeSaleReservation('OWN-A', 'LOT-A', $quantity, 'SOURCE-A'),
            'pledge' => $service->reservePledge('OWN-A', 'LOT-A', $quantity, 'BusinessLoanCollateral', 'SOURCE-A'),
            'releasePledge' => $service->releasePledge('OWN-A', 'LOT-A', $quantity, 'BusinessLoanCollateral', 'SOURCE-A'),
            'forfeit' => $service->reduceForfeiture('OWN-A', 'LOT-A', $quantity, 'BusinessLoanCollateral', 'SOURCE-A'),
        };
    }
}
