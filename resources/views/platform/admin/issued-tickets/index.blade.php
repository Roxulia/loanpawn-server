@extends('platform.admin.layouts.app')

@section('title', __('app.support.view.issued_tickets'))
@section('pageTitle', __('app.support.view.issued_tickets'))
@section('pageDescription', __('app.support.view.review_tickets'))

@section('content')
    <section class="panel">
        @if ($tickets->total() === 0)
            <div class="empty-state" id="issued-ticket-empty-state">
                <div>
                    <h2>{{ __('app.support.view.no_issued_tickets') }}</h2>
                    <p class="muted">{{ __('app.support.view.no_issued_tickets_description') }}</p>
                </div>
            </div>
        @endif

        <div
            class="table-wrap"
            @if ($tickets->total() === 0) style="display: none;" @endif
            id="issued-ticket-table-wrap"
            data-support-ticket-index="admin"
            data-body-id="issued-ticket-table-body"
            data-table-wrap-id="issued-ticket-table-wrap"
            data-empty-state-id="issued-ticket-empty-state"
        >
            <table>
                <thead>
                <tr>
                    <th>{{ __('app.common.view.labels.updated') }}</th>
                    <th>{{ __('app.common.view.labels.code') }}</th>
                    <th>{{ __('app.common.view.labels.user') }}</th>
                    <th>{{ __('app.common.view.labels.subject') }}</th>
                    <th>{{ __('app.common.view.labels.type') }}</th>
                    <th>{{ __('app.common.view.labels.status') }}</th>
                    <th>{{ __('app.support.view.messages') }}</th>
                    <th></th>
                </tr>
                </thead>
                <tbody id="issued-ticket-table-body">
                @foreach ($tickets as $ticket)
                    <tr data-ticket-id="{{ $ticket->id }}">
                        <td data-label="{{ __('app.common.view.labels.updated') }}" data-field="updated">
                            <time datetime="{{ $ticket->updated_at?->toISOString() }}" data-local-time="date">{{ $ticket->updated_at?->format('Y-m-d') ?? '-' }}</time>
                        </td>
                        <td data-label="{{ __('app.common.view.labels.code') }}" data-field="code">{{ $ticket->code }}</td>
                        <td data-label="{{ __('app.common.view.labels.user') }}" data-field="user">{{ $ticket->platformUser?->name ?? '-' }}</td>
                        <td data-label="{{ __('app.common.view.labels.subject') }}" data-field="subject">{{ $ticket->subject }}</td>
                        <td data-label="{{ __('app.common.view.labels.type') }}" data-field="type">{{ __('app.support.view.types.'.$ticket->type) }}</td>
                        <td data-label="{{ __('app.common.view.labels.status') }}"><span class="badge" data-field="status">{{ $ticket->status }}</span></td>
                        <td data-label="{{ __('app.support.view.messages') }}" data-field="messages">{{ $ticket->messages_count }}</td>
                        <td data-label="">
                            <a href="{{ route('admin.issued-tickets.show', $ticket->code) }}" class="button secondary" data-field="detail">{{ __('app.common.view.actions.view') }}</a>
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
