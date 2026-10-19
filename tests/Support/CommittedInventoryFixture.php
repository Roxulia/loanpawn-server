<?php

namespace Tests\Support;

use App\DataObjects\RequestObjects\InventoryReceiveRequest;
use App\Models\PlatformModule\{PlatformUser, Tenant};
use App\Services\TenantModule\InventoryService;
use App\Support\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/** Minimal committed fixtures allow genuinely independent MySQL processes to see the same stock. */
abstract class CommittedInventoryFixture extends InventoryMySqlTestCase
{
    protected bool $wrapFixtureTransaction = false;
    protected ?Tenant $fixtureTenant = null;
    protected ?PlatformUser $fixtureOwner = null;
    protected array $fixtureItems = [];
    protected string $fixtureLocation;
    protected array $workers = [];

    protected function setUp(): void
    {
        parent::setUp();
        $suffix = bin2hex(random_bytes(4));
        $this->fixtureOwner = PlatformUser::query()->create(['code' => 'PU'.$suffix, 'name' => 'Concurrency Fixture',
            'email' => $suffix.'@inventory.test', 'phone' => '099'.$suffix, 'password' => 'secret123', 'status' => 'active']);
        $this->fixtureTenant = Tenant::query()->create(['platform_user_id' => $this->fixtureOwner->id,
            'tenant_code' => 'ct'.$suffix, 'subdomain' => 'ct'.$suffix, 'name' => 'Committed Inventory Fixture', 'status' => 'active']);
        app(TenantContext::class)->set($this->fixtureTenant);
        DB::table('tenant_settings')->insert(['tenant_id' => $this->fixtureTenant->id, 'key' => 'timezone', 'value' => 'UTC', 'created_at' => now(), 'updated_at' => now()]);
        $service = app(InventoryService::class);
        $service->ensureMainShopForTenant($this->fixtureTenant->id);
        $this->fixtureLocation = $service->locations()[0]['code'];
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-10-15 12:00:00', 'UTC'));
        foreach (['QUANTITY', 'SERIALIZED'] as $mode) {
            $this->fixtureItems[$mode] = $service->receive(new InventoryReceiveRequest(
                locationCode: $this->fixtureLocation, quantity: '1', name: 'Committed '.$mode,
                trackingMode: $mode, unitCode: 'unit', serializedItemIdentifiers: $mode === 'SERIALIZED' ? ['fixture-'.$suffix] : [],
            ));
        }
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-11-02 12:00:00', 'UTC'));
    }

    protected function tearDown(): void
    {
        // Stop contenders before removing only this test's known tenant-owned fixture records.
        foreach ($this->workers as $worker) { if ($worker->isRunning()) $worker->stop(1); }
        if ($this->app && $this->fixtureTenant !== null) {
            while (DB::connection()->transactionLevel() > 0) DB::rollBack();
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
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }
}
