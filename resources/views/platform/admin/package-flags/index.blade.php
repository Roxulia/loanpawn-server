@extends('platform.admin.layouts.app')

@section('title', 'Feature & Plan Flags')
@section('pageTitle', 'Feature & Plan Flags')
@section('pageDescription', 'Control plan sales availability, global feature availability, and plan feature mappings.')

@section('content')
    <form method="POST" action="{{ route('admin.package-flags.update') }}" class="grid">
        @csrf
        @method('PUT')

        <section class="panel">
            <h2 style="margin-top: 0;">Plan Availability</h2>
            <div class="form-grid">
                @foreach ($packages as $package)
                    <label>
                        <input type="hidden" name="packages[{{ $package->id }}]" value="0">
                        <input type="checkbox" name="packages[{{ $package->id }}]" value="1" @checked($package->is_active)>
                        {{ $package->name }} sales enabled
                    </label>
                @endforeach
            </div>
        </section>

        <section class="panel">
            <h2 style="margin-top: 0;">Global Features</h2>
            <div class="form-grid">
                @foreach ($features as $feature)
                    <label>
                        <input type="hidden" name="features[{{ $feature->id }}]" value="0">
                        <input type="checkbox" name="features[{{ $feature->id }}]" value="1" @checked($feature->is_active)>
                        {{ $feature->name }}
                    </label>
                @endforeach
            </div>
        </section>

        <section class="panel">
            <h2 style="margin-top: 0;">Plan Feature Mappings</h2>
            <div class="table-wrap">
                <table>
                    <thead>
                    <tr>
                        <th>Plan</th>
                        <th>Feature</th>
                        <th>Enabled</th>
                    </tr>
                    </thead>
                    <tbody>
                    @foreach ($packages as $package)
                        @foreach ($package->packageFeatures as $mapping)
                            <tr>
                                <td>{{ $package->name }}</td>
                                <td>{{ $mapping->feature->name }}</td>
                                <td>
                                    <input type="hidden" name="mappings[{{ $mapping->id }}]" value="0">
                                    <input type="checkbox" name="mappings[{{ $mapping->id }}]" value="1" @checked($mapping->is_enabled)>
                                </td>
                            </tr>
                        @endforeach
                    @endforeach
                    </tbody>
                </table>
            </div>
        </section>

        <div>
            <button type="submit" class="button primary">Save Flags</button>
        </div>
    </form>
@endsection
