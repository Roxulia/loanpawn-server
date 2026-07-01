<?php

namespace Tests\Feature\PlatformModule;

use App\Models\PlatformModule\ManualPaymentRequest;
use App\Models\PlatformModule\PaymentQrImage;
use App\Models\PlatformModule\PlatformAdmin;
use App\Models\PlatformModule\PlatformUser;
use App\Models\PlatformModule\Tenant;
use App\Models\PlatformModule\TenantRequest;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class PaymentQrManagementTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_upload_payment_qr_to_private_storage(): void
    {
        Storage::fake('local');
        $admin = $this->platformAdmin();

        $this->actingAs($admin, 'platformadmin')
            ->post(route('admin.payment-qrs.store'), [
                'qr_image' => UploadedFile::fake()->image('kpay.png'),
            ])
            ->assertRedirect(route('admin.payment-qrs.index'))
            ->assertSessionHas('status', 'Payment QR image uploaded.');

        $qrImage = PaymentQrImage::query()->first();

        $this->assertNotNull($qrImage);
        $this->assertStringStartsWith('kpay-qr/', $qrImage->file_path);
        $this->assertSame($admin->id, $qrImage->uploaded_by);
        Storage::disk('local')->assertExists($qrImage->file_path);
    }

    public function test_admin_activation_keeps_only_one_active_payment_qr(): void
    {
        Storage::fake('local');
        $admin = $this->platformAdmin();
        $first = PaymentQrImage::query()->create([
            'file_path' => UploadedFile::fake()->image('first.png')->storeAs('kpay-qr', 'first.png', 'local'),
            'original_name' => 'first.png',
            'mime_type' => 'image/png',
            'is_active' => true,
            'activated_at' => now()->subDay(),
            'uploaded_by' => $admin->id,
        ]);
        $second = PaymentQrImage::query()->create([
            'file_path' => UploadedFile::fake()->image('second.png')->storeAs('kpay-qr', 'second.png', 'local'),
            'original_name' => 'second.png',
            'mime_type' => 'image/png',
            'uploaded_by' => $admin->id,
        ]);

        $this->actingAs($admin, 'platformadmin')
            ->post(route('admin.payment-qrs.activate', $second->id))
            ->assertRedirect(route('admin.payment-qrs.index'))
            ->assertSessionHas('status', 'Active payment QR image updated.');

        $this->assertFalse($first->refresh()->is_active);
        $this->assertTrue($second->refresh()->is_active);
        $this->assertNotNull($second->activated_at);
    }

    public function test_private_payment_qr_image_requires_authentication_and_streams_for_platform_user(): void
    {
        Storage::fake('local');
        $user = $this->platformUser();
        $path = UploadedFile::fake()->image('active.png')->storeAs('kpay-qr', 'active.png', 'local');
        $qrImage = PaymentQrImage::query()->create([
            'file_path' => $path,
            'original_name' => 'active.png',
            'mime_type' => 'image/png',
            'is_active' => true,
            'activated_at' => now(),
        ]);

        $this->get(route('platform.payment-qrs.image', $qrImage->id))
            ->assertRedirect(route('platform.login.show'));

        $this->actingAs($user, 'platformuser')
            ->get(route('platform.payment-qrs.image', $qrImage->id))
            ->assertOk();
    }

    public function test_billing_payment_modal_shows_active_qr_and_auto_open_marker(): void
    {
        Storage::fake('local');
        $user = $this->platformUser();
        [$tenantRequest] = $this->draftPaymentFixture($user);
        $path = UploadedFile::fake()->image('billing-qr.png')->storeAs('kpay-qr', 'billing-qr.png', 'local');
        $qrImage = PaymentQrImage::query()->create([
            'file_path' => $path,
            'original_name' => 'billing-qr.png',
            'mime_type' => 'image/png',
            'is_active' => true,
            'activated_at' => now(),
        ]);

        $this->actingAs($user, 'platformuser')
            ->withSession(['open_payment_tenant_request_id' => $tenantRequest->id])
            ->get(route('platform.billing.index'))
            ->assertOk()
            ->assertSee(route('platform.payment-qrs.image', $qrImage->id), false)
            ->assertSee('data-auto-open-payment-dialog', false);
    }

    private function draftPaymentFixture(PlatformUser $user): array
    {
        $tenant = Tenant::query()->create([
            'platform_user_id' => $user->id,
            'name' => 'QR Tenant',
            'tenant_code' => 'qr-tenant',
            'subdomain' => 'qr-tenant',
            'status' => 'active',
        ]);
        $tenantRequest = TenantRequest::query()->create([
            'code' => 'TRQR0001',
            'tenant_id' => $tenant->id,
            'platform_user_id' => $user->id,
            'request_type' => 'extension',
            'requested_plan_type' => 'basic',
            'extension_months' => 1,
            'total_cost' => 50000,
            'request_status' => 'waiting_payment',
        ]);
        $payment = ManualPaymentRequest::query()->create([
            'code' => 'MPQR0001',
            'platform_user_id' => $user->id,
            'tenant_request_id' => $tenantRequest->id,
            'tenant_id' => $tenant->id,
            'amount' => 50000,
            'currency' => 'MMK',
            'status' => 'draft',
        ]);

        return [$tenantRequest, $payment];
    }

    private function platformAdmin(): PlatformAdmin
    {
        return PlatformAdmin::query()->create([
            'code' => 'PAQR0001',
            'name' => 'QR Admin',
            'username' => 'qradmin',
            'email' => 'qr-admin@example.com',
            'password' => 'strong-secret',
            'status' => 'active',
        ]);
    }

    private function platformUser(): PlatformUser
    {
        return PlatformUser::query()->create([
            'code' => 'PUQR0001',
            'name' => 'QR User',
            'email' => 'qr-user@example.com',
            'password' => 'secret123',
            'status' => 'active',
        ]);
    }
}
