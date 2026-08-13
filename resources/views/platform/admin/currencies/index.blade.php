@extends('platform.admin.layouts.app')
@section('title', 'Currencies | LonePawn Admin')
@section('pageTitle', 'Default Currencies')
@section('pageDescription', 'Manage the read-only currency catalog inherited by tenants.')
@section('content')
<div class="content-stack">
    @if ($errors->any())<div class="flash error">{{ $errors->first() }}</div>@endif
    <section class="content-card">
        <h2>Add currency</h2>
        <form method="POST" action="{{ route('admin.currencies.store') }}" class="filter-grid">@csrf
            <label>Code<input name="code" maxlength="12" required></label>
            <label>Name<input name="name" maxlength="120" required></label>
            <label>Symbol<input name="symbol" maxlength="12"></label>
            <label>Decimals<input name="decimal_precision" type="number" min="0" max="8" value="2" required></label>
            <label>Rounding<select name="rounding_mode"><option>HALF_UP</option><option>HALF_DOWN</option><option>HALF_EVEN</option><option>UP</option><option>DOWN</option></select></label>
            <label>Adjustment step<input name="adjustment_step" type="number" min="0" step="0.00000001"></label>
            <button type="submit" class="primary-button">Create currency</button>
        </form>
    </section>
    <section class="content-card"><h2>Currency catalog</h2>
        <div class="table-wrap"><table><thead><tr><th>Currency</th><th>Precision</th><th>State</th><th>Actions</th></tr></thead><tbody>
        @forelse($currencies as $currency)<tr><td><strong>{{ $currency->code }}</strong><br>{{ $currency->name }} ({{ $currency->symbol ?: '-' }})</td><td>{{ $currency->decimal_precision }} / {{ $currency->rounding_mode }}</td><td>{{ $currency->is_active ? 'Active' : 'Inactive' }}</td><td>
            <form method="POST" action="{{ route('admin.currencies.update', $currency) }}" class="inline-form">@csrf @method('PUT')
                <input type="hidden" name="update_key" value="{{ $currency->update_key }}"><input name="code" value="{{ $currency->code }}" required><input name="name" value="{{ $currency->name }}" required><input name="symbol" value="{{ $currency->symbol }}"><input name="decimal_precision" type="number" min="0" max="8" value="{{ $currency->decimal_precision }}" required><select name="rounding_mode">@foreach(['HALF_UP','HALF_DOWN','HALF_EVEN','UP','DOWN'] as $mode)<option @selected($currency->rounding_mode === $mode)>{{ $mode }}</option>@endforeach</select><input name="adjustment_step" value="{{ $currency->adjustment_step }}" placeholder="Adjustment step"><input type="hidden" name="is_active" value="0"><label><input type="checkbox" name="is_active" value="1" @checked($currency->is_active)> Active</label><button>Save</button>
            </form>
            <form method="POST" action="{{ route('admin.currencies.destroy', $currency) }}">@csrf @method('DELETE')<button class="danger-button">Delete</button></form>
        </td></tr>@empty<tr><td colspan="4">No currencies.</td></tr>@endforelse
        </tbody></table></div>{{ $currencies->links() }}
    </section>
</div>
@endsection
