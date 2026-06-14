@extends('platform.layouts.app')

@section('title', __('app.platform.view.customer_service'))
@section('pageTitle', __('app.platform.view.customer_service'))
@section('pageDescription', __('app.support.view.customer_service_description'))

@section('pageAction')
    <a href="{{ route('platform.customer-service.create') }}" class="button primary">{{ __('app.support.view.create_ticket') }}</a>
@endsection

@section('content')
    <section class="panel">
        @if ($tickets->total() === 0)
            <div class="empty-state" id="customer-service-empty-state">
                <div>
                    <h2>{{ __('app.support.view.no_tickets') }}</h2>
                    <p class="muted">{{ __('app.support.view.no_tickets_description') }}</p>
                    <a href="{{ route('platform.customer-service.create') }}" class="button primary">{{ __('app.support.view.create_ticket') }}</a>
                </div>
            </div>
        @endif

        <div
            class="table-wrap"
            @if ($tickets->total() === 0) style="display: none;" @endif
            id="customer-service-table-wrap"
            data-support-ticket-index="customer"
            data-platform-user-id="{{ Auth::guard('platformuser')->id() }}"
            data-body-id="customer-service-table-body"
            data-table-wrap-id="customer-service-table-wrap"
            data-empty-state-id="customer-service-empty-state"
        >
            <h2 style="margin: 0 0 12px; color: var(--color-heading); font-size: 20px;">{{ __('app.platform.view.customer_service') }}</h2>
            <table>
                <thead>
                <tr>
                    <th>{{ __('app.common.view.labels.created') }}</th>
                    <th>{{ __('app.common.view.labels.code') }}</th>
                    <th>{{ __('app.common.view.labels.subject') }}</th>
                    <th>{{ __('app.common.view.labels.type') }}</th>
                    <th>{{ __('app.common.view.labels.status') }}</th>
                    <th>{{ __('app.support.view.messages') }}</th>
                    <th></th>
                </tr>
                </thead>
                <tbody id="customer-service-table-body">
                @foreach ($tickets as $ticket)
                    <tr data-ticket-id="{{ $ticket->id }}">
                        <td data-label="{{ __('app.common.view.labels.created') }}" data-field="created">{{ $ticket->created_at?->format('Y-m-d') ?? '-' }}</td>
                        <td data-label="{{ __('app.common.view.labels.code') }}" data-field="code">{{ $ticket->code }}</td>
                        <td data-label="{{ __('app.common.view.labels.subject') }}" data-field="subject">
                            {{ $ticket->subject }}
                            <span class="ticket-unread-badge" data-field="unread" @if ((int) $ticket->user_unread_replies_count === 0) hidden @endif>
                                {{ $ticket->user_unread_replies_count }}
                            </span>
                        </td>
                        <td data-label="{{ __('app.common.view.labels.type') }}" data-field="type">{{ __('app.support.view.types.'.$ticket->type) }}</td>
                        <td data-label="{{ __('app.common.view.labels.status') }}"><span class="badge" data-field="status">{{ $ticket->status }}</span></td>
                        <td data-label="{{ __('app.support.view.messages') }}" data-field="messages">{{ $ticket->messages_count }}</td>
                        <td data-label="">
                            <a href="{{ route('platform.customer-service.show', $ticket->code) }}" class="button secondary" data-field="detail">{{ __('app.common.view.actions.view') }}</a>
                        </td>
                    </tr>
                @endforeach
                </tbody>
            </table>
        </div>

        @if ($tickets->total() > 0)
            <div class="pagination">
                {{ $tickets->links() }}
            </div>
        @endif
    </section>
@endsection
