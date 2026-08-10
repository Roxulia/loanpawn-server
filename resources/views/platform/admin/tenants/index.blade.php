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
                            <td data-label="License"><span class="badge">{{ $tenant->license?->status ?? '-' }}</span></td>
                            <td data-label="Expires">{{ $tenant->license?->expires_at?->format('Y-m-d') ?? '-' }}</td>
                            <td data-label="Status"><span class="badge">{{ $tenant->status }}</span></td>
                            <td><button type="button" class="button secondary" data-open-dialog="tenant-plan-{{ $tenant->id }}">Change type/plan</button></td>
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
                <div><label>Category and plan</label><select name="plan_id" required>@foreach($plans as $plan)<option value="{{ $plan->id }}">{{ $plan->category?->name }} — {{ $plan->name }}</option>@endforeach</select></div>
                <div><label>Effective</label><select name="effective"><option value="immediate">Immediately, keep current expiry</option><option value="scheduled">At current expiry</option></select></div>
                <div><label>Scheduled duration</label><select name="duration_months"><option value="">Not required for immediate</option>@foreach(config('pricing.extension_discounts') as $months => $discount)<option value="{{ $months }}">{{ $months }} months</option>@endforeach</select></div>
            </div>
            <div style="margin-top:16px"><button class="button primary">Apply change</button></div>
        </form>
    </dialog>
    @endforeach
@endsection

@push('scripts')
<script>document.querySelectorAll('[data-open-dialog]').forEach(b=>b.addEventListener('click',()=>document.getElementById(b.dataset.openDialog)?.showModal()));document.querySelectorAll('[data-close-dialog]').forEach(b=>b.addEventListener('click',()=>document.getElementById(b.dataset.closeDialog)?.close()));</script>
@endpush
