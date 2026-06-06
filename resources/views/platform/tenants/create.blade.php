@extends('platform.layouts.app')

@section('title', __('app.common.view.actions.create_tenant'))
@section('pageTitle', __('app.common.view.actions.create_tenant'))
@section('pageDescription', __('app.platform.view.create_tenant_description'))

@section('content')
    <form method="POST" action="{{ route('platform.tenants.store') }}" class="panel">
        @csrf
        <div class="form-grid">
            <div>
                <label for="name">{{ __('app.platform.view.tenant_name') }}</label>
                <input id="name" name="name" value="{{ old('name') }}" required>
                @error('name') <div class="field-error">{{ $message }}</div> @enderror
            </div>
            <div>
                <label for="phone">{{ __('app.common.view.labels.phone') }}</label>
                <input id="phone" name="phone" value="{{ old('phone') }}">
                @error('phone') <div class="field-error">{{ $message }}</div> @enderror
            </div>
            <div>
                <label for="city">{{ __('app.common.view.labels.city') }}</label>
                <input id="city" name="city" value="{{ old('city') }}">
                @error('city') <div class="field-error">{{ $message }}</div> @enderror
            </div>
            <div>
                <label for="country">{{ __('app.common.view.labels.country') }}</label>
                <input id="country" name="country" value="{{ old('country') }}">
                @error('country') <div class="field-error">{{ $message }}</div> @enderror
            </div>
            <div style="grid-column: 1 / -1;">
                <label for="address">{{ __('app.common.view.labels.address') }}</label>
                <textarea id="address" name="address">{{ old('address') }}</textarea>
                @error('address') <div class="field-error">{{ $message }}</div> @enderror
            </div>
        </div>

        <div style="margin-top: 18px;">
            <button type="submit" class="button primary">{{ __('app.common.view.actions.create_tenant') }}</button>
            <a href="{{ route('platform.tenants.index') }}" class="button secondary">{{ __('app.common.view.actions.cancel') }}</a>
        </div>
    </form>
@endsection
