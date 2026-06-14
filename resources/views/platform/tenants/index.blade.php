@extends('platform.layouts.app')

@section('title', __('app.platform.view.tenant_management'))
@section('pageTitle', __('app.platform.view.tenant_management'))
@section('pageDescription', __('app.platform.view.tenant_management_description'))

@section('pageAction')
    <a href="{{ route('platform.tenants.create') }}" class="button primary">{{ __('app.common.view.actions.create_tenant') }}</a>
@endsection

@section('content')
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

    <section class="panel">
        @if ($tenants->total() === 0)
            <div class="empty-state">
                <div>
                    <h2>{{ __('app.platform.view.no_tenants_created') }}</h2>
                    <p>{{ __('app.platform.view.no_tenants_created_description') }}</p>
                    <a href="{{ route('platform.tenants.create') }}" class="button primary">{{ __('app.common.view.actions.create_tenant') }}</a>
                </div>
            </div>
        @else
            <div class="table-wrap">
                <h2 style="margin: 0 0 12px; color: var(--color-heading); font-size: 20px;">{{ __('app.platform.view.tenant_management') }}</h2>
                <table>
                    <thead>
                    <tr>
                        <th>{{ __('app.common.view.labels.name') }}</th>
                        <th>{{ __('app.common.view.labels.code') }}</th>
                        <th>{{ __('app.platform.view.subdomain') }}</th>
                        <th>{{ __('app.common.view.labels.plan') }}</th>
                        <th>{{ __('app.common.view.labels.status') }}</th>
                        <th>{{ __('app.platform.view.branding') }}</th>
                        <th>{{ __('app.platform.view.contact') }}</th>
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
                            <td data-label="{{ __('app.common.view.labels.status') }}"><span class="badge">{{ $tenant->status }}</span></td>
                            <td data-label="{{ __('app.platform.view.branding') }}">{{ $tenant->branding ? __('app.platform.view.configured') : __('app.platform.view.missing') }}</td>
                            <td data-label="{{ __('app.platform.view.contact') }}">{{ $tenant->contact ? __('app.platform.view.configured') : __('app.platform.view.missing') }}</td>
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

            <div class="pagination">
                {{ $tenants->links() }}
            </div>
        @endif
    </section>

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
