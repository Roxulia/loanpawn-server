@extends('platform.layouts.app')

@section('title', __('app.platform.view.user_setting'))
@section('pageTitle', __('app.platform.view.user_setting'))
@section('pageDescription', __('app.platform.view.user_setting_description'))

@section('content')
    <div class="grid two platform-settings-grid">
        <section class="panel platform-settings-panel" aria-labelledby="language-settings-title">
            <header class="platform-settings-header">
                <h2 id="language-settings-title">{{ __('app.platform.view.language_preferences') }}</h2>
                <p>{{ __('app.platform.view.language_preferences_description') }}</p>
            </header>

            <form method="POST" action="{{ route('platform.language.change') }}" class="grid platform-settings-form">
                @csrf
                @method('PUT')

                <div>
                    <label for="lang">{{ __('app.common.view.locale.language') }}</label>
                    <select id="lang" name="lang" required>
                        @foreach ($supportedLocales as $locale)
                            <option value="{{ $locale }}" @selected(old('lang', $user->prefer_lang ?? app()->getLocale()) === $locale)>
                                {{ __('app.common.view.locale.'.$locale) }}
                            </option>
                        @endforeach
                    </select>
                    @error('lang')
                        <div class="field-error">{{ $message }}</div>
                    @enderror
                </div>

                <div class="platform-settings-actions">
                    <button type="submit" class="button primary">{{ __('app.common.view.actions.save') }}</button>
                </div>
            </form>
        </section>

        <section class="panel platform-settings-panel" aria-labelledby="password-settings-title">
            <header class="platform-settings-header">
                <h2 id="password-settings-title">{{ __('app.platform.view.change_password') }}</h2>
                <p>{{ __('app.platform.view.change_password_description') }}</p>
            </header>

            <div id="platform-password-status" class="platform-settings-status" role="status" aria-live="polite" hidden></div>

            <form id="platform-password-form" method="POST" action="{{ route('platform.password.change') }}"
                class="grid platform-settings-form" novalidate>
                @csrf
                @method('PUT')

                <div>
                    <label for="current_password">{{ __('app.platform.view.current_password') }}</label>
                    <input id="current_password" name="current_password" type="password" maxlength="255"
                        autocomplete="current-password" required>
                    <div class="field-error" data-error-for="current_password" hidden></div>
                </div>

                <div>
                    <label for="password">{{ __('app.common.view.labels.new_password') }}</label>
                    <input id="password" name="password" type="password" maxlength="255"
                        autocomplete="new-password" aria-describedby="password-requirements" required>
                    <p id="password-requirements" class="platform-settings-hint">
                        {{ __('app.platform.view.password_requirements') }}
                    </p>
                    <div class="field-error" data-error-for="password" hidden></div>
                </div>

                <div>
                    <label for="password_confirmation">{{ __('app.platform.view.confirm_password') }}</label>
                    <input id="password_confirmation" name="password_confirmation" type="password" maxlength="255"
                        autocomplete="new-password" required>
                    <div class="field-error" data-error-for="password_confirmation" hidden></div>
                </div>

                <div class="field-error" data-general-error hidden></div>

                <div class="platform-settings-actions">
                    <button id="platform-password-submit" type="submit" class="button primary">
                        {{ __('app.platform.view.change_password') }}
                    </button>
                </div>
            </form>
        </section>
    </div>
@endsection

@push('scripts')
    <script>
        (() => {
            const form = document.getElementById('platform-password-form');

            if (!form) {
                return;
            }

            const submitButton = document.getElementById('platform-password-submit');
            const status = document.getElementById('platform-password-status');
            const defaultButtonText = @json(__('app.platform.view.change_password'));
            const pendingButtonText = @json(__('app.platform.view.changing_password'));
            const fallbackError = @json(__('app.platform.view.password_change_failed'));
            const fallbackSuccess = @json(__('app.auth.response.password_changed'));

            const clearFeedback = () => {
                status.hidden = true;
                status.textContent = '';
                status.classList.remove('is-success', 'is-error');

                form.querySelectorAll('[data-error-for], [data-general-error]').forEach((element) => {
                    element.hidden = true;
                    element.textContent = '';
                });

                form.querySelectorAll('[aria-invalid="true"]').forEach((input) => {
                    input.removeAttribute('aria-invalid');
                });
            };

            const showStatus = (message, type) => {
                status.textContent = message;
                status.classList.add(type === 'success' ? 'is-success' : 'is-error');
                status.hidden = false;
            };

            form.addEventListener('submit', async (event) => {
                event.preventDefault();
                clearFeedback();
                submitButton.disabled = true;
                submitButton.textContent = pendingButtonText;

                try {
                    const response = await fetch(form.action, {
                        method: 'POST',
                        headers: {
                            Accept: 'application/json',
                            'X-Requested-With': 'XMLHttpRequest',
                        },
                        body: new FormData(form),
                        credentials: 'same-origin',
                    });
                    const payload = await response.json().catch(() => ({}));

                    if (!response.ok) {
                        const errors = {...(payload?.data?.errors ?? {})};

                        if (payload?.data?.code === 'INVALID_CREDENTIAL' && !errors.current_password) {
                            errors.current_password = [payload.message || fallbackError];
                        }

                        let firstInvalidInput = null;

                        Object.entries(errors).forEach(([field, messages]) => {
                            const errorElement = form.querySelector(`[data-error-for="${field}"]`);
                            const input = form.elements.namedItem(field);

                            if (!errorElement) {
                                return;
                            }

                            errorElement.textContent = Array.isArray(messages) ? messages[0] : messages;
                            errorElement.hidden = false;

                            if (input instanceof HTMLElement) {
                                input.setAttribute('aria-invalid', 'true');
                                firstInvalidInput ??= input;
                            }
                        });

                        if (!firstInvalidInput) {
                            const generalError = form.querySelector('[data-general-error]');
                            generalError.textContent = payload.message || fallbackError;
                            generalError.hidden = false;
                        }

                        showStatus(payload.message || fallbackError, 'error');
                        firstInvalidInput?.focus();
                        return;
                    }

                    form.reset();
                    showStatus(payload.message || fallbackSuccess, 'success');
                } catch (error) {
                    showStatus(fallbackError, 'error');
                } finally {
                    submitButton.disabled = false;
                    submitButton.textContent = defaultButtonText;
                }
            });
        })();
    </script>
@endpush
