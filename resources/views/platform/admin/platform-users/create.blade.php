@extends('platform.admin.layouts.app')

@section('title', 'Create Platform User')
@section('pageTitle', 'Create Platform User')
@section('pageDescription', 'Add a platform user account for tenant ownership and billing workflows.')

@section('content')
    <form class="panel" method="POST" action="{{ route('admin.platform-users.store') }}">
        @include('platform.admin.platform-users._form', ['submitLabel' => 'Create User'])
    </form>
@endsection
