<section class="panel admin-tenant-create-desktop">
    <div class="admin-section-heading"><div><p class="admin-section-kicker">Organization provisioning</p><h2>Tenant and license details</h2><p>The license starts immediately and is recorded as a zero-cost admin grant.</p></div></div>
    <form method="POST" action="{{ route('admin.tenants.store') }}" data-admin-tenant-form>@include('platform.admin.tenants.partials.create-fields')</form>
</section>
