@extends('platform.admin.layouts.app')

@section('title', 'Issued Tickets')
@section('pageTitle', 'Issued Tickets')
@section('pageDescription', 'Review customer service tickets submitted by platform users.')

@section('content')
    <section class="panel">
        @if ($tickets->total() === 0)
            <div class="empty-state">
                <div>
                    <h2>No issued tickets</h2>
                    <p class="muted">Customer service tickets from platform users will appear here.</p>
                </div>
            </div>
        @else
            <div class="table-wrap">
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
                    <tbody>
                    @foreach ($tickets as $ticket)
                        <tr>
                            <td>{{ $ticket->updated_at?->format('Y-m-d') ?? '-' }}</td>
                            <td>{{ $ticket->code }}</td>
                            <td>{{ $ticket->platformUser?->name ?? '-' }}</td>
                            <td>{{ $ticket->subject }}</td>
                            <td>{{ ucfirst($ticket->type) }}</td>
                            <td><span class="badge">{{ $ticket->status }}</span></td>
                            <td>{{ $ticket->messages_count }}</td>
                            <td>
                                <a href="{{ route('admin.issued-tickets.show', $ticket->id) }}" class="button secondary">View</a>
                            </td>
                        </tr>
                    @endforeach
                    </tbody>
                </table>
            </div>

            <div class="pagination">
                {{ $tickets->links() }}
            </div>
        @endif
    </section>
@endsection
