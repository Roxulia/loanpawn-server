<?php

use App\Exceptions\TenantCodeNotGiven;
use App\Exceptions\TenantNotFound;
use App\Jobs\CheckExpireTenantLicenseJob;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use App\Http\Middleware\ResolveTenant;
use App\Http\Middleware\EnsurePlatformRole;
use App\Http\Middleware\EnsureTenantUserBelongsToTenant;
use App\Http\Middleware\EnsureAdminPasswordChanged;
use App\Http\Middleware\EnsureTenantPermission;
use App\Http\Middleware\LogHttpOperation;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        channels: __DIR__.'/../routes/channels.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->append(LogHttpOperation::class);
        $middleware->statefulApi();
        $middleware->redirectGuestsTo(function (Request $request) {
            if ($request->is('admin') || $request->is('admin/*')) {
                return route('admin.login.show');
            }

            return route('platform.login.show');
        });
        $middleware->alias([
            'tenant-resolve' => ResolveTenant::class,
            'tenant.access' => EnsureTenantUserBelongsToTenant::class,
            'tenant.permission' => EnsureTenantPermission::class,
            'platform.role' => EnsurePlatformRole::class,
            'admin.password.changed' => EnsureAdminPasswordChanged::class,
            'tenant.plan' => \App\Http\Middleware\resolvePlan::class,
        ]);
    })
    ->withSchedule(function (Schedule $schedule): void {
        $schedule->job(new CheckExpireTenantLicenseJob())->daily();
    })
    ->withExceptions(function (Exceptions $exceptions): void {

    })->create();
