@extends('platform.admin.layouts.app')
@section('title', 'Currencies | LonePawn Admin')
@section('pageTitle', 'Default Currencies')
@section('pageDescription', 'Manage the read-only currency catalog inherited by tenants.')
@section('content')
<div class="content-stack admin-currency-page">
    @if ($errors->any())<div class="flash error admin-finance-page-error" role="alert">{{ $errors->first() }}</div>@endif
    <section class="content-card admin-finance-create-card">
        <div class="admin-section-heading"><div><p class="admin-section-kicker">Catalog setup</p><h2>Add currency</h2><p>Define a currency available to tenants.</p></div></div>
        <form method="POST" action="{{ route('admin.currencies.store') }}" class="filter-grid">@csrf
            <label>Code<input name="code" maxlength="12" value="{{ old('code') }}" required></label>
            <label>Name<input name="name" maxlength="120" value="{{ old('name') }}" required></label>
            <label>Symbol<input name="symbol" maxlength="12" value="{{ old('symbol') }}"></label>
            <div class="admin-finance-create-actions"><button type="submit" class="button primary">Create currency</button></div>
        </form>
    </section>
    <section class="content-card admin-finance-list-card">
        <div class="admin-section-heading"><div><p class="admin-section-kicker">Global defaults</p><h2>Currency catalog</h2><p>{{ $currencies->total() }} configured currencies.</p></div></div>
        <div class="admin-finance-desktop"><div class="table-wrap"><table><thead><tr><th>Currency</th><th>Symbol</th><th>State</th><th>Actions</th></tr></thead><tbody>
        @forelse($currencies as $currency)<tr><td><strong>{{ $currency->code }}</strong><br>{{ $currency->name }}</td><td>{{ $currency->symbol ?: '-' }}</td><td><span class="badge" data-tone="{{ $currency->is_active ? 'success' : 'warning' }}">{{ $currency->is_active ? 'Active' : 'Inactive' }}</span></td><td><div class="action-row"><button type="button" class="button secondary" data-open-dialog="currency-edit-{{ $currency->id }}">Edit</button><button type="button" class="button danger" data-open-dialog="currency-delete-{{ $currency->id }}">Delete</button></div></td></tr>@empty<tr><td colspan="4">No currencies.</td></tr>@endforelse
        </tbody></table></div></div>
        <div class="admin-finance-mobile">@forelse($currencies as $currency)<article class="admin-finance-mobile-card"><div><p class="admin-section-kicker">{{ $currency->code }}</p><h3>{{ $currency->name }} · {{ $currency->symbol ?: '-' }}</h3></div><dl><div><dt>Status</dt><dd><span class="badge" data-tone="{{ $currency->is_active ? 'success' : 'warning' }}">{{ $currency->is_active ? 'Active' : 'Inactive' }}</span></dd></div></dl><div class="admin-finance-mobile-actions"><button type="button" class="button secondary" data-open-dialog="currency-edit-{{ $currency->id }}">Edit</button><button type="button" class="button danger" data-open-dialog="currency-delete-{{ $currency->id }}">Delete</button></div></article>@empty<p>No currencies.</p>@endforelse</div>
        {{ $currencies->links() }}
    </section>
</div>
@foreach($currencies as $currency)
<dialog class="platform-dialog admin-finance-dialog" id="currency-edit-{{ $currency->id }}"><form method="POST" action="{{ route('admin.currencies.update', $currency) }}">@csrf @method('PUT')<input type="hidden" name="dialog_id" value="currency-edit-{{ $currency->id }}"><input type="hidden" name="update_key" value="{{ $currency->update_key }}"><div class="dialog-header"><h2>Edit {{ $currency->code }}</h2><button type="button" class="dialog-close" data-close-dialog="currency-edit-{{ $currency->id }}">&times;</button></div><div class="form-grid"><label>Code<input name="code" value="{{ $currency->code }}" required></label><label>Name<input name="name" value="{{ $currency->name }}" required></label><label>Symbol<input name="symbol" value="{{ $currency->symbol }}"></label><label class="admin-check-row"><input type="hidden" name="is_active" value="0"><input type="checkbox" name="is_active" value="1" @checked($currency->is_active)> Active</label></div><div class="action-row admin-dialog-actions"><button type="button" class="button secondary" data-close-dialog="currency-edit-{{ $currency->id }}">Cancel</button><button type="submit" class="button primary">Save changes</button></div></form></dialog>
<dialog class="platform-dialog admin-finance-dialog" id="currency-delete-{{ $currency->id }}"><form method="POST" action="{{ route('admin.currencies.destroy', $currency) }}">@csrf @method('DELETE')<input type="hidden" name="dialog_id" value="currency-delete-{{ $currency->id }}"><div class="dialog-header"><h2>Delete {{ $currency->code }}?</h2><button type="button" class="dialog-close" data-close-dialog="currency-delete-{{ $currency->id }}">&times;</button></div><p>This action is only allowed when the currency is not referenced.</p><div class="action-row admin-dialog-actions"><button type="button" class="button secondary" data-close-dialog="currency-delete-{{ $currency->id }}">Cancel</button><button type="submit" class="button danger">Delete currency</button></div></form></dialog>
@endforeach
@include('platform.admin.partials.finance-dialog-script')
@endsection
