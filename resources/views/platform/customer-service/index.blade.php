@extends('platform.layouts.app')

@section('title', __('app.platform.view.customer_service'))
@section('pageTitle', __('app.platform.view.customer_service'))
@section('pageDescription', __('app.support.view.customer_service_description'))

@section('content')
    @if ($tickets->total() === 0)
        <section class="panel">
            <div class="empty-state">
                <div>
                    <h2>{{ __('app.support.view.no_tickets') }}</h2>
                    <p class="muted">{{ __('app.support.view.no_tickets_description') }}</p>
                    <a href="{{ route('platform.customer-service.create') }}" class="button primary">{{ __('app.support.view.create_ticket') }}</a>
                </div>
            </div>
        </section>
    @else
        <section
            class="mobile-only-section"
            data-support-ticket-index="customer"
            data-platform-user-id="{{ Auth::guard('platformuser')->id() }}"
            data-body-id="customer-service-table-body"
            data-card-list-id="customer-service-card-list"
            data-table-wrap-id="customer-service-table-wrap"
            data-empty-state-id="customer-service-empty-state"
        >
            <a href="{{ route('platform.customer-service.create') }}" class="button primary" style="width: 100%; margin-bottom: 16px;">{{ __('app.support.view.create_ticket') }}</a>
            <div class="ticket-list-heading">
                <h2>Active Tickets</h2>
                <span class="badge">{{ $tickets->total() }}</span>
            </div>
            <div class="mobile-card-list" id="customer-service-card-list">
                @foreach ($tickets as $ticket)
                    <x-platform.customer-ticket-card :ticket="$ticket" />
                @endforeach
            </div>
        </section>

        <section class="panel desktop-table-panel">
            <div class="desktop-panel-heading">
                <h2>{{ __('app.platform.view.customer_service') }}</h2>
                <a href="{{ route('platform.customer-service.create') }}" class="button primary icon-button-text">
                    <span aria-hidden="true">+</span>
                    <span>{{ __('app.support.view.create_ticket') }}</span>
                </a>
            </div>
        <div
            class="table-wrap"
            id="customer-service-table-wrap"
            data-support-ticket-index="customer"
            data-platform-user-id="{{ Auth::guard('platformuser')->id() }}"
            data-body-id="customer-service-table-body"
            data-card-list-id="customer-service-card-list"
            data-table-wrap-id="customer-service-table-wrap"
            data-empty-state-id="customer-service-empty-state"
        >
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
        </section>

        <div class="pagination">
            {{ $tickets->links() }}
        </div>
    @endif
@endsection
