@extends('platform.auth.layout')

@section('title', 'Platform Register')
@section('heading', 'Platform User Register')
@section('description', 'Create a new platform user account.')
@section('heroTitle', 'Create Platform User')
@section('heroText', 'Register a platform user with name, email, and password. Tenant requests and future tenant ownership records are linked from this account.')

@section('content')
    <div id="register-status" class="form-status hidden"></div>

    <form method="POST" action="{{ route('platform.register.submit') }}" class="grid" id="register-form">
        @csrf

        <div class="grid two">
            <div>
                <label for="name">Name</label>
                <input id="name" type="text" name="name" autocomplete="name" required>
                <div class="field-error hidden" data-error-for="name"></div>
            </div>

            <div>
                <label for="email">Email</label>
                <input id="email" type="email" name="email" autocomplete="email" required>
                <div class="field-error hidden" data-error-for="email"></div>
            </div>
        </div>

        <div class="grid two">
            <div>
                <label for="password">Password</label>
                <input id="password" type="password" name="password" autocomplete="new-password" required>
                <div class="field-error hidden" data-error-for="password"></div>
            </div>

            <div>
                <label for="password_confirmation">Confirm Password</label>
                <input id="password_confirmation" type="password" name="password_confirmation" autocomplete="new-password" required>
                <div class="field-error hidden" data-error-for="password_confirmation"></div>
            </div>
        </div>

        <div class="field-error hidden" data-error-for="general"></div>

        <div class="actions">
            <button type="submit" class="primary" id="register-submit">Register</button>
            <a class="button secondary" href="{{ route('platform.login.show') }}">Back to login</a>
        </div>
    </form>

    @push('scripts')
        <script>
            (function () {
                const form = document.getElementById('register-form');
                const submitButton = document.getElementById('register-submit');
                const statusBox = document.getElementById('register-status');

                if (!form || !submitButton || !statusBox) {
                    return;
                }

                const errorNodes = {
                    name: form.querySelector('[data-error-for="name"]'),
                    email: form.querySelector('[data-error-for="email"]'),
                    password: form.querySelector('[data-error-for="password"]'),
                    password_confirmation: form.querySelector('[data-error-for="password_confirmation"]'),
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
                            const message = payload.message || 'Registration failed.';
                            renderErrors(payload.errors || null, message);
                            showStatus('error', message);
                            return;
                        }

                        showStatus('success', payload.message || 'User registered successfully.');

                        if (payload.redirect) {
                            window.setTimeout(function () {
                                window.location.href = payload.redirect;
                            }, 900);
                        }
                    } catch (error) {
                        renderErrors(null, 'Unable to process registration right now.');
                        showStatus('error', 'Unable to process registration right now.');
                    } finally {
                        submitButton.disabled = false;
                    }
                });
            }());
        </script>
    @endpush
@endsection
