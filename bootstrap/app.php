<?php

use App\Exceptions\ApiException;
use App\Jobs\CheckExpireTenantLicenseJob;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use App\Http\Middleware\ResolveTenant;
use App\Http\Middleware\EnsurePlatformRole;
use App\Http\Middleware\EnsureTenantUserBelongsToTenant;
use App\Http\Middleware\EnsureAdminPasswordChanged;
use App\Http\Middleware\EnsureTenantPermission;
use App\Http\Middleware\EnsureTenantFeature;
use App\Http\Middleware\LogHttpOperation;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Http\Request;
use Mpdf\Tag\A;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Throwable;

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
            'tenant.feature' => EnsureTenantFeature::class,
            'platform.role' => EnsurePlatformRole::class,
            'admin.password.changed' => EnsureAdminPasswordChanged::class,
            'tenant.plan' => \App\Http\Middleware\resolvePlan::class,
        ]);
    })
    ->withSchedule(function (Schedule $schedule): void {
        $schedule->job(new CheckExpireTenantLicenseJob())->daily();
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->render(function (Throwable $e, $request) {

            if ($e instanceof ApiException) {
                return null; // Let Laravel handle web requests
            }

            // Only handle unhandled 500 errors
            if ($request->expectsJson() && $e instanceof HttpExceptionInterface && $e->getStatusCode() === 500) {
                return response()->json([
                    'success' => false,
                    'message' => 'Internal server error.',
                    'code' => 'INTERNAL_SERVER_ERROR',
                ], 500);
            }

            return null; // Let Laravel handle other exceptions
        });
    })->create();
