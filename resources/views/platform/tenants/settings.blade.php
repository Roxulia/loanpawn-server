@extends('platform.layouts.app')

@section('title', 'Tenant Settings')
@section('pageTitle', 'Tenant Settings')
@section('pageDescription', 'Update tenant profile, branding colors, slip text, and business contact settings.')

@section('content')
    <style>
        .platform-dialog {
            position: fixed;
            inset: 50% auto auto 50%;
            transform: translate(-50%, -50%);
            margin: 0;
        }

        .dialog-close.icon-only {
            width: 38px;
            height: 38px;
            padding: 0;
            font-size: 24px;
            line-height: 1;
        }

        .platform-schedule-mobile { display: none; }
        .platform-schedule-row {
            display: grid;
            grid-template-columns: minmax(110px, 1fr) minmax(110px, .7fr) minmax(130px, .8fr) minmax(130px, .8fr);
            gap: 16px;
            align-items: start;
            padding: 12px 0;
            border-bottom: 1px solid var(--color-border, #e2e8f0);
        }
        .platform-schedule-row.header {
            color: var(--color-muted, #64748b);
            font-size: 12px;
            font-weight: 700;
            text-transform: uppercase;
        }
        .platform-schedule-row input { min-height: 44px; }
        .platform-schedule-toggle { display: flex; align-items: center; gap: 8px; min-height: 44px; }
        .platform-schedule-toggle input { width: 20px; height: 20px; }
        @media (max-width: 640px) {
            .platform-schedule-desktop { display: none; }
            .platform-schedule-mobile { display: block; }
            .platform-schedule-card {
                padding: 16px;
                margin-bottom: 12px;
                border: 1px solid var(--color-border, #e2e8f0);
                border-radius: 8px;
                background: #fff;
            }
            .platform-schedule-card header { display: flex; justify-content: space-between; align-items: center; gap: 12px; margin-bottom: 14px; }
            .platform-schedule-card .form-grid { grid-template-columns: 1fr; }
            .platform-schedule-card input, .platform-schedule-submit { width: 100%; min-height: 44px; }
        }
    </style>

    @php
        $currentPlan = $tenant->license?->plan?->code ?? $tenant->license?->plan_type ?? 'trial';
        $currentPlanRank = (int) ($tenant->license?->plan?->rank ?? 0);
        $canExtendLicense = ! ($tenant->license?->plan?->is_trial ?? ($currentPlan === 'trial'));
        $licenseExpiresAt = $tenant->license?->expires_at;
        $upgradeBillingMonths = 1;

        if ($licenseExpiresAt !== null && $licenseExpiresAt->isFuture()) {
            $monthsUntilExpiry = max(1, now()->startOfDay()->diffInMonth($licenseExpiresAt->copy()->startOfDay()));
            $upgradeBillingMonths = max(1, (int) ceil($monthsUntilExpiry));
        }

        $packagePrices = $planOptions
            ->mapWithKeys(fn ($package) => [$package->code => (float) $package->price])
            ->toArray();
        $scheduledPlanTransition = $tenant->license?->scheduledPlanTransition;
    @endphp

    <form method="POST" action="{{ route('platform.tenants.update', $tenant->id) }}" class="grid">
        @csrf
        @method('PUT')
        <input type="hidden" name="update_key" value="{{ $tenant->update_key }}">

        <section class="panel">
            <h2 style="margin-top: 0; color: var(--color-heading); font-size: 20px;">Tenant Profile</h2>
            <div class="form-grid">
                <div>
                    <label for="name">Tenant Name</label>
                    <input id="name" name="name" value="{{ old('name', $tenant->name) }}">
                    @error('name') <div class="field-error">{{ $message }}</div> @enderror
                </div>
                <div>
                    <label>Tenant Code</label>
                    <input value="{{ $tenant->tenant_code }}" disabled>
                </div>
                @if ($canManageSubdomain)
                    <div>
                        <label for="subdomain">Subdomain</label>
                        <input
                            id="subdomain"
                            name="subdomain"
                            value="{{ old('subdomain', $tenant->subdomain) }}"
                            maxlength="25"
                            data-character-counter="subdomain-character-count"
                        >
                        <div id="subdomain-character-count" class="field-help" aria-live="polite"> {{ strlen((string) old('subdomain', $tenant->subdomain)) }}/25 characters remaining</div>
                        @error('subdomain') <div class="field-error">{{ $message }}</div> @enderror
                    </div>
                @endif
                <div>
                    <label>License</label>
                    <input value="{{ $tenant->license?->plan_type ?? 'trial' }} / {{ $tenant->license?->status ?? $tenant->status }}" disabled>
                </div>
                <div>
                    <label>Plan Request</label>
                    <div style="display: flex; gap: 10px; flex-wrap: wrap;">
                        @if ($planOptions->isNotEmpty())
                            <button type="button" class="button primary" data-open-dialog="upgrade-request-dialog">Change Plan</button>
                        @endif
                        @if ($canExtendLicense && ! $scheduledPlanTransition)
                            <button type="button" class="button secondary" data-open-dialog="extension-request-dialog">License Extension</button>
                        @endif
                    </div>
                </div>
                <div style="grid-column: 1 / -1;">
                    <label>License Key</label>
                    <input value="{{ $tenant->license?->license_key ?? '-' }}" disabled>
                </div>
                @if ($scheduledPlanTransition)
                    <div style="grid-column: 1 / -1;">
                        <label>Scheduled Next Plan</label>
                        <input value="{{ ucfirst($scheduledPlanTransition->to_plan_type) }} from {{ $scheduledPlanTransition->starts_at->format('Y-m-d H:i') }} until {{ $scheduledPlanTransition->expires_at->format('Y-m-d H:i') }}" disabled>
                    </div>
                @endif
            </div>
        </section>

        @if ($canManageBranding)
            <section class="panel">
                <h2 style="margin-top: 0; color: var(--color-heading); font-size: 20px;">Branding Settings</h2>
                <div class="form-grid">
                    <div>
                        <label for="primary_color">Primary Color</label>
                        <input id="primary_color" name="primary_color" value="{{ old('primary_color', $tenant->branding?->primary_color) }}" placeholder="#03003D">
                        @error('primary_color') <div class="field-error">{{ $message }}</div> @enderror
                    </div>
                    <div>
                        <label for="secondary_color">Secondary Color</label>
                        <input id="secondary_color" name="secondary_color" value="{{ old('secondary_color', $tenant->branding?->secondary_color) }}" placeholder="#0A0A5A">
                        @error('secondary_color') <div class="field-error">{{ $message }}</div> @enderror
                    </div>
                    <div>
                        <label for="accent_color">Accent Color</label>
                        <input id="accent_color" name="accent_color" value="{{ old('accent_color', $tenant->branding?->accent_color) }}" placeholder="#F5A700">
                        @error('accent_color') <div class="field-error">{{ $message }}</div> @enderror
                    </div>
                    <div>
                        <label for="slip_header_text">Slip Header Text</label>
                        <textarea id="slip_header_text" name="slip_header_text">{{ old('slip_header_text', $tenant->branding?->slip_header_text) }}</textarea>
                        @error('slip_header_text') <div class="field-error">{{ $message }}</div> @enderror
                    </div>
                    <div style="grid-column: 1 / -1;">
                        <label for="slip_footer_text">Slip Footer Text</label>
                        <textarea id="slip_footer_text" name="slip_footer_text">{{ old('slip_footer_text', $tenant->branding?->slip_footer_text) }}</textarea>
                        @error('slip_footer_text') <div class="field-error">{{ $message }}</div> @enderror
                    </div>
                </div>
            </section>
        @endif

        <section class="panel">
            <h2 style="margin-top: 0; color: var(--color-heading); font-size: 20px;">Contact Settings</h2>
            <div class="form-grid">
                <div>
                    <label for="phone">Phone</label>
                    <input id="phone" name="phone" value="{{ old('phone', $tenant->contact?->phone) }}">
                    @error('phone') <div class="field-error">{{ $message }}</div> @enderror
                </div>
                <div>
                    <label for="city">City</label>
                    <input id="city" name="city" value="{{ old('city', $tenant->contact?->city) }}">
                    @error('city') <div class="field-error">{{ $message }}</div> @enderror
                </div>
                <div>
                    <label for="country">Country</label>
                    <input id="country" name="country" value="{{ old('country', $tenant->contact?->country) }}">
                    @error('country') <div class="field-error">{{ $message }}</div> @enderror
                </div>
                <div style="grid-column: 1 / -1;">
                    <label for="address">Address</label>
                    <textarea id="address" name="address">{{ old('address', $tenant->contact?->address) }}</textarea>
                    @error('address') <div class="field-error">{{ $message }}</div> @enderror
                </div>
            </div>
        </section>

        <div>
            <button type="submit" class="button primary">Save Settings</button>
            <a href="{{ route('platform.tenants.index') }}" class="button secondary">Back</a>
        </div>
    </form>

    @if ($canManageAccountingSchedule && $accountingSchedule)
        @php($weekdayNames = ['Sunday', 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday'])
        <form method="POST" action="{{ route('platform.tenants.accounting-day-schedule.update', $tenant->id) }}" class="panel" id="platform-accounting-schedule-form">
            @csrf
            @method('PUT')
            <h2 style="margin-top: 0; color: var(--color-heading); font-size: 20px;">Automatic Accounting Day Schedule</h2>
            <p class="field-help">Times use {{ $accountingSchedule['timezone'] }}. Due actions are processed every 15 minutes.</p>

            <div class="platform-schedule-desktop">
                <div class="platform-schedule-row header" aria-hidden="true"><span>Day</span><span>Enabled</span><span>Open</span><span>Close</span></div>
                @foreach ($accountingSchedule['days'] as $index => $day)
                    <div class="platform-schedule-row">
                        <strong>{{ $weekdayNames[$day['weekday']] }}</strong>
                        <input type="hidden" name="days[{{ $index }}][weekday]" value="{{ $day['weekday'] }}">
                        <label class="platform-schedule-toggle">
                            <input type="checkbox" name="days[{{ $index }}][is_enabled]" value="1" data-schedule-field="{{ $index }}-enabled" @checked(old("days.$index.is_enabled", $day['is_enabled']))>
                            <span>Enabled</span>
                        </label>
                        <input aria-label="{{ $weekdayNames[$day['weekday']] }} open time" name="days[{{ $index }}][open_time]" type="time" value="{{ old("days.$index.open_time", $day['open_time']) }}" data-schedule-field="{{ $index }}-open">
                        <div>
                            <input aria-label="{{ $weekdayNames[$day['weekday']] }} close time" name="days[{{ $index }}][close_time]" type="time" value="{{ old("days.$index.close_time", $day['close_time']) }}" data-schedule-field="{{ $index }}-close">
                            @error("days.$index.close_time") <div class="field-error">{{ $message }}</div> @enderror
                        </div>
                    </div>
                @endforeach
            </div>

            <div class="platform-schedule-mobile">
                @foreach ($accountingSchedule['days'] as $index => $day)
                    <section class="platform-schedule-card">
                        <header>
                            <strong>{{ $weekdayNames[$day['weekday']] }}</strong>
                            <label class="platform-schedule-toggle"><input type="checkbox" data-schedule-mobile="{{ $index }}-enabled" @checked(old("days.$index.is_enabled", $day['is_enabled']))><span>Enabled</span></label>
                        </header>
                        <div class="form-grid">
                            <div><label for="mobile-open-{{ $index }}">Open time</label><input id="mobile-open-{{ $index }}" type="time" value="{{ old("days.$index.open_time", $day['open_time']) }}" data-schedule-mobile="{{ $index }}-open"></div>
                            <div><label for="mobile-close-{{ $index }}">Close time</label><input id="mobile-close-{{ $index }}" type="time" value="{{ old("days.$index.close_time", $day['close_time']) }}" data-schedule-mobile="{{ $index }}-close">@error("days.$index.close_time") <div class="field-error">{{ $message }}</div> @enderror</div>
                        </div>
                    </section>
                @endforeach
            </div>
            <button type="submit" class="button primary platform-schedule-submit">Save Schedule</button>
        </form>
    @endif

    @if ($planOptions->isNotEmpty())
    <dialog class="platform-dialog" id="upgrade-request-dialog">
        <form method="POST" action="{{ route('platform.tenants.upgrade-request', $tenant->id) }}">
            @csrf
            <div class="dialog-header">
                <h2>Change Plan</h2>
                <button type="button" class="dialog-close icon-only" data-close-dialog="upgrade-request-dialog" aria-label="{{ __('app.common.view.actions.close') }}">&times;</button>
            </div>
            <div class="grid">
                <div>
                    <label for="requested_plan_type">Requested Plan</label>
                    <select id="requested_plan_type" name="requested_plan_type" required>
                        @foreach ($planOptions as $plan)
                            <option value="{{ $plan->code }}" data-monthly-price="{{ (float) $plan->price }}" data-rank="{{ $plan->rank }}">
                                {{ $plan->category?->name }} — {{ $plan->name }}
                            </option>
                        @endforeach
                    </select>
                </div>
                    <div id="downgrade-term-field" hidden>
                        <label for="downgrade_extension_months">New plan duration</label>
                        <select id="downgrade_extension_months" name="extension_months">
                            <option value="">Select term for a scheduled downgrade</option>
                            @foreach (config('pricing.extension_discounts') as $months => $discount)
                                <option value="{{ $months }}" data-discount="{{ $discount }}">{{ $months }} month{{ $months === 1 ? '' : 's' }}</option>
                            @endforeach
                        </select>
                    </div>
                <div>
                    <label>Billing Months</label>
                    <input value="{{ $upgradeBillingMonths }} month{{ $upgradeBillingMonths === 1 ? '' : 's' }} until {{ $licenseExpiresAt?->format('Y-m-d') ?? 'license expiry' }}" disabled>
                </div>
                <div>
                    <label>Monthly Price</label>
                    <input id="upgrade_monthly_price" value="-" disabled>
                </div>
                <div>
                    <label>Estimated Payment Amount</label>
                    <input id="upgrade_total_price" value="-" disabled>
                </div>
                <div>
                    <label for="upgrade_note">Note</label>
                    <textarea id="upgrade_note" name="note" placeholder="Optional request note"></textarea>
                </div>
            </div>
            <div style="margin-top: 16px;">
                <button type="submit" class="button primary">Create Payment Request</button>
            </div>
        </form>
    </dialog>
    @endif

    @if ($canExtendLicense && ! $scheduledPlanTransition)
        <dialog class="platform-dialog" id="extension-request-dialog">
            <form method="POST" action="{{ route('platform.tenants.extension-request', $tenant->id) }}">
                @csrf
                <div class="dialog-header">
                    <h2>License Extension</h2>
                    <button type="button" class="dialog-close icon-only" data-close-dialog="extension-request-dialog" aria-label="{{ __('app.common.view.actions.close') }}">&times;</button>
                </div>
                <div class="grid">
                    <div>
                        <label for="extension_months">Extension Months</label>
                        <select id="extension_months" name="extension_months" required>
                            <option value="1">1 month</option>
                            <option value="3">3 months</option>
                            <option value="6">6 months</option>
                            <option value="12">12 months</option>
                        </select>
                    </div>
                    <div>
                        <label for="extension_note">Note</label>
                        <textarea id="extension_note" name="note" placeholder="Optional request note"></textarea>
                    </div>
                </div>
                <div style="margin-top: 16px;">
                    <button type="submit" class="button primary">Create Payment Request</button>
                </div>
            </form>
        </dialog>
    @endif

    <script>
        const upgradeBillingMonths = {{ $upgradeBillingMonths }};
        const currentPlan = @json($currentPlan);
        const currentPlanRank = {{ $currentPlanRank }};
        const currencyFormatter = new Intl.NumberFormat('en-US', {
            maximumFractionDigits: 0,
        });

        function updateUpgradePricePreview() {
            const planSelect = document.getElementById('requested_plan_type');
            const monthlyPriceInput = document.getElementById('upgrade_monthly_price');
            const totalPriceInput = document.getElementById('upgrade_total_price');

            if (!planSelect || !monthlyPriceInput || !totalPriceInput) {
                return;
            }

            const selectedOption = planSelect.options[planSelect.selectedIndex];
            const monthlyPrice = Number(selectedOption?.dataset.monthlyPrice || 0);
            const downgradeTerm = document.getElementById('downgrade_extension_months');
            const isDeferredDowngrade = Number(selectedOption?.dataset.rank || 0) < currentPlanRank;
            const selectedTerm = downgradeTerm?.options[downgradeTerm.selectedIndex];
            const months = isDeferredDowngrade ? Number(selectedTerm?.value || 0) : upgradeBillingMonths;
            const discount = isDeferredDowngrade ? Number(selectedTerm?.dataset.discount || 0) : 0;
            const totalPrice = monthlyPrice * months * (1 - discount);

            monthlyPriceInput.value = currencyFormatter.format(monthlyPrice) + ' MMK';
            totalPriceInput.value = currencyFormatter.format(totalPrice) + ' MMK';

            if (downgradeTerm) {
                downgradeTerm.required = isDeferredDowngrade;
                document.getElementById('downgrade-term-field').hidden = !isDeferredDowngrade;
            }
        }

        document.querySelectorAll('[data-open-dialog]').forEach(function (button) {
            button.addEventListener('click', function () {
                document.getElementById(button.dataset.openDialog)?.showModal();
                updateUpgradePricePreview();
            });
        });

        document.querySelectorAll('[data-close-dialog]').forEach(function (button) {
            button.addEventListener('click', function () {
                document.getElementById(button.dataset.closeDialog)?.close();
            });
        });

        document.querySelectorAll('[data-schedule-mobile]').forEach(function (mobileInput) {
            mobileInput.addEventListener('change', function () {
                const desktopInput = document.querySelector('[data-schedule-field="' + mobileInput.dataset.scheduleMobile + '"]');
                if (!desktopInput) return;
                if (mobileInput.type === 'checkbox') desktopInput.checked = mobileInput.checked;
                else desktopInput.value = mobileInput.value;
            });
        });

        document.getElementById('requested_plan_type')?.addEventListener('change', updateUpgradePricePreview);
        document.getElementById('downgrade_extension_months')?.addEventListener('change', updateUpgradePricePreview);
        updateUpgradePricePreview();

        document.querySelectorAll('[data-character-counter]').forEach(function (input) {
            const counter = document.getElementById(input.dataset.characterCounter);
            const maxLength = Number(input.getAttribute('maxlength') || 0);

            if (!counter || maxLength <= 0) {
                return;
            }

            const updateCounter = function () {
                counter.textContent = input.value.length + '/' + maxLength;
            };

            input.addEventListener('input', updateCounter);
            updateCounter();
        });
    </script>
@endsection
