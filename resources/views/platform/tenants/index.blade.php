@extends('platform.layouts.app')

@section('title', 'Tenant Management')
@section('pageTitle', 'Tenant Management')
@section('pageDescription', 'Manage tenant records, tenant branding, and business contact settings.')

@section('pageAction')
    <a href="{{ route('platform.tenants.create') }}" class="button primary">Create Tenant</a>
@endsection

@section('content')
    <section class="panel">
        @if ($tenants->total() === 0)
            <div class="empty-state">
                <div>
                    <h2>No tenants created</h2>
                    <p>Start with a tenant profile, then add branding colors and contact settings.</p>
                    <a href="{{ route('platform.tenants.create') }}" class="button primary">Create New Tenant</a>
                </div>
            </div>
        @else
            <div class="table-wrap">
                <table>
                    <thead>
                    <tr>
                        <th>Name</th>
                        <th>Code</th>
                        <th>Subdomain</th>
                        <th>Plan</th>
                        <th>Status</th>
                        <th>Branding</th>
                        <th>Contact</th>
                        <th></th>
                    </tr>
                    </thead>
                    <tbody>
                    @foreach ($tenants as $tenant)
                        <tr>
                            <td>{{ $tenant->name }}</td>
                            <td>{{ $tenant->tenant_code }}</td>
                            <td>{{ $tenant->subdomain ?? '-' }}</td>
                            <td>{{ $tenant->license?->plan_type ?? 'trial' }}</td>
                            <td><span class="badge">{{ $tenant->status }}</span></td>
                            <td>{{ $tenant->branding ? 'Configured' : 'Missing' }}</td>
                            <td>{{ $tenant->contact ? 'Configured' : 'Missing' }}</td>
                            <td>
                                <a href="{{ route('platform.tenants.edit', $tenant->id) }}" class="button secondary">Settings</a>
                                <form method="POST" action="{{ route('platform.tenants.open-app', $tenant->id) }}" style="display:inline;">
                                    @csrf
                                    <button type="submit" class="button secondary">Open App</button>
                                </form>
                            </td>
                        </tr>
                    @endforeach
                    </tbody>
                </table>
            </div>

            <div class="pagination">
                {{ $tenants->links() }}
            </div>
        @endif
    </section>
@endsection
