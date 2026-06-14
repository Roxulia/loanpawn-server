@extends('platform.admin.layouts.app')

@section('title', __('app.support.view.issued_ticket_detail'))
@section('pageTitle', $ticket->subject)
@section('pageDescription', $ticket->code.' - '.__('app.support.view.types.'.$ticket->type).' - '.$ticket->status)

@section('pageAction')
    <a href="{{ route('admin.issued-tickets.index') }}" class="button secondary">{{ __('app.common.view.actions.back') }}</a>
@endsection

@section('content')
    <section class="grid two">
        <div class="panel">
            <p class="metric-label">{{ __('app.support.view.ticket') }}</p>
            <div class="table-wrap" style="margin-top: 12px;">
                <table>
                    <tbody>
                    <tr><th>{{ __('app.common.view.labels.code') }}</th><td data-label="{{ __('app.common.view.labels.code') }}">{{ $ticket->code }}</td></tr>
                    <tr><th>{{ __('app.common.view.labels.type') }}</th><td data-label="{{ __('app.common.view.labels.type') }}">{{ __('app.support.view.types.'.$ticket->type) }}</td></tr>
                    <tr><th>{{ __('app.common.view.labels.status') }}</th><td data-label="{{ __('app.common.view.labels.status') }}"><span class="badge" data-ticket-status>{{ $ticket->status }}</span></td></tr>
                    <tr><th>{{ __('app.common.view.labels.created') }}</th><td data-label="{{ __('app.common.view.labels.created') }}">{{ $ticket->created_at?->format('Y-m-d H:i') ?? '-' }}</td></tr>
                    <tr><th>{{ __('app.support.view.opened') }}</th><td data-label="{{ __('app.support.view.opened') }}">{{ $ticket->opened_at?->format('Y-m-d H:i') ?? '-' }}</td></tr>
                    <tr><th>{{ __('app.support.view.resolved') }}</th><td data-label="{{ __('app.support.view.resolved') }}">{{ $ticket->resolved_at?->format('Y-m-d H:i') ?? '-' }}</td></tr>
                    </tbody>
                </table>
            </div>
        </div>

        <div class="panel">
            <p class="metric-label">{{ __('app.support.view.platform_user') }}</p>
            <div class="table-wrap" style="margin-top: 12px;">
                <table>
                    <tbody>
                    <tr><th>{{ __('app.common.view.labels.name') }}</th><td data-label="{{ __('app.common.view.labels.name') }}">{{ $ticket->platformUser?->name ?? '-' }}</td></tr>
                    <tr><th>{{ __('app.common.view.labels.email') }}</th><td data-label="{{ __('app.common.view.labels.email') }}">{{ $ticket->platformUser?->email ?? '-' }}</td></tr>
                    <tr><th>{{ __('app.common.view.labels.phone') }}</th><td data-label="{{ __('app.common.view.labels.phone') }}">{{ $ticket->platformUser?->phone ?? '-' }}</td></tr>
                    <tr><th>{{ __('app.support.view.user_code') }}</th><td data-label="{{ __('app.support.view.user_code') }}">{{ $ticket->platformUser?->code ?? '-' }}</td></tr>
                    </tbody>
                </table>
            </div>
        </div>
    </section>

    @if ($ticket->status !== 'resolved')
        <section class="panel" style="margin-top: 16px;">
            <div class="action-row">
                @if ($ticket->status === 'pending')
                    <form method="POST" action="{{ route('admin.issued-tickets.open', $ticket->code) }}">
                        @csrf
                        <button type="submit" class="button secondary">{{ __('app.common.view.actions.open') }}</button>
                    </form>
                @endif
                <form method="POST" action="{{ route('admin.issued-tickets.resolve', $ticket->code) }}">
                    @csrf
                    <button type="submit" class="button primary">{{ __('app.common.view.actions.resolve') }}</button>
                </form>
            </div>
        </section>
    @endif

    <section
        class="grid"
        style="margin-top: 16px;"
        id="ticket-message-thread"
        data-support-ticket-show
        data-ticket-id="{{ $ticket->id }}"
        data-current-sender="platform_admin"
        data-status-selector="[data-ticket-status]"
    >
        @foreach ($ticket->messages as $threadMessage)
            <article class="panel" data-message-id="{{ $threadMessage->id }}">
                <p class="metric-label">
                    {{ $threadMessage->sender_type === 'platform_admin' ? __('app.support.view.sender.admin') : __('app.support.view.sender.platform_user') }}
                    <span class="muted">- {{ $threadMessage->created_at?->format('Y-m-d H:i') ?? '-' }}</span>
                </p>
                <p style="white-space: pre-wrap;">{{ $threadMessage->message }}</p>

                @if ($threadMessage->attachments->isNotEmpty())
                    <div class="table-wrap">
                        <table>
                            <thead>
                            <tr>
                                <th>{{ __('app.billing.view.attachment') }}</th>
                                <th>{{ __('app.common.view.labels.type') }}</th>
                            </tr>
                            </thead>
                            <tbody>
                            @foreach ($threadMessage->attachments as $attachment)
                                <tr>
                                    <td data-label="{{ __('app.billing.view.attachment') }}"><a href="{{ asset('storage/'.$attachment->file_path) }}" target="_blank" rel="noopener">{{ $attachment->original_name ?? $attachment->file_path }}</a></td>
                                    <td data-label="{{ __('app.common.view.labels.type') }}">{{ $attachment->file_type ?? '-' }}</td>
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
        <form class="panel grid" style="margin-top: 16px;" method="POST" action="{{ route('admin.issued-tickets.messages.store', $ticket->code) }}" enctype="multipart/form-data">
            @csrf
            <div>
                <label for="message">{{ __('app.support.view.admin_reply') }}</label>
                <textarea id="message" name="message" required maxlength="5000">{{ old('message') }}</textarea>
                @error('message') <div class="field-error">{{ $message }}</div> @enderror
            </div>
            <div>
                <label for="attachments">{{ __('app.common.view.labels.attachments') }}</label>
                <input id="attachments" name="attachments[]" type="file" multiple>
                @error('attachments') <div class="field-error">{{ $message }}</div> @enderror
                @error('attachments.*') <div class="field-error">{{ $message }}</div> @enderror
            </div>
            <div>
                <button type="submit" class="button primary">{{ __('app.support.view.send_reply') }}</button>
            </div>
        </form>
    @endif
@endsection
