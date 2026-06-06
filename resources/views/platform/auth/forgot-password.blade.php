@extends('platform.auth.layout')

@section('title', 'Forgot Password')
@section('heading', 'Forgot Password')
@section('description', 'Send OTP to your platform user email, verify the code, then set a new password.')
@section('heroTitle', 'Password Recovery')
@section('heroText', 'The reset flow is handled in three steps: send code, verify OTP, then submit a new password.')

@section('content')
    <div id="forgot-password-status" class="form-status hidden"></div>

    <div class="section-card">
        <h3>Step 1. Send verification code</h3>
        <p>Enter your email and request the OTP. The send button is disabled for 90 seconds after each request.</p>

        <form method="POST" action="{{ route('platform.password.send-code') }}" class="grid" id="send-code-form">
            @csrf

            <div>
                <label for="email">Email</label>
                <input id="email" type="email" name="email" value="{{ $email }}" autocomplete="email" required>
                <div class="field-error hidden" data-error-for="send-email"></div>
            </div>

            <div class="actions">
                <button type="submit" id="send-code-button" class="primary">
                    Send Code
                </button>
            </div>

            <div id="resend-timer" class="timer"></div>
        </form>
    </div>

    <div class="section-card">
        <h3>Step 2. Verify OTP</h3>
        <p>Enter the six-digit OTP sent to your email address.</p>

        <form method="POST" action="{{ route('platform.password.verify-code') }}" class="grid" id="verify-code-form">
            @csrf
            <input type="hidden" name="email" value="{{ $email }}" id="verify-email">

            <div class="otp-row">
                <div>
                    <label for="otp">OTP Code</label>
                    <input id="otp" type="text" name="otp" inputmode="numeric" pattern="[0-9]{6}" maxlength="6" required>
                    <div class="field-error hidden" data-error-for="otp"></div>
                </div>

                <div class="actions">
                    <button type="submit" class="primary" id="verify-code-button">Submit Code</button>
                </div>
            </div>
        </form>

        <form method="POST" action="{{ route('platform.password.cancel') }}" class="actions" id="cancel-reset-form">
            @csrf
            <button type="submit" class="secondary" id="cancel-reset-button">Cancel</button>
        </form>
    </div>

    <div class="section-card hidden" id="reset-password-section">
        <h3>Step 3. Set new password</h3>
        <p>OTP verification succeeded. Submit your new password for the email shown below.</p>

        <form method="POST" action="{{ route('platform.password.reset') }}" class="grid" id="reset-password-form">
            @csrf

            <div>
                <label for="verified_email">Email</label>
                <input id="verified_email" type="email" name="email" value="{{ $email }}" readonly required>
            </div>

            <div class="grid two">
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
            </div>

            <div class="actions">
                <button type="submit" class="primary" id="reset-password-button">Reset Password</button>
            </div>
        </form>
    </div>

    @push('scripts')
        <script>
            (function () {
                const state = {
                    email: @json($email),
                    isCodeSent: @json($isCodeSent),
                    isOtpVerified: @json($isOtpVerified),
                    resendAvailableAt: {{ $resendAvailableAt }},
                };

                const statusBox = document.getElementById('forgot-password-status');
                const sendCodeForm = document.getElementById('send-code-form');
                const verifyCodeForm = document.getElementById('verify-code-form');
                const resetPasswordForm = document.getElementById('reset-password-form');
                const cancelResetForm = document.getElementById('cancel-reset-form');
                const sendCodeButton = document.getElementById('send-code-button');
                const verifyCodeButton = document.getElementById('verify-code-button');
                const resetPasswordButton = document.getElementById('reset-password-button');
                const cancelResetButton = document.getElementById('cancel-reset-button');
                const resendTimer = document.getElementById('resend-timer');
                const resetPasswordSection = document.getElementById('reset-password-section');
                const emailInput = document.getElementById('email');
                const verifyEmailInput = document.getElementById('verify-email');
                const verifiedEmailInput = document.getElementById('verified_email');
                let intervalId = null;

                if (!statusBox || !sendCodeForm || !verifyCodeForm || !resetPasswordForm || !cancelResetForm) {
                    return;
                }

                const errorNodes = {
                    sendEmail: sendCodeForm.querySelector('[data-error-for="send-email"]'),
                    otp: verifyCodeForm.querySelector('[data-error-for="otp"]'),
                    password: resetPasswordForm.querySelector('[data-error-for="password"]'),
                    passwordConfirmation: resetPasswordForm.querySelector('[data-error-for="password_confirmation"]'),
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

                function setFieldError(node, message) {
                    if (!node || !message) {
                        return;
                    }

                    node.textContent = message;
                    node.classList.remove('hidden');
                }

                function syncEmailFields() {
                    emailInput.value = state.email || '';
                    verifyEmailInput.value = state.email || '';
                    verifiedEmailInput.value = state.email || '';
                }

                function renderTimer() {
                    const remaining = state.resendAvailableAt - Math.floor(Date.now() / 1000);

                    if (remaining > 0) {
                        sendCodeButton.disabled = true;
                        resendTimer.textContent = 'Send Code is available again in ' + remaining + ' seconds.';
                        return;
                    }

                    sendCodeButton.disabled = false;
                    resendTimer.textContent = '';

                    if (intervalId) {
                        window.clearInterval(intervalId);
                        intervalId = null;
                    }
                }

                function updateUI() {
                    syncEmailFields();
                    verifyCodeButton.disabled = !state.isCodeSent;
                    resetPasswordSection.classList.toggle('hidden', !state.isOtpVerified);
                    renderTimer();
                }

                function startTimer() {
                    if (intervalId) {
                        window.clearInterval(intervalId);
                    }

                    intervalId = window.setInterval(renderTimer, 1000);
                    renderTimer();
                }

                function renderErrors(payload, source) {
                    const responseData = payload && payload.data ? payload.data : {};
                    const errors = responseData.errors || {};
                    const message = payload && payload.message ? payload.message : 'Request failed.';

                    setFieldError(errorNodes.sendEmail, errors.email ? errors.email[0] : '');
                    setFieldError(errorNodes.otp, errors.otp ? errors.otp[0] : '');
                    setFieldError(errorNodes.password, errors.password ? errors.password[0] : '');
                    setFieldError(
                        errorNodes.passwordConfirmation,
                        errors.password_confirmation ? errors.password_confirmation[0] : ''
                    );

                    if (!errors.email && !errors.otp && !errors.password && !errors.password_confirmation) {
                        if (source === 'verify') {
                            setFieldError(errorNodes.otp, message);
                        } else if (source === 'reset') {
                            setFieldError(errorNodes.password, message);
                        } else if (source === 'send') {
                            setFieldError(errorNodes.sendEmail, message);
                        }
                    }

                    showStatus('error', message);
                }

                async function submitJson(form, button, source) {
                    button.disabled = true;
                    clearErrors();
                    hideStatus();

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
                            renderErrors(payload, source);
                            return null;
                        }

                        showStatus('success', payload.message || 'Request completed.');
                        return payload;
                    } catch (error) {
                        showStatus('error', 'Unable to process your request right now.');
                        return null;
                    } finally {
                        button.disabled = false;
                    }
                }

                sendCodeForm.addEventListener('submit', async function (event) {
                    event.preventDefault();
                    state.email = emailInput.value.trim();
                    syncEmailFields();

                    const payload = await submitJson(sendCodeForm, sendCodeButton, 'send');

                    if (!payload || !payload.data) {
                        updateUI();
                        return;
                    }

                    state.email = payload.data.email || state.email;
                    state.isCodeSent = Boolean(payload.data.isCodeSent);
                    state.isOtpVerified = Boolean(payload.data.isOtpVerified);
                    state.resendAvailableAt = Number(payload.data.resendAvailableAt || 0);
                    updateUI();
                    startTimer();
                });

                verifyCodeForm.addEventListener('submit', async function (event) {
                    event.preventDefault();
                    state.email = emailInput.value.trim();
                    syncEmailFields();

                    const payload = await submitJson(verifyCodeForm, verifyCodeButton, 'verify');

                    if (!payload || !payload.data) {
                        updateUI();
                        return;
                    }

                    state.email = payload.data.email || state.email;
                    state.isCodeSent = Boolean(payload.data.isCodeSent);
                    state.isOtpVerified = Boolean(payload.data.isOtpVerified);
                    updateUI();
                });

                resetPasswordForm.addEventListener('submit', async function (event) {
                    event.preventDefault();
                    const payload = await submitJson(resetPasswordForm, resetPasswordButton, 'reset');
                    const responseData = payload && payload.data ? payload.data : {};

                    if (responseData.redirect) {
                        window.setTimeout(function () {
                            window.location.href = responseData.redirect;
                        }, 900);
                    }
                });

                cancelResetForm.addEventListener('submit', async function (event) {
                    event.preventDefault();
                    const payload = await submitJson(cancelResetForm, cancelResetButton, 'cancel');
                    const responseData = payload && payload.data ? payload.data : {};

                    if (responseData.redirect) {
                        window.location.href = responseData.redirect;
                    }
                });

                updateUI();

                if (state.resendAvailableAt > Math.floor(Date.now() / 1000)) {
                    startTimer();
                }
            }());
        </script>
    @endpush
@endsection
