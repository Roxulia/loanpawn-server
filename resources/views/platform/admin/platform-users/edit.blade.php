@extends('platform.admin.layouts.app')

@section('title', 'Edit Platform User')
@section('pageTitle', 'Edit Platform User')
@section('pageDescription', 'Update platform user account data, status, or password.')

@section('content')
    <form class="panel" method="POST" action="{{ route('admin.platform-users.update', $platformUser->id) }}">
        @include('platform.admin.platform-users._form', ['submitLabel' => 'Update User', 'method' => 'PUT'])
    </form>
@endsection
