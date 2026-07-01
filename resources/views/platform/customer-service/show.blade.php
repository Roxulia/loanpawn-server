@extends('platform.layouts.app')

@section('title', __('app.support.view.support_ticket'))
@section('pageTitle', $ticket->subject)
@section('pageDescription', $ticket->code.' - '.__('app.support.view.types.'.$ticket->type).' - '.$ticket->status)

@section('pageAction')
    <a href="{{ route('platform.customer-service.index') }}" class="button secondary">{{ __('app.common.view.actions.back') }}</a>
@endsection

@section('content')
    <div class="ticket-detail-shell">
        <section class="panel">
            <p class="section-kicker">{{ __('app.support.view.ticket') }}</p>
            <div class="ticket-info-grid" style="margin-top: 12px;">
                <div class="ticket-info-row">
                    <div class="field-kicker">{{ __('app.common.view.labels.code') }}</div>
                    <div class="field-value">{{ $ticket->code }}</div>
                </div>
                <div class="ticket-info-row">
                    <div class="field-kicker">{{ __('app.common.view.labels.type') }}</div>
                    <div class="field-value">{{ __('app.support.view.types.'.$ticket->type) }}</div>
                </div>
                <div class="ticket-info-row">
                    <div class="field-kicker">{{ __('app.common.view.labels.status') }}</div>
                    <div class="field-value"><span class="badge" data-ticket-status>{{ $ticket->status }}</span></div>
                </div>
                <div class="ticket-info-row">
                    <div class="field-kicker">{{ __('app.common.view.labels.created') }}</div>
                    <div class="field-value">
                        <time datetime="{{ $ticket->created_at?->toISOString() }}" data-local-time="datetime">{{ $ticket->created_at?->format('Y-m-d H:i') ?? '-' }}</time>
                    </div>
                </div>
                <div class="ticket-info-row">
                    <div class="field-kicker">{{ __('app.support.view.resolved') }}</div>
                    <div class="field-value">
                        <time datetime="{{ $ticket->resolved_at?->toISOString() }}" data-local-time="datetime">{{ $ticket->resolved_at?->format('Y-m-d H:i') ?? '-' }}</time>
                    </div>
                </div>
            </div>
        </section>

        <section class="panel ticket-chat-panel">
            <div class="ticket-chat-header">
                <h2>Conversation</h2>
                <span class="badge">{{ $ticket->messages->count() }}</span>
            </div>
            <div
                class="ticket-chat-scroll"
                id="ticket-message-thread"
                data-support-ticket-show
                data-ticket-id="{{ $ticket->id }}"
                data-current-sender="platform_user"
                data-status-selector="[data-ticket-status]"
            >
                @foreach ($ticket->messages as $threadMessage)
                    @php
                        $isOwnMessage = $threadMessage->sender_type === 'platform_user';
                    @endphp
                    <article class="ticket-message {{ $isOwnMessage ? 'is-own' : '' }}" data-message-id="{{ $threadMessage->id }}">
                        <div class="ticket-message-bubble">
                            <div class="ticket-message-meta">
                                <span class="sender">
                                    {{ $threadMessage->sender_type === 'platform_admin' ? __('app.support.view.sender.admin') : __('app.support.view.sender.you') }}
                                </span>
                                <time datetime="{{ $threadMessage->created_at?->toISOString() }}" data-local-time="datetime">{{ $threadMessage->created_at?->format('Y-m-d H:i') ?? '-' }}</time>
                            </div>
                            <p class="ticket-message-text">{{ $threadMessage->message }}</p>

                            @if ($threadMessage->attachments->isNotEmpty())
                                <div class="ticket-message-attachments">
                                    @foreach ($threadMessage->attachments as $attachment)
                                        <a class="ticket-attachment-link" href="{{ asset('storage/'.$attachment->file_path) }}" target="_blank" rel="noopener">
                                            <span>{{ $attachment->original_name ?? $attachment->file_path }}</span>
                                            <small>{{ $attachment->file_type ?? '-' }}</small>
                                        </a>
                                    @endforeach
                                </div>
                            @endif
                        </div>
                    </article>
                @endforeach
            </div>
        </section>

        @if ($ticket->status !== 'resolved')
            <form class="panel ticket-reply-card" method="POST" action="{{ route('platform.customer-service.messages.store', $ticket->code) }}" enctype="multipart/form-data">
                @csrf
                <div class="chat-composer-row">
                    <textarea id="message" name="message" rows="1" required maxlength="5000" placeholder="Type your reply..." aria-label="{{ __('app.support.view.reply') }}">{{ old('message') }}</textarea>
                    <label class="chat-icon-button" for="attachments" aria-label="{{ __('app.common.view.labels.attachments') }}">
                        <input id="attachments" name="attachments[]" type="file" multiple>
                        <svg viewBox="0 0 24 24" aria-hidden="true">
                            <path d="M21.4 11.6 12 21a6 6 0 0 1-8.5-8.5l9.9-9.9a4 4 0 0 1 5.7 5.7l-9.9 9.9a2 2 0 0 1-2.8-2.8l9.4-9.4" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
                        </svg>
                    </label>
                    <button type="submit" class="chat-icon-button send" aria-label="{{ __('app.support.view.send_reply') }}">
                        <svg viewBox="0 0 24 24" aria-hidden="true">
                            <path d="m22 2-7 20-4-9-9-4 20-7Z" fill="none" stroke="currentColor" stroke-width="2" stroke-linejoin="round"/>
                            <path d="M22 2 11 13" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"/>
                        </svg>
                    </button>
                </div>
                @error('message') <div class="field-error">{{ $message }}</div> @enderror
                @error('attachments') <div class="field-error">{{ $message }}</div> @enderror
                @error('attachments.*') <div class="field-error">{{ $message }}</div> @enderror
            </form>
        @endif
    </div>
@endsection
