@extends('platform.auth.layout')

@section('title', ($isAdmin ?? false) ? 'Admin Login' : 'Platform Login')
@section('heading', 'Login')
@section('description', ($isAdmin ?? false) ? 'Login with your platform admin account.' : 'Login with your platform user account.')
@section('heroTitle', ($isAdmin ?? false) ? 'Admin Login' : 'Platform User Login')
@section('heroText', ($isAdmin ?? false) ? 'Use your admin account to manage platform tenants, users, billing, and payment approvals.' : 'Use your platform account to access tenant onboarding, payment review, and license-related workflows.')

@section('content')
    <div id="login-status" class="form-status hidden"></div>

    <form method="POST" action="{{ ($isAdmin ?? false) ? route('admin.login.submit') : route('platform.login.submit') }}" class="grid" id="login-form">
        @csrf

        <div>
            <label for="email">Email</label>
            <input id="email" type="email" name="email" autocomplete="email" required>
            <div class="field-error hidden" data-error-for="email"></div>
        </div>

        <div>
            <label for="password">Password</label>
            <input id="password" type="password" name="password" autocomplete="current-password" required>
            <div class="field-error hidden" data-error-for="password"></div>
        </div>

        <div class="field-error hidden" data-error-for="general"></div>

        <div class="actions">
            <button type="submit" class="primary" id="login-submit">Login</button>
        </div>
    </form>

    @if (! ($isAdmin ?? false))
        <div class="sub-links">
            <a href="{{ route('platform.password.forgot') }}">Forgot password</a>
            <a href="{{ route('platform.register.show') }}">Create platform user account</a>
        </div>
    @endif

    @push('scripts')
        <script>
            (function () {
                const form = document.getElementById('login-form');
                const submitButton = document.getElementById('login-submit');
                const statusBox = document.getElementById('login-status');

                if (!form || !submitButton || !statusBox) {
                    return;
                }

                const errorNodes = {
                    email: form.querySelector('[data-error-for="email"]'),
                    password: form.querySelector('[data-error-for="password"]'),
                    general: form.querySelector('[data-error-for="general"]'),
                };

                function hideStatus() {
                    statusBox.textContent = '';
                    statusBox.className = 'form-status hidden';
                }

                function showStatus(type, message) {
                    statusBox.textContent = message;
                    statusBox.className = 'form-status ' + type;
                }

                function clearErrors() {
                    Object.values(errorNodes).forEach(function (node) {
                        node.textContent = '';
                        node.classList.add('hidden');
                    });
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
                    hideStatus();
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

                        if (!response.ok) {
                            const message = payload.message || 'Login failed.';
                            renderErrors(payload.errors || null, message);
                            showStatus('error', message);
                            if (payload.redirect) {
                                window.location.href = payload.redirect;
                            }
                            return;
                        }

                        showStatus('success', payload.message || 'Login success.');

                        if (payload.redirect) {
                            window.location.href = payload.redirect;
                        }
                    } catch (error) {
                        renderErrors(null, 'Unable to process login right now.');
                        showStatus('error', 'Unable to process login right now.');
                    } finally {
                        submitButton.disabled = false;
                    }
                });
            }());
        </script>
    @endpush
@endsection
