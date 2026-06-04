@extends('platform.admin.layouts.app')

@section('title', 'Issued Tickets')
@section('pageTitle', 'Issued Tickets')
@section('pageDescription', 'Review customer service tickets submitted by platform users.')

@section('content')
    <section class="panel">
        @if ($tickets->total() === 0)
            <div class="empty-state" id="issued-ticket-empty-state">
                <div>
                    <h2>No issued tickets</h2>
                    <p class="muted">Customer service tickets from platform users will appear here.</p>
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
                    <th>Updated</th>
                    <th>Code</th>
                    <th>User</th>
                    <th>Subject</th>
                    <th>Type</th>
                    <th>Status</th>
                    <th>Messages</th>
                    <th></th>
                </tr>
                </thead>
                <tbody id="issued-ticket-table-body">
                @foreach ($tickets as $ticket)
                    <tr data-ticket-id="{{ $ticket->id }}">
                        <td data-field="updated">{{ $ticket->updated_at?->format('Y-m-d') ?? '-' }}</td>
                        <td data-field="code">{{ $ticket->code }}</td>
                        <td data-field="user">{{ $ticket->platformUser?->name ?? '-' }}</td>
                        <td data-field="subject">{{ $ticket->subject }}</td>
                        <td data-field="type">{{ ucfirst($ticket->type) }}</td>
                        <td><span class="badge" data-field="status">{{ $ticket->status }}</span></td>
                        <td data-field="messages">{{ $ticket->messages_count }}</td>
                        <td>
                            <a href="{{ route('admin.issued-tickets.show', $ticket->id) }}" class="button secondary" data-field="detail">View</a>
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
