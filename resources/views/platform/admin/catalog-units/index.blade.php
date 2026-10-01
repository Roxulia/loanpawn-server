@extends('platform.admin.layouts.app')

@section('title', 'Catalog Units | LonePawn Admin')
@section('pageTitle', 'Catalog Units')
@section('pageDescription', 'Define units shared by tenants. Tenant administrators can add private units in Settings.')

@section('content')
    <div class="content-stack">
        @if ($errors->any())
            <div class="flash error" role="alert">{{ $errors->first() }}</div>
        @endif

        <section class="content-card">
            <div class="admin-section-heading">
                <div>
                    <p class="admin-section-kicker">Catalog setup</p>
                    <h2>Add shared unit</h2>
                    <p>Tenants can select this unit or add their own tenant-specific units.</p>
                </div>
            </div>

            <form
                method="POST"
                action="{{ route('admin.catalog-units.store') }}"
                class="filter-grid"
            >
                @csrf
                <label>
                    Code
                    <input name="code" maxlength="40" required placeholder="box">
                </label>
                <label>
                    Name
                    <input name="name" maxlength="100" required placeholder="Box">
                </label>
                <label>
                    Symbol (optional)
                    <input name="symbol" maxlength="30">
                </label>
                <button class="button primary" type="submit">Add unit</button>
            </form>
        </section>

        <section class="content-card">
            <div class="admin-section-heading">
                <div>
                    <p class="admin-section-kicker">Shared units</p>
                    <h2>Unit types</h2>
                    <p>{{ count($units) }} configured shared units.</p>
                </div>
            </div>

            <div class="admin-finance-desktop">
                <div class="table-wrap">
                    <table>
                        <thead>
                            <tr>
                                <th>Code</th>
                                <th>Name</th>
                                <th>Symbol</th>
                                <th>Status</th>
                                <th>Save</th>
                            </tr>
                        </thead>
                        <tbody>
                            @forelse ($units as $unit)
                                <tr>
                                    <td>
                                        <strong>{{ $unit->code }}</strong>
                                        @if ($unit->is_system)
                                            <br>System default
                                        @endif
                                    </td>
                                    <td>
                                        <input
                                            form="catalog-unit-{{ $unit->id }}"
                                            name="name"
                                            value="{{ $unit->name }}"
                                            maxlength="100"
                                            required
                                        >
                                    </td>
                                    <td>
                                        <input
                                            form="catalog-unit-{{ $unit->id }}"
                                            name="symbol"
                                            value="{{ $unit->symbol }}"
                                            maxlength="30"
                                        >
                                    </td>
                                    <td>
                                        <label>
                                            <input
                                                form="catalog-unit-{{ $unit->id }}"
                                                type="hidden"
                                                name="is_active"
                                                value="{{ $unit->is_system ? 1 : 0 }}"
                                            >
                                            <input
                                                form="catalog-unit-{{ $unit->id }}"
                                                type="checkbox"
                                                name="is_active"
                                                value="1"
                                                @checked($unit->is_active)
                                                @disabled($unit->is_system)
                                            >
                                            Active
                                        </label>
                                    </td>
                                    <td>
                                        <form
                                            id="catalog-unit-{{ $unit->id }}"
                                            method="POST"
                                            action="{{ route('admin.catalog-units.update', $unit->id) }}"
                                        >
                                            @csrf
                                            @method('PUT')
                                            <button class="button secondary" type="submit">Save</button>
                                        </form>
                                    </td>
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="5">No shared units.</td>
                                </tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </div>

            <div class="admin-finance-mobile">
                @forelse ($units as $unit)
                    <article class="admin-finance-mobile-card">
                        <div>
                            <p class="admin-section-kicker">{{ $unit->code }}</p>
                            <h3>{{ $unit->name }}{{ $unit->symbol ? ' · '.$unit->symbol : '' }}</h3>
                        </div>
                        <form
                            method="POST"
                            action="{{ route('admin.catalog-units.update', $unit->id) }}"
                            class="workflow-stack"
                        >
                            @csrf
                            @method('PUT')
                            <label>
                                Name
                                <input name="name" value="{{ $unit->name }}" maxlength="100" required>
                            </label>
                            <label>
                                Symbol (optional)
                                <input name="symbol" value="{{ $unit->symbol }}" maxlength="30">
                            </label>
                            <label>
                                <input
                                    type="hidden"
                                    name="is_active"
                                    value="{{ $unit->is_system ? 1 : 0 }}"
                                >
                                <input
                                    type="checkbox"
                                    name="is_active"
                                    value="1"
                                    @checked($unit->is_active)
                                    @disabled($unit->is_system)
                                >
                                Active
                            </label>
                            @if ($unit->is_system)
                                <p>System default unit</p>
                            @endif
                            <button class="button secondary" type="submit">Save changes</button>
                        </form>
                    </article>
                @empty
                    <p>No shared units.</p>
                @endforelse
            </div>
        </section>
    </div>
@endsection
