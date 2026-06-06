<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class ApplyLocale
{
    public function handle(Request $request, Closure $next): Response
    {
        $locale = $request->hasSession()
            ? $request->session()->get('locale')
            : null;

        $locale ??= $request->header('X-Locale', config('app.locale'));

        if (is_string($locale) && in_array($locale, config('app.supported_locales', []), true)) {
            app()->setLocale($locale);
        }

        return $next($request);
    }
}
