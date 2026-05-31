@extends('platform.auth.layout')

@section('title', 'Verify Platform Account')
@section('heading', 'Verify Platform Account')
@section('description', 'Enter the verification code sent to your email.')
@section('heroTitle', 'Check Your Email')
@section('heroText', 'Platform registration requires email verification before login.')

@section('content')
    <div id="verify-status" class="form-status hidden"></div>

    <form method="POST" action="{{ route('platform.register.verify-code') }}" class="grid" id="verify-register-form">
        @csrf

        <div>
            <label for="email">Email</label>
            <input id="email" type="email" name="email" value="{{ $email }}" required>
            <div class="field-error hidden" data-error-for="email"></div>
        </div>

        <div>
            <label for="otp">Verification Code</label>
            <input id="otp" type="text" name="otp" inputmode="numeric" maxlength="6" required>
            <div class="field-error hidden" data-error-for="otp"></div>
        </div>

        <div class="field-error hidden" data-error-for="general"></div>

        <div class="actions">
            <button type="submit" class="primary" id="verify-submit">Verify Email</button>
            <button type="button" class="secondary" id="resend-code">Resend Code</button>
            <a class="button secondary" href="{{ route('platform.login.show') }}">Back to login</a>
        </div>
    </form>

    @push('scripts')
        <script>
            (function () {
                const form = document.getElementById('verify-register-form');
                const submitButton = document.getElementById('verify-submit');
                const resendButton = document.getElementById('resend-code');
                const statusBox = document.getElementById('verify-status');

                if (!form || !submitButton || !resendButton || !statusBox) {
                    return;
                }

                const errors = {
                    email: form.querySelector('[data-error-for="email"]'),
                    otp: form.querySelector('[data-error-for="otp"]'),
                    general: form.querySelector('[data-error-for="general"]'),
                };

                function showStatus(type, message) {
                    statusBox.textContent = message;
                    statusBox.className = 'form-status ' + type;
                }

                function clearErrors() {
                    Object.values(errors).forEach(function (node) {
                        node.textContent = '';
                        node.classList.add('hidden');
                    });
                }

                function renderErrors(payload, fallback) {
                    let shown = false;
                    Object.keys(errors).forEach(function (key) {
                        if (!payload || !payload[key]) {
                            return;
                        }
                        errors[key].textContent = Array.isArray(payload[key]) ? payload[key][0] : payload[key];
                        errors[key].classList.remove('hidden');
                        shown = true;
                    });
                    if (!shown && fallback) {
                        errors.general.textContent = fallback;
                        errors.general.classList.remove('hidden');
                    }
                }

                async function submitForm(url, body, button) {
                    clearErrors();
                    button.disabled = true;
                    try {
                        const response = await fetch(url, {
                            method: 'POST',
                            headers: {
                                'Accept': 'application/json',
                                'X-Requested-With': 'XMLHttpRequest',
                            },
                            body,
                        });
                        const payload = await response.json();
                        if (!response.ok) {
                            renderErrors(payload.errors || null, payload.message || 'Request failed.');
                            showStatus('error', payload.message || 'Request failed.');
                            return;
                        }
                        showStatus('success', payload.message || 'Success.');
                        if (payload.redirect) {
                            window.setTimeout(function () {
                                window.location.href = payload.redirect;
                            }, 900);
                        }
                    } catch (error) {
                        renderErrors(null, 'Unable to process verification right now.');
                        showStatus('error', 'Unable to process verification right now.');
                    } finally {
                        button.disabled = false;
                    }
                }

                form.addEventListener('submit', function (event) {
                    event.preventDefault();
                    submitForm(form.action, new FormData(form), submitButton);
                });

                resendButton.addEventListener('click', function () {
                    const body = new FormData();
                    body.append('_token', form.querySelector('[name="_token"]').value);
                    body.append('email', form.querySelector('[name="email"]').value);
                    submitForm('{{ route('platform.register.send-code') }}', body, resendButton);
                });
            }());
        </script>
    @endpush
@endsection
