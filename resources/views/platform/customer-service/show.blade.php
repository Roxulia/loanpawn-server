@extends('platform.layouts.app')

@section('title', 'Support Ticket')
@section('pageTitle', $ticket->subject)
@section('pageDescription', $ticket->code.' - '.ucfirst($ticket->type).' - '.$ticket->status)

@section('pageAction')
    <a href="{{ route('platform.customer-service.index') }}" class="button secondary">Back</a>
@endsection

@section('content')
    <section class="panel">
        <div class="table-wrap">
            <table>
                <tbody>
                <tr><th>Code</th><td>{{ $ticket->code }}</td></tr>
                <tr><th>Type</th><td>{{ ucfirst($ticket->type) }}</td></tr>
                <tr><th>Status</th><td><span class="badge">{{ $ticket->status }}</span></td></tr>
                <tr><th>Created</th><td>{{ $ticket->created_at?->format('Y-m-d H:i') ?? '-' }}</td></tr>
                <tr><th>Resolved</th><td>{{ $ticket->resolved_at?->format('Y-m-d H:i') ?? '-' }}</td></tr>
                </tbody>
            </table>
        </div>
    </section>

    <section class="grid" style="margin-top: 16px;">
        @foreach ($ticket->messages as $threadMessage)
            <article class="panel">
                <p class="metric-label">
                    {{ $threadMessage->sender_type === 'platform_admin' ? 'Admin' : 'You' }}
                    <span class="muted">- {{ $threadMessage->created_at?->format('Y-m-d H:i') ?? '-' }}</span>
                </p>
                <p style="white-space: pre-wrap;">{{ $threadMessage->message }}</p>

                @if ($threadMessage->attachments->isNotEmpty())
                    <div class="table-wrap">
                        <table>
                            <thead>
                            <tr>
                                <th>Attachment</th>
                                <th>Type</th>
                            </tr>
                            </thead>
                            <tbody>
                            @foreach ($threadMessage->attachments as $attachment)
                                <tr>
                                    <td><a href="{{ asset('storage/'.$attachment->file_path) }}" target="_blank" rel="noopener">{{ $attachment->original_name ?? $attachment->file_path }}</a></td>
                                    <td>{{ $attachment->file_type ?? '-' }}</td>
                                </tr>
                            @endforeach
                            </tbody>
                        </table>
                    </div>
                @endif
            </article>
        @endforeach
    </section>

    @if ($ticket->status !== 'resolved')
        <form class="panel grid" style="margin-top: 16px;" method="POST" action="{{ route('platform.customer-service.messages.store', $ticket->id) }}" enctype="multipart/form-data">
            @csrf
            <div>
                <label for="message">Reply</label>
                <textarea id="message" name="message" required maxlength="5000">{{ old('message') }}</textarea>
                @error('message') <div class="field-error">{{ $message }}</div> @enderror
            </div>
            <div>
                <label for="attachments">Attachments</label>
                <input id="attachments" name="attachments[]" type="file" multiple>
                @error('attachments') <div class="field-error">{{ $message }}</div> @enderror
                @error('attachments.*') <div class="field-error">{{ $message }}</div> @enderror
            </div>
            <div>
                <button type="submit" class="button primary">Send Reply</button>
            </div>
        </form>
    @endif
@endsection
