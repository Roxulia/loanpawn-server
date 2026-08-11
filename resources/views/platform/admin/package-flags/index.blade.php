@extends('platform.admin.layouts.app')

@section('title', 'Feature & Plan Flags')
@section('pageTitle', 'Feature & Plan Flags')
@section('pageDescription', 'Control plan sales availability, global feature availability, and plan feature mappings.')

@section('content')
    @php
        $assignmentPackages = $packages->where('is_deleted', false);
    @endphp

    <style>
        .flag-stack {
            display: grid;
            gap: 16px;
        }
        .section-heading {
            display: flex;
            align-items: flex-start;
            justify-content: space-between;
            gap: 16px;
            margin-bottom: 16px;
        }
        .section-heading h2 {
            margin: 0;
            color: var(--color-heading);
            font-size: 20px;
        }
        .flag-list {
            display: grid;
            gap: 10px;
        }
        .flag-row {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 16px;
            padding: 13px 14px;
            border: 1px solid var(--color-border);
            border-radius: 8px;
            background: var(--color-background);
        }
        .flag-title {
            margin: 0;
            color: var(--color-heading);
            font-weight: 800;
        }
        .flag-description {
            margin: 4px 0 0;
            color: var(--color-text-muted);
            font-size: 13px;
            line-height: 1.5;
        }
        .plan-limit-fields {
            display: grid;
            grid-template-columns: repeat(2, minmax(130px, 1fr));
            gap: 12px;
            min-width: min(360px, 100%);
        }
        .plan-limit-fields label {
            margin-bottom: 5px;
        }
        .plan-limit-fields input {
            width: 100%;
        }
        .switch {
            position: relative;
            display: inline-flex;
            width: 48px;
            height: 28px;
            flex: 0 0 auto;
            margin: 0;
        }
        .switch input {
            position: absolute;
            inset: 0;
            opacity: 0;
            cursor: pointer;
        }
        .switch-track {
            width: 100%;
            border-radius: 999px;
            background: var(--color-border-strong);
            transition: background 160ms ease;
        }
        .switch-track::after {
            content: "";
            position: absolute;
            top: 4px;
            left: 4px;
            width: 20px;
            height: 20px;
            border-radius: 999px;
            background: var(--color-surface);
            box-shadow: 0 2px 5px rgba(3, 0, 61, 0.2);
            transition: transform 160ms ease;
        }
        .switch input:checked + .switch-track {
            background: var(--color-primary);
        }
        .switch input:checked + .switch-track::after {
            transform: translateX(20px);
        }
        .flag-actions {
            display: flex;
            gap: 10px;
            flex-wrap: wrap;
            margin-top: 16px;
        }
        .tabs {
            display: inline-flex;
            flex-wrap: wrap;
            gap: 8px;
            margin-bottom: 14px;
        }
        .tab-button {
            border: 1px solid var(--color-border-strong);
            border-radius: 8px;
            padding: 9px 12px;
            background: var(--color-surface);
            color: var(--color-heading);
            cursor: pointer;
            font: inherit;
            font-weight: 800;
        }
        .tab-button.active {
            background: var(--color-primary);
            border-color: var(--color-primary);
            color: var(--color-on-primary);
        }
        .tab-panel[hidden] {
            display: none;
        }
        .platform-dialog {
            width: min(640px, calc(100vw - 32px));
            max-height: calc(100vh - 24px);
            overflow-y: auto;
            position: fixed;
            inset: 50% auto auto 50%;
            transform: translate(-50%, -50%);
            margin: 0;
            border: 1px solid var(--color-border);
            border-radius: 8px;
            padding: 20px;
            background: var(--color-surface);
            color: var(--color-text);
        }
        .platform-dialog::backdrop {
            background: rgba(3, 0, 61, 0.38);
        }
        .dialog-header {
            display: flex;
            align-items: flex-start;
            justify-content: space-between;
            gap: 16px;
            margin-bottom: 16px;
        }
        .dialog-header h2 {
            margin: 0;
            color: var(--color-heading);
            font-size: 20px;
        }
        .dialog-close {
            width: 38px;
            height: 38px;
            border: 1px solid var(--color-border-strong);
            border-radius: 8px;
            padding: 0;
            background: var(--color-surface);
            color: var(--color-heading);
            cursor: pointer;
            font-size: 24px;
            line-height: 1;
        }
        @media (max-width: 640px) {
            .section-heading,
            .flag-row {
                display: grid;
            }
            .plan-limit-fields {
                min-width: 0;
            }
            .flag-actions,
            .flag-actions .button {
                width: 100%;
            }
            .switch {
                justify-self: start;
            }
        }
    </style>

    <div class="flag-stack">
        <form method="POST" action="{{ route('admin.package-flags.plans.update') }}" class="panel" data-resettable-form>
            @csrf
            <div class="section-heading">
                <h2>Plan</h2>
            </div>
            <div class="flag-list">
                @foreach ($packages as $package)
                    <div class="flag-row">
                        <div>
                            <p class="flag-title">{{ $package->name }}</p>
                            <p class="flag-description">{{ $package->description ?? 'Plan sales availability' }}</p>
                        </div>
                        <div class="plan-limit-fields">
                            <div>
                                <label for="max_slip_per_month_{{ $package->id }}">Max slips / month</label>
                                <input
                                    id="max_slip_per_month_{{ $package->id }}"
                                    type="number"
                                    min="0"
                                    name="max_slip_per_month[{{ $package->id }}]"
                                    value="{{ old('max_slip_per_month.'.$package->id, $package->max_slip_per_month) }}"
                                    placeholder="Unlimited"
                                >
                                @error('max_slip_per_month.'.$package->id) <div class="field-error">{{ $message }}</div> @enderror
                            </div>
                            <div>
                                <label for="max_staff_count_{{ $package->id }}">Max staff</label>
                                <input
                                    id="max_staff_count_{{ $package->id }}"
                                    type="number"
                                    min="0"
                                    name="max_staff_count[{{ $package->id }}]"
                                    value="{{ old('max_staff_count.'.$package->id, $package->max_staff_count) }}"
                                    placeholder="Unlimited"
                                >
                                @error('max_staff_count.'.$package->id) <div class="field-error">{{ $message }}</div> @enderror
                            </div>
                            <div>
                                <label for="max_account_count_{{ $package->id }}">Max accounts</label>
                                <input id="max_account_count_{{ $package->id }}" type="number" min="1" name="max_account_count[{{ $package->id }}]" value="{{ old('max_account_count.'.$package->id, $package->max_account_count) }}" placeholder="Unlimited">
                                @error('max_account_count.'.$package->id) <div class="field-error">{{ $message }}</div> @enderror
                            </div>
                            <div>
                                <label for="max_currency_type_count_{{ $package->id }}">Max currency types</label>
                                <input id="max_currency_type_count_{{ $package->id }}" type="number" min="0" name="max_currency_type_count[{{ $package->id }}]" value="{{ old('max_currency_type_count.'.$package->id, $package->max_currency_type_count) }}" placeholder="Unlimited">
                                @error('max_currency_type_count.'.$package->id) <div class="field-error">{{ $message }}</div> @enderror
                            </div>
                            <div>
                                <label for="max_exchange_pair_count_{{ $package->id }}">Max exchange pairs</label>
                                <input id="max_exchange_pair_count_{{ $package->id }}" type="number" min="0" name="max_exchange_pair_count[{{ $package->id }}]" value="{{ old('max_exchange_pair_count.'.$package->id, $package->max_exchange_pair_count) }}" placeholder="Unlimited">
                                @error('max_exchange_pair_count.'.$package->id) <div class="field-error">{{ $message }}</div> @enderror
                            </div>
                            <label class="switch" aria-label="{{ $package->name }} status">
                                <input type="hidden" name="packages[{{ $package->id }}]" value="0">
                                <input type="checkbox" name="packages[{{ $package->id }}]" value="1" @checked($package->is_active)>
                                <span class="switch-track"></span>
                            </label>
                        </div>
                    </div>
                @endforeach
            </div>
            <div class="flag-actions">
                <button type="submit" class="button primary">Save</button>
                <button type="reset" class="button secondary">Cancel</button>
            </div>
        </form>

        <section class="panel">
            <div class="section-heading">
                <h2>Feature Management</h2>
                <button type="button" class="button primary" data-open-dialog="add-feature-dialog">Add Feature</button>
            </div>

            <form method="POST" action="{{ route('admin.package-flags.features.update') }}" data-resettable-form>
                @csrf
                @method('PUT')
                <div class="flag-list">
                    @foreach ($features as $feature)
                        <div class="flag-row">
                            <div>
                                <p class="flag-title">{{ $feature->name }}</p>
                                <p class="flag-description">{{ $feature->code }}{{ $feature->description ? ' - '.$feature->description : '' }}</p>
                            </div>
                            <label class="switch" aria-label="{{ $feature->name }} status">
                                <input type="hidden" name="features[{{ $feature->id }}]" value="0">
                                <input type="checkbox" name="features[{{ $feature->id }}]" value="1" @checked($feature->is_active)>
                                <span class="switch-track"></span>
                            </label>
                        </div>
                    @endforeach
                </div>
                <div class="flag-actions">
                    <button type="submit" class="button primary">Save</button>
                    <button type="reset" class="button secondary">Cancel</button>
                </div>
            </form>
        </section>

        <form method="POST" action="{{ route('admin.package-flags.feature-assignment.update') }}" class="panel" data-resettable-form>
            @csrf
            <div class="section-heading">
                <h2>Feature Assignment</h2>
            </div>

            <div class="tabs" role="tablist" aria-label="Plan feature assignments">
                @foreach ($assignmentPackages as $package)
                    <button
                        type="button"
                        class="tab-button @if ($loop->first) active @endif"
                        id="tab-{{ $package->code }}"
                        role="tab"
                        aria-selected="{{ $loop->first ? 'true' : 'false' }}"
                        aria-controls="panel-{{ $package->code }}"
                        data-tab-target="panel-{{ $package->code }}"
                    >
                        {{ $package->name }}
                    </button>
                @endforeach
            </div>

            @foreach ($assignmentPackages as $package)
                @php
                    $assignmentFlags = $package->packageFeatures->mapWithKeys(fn ($mapping) => [$mapping->feature_id => $mapping->is_enabled]);
                @endphp
                <div
                    id="panel-{{ $package->code }}"
                    class="tab-panel"
                    role="tabpanel"
                    aria-labelledby="tab-{{ $package->code }}"
                    @if (! $loop->first) hidden @endif
                >
                    <div class="flag-list">
                        @foreach ($features as $feature)
                            <div class="flag-row">
                                <div>
                                    <p class="flag-title">{{ $feature->name }}</p>
                                    <p class="flag-description">{{ $feature->description ?? $feature->code }}</p>
                                </div>
                                <label class="switch" aria-label="{{ $package->name }} {{ $feature->name }} assignment">
                                    <input type="hidden" name="assignments[{{ $package->id }}][{{ $feature->id }}]" value="0">
                                    <input
                                        type="checkbox"
                                        name="assignments[{{ $package->id }}][{{ $feature->id }}]"
                                        value="1"
                                        @checked((bool) ($assignmentFlags[$feature->id] ?? false))
                                    >
                                    <span class="switch-track"></span>
                                </label>
                            </div>
                        @endforeach
                    </div>
                </div>
            @endforeach

            <div class="flag-actions">
                <button type="submit" class="button primary">Save</button>
                <button type="reset" class="button secondary">Cancel</button>
            </div>
        </form>
    </div>

    <dialog class="platform-dialog" id="add-feature-dialog">
        <form method="POST" action="{{ route('admin.package-flags.features.store') }}">
            @csrf
            <div class="dialog-header">
                <h2>Add Feature</h2>
                <button type="button" class="dialog-close" data-close-dialog="add-feature-dialog" aria-label="Close">&times;</button>
            </div>
            <div class="form-grid">
                <div>
                    <label for="feature_name">Feature Name</label>
                    <input id="feature_name" name="name" value="{{ old('name') }}" required maxlength="120">
                    @error('name') <div class="field-error">{{ $message }}</div> @enderror
                </div>
                <div>
                    <label for="feature_code">Feature Code</label>
                    <input id="feature_code" name="code" value="{{ old('code') }}" required maxlength="80">
                    @error('code') <div class="field-error">{{ $message }}</div> @enderror
                </div>
                <div style="grid-column: 1 / -1;">
                    <label for="feature_description">Description</label>
                    <textarea id="feature_description" name="description">{{ old('description') }}</textarea>
                    @error('description') <div class="field-error">{{ $message }}</div> @enderror
                </div>
            </div>
            <div class="flag-actions">
                <button type="submit" class="button primary">Submit</button>
                <button type="button" class="button secondary" data-close-dialog="add-feature-dialog">Cancel</button>
            </div>
        </form>
    </dialog>

    <script>
        document.querySelectorAll('[data-open-dialog]').forEach(function (button) {
            button.addEventListener('click', function () {
                document.getElementById(button.dataset.openDialog)?.showModal();
            });
        });

        document.querySelectorAll('[data-close-dialog]').forEach(function (button) {
            button.addEventListener('click', function () {
                document.getElementById(button.dataset.closeDialog)?.close();
            });
        });

        document.querySelectorAll('[data-tab-target]').forEach(function (button) {
            button.addEventListener('click', function () {
                const targetId = button.dataset.tabTarget;

                document.querySelectorAll('[data-tab-target]').forEach(function (tab) {
                    const isActive = tab === button;
                    tab.classList.toggle('active', isActive);
                    tab.setAttribute('aria-selected', isActive ? 'true' : 'false');
                });

                document.querySelectorAll('.tab-panel').forEach(function (panel) {
                    panel.hidden = panel.id !== targetId;
                });
            });
        });

        document.querySelectorAll('[data-resettable-form]').forEach(function (form) {
            form.addEventListener('reset', function () {
                window.setTimeout(function () {
                    form.querySelectorAll('input[type="checkbox"]').forEach(function (checkbox) {
                        checkbox.checked = checkbox.defaultChecked;
                    });
                });
            });
        });

        @if ($errors->has('name') || $errors->has('code') || $errors->has('description'))
            document.getElementById('add-feature-dialog')?.showModal();
        @endif
    </script>
@endsection
