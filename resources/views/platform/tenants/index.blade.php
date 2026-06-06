@extends('platform.layouts.app')

@section('title', __('app.platform.view.tenant_management'))
@section('pageTitle', __('app.platform.view.tenant_management'))
@section('pageDescription', __('app.platform.view.tenant_management_description'))

@section('pageAction')
    <a href="{{ route('platform.tenants.create') }}" class="button primary">{{ __('app.common.view.actions.create_tenant') }}</a>
@endsection

@section('content')
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
                            <td>{{ $tenant->name }}</td>
                            <td>{{ $tenant->tenant_code }}</td>
                            <td>{{ $tenant->subdomain ?? '-' }}</td>
                            <td>{{ $tenant->license?->plan_type ?? 'trial' }}</td>
                            <td><span class="badge">{{ $tenant->status }}</span></td>
                            <td>{{ $tenant->branding ? __('app.platform.view.configured') : __('app.platform.view.missing') }}</td>
                            <td>{{ $tenant->contact ? __('app.platform.view.configured') : __('app.platform.view.missing') }}</td>
                            <td>
                                <a href="{{ route('platform.tenants.edit', $tenant->id) }}" class="button secondary">{{ __('app.platform.view.settings') }}</a>
                                <form method="POST" action="{{ route('platform.tenants.open-app', $tenant->id) }}" style="display:inline;">
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
@endsection
