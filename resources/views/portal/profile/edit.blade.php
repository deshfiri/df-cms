@extends('layouts.portal')
@section('title', 'Profile')

@section('content')
<h5 class="mb-3">Profile</h5>

@if(session('success'))
<div class="alert alert-success" style="font-size:.85rem">{{ session('success') }}</div>
@endif

<div class="card p-4 mb-4">
    <div style="font-size:.68rem;color:var(--text3);text-transform:uppercase">Name</div>
    <div style="font-size:.9rem;font-weight:600" class="mb-2">{{ $portalUser->name }}</div>
    @if($portalUser->email)
        <div style="font-size:.68rem;color:var(--text3);text-transform:uppercase">Email</div>
        <div style="font-size:.85rem" class="mb-2">{{ $portalUser->email }}</div>
    @endif
    @if($portalUser->phone)
        <div style="font-size:.68rem;color:var(--text3);text-transform:uppercase">Phone</div>
        <div style="font-size:.85rem">{{ $portalUser->phone }}</div>
    @endif
</div>

<div class="card p-4">
    <div class="fw-semibold mb-3" style="font-size:.85rem">Change Password</div>
    <form method="POST" action="{{ route('portal.profile.password') }}">
        @csrf
        @method('PUT')
        <div class="mb-3">
            <label class="form-label small">Current Password</label>
            <input type="password" name="current_password" class="form-control @error('current_password') is-invalid @enderror" required>
            @error('current_password')<div class="invalid-feedback">{{ $message }}</div>@enderror
        </div>
        <div class="mb-3">
            <label class="form-label small">New Password</label>
            <input type="password" name="password" class="form-control @error('password') is-invalid @enderror" required>
            @error('password')<div class="invalid-feedback">{{ $message }}</div>@enderror
        </div>
        <div class="mb-3">
            <label class="form-label small">Confirm New Password</label>
            <input type="password" name="password_confirmation" class="form-control" required>
        </div>
        <button type="submit" class="btn btn-primary btn-sm">Update Password</button>
    </form>
</div>
@endsection
