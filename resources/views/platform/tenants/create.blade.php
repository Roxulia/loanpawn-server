@extends('platform.layouts.app')

@section('title', __('app.common.view.actions.create_tenant'))
@section('pageTitle', __('app.common.view.actions.create_tenant'))
@section('pageDescription', 'Add the tenant information, then choose the preferred plan. Every new tenant starts with a four-month trial.')
@section('pageAction')
    <a href="{{ route('platform.tenants.index') }}" class="button secondary">Back to tenants</a>
@endsection

@php
    $tenantInformationFields = ['name', 'phone', 'address', 'city', 'country'];
    $hasTenantInformationError = collect($tenantInformationFields)
        ->contains(fn (string $field): bool => $errors->has($field));
    $hasPlanError = collect(['category_id', 'plan_id', 'duration_months'])
        ->contains(fn (string $field): bool => $errors->has($field));
    $initialStage = ! $hasTenantInformationError && ($hasPlanError || old('plan_id')) ? 2 : 1;
    $requestedCategoryId = (string) old('category_id', $categories->first()?->id ?? '');
    $initialCategoryId = $categories->contains(
        fn ($category): bool => (string) $category->id === $requestedCategoryId,
    ) ? $requestedCategoryId : (string) ($categories->first()?->id ?? '');
    $formatLimit = static fn (?int $limit): string => $limit === null
        ? 'Unlimited'
        : ($limit === 0 ? 'Not included' : number_format($limit));
@endphp

@section('content')
    @if ($errors->any())
        <div class="flash error" role="alert">Please review the highlighted fields before creating the tenant.</div>
    @endif

    <section class="panel admin-tenant-wizard" data-tenant-wizard data-initial-stage="{{ $initialStage }}">
        <ol class="admin-tenant-wizard__steps" aria-label="Tenant creation progress">
            <li data-step-indicator="1">
                <span class="admin-tenant-wizard__step-number">1</span>
                <span><strong>Add tenant information</strong><small>Organization and contact details</small></span>
            </li>
            <li data-step-indicator="2">
                <span class="admin-tenant-wizard__step-number">2</span>
                <span><strong>Choose your plan</strong><small>Tenant type, features, and limits</small></span>
            </li>
        </ol>

        <form method="POST" action="{{ route('platform.tenants.store') }}" data-tenant-form>
            @csrf
            <input type="hidden" name="category_id" value="{{ $initialCategoryId }}" data-category-input>

            <div class="admin-tenant-wizard__stage" data-wizard-stage="1" @if ($initialStage !== 1) hidden @endif>
                <div class="admin-section-heading">
                    <div>
                        <p class="admin-section-kicker">Stage 1 of 2</p>
                        <h2>Add tenant information</h2>
                        <p>Enter the organization and contact details. Nothing will be created until the plan is confirmed.</p>
                    </div>
                </div>

                <div class="admin-tenant-create__grid">
                    <label>
                        {{ __('app.platform.view.tenant_name') }}
                        <input name="name" value="{{ old('name') }}" maxlength="255" required>
                        @error('name') <span class="field-error">{{ $message }}</span> @enderror
                    </label>
                    <label>
                        {{ __('app.common.view.labels.phone') }} <span class="admin-field-optional">Optional</span>
                        <input name="phone" value="{{ old('phone') }}" maxlength="20">
                        @error('phone') <span class="field-error">{{ $message }}</span> @enderror
                    </label>
                    <label>
                        {{ __('app.common.view.labels.city') }} <span class="admin-field-optional">Optional</span>
                        <input name="city" value="{{ old('city') }}" maxlength="255">
                        @error('city') <span class="field-error">{{ $message }}</span> @enderror
                    </label>
                    <label>
                        {{ __('app.common.view.labels.country') }} <span class="admin-field-optional">Optional</span>
                        <input name="country" value="{{ old('country') }}" maxlength="255">
                        @error('country') <span class="field-error">{{ $message }}</span> @enderror
                    </label>
                    <label class="admin-tenant-create__wide">
                        {{ __('app.common.view.labels.address') }} <span class="admin-field-optional">Optional</span>
                        <textarea name="address" rows="3" maxlength="100">{{ old('address') }}</textarea>
                        @error('address') <span class="field-error">{{ $message }}</span> @enderror
                    </label>
                </div>

                <div class="admin-tenant-create__actions">
                    <a href="{{ route('platform.tenants.index') }}" class="button secondary">{{ __('app.common.view.actions.cancel') }}</a>
                    <button type="button" class="button primary" data-wizard-next>Continue to plans</button>
                </div>
            </div>

            <div class="admin-tenant-wizard__stage" data-wizard-stage="2" @if ($initialStage !== 2) hidden @endif>
                <div class="admin-section-heading admin-tenant-plan-heading">
                    <div>
                        <p class="admin-section-kicker">Stage 2 of 2</p>
                        <h2>Choose your plan</h2>
                        <p>Select a tenant type, compare its available plans, and confirm your choice.</p>
                    </div>
                    <div class="admin-tenant-grant-summary">
                        <span>New tenant access</span>
                        <strong>4-month trial</strong>
                    </div>
                </div>

                @if ($categories->isEmpty())
                    <div class="empty-state admin-tenant-plan-empty">
                        <h3>No plans available</h3>
                        <p>There are no active tenant types or plans available right now.</p>
                    </div>
                @else
                    <div class="admin-tenant-type-tabs" role="tablist" aria-label="Tenant types">
                        @foreach ($categories as $category)
                            <button type="button" id="tenant-type-tab-{{ $category->id }}" class="admin-tenant-type-tab" role="tab"
                                aria-selected="{{ (string) $category->id === $initialCategoryId ? 'true' : 'false' }}"
                                aria-controls="tenant-type-panel-{{ $category->id }}"
                                tabindex="{{ (string) $category->id === $initialCategoryId ? '0' : '-1' }}"
                                data-category-tab data-category-id="{{ $category->id }}">
                                <strong>{{ $category->name }}</strong>
                                @if ($category->description) <small>{{ $category->description }}</small> @endif
                            </button>
                        @endforeach
                    </div>

                    @foreach ($categories as $category)
                        <section id="tenant-type-panel-{{ $category->id }}" class="admin-tenant-plan-panel" role="tabpanel"
                            aria-labelledby="tenant-type-tab-{{ $category->id }}" data-category-panel="{{ $category->id }}"
                            @if ((string) $category->id !== $initialCategoryId) hidden @endif>
                            @if ($category->packages->isEmpty())
                                <div class="empty-state admin-tenant-plan-empty">
                                    <h3>No plans for {{ $category->name }}</h3>
                                    <p>Choose another tenant type to continue.</p>
                                </div>
                            @else
                                <div class="admin-tenant-plan-grid">
                                    @foreach ($category->packages as $plan)
                                        @php
                                            $enabledFeatures = $plan->packageFeatures
                                                ->filter(fn ($mapping): bool => $mapping->is_enabled
                                                    && (bool) $mapping->feature?->is_active
                                                    && ! (bool) $mapping->feature?->is_deleted)
                                                ->sortBy(fn ($mapping): string => $mapping->feature->name);
                                            $limits = [
                                                'Slips per month' => $plan->max_slip_per_month,
                                                'Staff members' => $plan->max_staff_count,
                                                'Financial accounts' => $plan->max_account_count,
                                                'Currencies' => $plan->max_currency_type_count,
                                                'Exchange pairs' => $plan->max_exchange_pair_count,
                                            ];
                                        @endphp
                                        <label class="admin-tenant-plan-card" data-plan-card>
                                            <input type="radio" name="plan_id" value="{{ $plan->id }}" data-plan-option
                                                data-category-id="{{ $category->id }}" data-trial="{{ $plan->is_trial ? '1' : '0' }}"
                                                @checked((string) old('plan_id') === (string) $plan->id)>
                                            <span class="admin-tenant-plan-card__check" aria-hidden="true">&#10003;</span>
                                            <span class="admin-tenant-plan-card__header">
                                                <span>
                                                    @if ($plan->is_trial) <span class="badge">Trial</span> @endif
                                                    <strong>{{ $plan->name }}</strong>
                                                </span>
                                                <span class="admin-tenant-plan-card__price">
                                                    <strong>{{ number_format((float) $plan->price) }} MMK</strong>
                                                    <small>per month</small>
                                                </span>
                                            </span>
                                            @if ($plan->description)
                                                <span class="admin-tenant-plan-card__description">{{ $plan->description }}</span>
                                            @endif
                                            <span class="admin-tenant-plan-card__grant">
                                                {{ $plan->is_trial ? 'Starts immediately with the tenant trial.' : 'Activates after payment is completed and approved.' }}
                                            </span>
                                            <span class="admin-tenant-plan-card__section-title">Plan limits</span>
                                            <span class="admin-tenant-plan-limits">
                                                @foreach ($limits as $label => $limit)
                                                    <span><small>{{ $label }}</small><strong>{{ $formatLimit($limit) }}</strong></span>
                                                @endforeach
                                            </span>
                                            <span class="admin-tenant-plan-card__section-title">Included features</span>
                                            @if ($enabledFeatures->isEmpty())
                                                <span class="admin-tenant-plan-card__no-features">No enabled features</span>
                                            @else
                                                <span class="admin-tenant-plan-features">
                                                    @foreach ($enabledFeatures as $mapping)
                                                        <span><span aria-hidden="true">&#10003;</span>{{ $mapping->feature->name }}</span>
                                                    @endforeach
                                                </span>
                                            @endif
                                        </label>
                                    @endforeach
                                </div>
                            @endif
                        </section>
                    @endforeach
                @endif

                <div class="admin-tenant-create__grid" data-duration-field hidden>
                    <label class="admin-tenant-create__wide">
                        Paid duration
                        <select name="duration_months" disabled>
                            @foreach (config('pricing.extension_discounts') as $months => $discount)
                                <option value="{{ $months }}" @selected((int) old('duration_months') === (int) $months)>
                                    {{ $months }} month{{ (int) $months === 1 ? '' : 's' }}{{ $discount ? ' · '.($discount * 100).'% discount' : '' }}
                                </option>
                            @endforeach
                        </select>
                        @error('duration_months') <span class="field-error">{{ $message }}</span> @enderror
                    </label>
                </div>

                <p class="field-error admin-tenant-plan-error" data-plan-error @if (! $errors->has('plan_id')) hidden @endif>
                    {{ $errors->first('plan_id') ?: 'Select a plan before creating the tenant.' }}
                </p>
                @error('category_id') <p class="field-error admin-tenant-plan-error">{{ $message }}</p> @enderror

                <div class="payment-info-box" style="margin-top: 18px;">
                    The tenant will open with the trial version. A selected paid plan will not become active until payment is completed and approved.
                </div>

                <div class="admin-tenant-create__actions">
                    <button type="button" class="button secondary" data-wizard-back>Back to tenant information</button>
                    @if ($categories->isNotEmpty())
                        <button type="submit" class="button primary" data-wizard-submit>{{ __('app.common.view.actions.create_tenant') }}</button>
                    @endif
                </div>
            </div>
        </form>
    </section>

    @if ($createdTenant)
        <dialog class="platform-dialog" id="tenant-created-dialog">
            <div class="dialog-header"><h2>Tenant created</h2></div>
            <p><strong>{{ $createdTenant['name'] }}</strong> · {{ $createdTenant['code'] }}</p>
            <div class="form-grid">
                <div><label>Owner email</label><input value="{{ $createdTenant['email'] }}" readonly></div>
                <div><label>Password</label><input value="Use current password for login" readonly></div>
            </div>
            @if ($createdTenant['payment_request_id'])
                <div class="flash" style="margin-top: 14px;">Tenant is active on Trial. {{ $createdTenant['selected_plan'] }} will activate only after payment approval.</div>
            @endif
            <div style="margin-top: 18px; display: flex; gap: 10px; flex-wrap: wrap;">
                @if ($createdTenant['payment_request_id'])
                    <a class="button primary" href="{{ route('platform.billing.index', ['open_request' => $createdTenant['payment_request_id']]) }}">Pay now</a>
                @endif
                <form method="POST" action="{{ route('platform.tenants.open-app', $createdTenant['id']) }}">@csrf<button class="button primary">Go to tenant</button></form>
                <a class="button secondary" href="{{ route('platform.tenants.index') }}">View tenant list</a>
            </div>
        </dialog>
    @endif
@endsection

@push('scripts')
<script>
(function () {
    const wizard = document.querySelector('[data-tenant-wizard]');
    const form = wizard?.querySelector('[data-tenant-form]');
    if (!wizard || !form) return;

    const stages = Array.from(wizard.querySelectorAll('[data-wizard-stage]'));
    const indicators = Array.from(wizard.querySelectorAll('[data-step-indicator]'));
    const categoryInput = form.querySelector('[data-category-input]');
    const categoryTabs = Array.from(form.querySelectorAll('[data-category-tab]'));
    const categoryPanels = Array.from(form.querySelectorAll('[data-category-panel]'));
    const durationField = form.querySelector('[data-duration-field]');
    const durationSelect = durationField?.querySelector('[name="duration_months"]');
    const planError = form.querySelector('[data-plan-error]');
    let currentStage = Number(wizard.dataset.initialStage || 1);

    function showStage(stageNumber, shouldScroll = true) {
        currentStage = stageNumber;
        stages.forEach((stage) => { stage.hidden = Number(stage.dataset.wizardStage) !== stageNumber; });
        indicators.forEach((indicator, index) => {
            const indicatorStage = index + 1;
            indicator.classList.toggle('is-active', indicatorStage === stageNumber);
            indicator.classList.toggle('is-complete', indicatorStage < stageNumber);
        });
        if (shouldScroll) wizard.scrollIntoView({ behavior: 'smooth', block: 'start' });
    }

    function updatePlanSelection() {
        const selectedPlan = form.querySelector('[data-plan-option]:checked');
        wizard.querySelectorAll('[data-plan-card]').forEach((card) => {
            card.classList.toggle('is-selected', Boolean(card.querySelector('[data-plan-option]:checked')));
        });
        const paidPlanSelected = selectedPlan?.dataset.trial === '0';
        if (durationField) durationField.hidden = !paidPlanSelected;
        if (durationSelect) {
            durationSelect.disabled = !paidPlanSelected;
            durationSelect.required = paidPlanSelected;
        }
        if (selectedPlan && planError) planError.hidden = true;
    }

    function selectCategory(categoryId, focusTab = false) {
        categoryInput.value = categoryId;
        categoryTabs.forEach((tab) => {
            const selected = tab.dataset.categoryId === categoryId;
            tab.setAttribute('aria-selected', selected ? 'true' : 'false');
            tab.tabIndex = selected ? 0 : -1;
            if (selected && focusTab) tab.focus();
        });
        categoryPanels.forEach((panel) => { panel.hidden = panel.dataset.categoryPanel !== categoryId; });
        const selectedPlan = form.querySelector('[data-plan-option]:checked');
        if (selectedPlan && selectedPlan.dataset.categoryId !== categoryId) selectedPlan.checked = false;
        updatePlanSelection();
    }

    form.querySelector('[data-wizard-next]')?.addEventListener('click', () => {
        const firstStage = form.querySelector('[data-wizard-stage="1"]');
        const invalidControl = Array.from(firstStage.querySelectorAll('input, select, textarea'))
            .find((control) => !control.checkValidity());
        if (invalidControl) {
            invalidControl.reportValidity();
            invalidControl.focus();
            return;
        }
        showStage(2);
        categoryTabs.find((tab) => tab.getAttribute('aria-selected') === 'true')?.focus();
    });

    form.querySelector('[data-wizard-back]')?.addEventListener('click', () => {
        showStage(1);
        form.querySelector('[name="name"]')?.focus();
    });

    categoryTabs.forEach((tab, index) => {
        tab.addEventListener('click', () => selectCategory(tab.dataset.categoryId));
        tab.addEventListener('keydown', (event) => {
            let nextIndex = null;
            if (event.key === 'ArrowRight') nextIndex = (index + 1) % categoryTabs.length;
            if (event.key === 'ArrowLeft') nextIndex = (index - 1 + categoryTabs.length) % categoryTabs.length;
            if (event.key === 'Home') nextIndex = 0;
            if (event.key === 'End') nextIndex = categoryTabs.length - 1;
            if (nextIndex === null) return;
            event.preventDefault();
            selectCategory(categoryTabs[nextIndex].dataset.categoryId, true);
        });
    });

    form.querySelectorAll('[data-plan-option]').forEach((option) => option.addEventListener('change', updatePlanSelection));
    form.addEventListener('submit', (event) => {
        if (currentStage !== 2 || !form.querySelector('[data-plan-option]:checked')) {
            event.preventDefault();
            showStage(2);
            if (planError) planError.hidden = false;
            categoryPanels.find((panel) => !panel.hidden)?.querySelector('[data-plan-option]')?.focus();
            return;
        }
        const submitButton = form.querySelector('[data-wizard-submit]');
        submitButton.disabled = true;
        submitButton.textContent = 'Creating tenant...';
    });

    if (categoryInput.value) selectCategory(categoryInput.value);
    updatePlanSelection();
    showStage(currentStage, false);
    document.getElementById('tenant-created-dialog')?.showModal();
}());
</script>
@endpush
