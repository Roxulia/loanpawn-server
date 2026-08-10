@extends('platform.admin.layouts.app')
@section('title', 'Exchange Pairs | LonePawn Admin')
@section('pageTitle', 'Default Exchange Pairs')
@section('pageDescription', 'Define explicit base and quote directions available to tenants.')
@section('content')
<div class="content-stack">@if ($errors->any())<div class="flash error">{{ $errors->first() }}</div>@endif
<section class="content-card"><h2>Add pair</h2><form method="POST" action="{{ route('admin.exchange-pairs.store') }}" class="filter-grid">@csrf
<label>Base<select name="base_currency_code">@foreach($currencies as $currency)<option value="{{ $currency->code }}">{{ $currency->code }}</option>@endforeach</select></label>
<label>Quote<select name="quote_currency_code">@foreach($currencies as $currency)<option value="{{ $currency->code }}">{{ $currency->code }}</option>@endforeach</select></label><button class="primary-button">Create pair</button></form></section>
<section class="content-card"><h2>Pair catalog</h2><div class="table-wrap"><table><thead><tr><th>Pair</th><th>Meaning</th><th>State</th><th>Actions</th></tr></thead><tbody>
@forelse($pairs as $pair)<tr><td><strong>{{ $pair->baseCurrency->code }}/{{ $pair->quoteCurrency->code }}</strong></td><td>1 {{ $pair->baseCurrency->code }} = rate × {{ $pair->quoteCurrency->code }}</td><td>{{ $pair->is_active ? 'Active' : 'Inactive' }}</td><td><form method="POST" action="{{ route('admin.exchange-pairs.update', $pair) }}" class="inline-form">@csrf @method('PUT')<input type="hidden" name="update_key" value="{{ $pair->update_key }}"><input type="hidden" name="base_currency_code" value="{{ $pair->baseCurrency->code }}"><input type="hidden" name="quote_currency_code" value="{{ $pair->quoteCurrency->code }}"><input type="hidden" name="is_active" value="0"><label><input type="checkbox" name="is_active" value="1" @checked($pair->is_active)> Active</label><button>Save</button></form><form method="POST" action="{{ route('admin.exchange-pairs.destroy', $pair) }}">@csrf @method('DELETE')<button class="danger-button">Delete</button></form></td></tr>@empty<tr><td colspan="4">No pairs.</td></tr>@endforelse
</tbody></table></div>{{ $pairs->links() }}</section></div>
@endsection
