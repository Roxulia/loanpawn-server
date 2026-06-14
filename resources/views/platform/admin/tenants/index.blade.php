@extends('platform.admin.layouts.app')

@section('title', 'Admin Tenant Management')
@section('pageTitle', 'Tenant Management')
@section('pageDescription', 'Review all platform tenants and their current owner, plan, and license status.')

@section('content')
    <section class="panel">
        @if ($tenants->total() === 0)
            <div class="empty-state">
                <div>
                    <h2>No tenants</h2>
                    <p class="muted">Tenants created by platform users will appear here.</p>
                </div>
            </div>
        @else
            <div class="table-wrap">
                <table>
                    <thead>
                    <tr>
                        <th>Name</th>
                        <th>Owner</th>
                        <th>Code</th>
                        <th>Subdomain</th>
                        <th>Plan</th>
                        <th>License</th>
                        <th>Expires</th>
                        <th>Status</th>
                    </tr>
                    </thead>
                    <tbody>
                    @foreach ($tenants as $tenant)
                        <tr>
                            <td data-label="Name">{{ $tenant->name }}</td>
                            <td data-label="Owner">{{ $tenant->owner?->name ?? '-' }}</td>
                            <td data-label="Code">{{ $tenant->tenant_code }}</td>
                            <td data-label="Subdomain">{{ $tenant->subdomain ?? '-' }}</td>
                            <td data-label="Plan">{{ $tenant->license?->plan_type ?? '-' }}</td>
                            <td data-label="License"><span class="badge">{{ $tenant->license?->status ?? '-' }}</span></td>
                            <td data-label="Expires">{{ $tenant->license?->expires_at?->format('Y-m-d') ?? '-' }}</td>
                            <td data-label="Status"><span class="badge">{{ $tenant->status }}</span></td>
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
