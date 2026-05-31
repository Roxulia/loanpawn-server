<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

class LogHttpOperation
{
    public function handle(Request $request, Closure $next): Response
    {
        $initialUserContext = $this->authenticatedUserContext();

        try {
            $response = $next($request);

            Log::channel('routes')->info('Route request completed.', $this->routeContext($request, $response));
            Log::channel('controllers')->info('Controller request completed.', $this->controllerContext($request, $response, $initialUserContext));
            Log::channel('services')->info('Service entry operation completed.', $this->serviceContext($request));

            return $response;
        } catch (Throwable $exception) {
            $error = [
                'exception' => $exception::class,
                'message' => $exception->getMessage(),
            ];

            Log::channel('routes')->error('Route request failed.', $this->routeContext($request) + $error);
            Log::channel('controllers')->error('Controller request failed.', $this->controllerContext($request, null, $initialUserContext) + $error);
            Log::channel('services')->error('Service entry operation failed.', $this->serviceContext($request) + $error);

            throw $exception;
        }
    }

    protected function routeContext(Request $request, ?Response $response = null): array
    {
        return [
            'method' => $request->method(),
            'route_name' => $request->route()?->getName(),
            'uri' => $request->route()?->uri() ?? $request->path(),
            'status' => $response?->getStatusCode(),
            'ip' => $request->ip(),
        ];
    }

    protected function controllerContext(Request $request, ?Response $response = null, array $initialUserContext = []): array
    {
        return [
            'controller_action' => $request->route()?->getActionName(),
            'status' => $response?->getStatusCode(),
        ] + ($this->authenticatedUserContext() ?: $initialUserContext);
    }

    protected function serviceContext(Request $request): array
    {
        return [
            'operation' => $request->route()?->getActionName(),
        ];
    }

    protected function authenticatedUserContext(): array
    {
        foreach (['platformadmin', 'platformuser', 'tenantuser', 'sanctum', 'web'] as $guard) {
            $user = Auth::guard($guard)->user();

            if ($user === null) {
                continue;
            }

            return [
                'auth_guard' => $guard,
                'user_type' => $user::class,
                'user_id' => $user->getAuthIdentifier(),
                'user_code' => $user->code ?? null,
            ];
        }

        return [];
    }
}
