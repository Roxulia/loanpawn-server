<?php

// Dedicated test process. No credentials are accepted on the command line or printed.
require dirname(__DIR__, 2).'/vendor/autoload.php';
$root = dirname(__DIR__, 2);
$file = is_file($root.'/.env.test') ? '.env.test' : '.env.testing';
$environment = Dotenv\Dotenv::parse(file_get_contents($root.'/'.$file));
if (($environment['DB_CONNECTION'] ?? '') !== 'mysql' || ! str_contains(strtolower($environment['DB_DATABASE'] ?? ''), 'test')) exit(10);
$app = require $root.'/bootstrap/app.php';
$app->loadEnvironmentFrom($file);
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
config(['database.connections.inventory_testing' => array_replace(config('database.connections.mysql'), [
    'url' => null, 'host' => $environment['DB_HOST'] ?? '127.0.0.1', 'port' => $environment['DB_PORT'] ?? '3306',
    'database' => $environment['DB_DATABASE'], 'username' => $environment['DB_USERNAME'] ?? '', 'password' => $environment['DB_PASSWORD'] ?? '',
    'unix_socket' => $environment['DB_SOCKET'] ?? '',
])]);
Illuminate\Support\Facades\DB::setDefaultConnection('inventory_testing');
config(['cache.default' => 'array', 'cache.stores.database' => ['driver' => 'array'], 'cache.stores.redis' => ['driver' => 'array']]);
app('cache')->forgetDriver('database');
app('cache')->forgetDriver('redis');
app('cache')->setDefaultDriver('array');
$request = json_decode(base64_decode($argv[1]), true, flags: JSON_THROW_ON_ERROR);
app(App\Support\TenantContext::class)->set($request['tenant']);
Carbon\CarbonImmutable::setTestNow(Carbon\CarbonImmutable::parse($request['now'] ?? '2026-11-02 12:00:00', 'UTC'));
$report = static function (array $data): void { echo json_encode($data)."\n"; fflush(STDOUT); };
try {
    $service = app(App\Services\TenantModule\InventoryService::class);
    $report(['state' => 'attempting', 'connection' => Illuminate\Support\Facades\DB::selectOne('SELECT CONNECTION_ID() AS id')->id]);
    if (in_array($request['action'], ['issue', 'lock', 'receive'], true)) {
        Illuminate\Support\Facades\DB::beginTransaction();
        $service->prelockItems($request['items']);
        // Hold the destination's shared custody lock before testing a competing restriction edit.
        if (! empty($request['hold_location'])) {
            $service->lockStockForOperation($request['items'], [$request['location']]);
        }
        $report(['state' => 'locked']);
        if (! empty($request['wait'])) fgets(STDIN);
        if ($request['action'] === 'issue') {
            $service->issueStock(new App\DataObjects\RequestObjects\InventoryIssueRequest(
                inventoryItemCode: $request['items'][0], locationCode: $request['location'], quantity: '1',
                operationContext: App\Enums\InventoryIssueContext::Manual, serializedItemCodes: $request['codes'] ?? [],
            ));
        } elseif ($request['action'] === 'receive') {
            $service->receive(new App\DataObjects\RequestObjects\InventoryReceiveRequest(
                locationCode: $request['location'], quantity: '1', inventoryItemCode: $request['items'][0],
            ));
        }
        Illuminate\Support\Facades\DB::commit();
    } elseif ($request['action'] === 'summary') {
        $count = app(App\Services\TenantModule\InventoryMonthlySummaryService::class)->summarizeTenant($request['tenant'], '2026-10');
    } elseif ($request['action'] === 'deactivate') {
        $service->updateLocationByCode(new App\DataObjects\RequestObjects\InventoryLocationUpdate($request['location'], 0, isActive: false));
    } else throw new LogicException('Unknown verification action.');
    $report(['state' => 'success', 'count' => $count ?? null]);
} catch (Throwable $exception) {
    while (Illuminate\Support\Facades\DB::connection()->transactionLevel() > 0) Illuminate\Support\Facades\DB::rollBack();
    $report(['state' => 'rejected', 'exception' => get_class($exception)]);
    exit(2);
}
