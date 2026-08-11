@php($editing = isset($plan) && $plan)
<div><label>Category</label><select name="category_id" required>@foreach($categories as $category)<option value="{{ $category->id }}" @selected($editing && $plan->category_id === $category->id)>{{ $category->name }}</option>@endforeach</select></div>
<div><label>Code</label><input name="code" value="{{ $editing ? $plan->code : '' }}" required @disabled($editing && ($plan->licenses()->exists() || $plan->requestedBy()->exists()))></div>
@if($editing && ($plan->licenses()->exists() || $plan->requestedBy()->exists()))<input type="hidden" name="code" value="{{ $plan->code }}">@endif
<div><label>Name</label><input name="name" value="{{ $editing ? $plan->name : '' }}" required></div>
<div><label>Monthly price (MMK)</label><input type="number" min="0" name="price" value="{{ $editing ? $plan->price : 0 }}" required></div>
<div><label>Rank</label><input type="number" min="0" name="rank" value="{{ $editing ? $plan->rank : 0 }}" required></div>
<div><label>Max slips / month</label><input type="number" min="0" name="max_slip_per_month" value="{{ $editing ? $plan->max_slip_per_month : '' }}"></div>
<div><label>Max staff</label><input type="number" min="0" name="max_staff_count" value="{{ $editing ? $plan->max_staff_count : '' }}"></div>
<div><label>Max accounts</label><input type="number" min="0" name="max_account_count" value="{{ $editing ? $plan->max_account_count : '' }}"></div>
<div><label>Max currency types</label><input type="number" min="0" name="max_currency_type_count" value="{{ $editing ? $plan->max_currency_type_count : '' }}"></div>
<div><label>Max exchange pairs</label><input type="number" min="0" name="max_exchange_pair_count" value="{{ $editing ? $plan->max_exchange_pair_count : '' }}"></div>
<div style="grid-column:1/-1"><label>Description</label><textarea name="description">{{ $editing ? $plan->description : '' }}</textarea></div>
<div><input type="hidden" name="is_trial" value="0"><label><input type="checkbox" name="is_trial" value="1" @checked($editing && $plan->is_trial)> Trial plan</label></div>
<div><input type="hidden" name="is_active" value="0"><label><input type="checkbox" name="is_active" value="1" @checked(!$editing || $plan->is_active)> Active</label></div>
