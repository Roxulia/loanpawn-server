@props(['tenant'])

<article class="lp-tenant-card">
    <div class="lp-tenant-card-head">
        <div>
            <h2 class="mobile-card-title">{{ $tenant->name }}</h2>
            <p class="mobile-card-kicker">{{ $tenant->tenant_code }}</p>
        </div>
        <span class="badge">{{ $tenant->status }}</span>
    </div>

    <div class="lp-tenant-card-meta">
        <div class="lp-inline-meta">
            <span aria-hidden="true">◆</span>
            <strong>{{ ucfirst($tenant->license?->plan_type ?? 'trial') }}</strong>
        </div>
        <div class="lp-inline-meta">
            <span aria-hidden="true">◷</span>
            <strong>{{ $tenant->license?->expires_at?->format('Y-m-d') ?? '-' }}</strong>
        </div>
    </div>

    <div class="card-action-grid">
        <a href="{{ route('platform.tenants.edit', $tenant->id) }}" class="button secondary">{{ __('app.platform.view.settings') }}</a>
        <form method="POST" action="{{ route('platform.tenants.open-app', $tenant->id) }}" data-open-app-form>
            @csrf
            <button type="submit" class="button primary">{{ __('app.platform.view.open_app') }}</button>
        </form>
    </div>
</article>
