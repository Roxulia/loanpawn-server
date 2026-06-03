@extends('platform.admin.layouts.app')

@section('title', 'Issued Ticket Detail')
@section('pageTitle', $ticket->subject)
@section('pageDescription', $ticket->code.' - '.ucfirst($ticket->type).' - '.$ticket->status)

@section('pageAction')
    <a href="{{ route('admin.issued-tickets.index') }}" class="button secondary">Back</a>
@endsection

@section('content')
    <section class="grid two">
        <div class="panel">
            <p class="metric-label">Ticket</p>
            <div class="table-wrap" style="margin-top: 12px;">
                <table>
                    <tbody>
                    <tr><th>Code</th><td>{{ $ticket->code }}</td></tr>
                    <tr><th>Type</th><td>{{ ucfirst($ticket->type) }}</td></tr>
                    <tr><th>Status</th><td><span class="badge">{{ $ticket->status }}</span></td></tr>
                    <tr><th>Created</th><td>{{ $ticket->created_at?->format('Y-m-d H:i') ?? '-' }}</td></tr>
                    <tr><th>Opened</th><td>{{ $ticket->opened_at?->format('Y-m-d H:i') ?? '-' }}</td></tr>
                    <tr><th>Resolved</th><td>{{ $ticket->resolved_at?->format('Y-m-d H:i') ?? '-' }}</td></tr>
                    </tbody>
                </table>
            </div>
        </div>

        <div class="panel">
            <p class="metric-label">Platform User</p>
            <div class="table-wrap" style="margin-top: 12px;">
                <table>
                    <tbody>
                    <tr><th>Name</th><td>{{ $ticket->platformUser?->name ?? '-' }}</td></tr>
                    <tr><th>Email</th><td>{{ $ticket->platformUser?->email ?? '-' }}</td></tr>
                    <tr><th>Phone</th><td>{{ $ticket->platformUser?->phone ?? '-' }}</td></tr>
                    <tr><th>User Code</th><td>{{ $ticket->platformUser?->code ?? '-' }}</td></tr>
                    </tbody>
                </table>
            </div>
        </div>
    </section>

    @if ($ticket->status !== 'resolved')
        <section class="panel" style="margin-top: 16px;">
            <div class="action-row">
                @if ($ticket->status === 'pending')
                    <form method="POST" action="{{ route('admin.issued-tickets.open', $ticket->id) }}">
                        @csrf
                        <button type="submit" class="button secondary">Open</button>
                    </form>
                @endif
                <form method="POST" action="{{ route('admin.issued-tickets.resolve', $ticket->id) }}">
                    @csrf
                    <button type="submit" class="button primary">Resolve</button>
                </form>
            </div>
        </section>
    @endif

    <section class="grid" style="margin-top: 16px;">
        @foreach ($ticket->messages as $threadMessage)
            <article class="panel">
                <p class="metric-label">
                    {{ $threadMessage->sender_type === 'platform_admin' ? 'Admin' : 'Platform User' }}
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
        <form class="panel grid" style="margin-top: 16px;" method="POST" action="{{ route('admin.issued-tickets.messages.store', $ticket->id) }}" enctype="multipart/form-data">
            @csrf
            <div>
                <label for="message">Admin Reply</label>
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
