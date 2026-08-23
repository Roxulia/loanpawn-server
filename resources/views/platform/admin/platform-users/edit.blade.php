@extends('platform.admin.layouts.app')

@section('title', 'Edit Platform User')
@section('pageTitle', 'Edit Platform User')
@section('pageDescription', 'Update platform user account data or status.')

@section('pageAction')
    <form method="POST" action="{{ route('admin.platform-users.reset-password', $platformUser->id) }}"
          onsubmit="return confirm('Reset this user password to the configured default password?')">
        @csrf
        <button type="submit" class="button danger">Reset Password</button>
    </form>
@endsection

@section('content')
    <form class="panel" method="POST" action="{{ route('admin.platform-users.update', $platformUser->id) }}">
        @include('platform.admin.platform-users._form', ['submitLabel' => 'Update User', 'method' => 'PUT'])
    </form>
@endsection
