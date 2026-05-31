<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Symfony\Component\HttpFoundation\Response;

class EnsureAdminPasswordChanged
{
    public function handle(Request $request, Closure $next): Response
    {
        $admin = Auth::guard('platformadmin')->user();

        if ($admin && Hash::check(config('auth.seeded_platform_admin_password', 'password'), $admin->password)) {
            return redirect()->route('admin.password.change');
        }

        return $next($request);
    }
}
