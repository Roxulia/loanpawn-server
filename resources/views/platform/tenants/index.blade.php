@extends('platform.layouts.app')

@section('title', __('app.platform.view.tenant_management'))
@section('pageTitle', __('app.platform.view.tenant_management'))
@section('pageDescription', __('app.platform.view.tenant_management_description'))

@section('content')
    @php
        $search = $search ?? '';
    @endphp

    <style>
        #tenant-error-dialog {
            position: fixed;
            inset: 50% auto auto 50%;
            transform: translate(-50%, -50%);
            margin: 0;
        }

        .tenant-error-dialog-close {
            width: 38px;
            height: 38px;
            padding: 0;
            font-size: 24px;
            line-height: 1;
        }
    </style>

    <form method="GET" action="{{ route('platform.tenants.index') }}" class="search-panel">
        <input type="search" name="q" value="{{ $search }}" placeholder="Search tenants..." aria-label="Search tenants">
        <button type="submit" class="button primary">Search</button>
    </form>

    <a href="{{ route('platform.tenants.create') }}" class="create-tenant-card mobile-create-tenant-card">
        <span class="create-icon">+</span>
        <span>{{ __('app.common.view.actions.create_tenant') }}</span>
    </a>

    @if ($tenants->total() === 0)
        <section class="panel">
            <div class="empty-state">
                <div>
                    <h2>{{ $search !== '' ? 'No matching tenants found' : __('app.platform.view.no_tenants_created') }}</h2>
                    <p>{{ $search !== '' ? 'Try another name, code, subdomain, status, or plan.' : __('app.platform.view.no_tenants_created_description') }}</p>
                    <a href="{{ route('platform.tenants.create') }}" class="button primary">{{ __('app.common.view.actions.create_tenant') }}</a>
                </div>
            </div>
        </section>
    @else
        <section class="mobile-only-section">
            <div class="mobile-card-list">
                @foreach ($tenants as $tenant)
                    <x-platform.tenant-card :tenant="$tenant" />
                @endforeach
            </div>
        </section>

        <section class="panel desktop-table-panel">
            <div class="desktop-panel-heading">
                <h2>{{ __('app.platform.view.tenant_management') }}</h2>
                <a href="{{ route('platform.tenants.create') }}" class="button primary icon-button-text">
                    <span aria-hidden="true">+</span>
                    <span>{{ __('app.common.view.actions.create_tenant') }}</span>
                </a>
            </div>
            <div class="table-wrap">
                <table>
                    <thead>
                    <tr>
                        <th>{{ __('app.common.view.labels.name') }}</th>
                        <th>{{ __('app.common.view.labels.code') }}</th>
                        <th>{{ __('app.platform.view.subdomain') }}</th>
                        <th>{{ __('app.common.view.labels.plan') }}</th>
                        <th>{{ __('app.platform.view.expiry') }}</th>
                        <th>{{ __('app.common.view.labels.status') }}</th>
                        <th></th>
                    </tr>
                    </thead>
                    <tbody>
                    @foreach ($tenants as $tenant)
                        <tr>
                            <td data-label="{{ __('app.common.view.labels.name') }}">{{ $tenant->name }}</td>
                            <td data-label="{{ __('app.common.view.labels.code') }}">{{ $tenant->tenant_code }}</td>
                            <td data-label="{{ __('app.platform.view.subdomain') }}">{{ $tenant->subdomain ?? '-' }}</td>
                            <td data-label="{{ __('app.common.view.labels.plan') }}">{{ $tenant->license?->plan_type ?? 'trial' }}</td>
                            <td data-label="{{ __('app.platform.view.expiry') }}">{{ $tenant->license?->expires_at?->format('Y-m-d') ?? '-' }}</td>
                            <td data-label="{{ __('app.common.view.labels.status') }}"><span class="badge">{{ $tenant->status }}</span></td>
                            <td data-label="">
                                <a href="{{ route('platform.tenants.edit', $tenant->id) }}" class="button secondary">{{ __('app.platform.view.settings') }}</a>
                                <form method="POST" action="{{ route('platform.tenants.open-app', $tenant->id) }}" style="display:inline;" data-open-app-form>
                                    @csrf
                                    <button type="submit" class="button secondary">{{ __('app.platform.view.open_app') }}</button>
                                </form>
                            </td>
                        </tr>
                    @endforeach
                    </tbody>
                </table>
            </div>
        </section>

        <div class="pagination">
            {{ $tenants->links() }}
        </div>
    @endif

    <dialog class="platform-dialog" id="tenant-error-dialog" aria-labelledby="tenant-error-dialog-title">
        <div class="dialog-header">
            <h2 id="tenant-error-dialog-title">{{ __('app.common.response.failed') }}</h2>
            <button type="button" class="dialog-close tenant-error-dialog-close" data-close-tenant-error-dialog aria-label="{{ __('app.common.view.actions.close') }}">&times;</button>
        </div>
        <p id="tenant-error-dialog-message" style="margin: 0; line-height: 1.6;"></p>
        <div style="margin-top: 16px; display: flex; justify-content: flex-end;">
            <button type="button" class="button primary" data-close-tenant-error-dialog>OK</button>
        </div>
    </dialog>
@endsection

@push('scripts')
    <script>
        const tenantErrorDialog = document.getElementById('tenant-error-dialog');
        const tenantErrorDialogMessage = document.getElementById('tenant-error-dialog-message');
        const defaultTenantErrorMessage = @json(__('app.common.response.failed'));

        function showTenantErrorDialog(message) {
            const errorMessage = message || defaultTenantErrorMessage;

            if (!tenantErrorDialog || !tenantErrorDialogMessage || typeof tenantErrorDialog.showModal !== 'function') {
                alert(errorMessage);
                return;
            }

            tenantErrorDialogMessage.textContent = errorMessage;
            tenantErrorDialog.showModal();
        }

        document.querySelectorAll('[data-close-tenant-error-dialog]').forEach((button) => {
            button.addEventListener('click', () => {
                tenantErrorDialog?.close();
            });
        });

        tenantErrorDialog?.addEventListener('click', (event) => {
            if (event.target === tenantErrorDialog) {
                tenantErrorDialog.close();
            }
        });

        @if (session('error'))
            showTenantErrorDialog(@json(session('error')));
        @endif

        document.querySelectorAll('[data-open-app-form]').forEach((form) => {
            form.addEventListener('submit', async (event) => {
                event.preventDefault();

                const button = form.querySelector('button[type="submit"]');

                if (button) {
                    button.disabled = true;
                }

                try {
                    const response = await fetch(form.action, {
                        method: form.method || 'POST',
                        headers: {
                            'Accept': 'application/json',
                        },
                        body: new FormData(form),
                    });
                    const contentType = response.headers.get('content-type') || '';
                    const payload = contentType.includes('application/json')
                        ? await response.json()
                        : null;

                    if (response.ok && payload?.success && payload?.data?.redirect_url) {
                        window.location.href = payload.data.redirect_url;
                        return;
                    }

                    showTenantErrorDialog(payload?.message);
                } catch (error) {
                    showTenantErrorDialog();
                } finally {
                    if (button) {
                        button.disabled = false;
                    }
                }
            });
        });
    </script>
@endpush
