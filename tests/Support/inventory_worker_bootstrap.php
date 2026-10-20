<?php

// Shared test-worker bootstrap: no credentials are accepted in argv or printed.
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
