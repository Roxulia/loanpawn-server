<?php

namespace Tests\Feature\TenantModule;

use App\Exceptions\InvalidTenantRequest;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\CommittedOwnershipFifoTestCase;

/** Independent MySQL connections must contend on locks acquired by the actual FIFO allocator. */
class OwnershipFifoConcurrencyTest extends CommittedOwnershipFifoTestCase
{
    public function test_second_checkout_rejects_after_first_consumes_four_of_five(): void
    {
        // Both callers ask for four, but only the first may consume them; final stock is one.
        $goods = $this->fifoGoods(quantity: '5.000');
        $this->race($goods, '4.000', [], false, 'rejected', 'ownership.insufficient_lot_balance');
        $this->assertFifoBalances($goods, '1.000');
    }

    #[DataProvider('rollbackModes')]
    public function test_same_serial_cannot_be_issued_twice_and_rollback_releases_it(bool $rollback): void
    {
        // With commit only A succeeds. With rollback B issues the restored exact piece, still only once.
        $goods = $this->fifoGoods(quantity: '1.000', serials: ['FIFO-RACE']);
        $this->race($goods, '1.000', $goods['serials'], $rollback, $rollback ? 'success' : 'rejected',
            $rollback ? null : 'ownership.piece_selection_invalid');
        $this->assertFifoBalances($goods, '0.000');
        $this->assertCurrentPieces($goods, []);
        $this->assertSame(1, $this->countRows('sales_delivery_allocations'));
    }

    public function test_rollback_restores_quantity_for_waiting_checkout(): void
    {
        // A actually applies four then rolls back; B must subsequently consume four of the restored five.
        $goods = $this->fifoGoods(quantity: '5.000');
        $this->race($goods, '4.000', [], true, 'success');
        $this->assertFifoBalances($goods, '1.000');
        $this->assertSame(1, $this->countRows('sales_delivery_allocations'));
    }

    public static function rollbackModes(): array
    {
        return ['commit' => [false], 'rollback' => [true]];
    }

    private function race(array $goods, string $quantity, array $serials, bool $rollback, string $expected,
        ?string $message = null): void
    {
        // Sources differ so shared business-source locks cannot falsely prove allocator locking.
        $firstSources = $this->fifoSources([$goods], $quantity);
        $secondSources = $this->fifoSources([$goods], $quantity);
        $payload = ['items' => [$goods['item']->code], 'quantity' => $quantity, 'codes' => $serials];
        [$first, $input] = $this->worker()->start($rollback ? 'fifo_rollback' : 'fifo_apply',
            $payload + ['sources' => $firstSources, 'wait' => true]);
        $this->worker()->waitForState($first, 'locked');
        [$second] = $this->worker()->start('fifo_apply', $payload + ['sources' => $secondSources, 'wait' => false]);
        // Include the holder's bounded protocol diagnostics if actual contention was not observed.
        try {
            $this->worker()->waitForLock($second);
        } catch (\PHPUnit\Framework\AssertionFailedError $error) {
            $this->fail($error->getMessage().'; holder result: '.json_encode($this->worker()->lastMessage($first)));
        }
        $this->worker()->release($input);
        $first->wait();
        $second->wait();
        $this->assertSame('success', $this->worker()->lastMessage($first)['state']);
        $result = $this->worker()->lastMessage($second);
        $this->assertSame($expected, $result['state']);
        if ($expected === 'rejected') {
            $this->assertSame(InvalidTenantRequest::class, $result['exception']);
            $domain = $this->worker()->waitForState($second, 'domain_rejection');
            $this->assertSame('INVALID_TENANT_REQUEST', $domain['error_code']);
            $this->assertSame(__($message), $domain['message']);
        }
    }
}
