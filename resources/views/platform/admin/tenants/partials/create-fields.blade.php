@csrf
<div class="admin-tenant-create__grid">
    <label>Platform owner<select name="platform_user_id" required><option value="">Select an active platform user</option>@foreach ($owners as $owner)<option value="{{ $owner->id }}" @selected((string) old('platform_user_id') === (string) $owner->id)>{{ $owner->name }} — {{ $owner->email }} ({{ $owner->code }})</option>@endforeach</select></label>
    <label>Tenant name<input name="name" value="{{ old('name') }}" maxlength="255" required></label>
    <label data-subdomain-field hidden>Subdomain<input name="subdomain" value="{{ old('subdomain') }}" maxlength="63" pattern="[A-Za-z0-9_-]+" disabled></label>
    <label>Category<select name="category_id" required><option value="">Select a category</option>@foreach ($categories as $category)<option value="{{ $category->id }}" @selected((string) old('category_id') === (string) $category->id)>{{ $category->name }}</option>@endforeach</select></label>
    <label>Plan<select name="plan_id" required>@foreach ($categories as $category)@foreach ($category->packages as $plan)<option value="{{ $plan->id }}" data-category-id="{{ $category->id }}" data-subdomain-available="true" @selected((string) old('plan_id') === (string) $plan->id)>{{ $plan->name }}</option>@endforeach @endforeach</select></label>
    <label>License term<select name="license_months" required>@foreach ([1, 3, 6, 12] as $months)<option value="{{ $months }}" @selected((int) old('license_months', 1) === $months)>{{ $months }} month{{ $months === 1 ? '' : 's' }}</option>@endforeach</select></label>
    <label>Phone<input name="phone" value="{{ old('phone') }}" maxlength="20"></label>
    <label class="admin-tenant-create__wide">Address<input name="address" value="{{ old('address') }}" maxlength="100"></label>
    <label>City<input name="city" value="{{ old('city') }}" maxlength="255"></label>
    <label>Country<input name="country" value="{{ old('country') }}" maxlength="255"></label>
    <label class="admin-tenant-create__wide">Reason for free license<textarea name="reason" rows="4" maxlength="1000" required>{{ old('reason') }}</textarea></label>
</div>
<div class="admin-tenant-create__actions"><a href="{{ route('admin.tenants.index') }}" class="button secondary">Cancel</a><button type="submit" class="button primary">Create tenant and license</button></div>
