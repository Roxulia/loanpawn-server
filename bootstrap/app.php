<?php

use App\Exceptions\ApiException;
use App\Console\Commands\RepairAccountingChange;
use App\Http\Middleware\ApplyLocale;
use App\Http\Middleware\EnsureAdminPasswordChanged;
use App\Http\Middleware\EnsurePlatformRole;
use App\Http\Middleware\EnsurePlatformTenantSubmittedFeature;
use App\Http\Middleware\EnsureTenantFeature;
use App\Http\Middleware\EnsureTenantPermission;
use App\Http\Middleware\EnsureTenantUserBelongsToTenant;
use App\Http\Middleware\LogHttpOperation;
use App\Http\Middleware\ResolveTenant;
use App\Http\Middleware\StandardizeJsonResponse;
use App\Http\Middleware\TrackTenantUserActivity;
use App\Http\Responses\ApiResponse;
use App\Jobs\CheckExpirePawnLoanContractSlipJob;
use App\Jobs\CheckExpireTenantLicenseJob;
use App\Jobs\ResetTenantLicenseMonthlySlipCountJob;
use App\Jobs\ExpireInactiveTenantUsersJob;
use App\Support\InternalServerErrorNotifier;
use App\Utility\MessageCode;
use App\Utility\Messages;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        channels: __DIR__.'/../routes/channels.php',
        health: '/up',
    )
    ->withCommands([
        RepairAccountingChange::class,
    ])
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->append(LogHttpOperation::class);
        $middleware->append(StandardizeJsonResponse::class);
        $middleware->web(append: [
            ApplyLocale::class,
        ]);
        $middleware->api(append: [
            ApplyLocale::class,
        ]);
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
            'tenant.activity' => TrackTenantUserActivity::class,
            'tenant.permission' => EnsureTenantPermission::class,
            'tenant.feature' => EnsureTenantFeature::class,
            'platform.tenant.submitted-feature' => EnsurePlatformTenantSubmittedFeature::class,
            'platform.role' => EnsurePlatformRole::class,
            'admin.password.changed' => EnsureAdminPasswordChanged::class,
            'tenant.plan' => \App\Http\Middleware\resolvePlan::class,
        ]);
    })
    ->withSchedule(function (Schedule $schedule): void {
        $schedule->job(new CheckExpireTenantLicenseJob())->daily();
        $schedule->job(new CheckExpirePawnLoanContractSlipJob())->dailyAt('23:59');
        $schedule->job(new ResetTenantLicenseMonthlySlipCountJob())->monthlyOn(1, '00:00');
        $schedule->job(new ExpireInactiveTenantUsersJob())->everyFiveMinutes()->withoutOverlapping();
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->render(function (ValidationException $exception, Request $request) {
            if (! $request->expectsJson() && ! $request->is('api/*')) {
                return null;
            }

            return ApiResponse::errorResponse(
                message: app(Messages::class)->responseMessage(MessageCode::ApiValidationFailed),
                data: ['errors' => $exception->errors()],
                statusCode: $exception->status,
            );
        });

        $exceptions->render(function (AuthenticationException $exception, Request $request) {
            if (! $request->expectsJson() && ! $request->is('api/*')) {
                return null;
            }

            return ApiResponse::errorResponse(
                message: $exception->getMessage() ?: app(Messages::class)->responseMessage(MessageCode::ApiUnauthenticated),
                statusCode: 401,
            );
        });
        $exceptions->render(function (Throwable $e, $request) {
            app(InternalServerErrorNotifier::class)->notify($e, $request);

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
