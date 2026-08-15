@extends('platform.admin.layouts.app')

@section('title', 'Admin Tenant Management')
@section('pageTitle', 'Tenant Management')
@section('pageDescription', 'Review all platform tenants and their current owner, plan, and license status.')
@section('pageAction')
    <a href="{{ route('admin.tenants.create') }}" class="button primary">Create tenant</a>
@endsection

@section('content')
    <section class="panel">
        <div class="admin-section-heading"><div><p class="admin-section-kicker">Organization</p><h2>Tenant directory</h2><p>{{ $tenants->total() }} tenant{{ $tenants->total() === 1 ? '' : 's' }} across the platform.</p></div></div>
        @if ($tenants->total() === 0)
            <div class="empty-state">
                <div>
                    <h2>No tenants</h2>
                    <p class="muted">Tenants created by platform users will appear here.</p>
                </div>
            </div>
        @else
            <div class="table-wrap admin-table--desktop admin-cards--mobile">
                <table>
                    <thead>
                    <tr>
                        <th>Name</th>
                        <th>Owner</th>
                        <th>Code</th>
                        <th>Subdomain</th>
                        <th>Plan</th>
                        <th>Type</th>
                        <th>License</th>
                        <th>Expires</th>
                        <th>Status</th>
                        <th></th>
                    </tr>
                    </thead>
                    <tbody>
                    @foreach ($tenants as $tenant)
                        <tr>
                            <td data-label="Name">{{ $tenant->name }}</td>
                            <td data-label="Owner">{{ $tenant->owner?->name ?? '-' }}</td>
                            <td data-label="Code">{{ $tenant->tenant_code }}</td>
                            <td data-label="Subdomain">{{ $tenant->subdomain ?? '-' }}</td>
                            <td data-label="Plan">{{ $tenant->license?->plan?->name ?? $tenant->license?->plan_type ?? '-' }}</td>
                            <td data-label="Type">{{ $tenant->category?->name ?? '-' }}</td>
                            <td data-label="License"><span class="badge" data-tone="{{ in_array($tenant->license?->status, ['active', 'paid', 'trial']) ? 'success' : 'warning' }}">{{ $tenant->license?->status ?? '-' }}</span></td>
                            <td data-label="Expires">{{ $tenant->license?->expires_at?->format('Y-m-d') ?? '-' }}</td>
                            <td data-label="Status"><span class="badge" data-tone="{{ $tenant->status === 'active' ? 'success' : 'warning' }}">{{ $tenant->status }}</span></td>
                            <td data-label=""><button type="button" class="button secondary" data-open-dialog="tenant-plan-{{ $tenant->id }}">Change type/plan</button></td>
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

    @foreach($tenants as $tenant)
    <dialog class="platform-dialog" id="tenant-plan-{{ $tenant->id }}">
        <form method="POST" action="{{ route('admin.tenants.plan.update', $tenant) }}">
            @csrf
            <div class="dialog-header"><h2>Change {{ $tenant->name }}</h2><button type="button" class="dialog-close" data-close-dialog="tenant-plan-{{ $tenant->id }}">&times;</button></div>
            <div class="form-grid">
                <div><label>Category and plan</label><select name="plan_id" required>@foreach($plans as $plan)<option value="{{ $plan->id }}">{{ $plan->category?->name }} &mdash; {{ $plan->name }}</option>@endforeach</select></div>
                <div><label>Effective</label><select name="effective"><option value="immediate">Immediately, keep current expiry</option><option value="scheduled">At current expiry</option></select></div>
                <div><label>Scheduled duration</label><select name="duration_months"><option value="">Not required for immediate</option>@foreach(config('pricing.extension_discounts') as $months => $discount)<option value="{{ $months }}">{{ $months }} months</option>@endforeach</select></div>
                <div class="form-grid-wide"><label>Reason for free grant</label><textarea name="admin_review_note" rows="3" maxlength="1000" required></textarea></div>
            </div>
            <div class="action-row admin-dialog-actions"><button type="button" class="button secondary" data-close-dialog="tenant-plan-{{ $tenant->id }}">Cancel</button><button type="submit" class="button primary">Apply change</button></div>
        </form>
    </dialog>
    @endforeach
@endsection

@push('scripts')
<script>document.querySelectorAll('[data-open-dialog]').forEach(b=>b.addEventListener('click',()=>document.getElementById(b.dataset.openDialog)?.showModal()));document.querySelectorAll('[data-close-dialog]').forEach(b=>b.addEventListener('click',()=>document.getElementById(b.dataset.closeDialog)?.close()));</script>
@endpush
