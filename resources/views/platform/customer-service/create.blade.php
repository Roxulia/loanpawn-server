@extends('platform.layouts.app')

@section('title', __('app.support.view.create_support_ticket'))
@section('pageTitle', __('app.support.view.create_support_ticket'))
@section('pageDescription', __('app.support.view.create_support_ticket_description'))

@section('pageAction')
    <a href="{{ route('platform.customer-service.index') }}" class="button secondary">{{ __('app.common.view.actions.back') }}</a>
@endsection

@section('content')
    <form class="panel grid" method="POST" action="{{ route('platform.customer-service.store') }}" enctype="multipart/form-data">
        @csrf

        <div>
            <label for="subject">{{ __('app.common.view.labels.subject') }}</label>
            <input id="subject" name="subject" value="{{ old('subject', $prefillSubject ?? '') }}" required maxlength="180">
            @error('subject') <div class="field-error">{{ $message }}</div> @enderror
        </div>

        <div>
            <label for="type">{{ __('app.common.view.labels.type') }}</label>
            <select id="type" name="type" required>
                @foreach (['bugs' => __('app.support.view.types.bugs'), 'features' => __('app.support.view.types.features'), 'support' => __('app.support.view.types.support')] as $value => $label)
                    <option value="{{ $value }}" @selected(old('type', $prefillType ?? 'support') === $value)>{{ $label }}</option>
                @endforeach
            </select>
            @error('type') <div class="field-error">{{ $message }}</div> @enderror
        </div>

        <div>
            <label for="message">{{ __('app.common.view.labels.message') }}</label>
            <textarea id="message" name="message" required maxlength="5000">{{ old('message', $prefillMessage ?? '') }}</textarea>
            @error('message') <div class="field-error">{{ $message }}</div> @enderror
        </div>

        <div>
            <label for="attachments">{{ __('app.common.view.labels.attachments') }}</label>
            <input id="attachments" name="attachments[]" type="file" multiple>
            @error('attachments') <div class="field-error">{{ $message }}</div> @enderror
            @error('attachments.*') <div class="field-error">{{ $message }}</div> @enderror
        </div>

        <div>
            <button type="submit" class="button primary">{{ __('app.support.view.submit_ticket') }}</button>
        </div>
    </form>
@endsection
