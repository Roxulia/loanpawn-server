@extends('platform.layouts.app')

@section('title', __('app.platform.view.user_setting'))
@section('pageTitle', __('app.platform.view.user_setting'))
@section('pageDescription', __('app.platform.view.user_setting_description'))

@section('content')
    <div class="panel" style="max-width: 560px;">
        <form method="POST" action="{{ route('platform.language.change') }}" class="grid">
            @csrf
            @method('PUT')

            <div>
                <label for="lang">{{ __('app.common.view.locale.language') }}</label>
                <select id="lang" name="lang" required>
                    @foreach ($supportedLocales as $locale)
                        <option value="{{ $locale }}" @selected(old('lang', $user->prefer_lang ?? app()->getLocale()) === $locale)>
                            {{ __('app.common.view.locale.'.$locale) }}
                        </option>
                    @endforeach
                </select>
                @error('lang')
                    <div class="field-error">{{ $message }}</div>
                @enderror
            </div>

            <div>
                <button type="submit" class="button primary">{{ __('app.common.view.actions.save') }}</button>
            </div>
        </form>
    </div>
@endsection
