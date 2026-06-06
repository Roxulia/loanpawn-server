<?php

namespace Tests\Feature;

use Tests\TestCase;

class LocaleSwitchTest extends TestCase
{
    public function test_web_locale_switch_persists_locale_in_session(): void
    {
        $this->from('/')
            ->get(route('locale.set', 'mm'))
            ->assertRedirect('/')
            ->assertSessionHas('locale', 'mm');

        $this->withSession(['locale' => 'mm'])
            ->get('/')
            ->assertOk();

        $this->assertSame('mm', app()->getLocale());
    }
}
