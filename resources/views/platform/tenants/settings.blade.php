@extends('platform.layouts.app')

@section('title', 'Tenant Settings')
@section('pageTitle', 'Tenant Settings')
@section('pageDescription', 'Update tenant profile, branding colors, slip text, and business contact settings.')

@section('content')
    @php
        $currentPlan = $tenant->license?->plan_type ?? 'trial';
        $canExtendLicense = $currentPlan !== 'trial';
        $licenseExpiresAt = $tenant->license?->expires_at;
        $upgradeBillingMonths = 1;

        if ($licenseExpiresAt !== null && $licenseExpiresAt->isFuture()) {
            $daysUntilExpiry = max(1, now()->startOfDay()->diffInDays($licenseExpiresAt->copy()->startOfDay()));
            $upgradeBillingMonths = max(1, (int) ceil($daysUntilExpiry / 30));
        }

        $packagePrices = collect(config('package_features.packages'))
            ->mapWithKeys(fn (array $package, string $code) => [$code => (float) $package['price']])
            ->toArray();
        $upgradePlanOptions = $currentPlan === 'trial' ? ['basic', 'premium'] : ['premium'];
    @endphp

    <form method="POST" action="{{ route('platform.tenants.update', $tenant->id) }}" class="grid">
        @csrf
        @method('PUT')

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
                @if ($tenant->license?->plan_type === 'premium')
                    <div>
                        <label for="subdomain">Subdomain</label>
                        <input id="subdomain" name="subdomain" value="{{ old('subdomain', $tenant->subdomain) }}">
                        @error('subdomain') <div class="field-error">{{ $message }}</div> @enderror
                    </div>
                @endif
                <div>
                    <label>License</label>
                    <input value="{{ $tenant->license?->plan_type ?? 'trial' }} / {{ $tenant->license?->status ?? $tenant->status }}" disabled>
                </div>
                <div style="grid-column: 1 / -1;">
                    <label>License Key</label>
                    <input value="{{ $tenant->license?->license_key ?? '-' }}" disabled>
                </div>
            </div>
        </section>

        @if ($currentPlan === 'premium')
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

    <section class="panel" style="margin-top: 16px;">
        <h2 style="margin-top: 0; color: var(--color-heading); font-size: 20px;">Plan Requests</h2>
        <p class="muted" style="margin-top: 0;">Create an upgrade or license extension request, then submit payment evidence in Billing Management.</p>
        <div style="display: flex; gap: 10px; flex-wrap: wrap;">
            <button type="button" class="button primary" data-open-dialog="upgrade-request-dialog">Upgrade Plan</button>
            @if ($canExtendLicense)
                <button type="button" class="button secondary" data-open-dialog="extension-request-dialog">License Extension</button>
            @endif
        </div>
    </section>

    <dialog class="platform-dialog" id="upgrade-request-dialog">
        <form method="POST" action="{{ route('platform.tenants.upgrade-request', $tenant->id) }}">
            @csrf
            <div class="dialog-header">
                <h2>Upgrade Plan</h2>
                <button type="button" class="dialog-close" data-close-dialog="upgrade-request-dialog">Close</button>
            </div>
            <div class="grid">
                <div>
                    <label for="requested_plan_type">Requested Plan</label>
                    <select id="requested_plan_type" name="requested_plan_type" required>
                        @foreach ($upgradePlanOptions as $planCode)
                            <option value="{{ $planCode }}" data-monthly-price="{{ $packagePrices[$planCode] ?? 0 }}">
                                {{ ucfirst($planCode) }}
                            </option>
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

    @if ($canExtendLicense)
        <dialog class="platform-dialog" id="extension-request-dialog">
            <form method="POST" action="{{ route('platform.tenants.extension-request', $tenant->id) }}">
                @csrf
                <div class="dialog-header">
                    <h2>License Extension</h2>
                    <button type="button" class="dialog-close" data-close-dialog="extension-request-dialog">Close</button>
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
            const totalPrice = monthlyPrice * upgradeBillingMonths;

            monthlyPriceInput.value = currencyFormatter.format(monthlyPrice) + ' MMK';
            totalPriceInput.value = currencyFormatter.format(totalPrice) + ' MMK';
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

        document.getElementById('requested_plan_type')?.addEventListener('change', updateUpgradePricePreview);
        updateUpgradePricePreview();
    </script>
@endsection
