@extends('platform.admin.layouts.app')

@section('title', 'Plan & Tenant Types')
@section('pageTitle', 'Plan & Tenant Types')
@section('pageDescription', 'Create tenant categories and manage their ordered license plans.')

@section('content')
<div class="flag-stack">
    <section class="panel">
        <h2>Add tenant category</h2>
        <form method="POST" action="{{ route('admin.plans.categories.store') }}" class="form-grid">
            @csrf
            <div><label for="category_name">Name</label><input id="category_name" name="name" required></div>
            <div><label for="category_code">Code</label><input id="category_code" name="code" placeholder="budgeting" required></div>
            <div style="grid-column:1/-1"><label for="category_description">Description</label><textarea id="category_description" name="description"></textarea></div>
            <div><button class="button primary">Add category</button></div>
        </form>
    </section>

    <section class="panel">
        <h2>Add plan</h2>
        <form method="POST" action="{{ route('admin.plans.store') }}" class="form-grid">
            @csrf
            @include('platform.admin.plans.partials.fields', ['plan' => null])
            <div><button class="button primary">Add plan</button></div>
        </form>
    </section>

    @foreach($categories as $category)
        <section class="panel">
            <form method="POST" action="{{ route('admin.plans.categories.update', $category) }}" class="form-grid">
                @csrf @method('PUT')
                <div><label>Name</label><input name="name" value="{{ $category->name }}" required></div>
                <div><label>Code</label><input value="{{ $category->code }}" disabled></div>
                <div style="grid-column:1/-1"><label>Description</label><textarea name="description">{{ $category->description }}</textarea></div>
                <div><input type="hidden" name="is_active" value="0"><label><input type="checkbox" name="is_active" value="1" @checked($category->is_active)> Active</label></div>
                <div><button class="button primary">Save category</button></div>
            </form>
            <form method="POST" action="{{ route('admin.plans.categories.destroy', $category) }}" style="margin-top:10px" onsubmit="return confirm('Delete or archive this category?')">
                @csrf @method('DELETE')
                <button class="button secondary">Delete / archive category</button>
            </form>

            <div class="table-wrap" style="margin-top:18px">
                <table><thead><tr><th>Plan</th><th>Rank</th><th>Price</th><th>Limits</th><th>Status</th><th></th></tr></thead><tbody>
                @forelse($category->packages as $plan)
                    <tr>
                        <td>{{ $plan->name }}<br><small>{{ $plan->code }}{{ $plan->is_trial ? ' · Trial' : '' }}</small></td>
                        <td>{{ $plan->rank }}</td><td>{{ number_format((float)$plan->price) }} MMK</td>

                        <td>
                            {{ $plan->max_slip_per_month ?? '∞' }} slips / {{ $plan->max_staff_count ?? '∞' }} staff<br>
                            {{ $plan->max_account_count ?? '∞' }} accounts / {{ $plan->max_currency_type_count ?? '∞' }} currencies / {{ $plan->max_exchange_pair_count ?? '∞' }} pairs
                        </td>

                        <td><span class="badge">{{ $plan->is_active ? 'Active' : 'Inactive' }}</span></td>
                        <td><button type="button" class="button secondary" data-edit-plan="plan-dialog-{{ $plan->id }}">Edit</button></td>
                    </tr>
                @empty <tr><td colspan="6">No plans yet.</td></tr> @endforelse
                </tbody></table>
            </div>
        </section>

        @foreach($category->packages as $plan)
            <dialog class="platform-dialog" id="plan-dialog-{{ $plan->id }}">
                <form method="POST" action="{{ route('admin.plans.update', $plan) }}" class="form-grid">
                    @csrf @method('PUT')
                    <h2 style="grid-column:1/-1">Edit {{ $plan->name }}</h2>
                    @include('platform.admin.plans.partials.fields', ['plan' => $plan])
                    <div><button class="button primary">Save</button> <button type="button" class="button secondary" data-close-plan>Cancel</button></div>
                </form>
                <form method="POST" action="{{ route('admin.plans.destroy', $plan) }}" style="margin-top:12px" onsubmit="return confirm('Delete or archive this plan?')">
                    @csrf @method('DELETE')
                    <button class="button secondary">Delete / archive plan</button>
                </form>
            </dialog>
        @endforeach
    @endforeach
</div>
@endsection

@push('scripts')
<script>
document.querySelectorAll('[data-edit-plan]').forEach((button) => button.addEventListener('click', () => document.getElementById(button.dataset.editPlan)?.showModal()));
document.querySelectorAll('[data-close-plan]').forEach((button) => button.addEventListener('click', () => button.closest('dialog')?.close()));
</script>
@endpush
