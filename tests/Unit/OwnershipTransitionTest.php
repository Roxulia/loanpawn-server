<?php

namespace Tests\Unit;

use App\Exceptions\InvalidTenantRequest;
use App\Repository\OwnershipRepository;
use App\Services\TenantModule\Ownership\{MovementService, QuantityValidationService};
use App\Support\InventoryQuantity;
use Mockery;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/** OWN-05e/20/21: pure transition and capacity contracts; database tests cover authority and atomicity. */
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
        $this->expectException(InvalidTenantRequest::class);
        $this->propose($operation, $before, $quantity);
    }

    #[DataProvider('invalidQuantities')]
    public function test_invalid_quantity_is_rejected_before_movement(string $operation, string $quantity): void
    {
        // Every operation rejects zero, negative and over-precision input without a proposed movement.
        $this->expectException(InvalidTenantRequest::class);
        $this->propose($operation, ['10.000', '3.000', '3.000'], $quantity);
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
        // Exercise the same pure helpers used by actual quantity mutations, without mocking source persistence.
        $movement = $this->propose($operation, $before, $quantity);

        // Assert literal deltas instead of reproducing the transition calculation in test code.
        $this->assertIsArray($movement);
        $this->assertSame($type, $movement['movement_type']);
        $this->assertSame($quantity, $movement['quantity']);
        $this->assertSame($deltas[0], $movement['owned_delta']);
        $this->assertSame($deltas[1], $movement['reserved_delta']);
        $this->assertSame($deltas[2], $movement['pledged_delta']);
        $this->assertSame('SOURCE-A', $movement['source_code']);
    }

    private function propose(string $operation, array $before, string $quantity): array
    {
        // These are explicit locked-balance/source inputs, not evidence of tenant/custody/source resolution.
        $actions = ['reserve' => 'reserve', 'releaseSale' => 'release', 'consume' => 'consume',
            'pledge' => 'pledge', 'releasePledge' => 'unpledge', 'forfeit' => 'forfeit'];
        $balance = ['owned' => InventoryQuantity::parseBalance($before[0]),
            'reserved' => InventoryQuantity::parseBalance($before[1]), 'pledged' => InventoryQuantity::parseBalance($before[2])];
        $balance['available'] = $balance['owned'] - $balance['reserved'] - $balance['pledged'];
        $amount = InventoryQuantity::parse($quantity);
        $sourceRemaining = in_array($operation, ['releasePledge', 'forfeit'], true) ? $before[2] : $before[1];
        app(QuantityValidationService::class)->validateQuantityActionBalance($actions[$operation], $amount, $balance, $sourceRemaining);

        // A pure proposal must not reach database persistence on either successful or rejected input.
        $repository = Mockery::mock(OwnershipRepository::class);
        $repository->shouldNotReceive('createMovement');
        return (new MovementService($repository))->buildOwnershipQuantityTransition($actions[$operation], $quantity, ['source_code' => 'SOURCE-A']);
    }
}
