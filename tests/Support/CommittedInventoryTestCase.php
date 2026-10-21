<?php

namespace Tests\Support;

use App\DataObjects\RequestObjects\InventoryReceiveRequest;
use App\Models\PlatformModule\{PlatformUser, Tenant};
use App\Services\TenantModule\InventoryService;
use App\Support\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/** Minimal committed fixtures allow genuinely independent MySQL processes to see the same stock. */
abstract class CommittedInventoryTestCase extends InventoryMySqlTestCase
{
    protected bool $wrapFixtureTransaction = false;
    protected ?Tenant $fixtureTenant = null;
    protected ?PlatformUser $fixtureOwner = null;
    protected array $fixtureItems = [];
    protected string $fixtureLocation;
    private ?InventoryWorker $testWorker = null;

    protected function setUp(): void
    {
        // Create committed stock visible to independent connections.
        parent::setUp();
        $suffix = bin2hex(random_bytes(4));
        $this->createTenant($suffix);
        $this->receiveStock($suffix);
    }

    protected function tearDown(): void
    {
        // Stop workers before cleanup and always release inherited framework state.
        try {
            $this->testWorker?->stop();
            $this->removeFixtures();
        } finally {
            CarbonImmutable::setTestNow();
            parent::tearDown();
        }
    }

    protected function worker(): InventoryWorker
    {
        // Reuse one process registry for all contenders belonging to this fixture.
        return $this->testWorker ??= new InventoryWorker((int) $this->fixtureTenant->id,
            ['location' => $this->fixtureLocation]);
    }

    private function createTenant(string $suffix): void
    {
        // Track the owner and tenant before adding dependent records.
        $this->fixtureOwner = PlatformUser::query()->create(['code' => 'PU'.$suffix, 'name' => 'Concurrency Fixture',
            'email' => $suffix.'@inventory.test', 'phone' => '099'.$suffix, 'password' => 'secret123', 'status' => 'active']);
        $this->fixtureTenant = Tenant::query()->create(['platform_user_id' => $this->fixtureOwner->id,
            'tenant_code' => 'ct'.$suffix, 'subdomain' => 'ct'.$suffix, 'name' => 'Committed Inventory Fixture', 'status' => 'active']);
        app(TenantContext::class)->set($this->fixtureTenant);
        DB::table('tenant_settings')->insert(['tenant_id' => $this->fixtureTenant->id, 'key' => 'timezone', 'value' => 'UTC', 'created_at' => now(), 'updated_at' => now()]);
        $service = app(InventoryService::class);
        $service->ensureMainShopForTenant($this->fixtureTenant->id);
        $this->fixtureLocation = $service->locations()[0]['code'];
    }

    private function receiveStock(string $suffix): void
    {
        // Keep the original tracking modes and checkpoint dates.
        $service = app(InventoryService::class);
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-10-15 12:00:00', 'UTC'));
        foreach (['QUANTITY', 'SERIALIZED'] as $mode) {
            $this->fixtureItems[$mode] = $service->receive(new InventoryReceiveRequest(
                locationCode: $this->fixtureLocation, quantity: '1', name: 'Committed '.$mode,
                trackingMode: $mode, unitCode: 'unit', serializedItemIdentifiers: $mode === 'SERIALIZED' ? ['fixture-'.$suffix] : [],
            ));
        }
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-11-02 12:00:00', 'UTC'));
    }

    private function removeFixtures(): void
    {
        // Delete only this fixture in child-first order with foreign keys enabled.
        if ($this->app && $this->fixtureTenant !== null) {
            TestDatabase::rollBack();
            $id = $this->fixtureTenant->id;
            DB::transaction(function () use ($id): void {
                // Delete source children first, retaining foreign-key enforcement throughout cleanup.
                foreach (['inventory_reservations', 'inventory_movements', 'inventory_monthly_checkpoints',
                    'inventory_serialized_items', 'inventory_items', 'inventory_locations'] as $table) {
                    DB::table($table)->where('tenant_id', $id)->delete();
                }
                // Remaining tenant-owned metadata uses its existing tenant cascade constraints.
                DB::table('tenants')->where('id', $id)->delete();
                DB::table('platform_users')->where('id', $this->fixtureOwner->id)->delete();
            });
        } elseif ($this->app && $this->fixtureOwner !== null) {
            DB::table('platform_users')->where('id', $this->fixtureOwner->id)->delete();
        }
    }
}
