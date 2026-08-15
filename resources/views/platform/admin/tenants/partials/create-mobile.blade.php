<section class="panel admin-tenant-create-mobile">
    <div class="admin-section-heading"><div><p class="admin-section-kicker">New tenant</p><h2>Provision tenant</h2><p>Create the tenant and its audited free license in one step.</p></div></div>
    <form method="POST" action="{{ route('admin.tenants.store') }}" data-admin-tenant-form>@include('platform.admin.tenants.partials.create-fields')</form>
</section>
