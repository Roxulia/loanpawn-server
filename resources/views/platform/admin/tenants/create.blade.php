@extends('platform.admin.layouts.app')

@section('title', 'Create Tenant | LonePawn Admin')
@section('pageTitle', 'Create Tenant')
@section('pageDescription', 'Provision a tenant and a free fixed-term license for an existing platform user.')
@section('pageAction')
    <a href="{{ route('admin.tenants.index') }}" class="button secondary">Back to tenants</a>
@endsection

@section('content')
    @if ($errors->any())
        <div class="flash error" role="alert">{{ $errors->first() }}</div>
    @endif
    @include('platform.admin.tenants.partials.create-desktop')
    @include('platform.admin.tenants.partials.create-mobile')
@endsection

@push('scripts')
<script>
document.querySelectorAll('[data-admin-tenant-form]').forEach((form) => {
    const category = form.querySelector('[name="category_id"]');
    const plan = form.querySelector('[name="plan_id"]');
    const subdomainField = form.querySelector('[data-subdomain-field]');
    const subdomainInput = subdomainField.querySelector('[name="subdomain"]');
    const updateSubdomainVisibility = () => {
        const selectedPlan = plan.selectedOptions[0];
        const subdomainAvailable = selectedPlan
            && !selectedPlan.disabled
            && selectedPlan.dataset.subdomainAvailable === 'true';

        subdomainField.hidden = !subdomainAvailable;
        subdomainInput.disabled = !subdomainAvailable;
    };
    const filterPlans = () => {
        const categoryId = category.value;
        let firstVisible = null;
        Array.from(plan.options).forEach((option) => {
            const visible = option.dataset.categoryId === categoryId;
            option.hidden = !visible;
            option.disabled = !visible;
            if (visible && firstVisible === null) firstVisible = option;
        });
        if (!plan.selectedOptions.length || plan.selectedOptions[0].disabled) plan.value = firstVisible?.value ?? '';
        updateSubdomainVisibility();
    };
    category.addEventListener('change', filterPlans);
    plan.addEventListener('change', updateSubdomainVisibility);
    filterPlans();
});
</script>
@endpush
