@csrf
@if ($method ?? false)
    @method($method)
@endif

<div class="form-grid">
    @isset($platformUser)
        <div>
            <label>Code</label>
            <input value="{{ $platformUser->code ?? '-' }}" disabled>
        </div>
    @endisset

    <div>
        <label for="name">Name</label>
        <input id="name" name="name" value="{{ old('name', $platformUser->name ?? '') }}" required>
        @error('name') <div class="field-error">{{ $message }}</div> @enderror
    </div>

    <div>
        <label for="email">Email</label>
        <input id="email" type="email" name="email" value="{{ old('email', $platformUser->email ?? '') }}" required>
        @error('email') <div class="field-error">{{ $message }}</div> @enderror
    </div>

    <div>
        <label for="phone">Phone</label>
        <input id="phone" name="phone" value="{{ old('phone', $platformUser->phone ?? '') }}">
        @error('phone') <div class="field-error">{{ $message }}</div> @enderror
    </div>

    <div>
        <label for="status">Status</label>
        <select id="status" name="status" required>
            @foreach (['active', 'inactive', 'suspended'] as $status)
                <option value="{{ $status }}" @selected(old('status', $platformUser->status ?? 'active') === $status)>
                    {{ ucfirst($status) }}
                </option>
            @endforeach
        </select>
        @error('status') <div class="field-error">{{ $message }}</div> @enderror
    </div>

    <div>
        <label for="password">{{ isset($platformUser) ? 'New Password' : 'Password' }}</label>
        <input id="password" type="password" name="password" autocomplete="new-password" {{ isset($platformUser) ? '' : 'required' }}>
        @error('password') <div class="field-error">{{ $message }}</div> @enderror
    </div>

    <div>
        <label for="password_confirmation">Confirm Password</label>
        <input id="password_confirmation" type="password" name="password_confirmation" autocomplete="new-password" {{ isset($platformUser) ? '' : 'required' }}>
    </div>
</div>

<div style="margin-top: 16px;">
    <button type="submit" class="button primary">{{ $submitLabel }}</button>
    <a href="{{ route('admin.platform-users.index') }}" class="button secondary">Cancel</a>
</div>
