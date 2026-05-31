@extends('platform.layouts.app')

@section('title', 'Create Tenant')
@section('pageTitle', 'Create Tenant')
@section('pageDescription', 'Create a tenant workspace and initial business contact details.')

@section('content')
    <form method="POST" action="{{ route('platform.tenants.store') }}" class="panel">
        @csrf
        <div class="form-grid">
            <div>
                <label for="name">Tenant Name</label>
                <input id="name" name="name" value="{{ old('name') }}" required>
                @error('name') <div class="field-error">{{ $message }}</div> @enderror
            </div>
            <div>
                <label for="phone">Phone</label>
                <input id="phone" name="phone" value="{{ old('phone') }}">
                @error('phone') <div class="field-error">{{ $message }}</div> @enderror
            </div>
            <div>
                <label for="city">City</label>
                <input id="city" name="city" value="{{ old('city') }}">
                @error('city') <div class="field-error">{{ $message }}</div> @enderror
            </div>
            <div>
                <label for="country">Country</label>
                <input id="country" name="country" value="{{ old('country') }}">
                @error('country') <div class="field-error">{{ $message }}</div> @enderror
            </div>
            <div style="grid-column: 1 / -1;">
                <label for="address">Address</label>
                <textarea id="address" name="address">{{ old('address') }}</textarea>
                @error('address') <div class="field-error">{{ $message }}</div> @enderror
            </div>
        </div>

        <div style="margin-top: 18px;">
            <button type="submit" class="button primary">Create Tenant</button>
            <a href="{{ route('platform.tenants.index') }}" class="button secondary">Cancel</a>
        </div>
    </form>
@endsection
