@extends('platform.layouts.app')

@section('title', __('app.common.view.actions.create_tenant'))
@section('pageTitle', __('app.common.view.actions.create_tenant'))
@section('pageDescription', 'Choose the business type and preferred plan. Every new tenant starts with a four-month trial.')

@section('content')
<form method="POST" action="{{ route('platform.tenants.store') }}" class="panel">
    @csrf
    <div class="form-grid">
        <div><label for="category_id">Tenant type</label><select id="category_id" name="category_id" required>@foreach($categories as $category)<option value="{{ $category->id }}" @selected((int)old('category_id') === $category->id)>{{ $category->name }}</option>@endforeach</select></div>
        <div><label for="plan_id">Preferred plan</label><select id="plan_id" name="plan_id" required>@foreach($categories as $category)@foreach($category->packages as $plan)<option value="{{ $plan->id }}" data-category="{{ $category->id }}" data-trial="{{ $plan->is_trial ? '1' : '0' }}" @selected((int)old('plan_id') === $plan->id)>{{ $plan->name }} — {{ number_format((float)$plan->price) }} MMK/month</option>@endforeach @endforeach</select>@error('plan_id') <div class="field-error">{{ $message }}</div> @enderror</div>
        <div id="duration-field" hidden><label for="duration_months">Paid duration</label><select id="duration_months" name="duration_months">@foreach(config('pricing.extension_discounts') as $months => $discount)<option value="{{ $months }}" @selected((int)old('duration_months') === (int)$months)>{{ $months }} month{{ (int)$months === 1 ? '' : 's' }}{{ $discount ? ' · '.($discount * 100).'% discount' : '' }}</option>@endforeach</select>@error('duration_months') <div class="field-error">{{ $message }}</div> @enderror</div>
        <div style="grid-column:1/-1" class="flash">Tenant will open with the trial version. A selected paid plan will not become active until payment is completed and approved.</div>
        <div><label for="name">{{ __('app.platform.view.tenant_name') }}</label><input id="name" name="name" value="{{ old('name') }}" required>@error('name') <div class="field-error">{{ $message }}</div> @enderror</div>
        <div><label for="phone">{{ __('app.common.view.labels.phone') }}</label><input id="phone" name="phone" value="{{ old('phone') }}">@error('phone') <div class="field-error">{{ $message }}</div> @enderror</div>
        <div><label for="city">{{ __('app.common.view.labels.city') }}</label><input id="city" name="city" value="{{ old('city') }}">@error('city') <div class="field-error">{{ $message }}</div> @enderror</div>
        <div><label for="country">{{ __('app.common.view.labels.country') }}</label><input id="country" name="country" value="{{ old('country') }}">@error('country') <div class="field-error">{{ $message }}</div> @enderror</div>
        <div style="grid-column:1/-1"><label for="address">{{ __('app.common.view.labels.address') }}</label><textarea id="address" name="address">{{ old('address') }}</textarea>@error('address') <div class="field-error">{{ $message }}</div> @enderror</div>
    </div>
    <div style="margin-top:18px;display:flex;gap:10px;flex-wrap:wrap"><a href="{{ route('platform.tenants.index') }}" class="button secondary">{{ __('app.common.view.actions.cancel') }}</a><button class="button primary">{{ __('app.common.view.actions.create_tenant') }}</button></div>
</form>

@if($createdTenant)
<dialog class="platform-dialog" id="tenant-created-dialog">
    <div class="dialog-header"><h2>Tenant created</h2></div>
    <p><strong>{{ $createdTenant['name'] }}</strong> · {{ $createdTenant['code'] }}</p>
    <div class="form-grid"><div><label>Owner email</label><input value="{{ $createdTenant['email'] }}" readonly></div><div><label>Password</label><input value="Use current password for login" readonly></div></div>
    @if($createdTenant['payment_request_id'])<div class="flash" style="margin-top:14px">Tenant is active on Trial. {{ $createdTenant['selected_plan'] }} will activate only after payment approval.</div>@endif
    <div style="margin-top:18px;display:flex;gap:10px;flex-wrap:wrap">
        @if($createdTenant['payment_request_id'])<a class="button primary" href="{{ route('platform.billing.index', ['open_request' => $createdTenant['payment_request_id']]) }}">Pay now</a>@endif
        <form method="POST" action="{{ route('platform.tenants.open-app', $createdTenant['id']) }}">@csrf<button class="button primary">Go to tenant</button></form>
        <a class="button secondary" href="{{ route('platform.tenants.index') }}">View tenant list</a>
    </div>
</dialog>
@endif
@endsection

@push('scripts')
<script>
const categorySelect=document.getElementById('category_id');const planSelect=document.getElementById('plan_id');const allPlanOptions=Array.from(planSelect?.options||[]);
function syncPlans(){const category=categorySelect?.value;let first=null;allPlanOptions.forEach((option)=>{option.hidden=option.dataset.category!==category;option.disabled=option.hidden;if(!option.hidden&&!first)first=option;});if(planSelect?.selectedOptions[0]?.hidden)planSelect.value=first?.value||'';const paid=planSelect?.selectedOptions[0]?.dataset.trial==='0';document.getElementById('duration-field').hidden=!paid;document.getElementById('duration_months').required=paid;}
categorySelect?.addEventListener('change',syncPlans);planSelect?.addEventListener('change',syncPlans);syncPlans();document.getElementById('tenant-created-dialog')?.showModal();
</script>
@endpush
