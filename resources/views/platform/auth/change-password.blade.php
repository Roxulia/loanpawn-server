@extends('platform.auth.layout')

@section('title', 'Change Admin Password')
@section('heading', 'Change Password')
@section('description', 'Set a private admin password before entering the admin workspace.')
@section('heroTitle', 'Admin Password Required')
@section('heroText', 'The seeded admin password must be replaced before platform administration is available.')

@section('content')
    <div id="password-status" class="form-status hidden"></div>

    <form method="POST" action="{{ route('admin.password.update') }}" class="grid" id="password-form">
        @csrf

        <div>
            <label for="current_password">Current Password</label>
            <input id="current_password" type="password" name="current_password" autocomplete="current-password" required>
            <div class="field-error hidden" data-error-for="current_password"></div>
        </div>

        <div>
            <label for="password">New Password</label>
            <input id="password" type="password" name="password" autocomplete="new-password" required>
            <div class="field-error hidden" data-error-for="password"></div>
        </div>

        <div>
            <label for="password_confirmation">Confirm Password</label>
            <input id="password_confirmation" type="password" name="password_confirmation" autocomplete="new-password" required>
            <div class="field-error hidden" data-error-for="password_confirmation"></div>
        </div>

        <div class="field-error hidden" data-error-for="general"></div>

        <div class="actions">
            <button type="submit" class="primary" id="password-submit">Change Password</button>
        </div>
    </form>

    @push('scripts')
        <script>
            (function () {
                const form = document.getElementById('password-form');
                const submitButton = document.getElementById('password-submit');
                const statusBox = document.getElementById('password-status');

                if (!form || !submitButton || !statusBox) {
                    return;
                }

                const errorNodes = {
                    current_password: form.querySelector('[data-error-for="current_password"]'),
                    password: form.querySelector('[data-error-for="password"]'),
                    password_confirmation: form.querySelector('[data-error-for="password_confirmation"]'),
                    general: form.querySelector('[data-error-for="general"]'),
                };

                function clearErrors() {
                    Object.values(errorNodes).forEach(function (node) {
                        node.textContent = '';
                        node.classList.add('hidden');
                    });
                    statusBox.textContent = '';
                    statusBox.className = 'form-status hidden';
                }

                function renderErrors(errors, fallbackMessage) {
                    let hasFieldError = false;

                    Object.keys(errorNodes).forEach(function (key) {
                        if (!errors || !errors[key] || !errorNodes[key]) {
                            return;
                        }

                        errorNodes[key].textContent = Array.isArray(errors[key]) ? errors[key][0] : errors[key];
                        errorNodes[key].classList.remove('hidden');
                        hasFieldError = true;
                    });

                    if (!hasFieldError && fallbackMessage) {
                        errorNodes.general.textContent = fallbackMessage;
                        errorNodes.general.classList.remove('hidden');
                    }
                }

                form.addEventListener('submit', async function (event) {
                    event.preventDefault();
                    clearErrors();
                    submitButton.disabled = true;

                    try {
                        const response = await fetch(form.action, {
                            method: 'POST',
                            headers: {
                                'Accept': 'application/json',
                                'X-Requested-With': 'XMLHttpRequest',
                            },
                            body: new FormData(form),
                        });
                        const payload = await response.json();
                        const responseData = payload.data || {};

                        if (!response.ok) {
                            const message = payload.message || 'Password change failed.';
                            renderErrors(responseData.errors || null, message);
                            statusBox.textContent = message;
                            statusBox.className = 'form-status error';
                            return;
                        }

                        statusBox.textContent = payload.message || 'Password changed.';
                        statusBox.className = 'form-status success';

                        if (responseData.redirect) {
                            window.location.href = responseData.redirect;
                        }
                    } catch (error) {
                        renderErrors(null, 'Unable to change password right now.');
                        statusBox.textContent = 'Unable to change password right now.';
                        statusBox.className = 'form-status error';
                    } finally {
                        submitButton.disabled = false;
                    }
                });
            }());
        </script>
    @endpush
@endsection
