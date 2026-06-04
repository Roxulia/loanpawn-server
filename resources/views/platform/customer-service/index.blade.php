@extends('platform.layouts.app')

@section('title', 'Customer Service')
@section('pageTitle', 'Customer Service')
@section('pageDescription', 'Create and follow support tickets for bugs, feature requests, and other support needs.')

@section('pageAction')
    <a href="{{ route('platform.customer-service.create') }}" class="button primary">Create Ticket</a>
@endsection

@section('content')
    <section class="panel">
        @if ($tickets->total() === 0)
            <div class="empty-state" id="customer-service-empty-state">
                <div>
                    <h2>No tickets</h2>
                    <p class="muted">Support tickets you create will appear here.</p>
                    <a href="{{ route('platform.customer-service.create') }}" class="button primary">Create Ticket</a>
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
            <table>
                <thead>
                <tr>
                    <th>Created</th>
                    <th>Code</th>
                    <th>Subject</th>
                    <th>Type</th>
                    <th>Status</th>
                    <th>Messages</th>
                    <th></th>
                </tr>
                </thead>
                <tbody id="customer-service-table-body">
                @foreach ($tickets as $ticket)
                    <tr data-ticket-id="{{ $ticket->id }}">
                        <td data-field="created">{{ $ticket->created_at?->format('Y-m-d') ?? '-' }}</td>
                        <td data-field="code">{{ $ticket->code }}</td>
                        <td data-field="subject">
                            {{ $ticket->subject }}
                            <span class="ticket-unread-badge" data-field="unread" @if ((int) $ticket->user_unread_replies_count === 0) hidden @endif>
                                {{ $ticket->user_unread_replies_count }}
                            </span>
                        </td>
                        <td data-field="type">{{ ucfirst($ticket->type) }}</td>
                        <td><span class="badge" data-field="status">{{ $ticket->status }}</span></td>
                        <td data-field="messages">{{ $ticket->messages_count }}</td>
                        <td>
                            <a href="{{ route('platform.customer-service.show', $ticket->id) }}" class="button secondary" data-field="detail">View</a>
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
