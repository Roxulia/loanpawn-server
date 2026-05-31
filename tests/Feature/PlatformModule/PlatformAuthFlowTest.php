<?php

namespace Tests\Feature\PlatformModule;

use App\Mail\PlatformRegistrationVerificationMail;
use App\Models\PlatformModule\PlatformUser;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class PlatformAuthFlowTest extends TestCase
{
    use RefreshDatabase;

    public function test_unverified_platform_user_login_redirects_to_verification_screen(): void
    {
        Mail::fake();

        $user = PlatformUser::query()->create([
            'code' => 'PU12345678',
            'name' => 'Pending User',
            'email' => 'pending@example.com',
            'password' => Hash::make('Password@123'),
            'status' => 'pending_verification',
        ]);

        $response = $this->postJson(route('platform.login.submit'), [
            'email' => $user->email,
            'password' => 'Password@123',
        ]);

        $response
            ->assertStatus(403)
            ->assertJsonPath('redirect', route('platform.register.verify', ['email' => $user->email]));

        $this->assertSame($user->email, session('platform_user_register_email'));
        Mail::assertSent(PlatformRegistrationVerificationMail::class);
    }
}
