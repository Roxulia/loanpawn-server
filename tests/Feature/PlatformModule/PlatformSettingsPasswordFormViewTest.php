<?php

namespace Tests\Feature\PlatformModule;

use Illuminate\Support\ViewErrorBag;
use Tests\TestCase;

class PlatformSettingsPasswordFormViewTest extends TestCase
{
    public function test_settings_view_contains_password_change_form(): void
    {
        $view = $this->view('platform.settings', [
            'user' => (object) ['prefer_lang' => 'en'],
            'supportedLocales' => ['en', 'mm'],
            'errors' => new ViewErrorBag,
        ]);

        $view
            ->assertSee('id="platform-password-form"', false)
            ->assertSee('action="'.route('platform.password.change').'"', false)
            ->assertSee('name="_method" value="PUT"', false)
            ->assertSee('name="current_password"', false)
            ->assertSee('autocomplete="current-password"', false)
            ->assertSee('name="password"', false)
            ->assertSee('name="password_confirmation"', false)
            ->assertSee('autocomplete="new-password"', false)
            ->assertSee('data-error-for="current_password"', false);
    }
}
