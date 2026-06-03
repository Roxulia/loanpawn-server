@extends('platform.layouts.app')

@section('title', 'Create Support Ticket')
@section('pageTitle', 'Create Support Ticket')
@section('pageDescription', 'Send a bug report, feature request, or support message to the platform admin team.')

@section('pageAction')
    <a href="{{ route('platform.customer-service.index') }}" class="button secondary">Back</a>
@endsection

@section('content')
    <form class="panel grid" method="POST" action="{{ route('platform.customer-service.store') }}" enctype="multipart/form-data">
        @csrf

        <div>
            <label for="subject">Subject</label>
            <input id="subject" name="subject" value="{{ old('subject', $prefillSubject ?? '') }}" required maxlength="180">
            @error('subject') <div class="field-error">{{ $message }}</div> @enderror
        </div>

        <div>
            <label for="type">Type</label>
            <select id="type" name="type" required>
                @foreach (['bugs' => 'Bugs', 'features' => 'Features', 'support' => 'Support'] as $value => $label)
                    <option value="{{ $value }}" @selected(old('type', $prefillType ?? 'support') === $value)>{{ $label }}</option>
                @endforeach
            </select>
            @error('type') <div class="field-error">{{ $message }}</div> @enderror
        </div>

        <div>
            <label for="message">Message</label>
            <textarea id="message" name="message" required maxlength="5000">{{ old('message', $prefillMessage ?? '') }}</textarea>
            @error('message') <div class="field-error">{{ $message }}</div> @enderror
        </div>

        <div>
            <label for="attachments">Attachments</label>
            <input id="attachments" name="attachments[]" type="file" multiple>
            @error('attachments') <div class="field-error">{{ $message }}</div> @enderror
            @error('attachments.*') <div class="field-error">{{ $message }}</div> @enderror
        </div>

        <div>
            <button type="submit" class="button primary">Submit Ticket</button>
        </div>
    </form>
@endsection
