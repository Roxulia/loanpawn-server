<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Utility\MessageCode;

class LocaleSetterController extends Controller
{
    public function setLocale(string $locale)
    {
        if (in_array($locale, config('app.supported_locales'))) {
            app()->setLocale($locale);
            session()->put('locale', $locale);
            return redirect()->back();
        }

        return redirect()->back()->with('error', $this->responseMessage(MessageCode::LocaleSetFailed));
    }

    public function getLocale()
    {
        return app()->getLocale();
    }

    public function getSupportedLocales()
    {
        return config('app.supported_locales');
    }

    public function setLocaleAPI(string $locale)
    {
        if (in_array($locale, config('app.supported_locales'))) {
            app()->setLocale($locale);
            session()->put('locale', $locale);
            return $this->successResponse(message: $this->responseMessage(MessageCode::LocaleSetSuccess));
        }

        return $this->errorResponse(message: $this->responseMessage(MessageCode::LocaleSetFailed), statusCode: 400);
    }
}
