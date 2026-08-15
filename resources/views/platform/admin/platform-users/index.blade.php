@extends('platform.admin.layouts.app')

@section('title', 'Platform User Management')
@section('pageTitle', 'Platform User Management')
@section('pageDescription', 'Create, edit, disable, or delete platform users.')

@section('pageAction')
    <a href="{{ route('admin.platform-users.create') }}" class="button primary">Create User</a>
@endsection

@section('content')
    <section class="panel">
        @if ($platformUsers->total() === 0)
            <div class="empty-state">
                <div>
                    <h2>No platform users</h2>
                    <p class="muted">Create platform users to let tenant owners access the platform workspace.</p>
                </div>
            </div>
        @else
            <div class="table-wrap">
                <table>
                    <thead>
                    <tr>
                        <th>Name</th>
                        <th>Code</th>
                        <th>Email</th>
                        <th>Phone</th>
                        <th>Status</th>
                        <th>Tenants</th>
                        <th>Requests</th>
                        <th></th>
                    </tr>
                    </thead>
                    <tbody>
                    @foreach ($platformUsers as $platformUser)
                        <tr>
                            <td data-label="Name">{{ $platformUser->name }}</td>
                            <td data-label="Code">{{ $platformUser->code ?? '-' }}</td>
                            <td data-label="Email">{{ $platformUser->email }}</td>
                            <td data-label="Phone">{{ $platformUser->phone ?? '-' }}</td>
                            <td data-label="Status"><span class="badge">{{ $platformUser->status }}</span></td>
                            <td data-label="Tenants">{{ $platformUser->tenants_count }}</td>
                            <td data-label="Requests">{{ $platformUser->tenant_requests_count }}</td>
                            <td data-label="">
                                <div class="action-row">
                                    <a href="{{ route('admin.platform-users.edit', $platformUser->id) }}" class="button secondary">Edit</a>
                                    <form method="POST" action="{{ route('admin.platform-users.reset-password', $platformUser->id) }}"
                                          onsubmit="return confirm('Reset this user password to the configured default password?')">
                                        @csrf
                                        <button type="submit" class="button secondary">Reset Password</button>
                                    </form>
                                    <form method="POST" action="{{ route('admin.platform-users.destroy', $platformUser->id) }}"
                                          onsubmit="return confirm('Delete this platform user? This action cannot be undone.')">
                                        @csrf
                                        @method('DELETE')
                                        <button type="submit" class="button danger">Delete</button>
                                    </form>
                                </div>
                            </td>
                        </tr>
                    @endforeach
                    </tbody>
                </table>
            </div>

            <div class="pagination">
                {{ $platformUsers->links() }}
            </div>
        @endif
    </section>
@endsection
