<?php

namespace Tests\Feature;

use App\Http\Middleware\LogHttpOperation;
use App\Models\PlatformModule\PlatformUser;
use App\Support\OperationLogger;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Mockery;
use Psr\Log\LoggerInterface;
use RuntimeException;
use Symfony\Component\HttpFoundation\Response;
use Tests\TestCase;

class OperationalLoggingTest extends TestCase
{
    public function test_http_middleware_logs_layer_specific_context_without_request_payload(): void
    {
        $routeLogger = Mockery::mock(LoggerInterface::class);
        $controllerLogger = Mockery::mock(LoggerInterface::class);
        $serviceLogger = Mockery::mock(LoggerInterface::class);
        $user = new PlatformUser([
            'code' => 'PU00000001',
            'email' => 'must-not-be-logged@example.com',
        ]);
        $user->id = 7;
        Auth::guard('platformuser')->setUser($user);

        Log::shouldReceive('channel')->with('routes')->once()->andReturn($routeLogger);
        Log::shouldReceive('channel')->with('controllers')->once()->andReturn($controllerLogger);
        Log::shouldReceive('channel')->with('services')->once()->andReturn($serviceLogger);
        $routeLogger->shouldReceive('info')->once()->withArgs(function (string $message, array $context): bool {
            return $message === 'Route request completed.'
                && $context['ip'] === '203.0.113.10'
                && ! array_key_exists('secret', $context);
        });
        $controllerLogger->shouldReceive('info')->once()->withArgs(function (string $message, array $context): bool {
            return $message === 'Controller request completed.'
                && $context['auth_guard'] === 'platformuser'
                && $context['user_type'] === PlatformUser::class
                && $context['user_id'] === 7
                && $context['user_code'] === 'PU00000001'
                && ! array_key_exists('email', $context);
        });
        $serviceLogger->shouldReceive('info')->once()->withArgs(function (string $message, array $context): bool {
            return $message === 'Service entry operation completed.'
                && array_keys($context) === ['operation'];
        });
        $request = Request::create('/billing', 'POST', [
            'secret' => 'must-not-be-logged',
        ], server: [
            'REMOTE_ADDR' => '203.0.113.10',
        ]);

        app(LogHttpOperation::class)->handle($request, fn (): Response => new Response('', 204));

    }

    public function test_service_operation_logger_records_exception_type_and_message(): void
    {
        $serviceLogger = Mockery::mock(LoggerInterface::class);
        Log::shouldReceive('channel')->with('services')->once()->andReturn($serviceLogger);
        $serviceLogger->shouldReceive('error')->once()->withArgs(function (string $message, array $context): bool {
            return $message === 'Service operation failed.'
                && $context === [
                    'operation' => 'ExampleService::perform',
                    'exception' => RuntimeException::class,
                    'message' => 'Expected failure detail.',
                ];
        });

        try {
            app(OperationLogger::class)->run('ExampleService::perform', function (): void {
                throw new RuntimeException('Expected failure detail.');
            });
        } catch (RuntimeException) {
        }

    }
}
