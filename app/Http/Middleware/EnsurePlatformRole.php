<?php

namespace App\Http\Middleware;

use App\Utility\MessageCode;
use App\Utility\Messages;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

class EnsurePlatformRole
{
    public function handle(Request $request, Closure $next, string $role): Response
    {
        $normalizedRole = strtolower($role);

        if ($normalizedRole === 'admin' && Auth::guard('platformadmin')->check()) {
            return $next($request);
        }

        if ($normalizedRole === 'user' && Auth::guard('platformuser')->check()) {
            return $next($request);
        }

        return response()->json([
            'message' => app(Messages::class)->responseMessage(MessageCode::MiddlewareForbidden),
        ], Response::HTTP_FORBIDDEN);
    }
}
