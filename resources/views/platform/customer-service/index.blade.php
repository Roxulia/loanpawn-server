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
            <div class="empty-state">
                <div>
                    <h2>No tickets</h2>
                    <p class="muted">Support tickets you create will appear here.</p>
                    <a href="{{ route('platform.customer-service.create') }}" class="button primary">Create Ticket</a>
                </div>
            </div>
        @else
            <div class="table-wrap">
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
                    <tbody>
                    @foreach ($tickets as $ticket)
                        <tr>
                            <td>{{ $ticket->created_at?->format('Y-m-d') ?? '-' }}</td>
                            <td>{{ $ticket->code }}</td>
                            <td>{{ $ticket->subject }}</td>
                            <td>{{ ucfirst($ticket->type) }}</td>
                            <td><span class="badge">{{ $ticket->status }}</span></td>
                            <td>{{ $ticket->messages_count }}</td>
                            <td>
                                <a href="{{ route('platform.customer-service.show', $ticket->id) }}" class="button secondary">View</a>
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
