@extends('platform.auth.layout')

@section('title', 'Forgot Password')
@section('heading', 'Forgot Password')
@section('description', 'Send OTP to your platform user email, verify the code, then set a new password.')
@section('heroTitle', 'Password Recovery')
@section('heroText', 'The reset flow is handled in three steps: send code, verify OTP, then submit a new password.')

@section('content')
    <style>
        .otp-code-row {
            display: grid;
            grid-template-columns: repeat(6, minmax(0, 48px));
            gap: 10px;
        }

        .otp-digit {
            aspect-ratio: 1;
            padding: 0;
            text-align: center;
            font-size: 20px;
            font-weight: 800;
        }

        @media (max-width: 480px) {
            .otp-code-row {
                grid-template-columns: repeat(6, minmax(0, 1fr));
                gap: 8px;
            }
        }
    </style>

    <div id="forgot-password-status" class="form-status hidden"></div>

    <div class="section-card" id="send-code-section">
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

    <div class="section-card hidden" id="verify-code-section">
        <h3>Step 2. Verify OTP</h3>
        <p id="verify-code-message">A 6 digit code is sent to your email. Enter it below to continue.</p>

        <form method="POST" action="{{ route('platform.password.verify-code') }}" class="grid" id="verify-code-form">
            @csrf
            <input type="hidden" name="email" value="{{ $email }}" id="verify-email">
            <input type="hidden" name="otp" value="" id="otp">

            <div>
                <label for="otp_digit_1">OTP Code</label>
                <div class="otp-code-row" id="otp-code-row">
                    <input id="otp_digit_1" class="otp-digit" type="text" inputmode="numeric" pattern="[0-9]" maxlength="1" autocomplete="one-time-code" aria-label="OTP digit 1" required>
                    <input id="otp_digit_2" class="otp-digit" type="text" inputmode="numeric" pattern="[0-9]" maxlength="1" aria-label="OTP digit 2" required>
                    <input id="otp_digit_3" class="otp-digit" type="text" inputmode="numeric" pattern="[0-9]" maxlength="1" aria-label="OTP digit 3" required>
                    <input id="otp_digit_4" class="otp-digit" type="text" inputmode="numeric" pattern="[0-9]" maxlength="1" aria-label="OTP digit 4" required>
                    <input id="otp_digit_5" class="otp-digit" type="text" inputmode="numeric" pattern="[0-9]" maxlength="1" aria-label="OTP digit 5" required>
                    <input id="otp_digit_6" class="otp-digit" type="text" inputmode="numeric" pattern="[0-9]" maxlength="1" aria-label="OTP digit 6" required>
                </div>
                <div class="field-error hidden" data-error-for="otp"></div>
            </div>

            <div class="actions">
                <button type="submit" class="primary" id="verify-code-button">Submit Code</button>
            </div>
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
                const sendCodeSection = document.getElementById('send-code-section');
                const verifyCodeSection = document.getElementById('verify-code-section');
                const resetPasswordSection = document.getElementById('reset-password-section');
                const sendCodeForm = document.getElementById('send-code-form');
                const verifyCodeForm = document.getElementById('verify-code-form');
                const resetPasswordForm = document.getElementById('reset-password-form');
                const sendCodeButton = document.getElementById('send-code-button');
                const verifyCodeButton = document.getElementById('verify-code-button');
                const resetPasswordButton = document.getElementById('reset-password-button');
                const resendTimer = document.getElementById('resend-timer');
                const emailInput = document.getElementById('email');
                const verifyEmailInput = document.getElementById('verify-email');
                const verifiedEmailInput = document.getElementById('verified_email');
                const verifyCodeMessage = document.getElementById('verify-code-message');
                const otpInput = document.getElementById('otp');
                const otpDigits = Array.from(document.querySelectorAll('.otp-digit'));
                let intervalId = null;

                if (
                    !statusBox ||
                    !sendCodeSection ||
                    !verifyCodeSection ||
                    !resetPasswordSection ||
                    !sendCodeForm ||
                    !verifyCodeForm ||
                    !resetPasswordForm ||
                    !otpInput ||
                    otpDigits.length !== 6
                ) {
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
                    verifyCodeMessage.textContent = state.email
                        ? 'A 6 digit code is sent to ' + state.email + '. Enter it below to continue.'
                        : 'A 6 digit code is sent to your email. Enter it below to continue.';
                }

                function getOtpValue() {
                    return otpDigits.map(function (input) {
                        return input.value;
                    }).join('');
                }

                function syncOtpInput() {
                    otpInput.value = getOtpValue();
                    return otpInput.value;
                }

                function clearOtpInputs() {
                    otpDigits.forEach(function (input) {
                        input.value = '';
                    });
                    syncOtpInput();
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
                    sendCodeSection.classList.toggle('hidden', state.isCodeSent || state.isOtpVerified);
                    verifyCodeSection.classList.toggle('hidden', !state.isCodeSent || state.isOtpVerified);
                    resetPasswordSection.classList.toggle('hidden', !state.isOtpVerified);
                    verifyCodeButton.disabled = !state.isCodeSent;
                    renderTimer();
                }

                function focusFirstEmptyOtpDigit() {
                    const nextInput = otpDigits.find(function (input) {
                        return !input.value;
                    });

                    (nextInput || otpDigits[otpDigits.length - 1]).focus();
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

                otpDigits.forEach(function (input, index) {
                    input.addEventListener('input', function () {
                        input.value = input.value.replace(/\D/g, '').slice(0, 1);
                        syncOtpInput();

                        if (input.value && otpDigits[index + 1]) {
                            otpDigits[index + 1].focus();
                        }
                    });

                    input.addEventListener('keydown', function (event) {
                        if (event.key === 'Backspace' && !input.value && otpDigits[index - 1]) {
                            otpDigits[index - 1].focus();
                        }
                    });

                    input.addEventListener('paste', function (event) {
                        const pastedCode = event.clipboardData.getData('text').replace(/\D/g, '').slice(0, 6);

                        if (!pastedCode) {
                            return;
                        }

                        event.preventDefault();

                        otpDigits.forEach(function (digitInput, digitIndex) {
                            digitInput.value = pastedCode[digitIndex] || '';
                        });

                        syncOtpInput();
                        focusFirstEmptyOtpDigit();
                    });
                });

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
                    clearOtpInputs();
                    updateUI();
                    focusFirstEmptyOtpDigit();
                    startTimer();
                });

                verifyCodeForm.addEventListener('submit', async function (event) {
                    event.preventDefault();
                    state.email = verifyEmailInput.value.trim();
                    syncEmailFields();
                    syncOtpInput();

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

                updateUI();

                if (state.resendAvailableAt > Math.floor(Date.now() / 1000)) {
                    startTimer();
                }
            }());
        </script>
    @endpush
@endsection
