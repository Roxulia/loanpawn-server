<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

class ApplyLocale
{
    public function handle(Request $request, Closure $next): Response
    {
        $locale = $request->hasSession()
            ? $request->session()->get('locale')
            : null;

        $locale ??= $request->header('X-Locale');
        $locale ??= Auth::guard('platformuser')->user()?->prefer_lang;
        $locale ??= Auth::guard('tenantuser')->user()?->prefer_lang;
        $locale ??= config('app.locale');

        if (is_string($locale) && in_array($locale, config('app.supported_locales', []), true)) {
            app()->setLocale($locale);
        }

        return $next($request);
    }
}
