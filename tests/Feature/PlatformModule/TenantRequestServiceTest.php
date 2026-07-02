<?php

namespace Tests\Feature\PlatformModule;

use App\DataObjects\RequestObjects\TenantCreate;
use App\DataObjects\RequestObjects\TenantRequestCreate;
use App\DataObjects\RequestObjects\TenantRequestPaymentSubmit;
use App\Exceptions\InvalidTenantRequest;
use App\Models\CoreModule\TenantRole;
use App\Models\PlatformModule\Package;
use App\Models\PlatformModule\PlatformAdmin;
use App\Models\PlatformModule\PlatformUser;
use App\Services\PlatformModule\TenantRequestService;
use App\Services\PlatformModule\TenantServices\TenantManagementService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Tests\TestCase;

class TenantRequestServiceTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();

        parent::tearDown();
    }

    public function test_platform_user_can_create_upgrade_request_with_total_cost(): void
    {
        Storage::fake('public');
        $this->createDefaultAdminRole();
        $this->createPackages();

        $platformUser = PlatformUser::query()->create([
            'code' => 'PU'.str_pad((string) random_int(0, 99999999), 8, '0', STR_PAD_LEFT),
            'name' => 'Request User',
            'email' => 'request-user@example.com',
            'phone' => '09111111111',
            'password' => 'secret123',
            'status' => 'active',
        ]);

        $this->actingAs($platformUser, 'platformuser');

        $tenant = app(TenantManagementService::class)->createTenant(new TenantCreate(
            name: 'Upgrade Tenant',
            code: 'upgrade-tenant',
            subdomain: 'upgrade-subdomain',
            createdByAdmin: false,
            planType: null,
        ));

        $detail = app(TenantRequestService::class)->createRequest(new TenantRequestCreate(
            tenantId: $tenant->id,
            requestType: 'plan_change',
            requestedPlanType: 'basic',
        ));
        $billingMonths = max(1, (int) ceil(
            now()->startOfDay()->diffInMonths($tenant->license->expires_at->copy()->startOfDay())
        ));
        $expectedTotalCost = number_format(50000 * $billingMonths, 2, '.', '');

        $this->assertSame('plan_change', $detail->requestType);
        $this->assertSame('basic', $detail->requestedPlanType);
        $this->assertSame('waiting_payment', $detail->requestStatus);
        $this->assertSame($expectedTotalCost, $detail->totalCost);

        $this->assertDatabaseHas('tenant_requests', [
            'id' => $detail->id,
            'tenant_id' => $tenant->id,
            'platform_user_id' => $platformUser->id,
            'request_type' => 'plan_change',
            'requested_plan_type' => 'basic',
            'request_status' => 'waiting_payment',
            'total_cost' => $expectedTotalCost,
        ]);

        $this->assertDatabaseHas('manual_payment_requests', [
            'tenant_request_id' => $detail->id,
            'tenant_id' => $tenant->id,
            'platform_user_id' => $platformUser->id,
            'amount' => $expectedTotalCost,
            'status' => 'draft',
        ]);
    }

    public function test_upgrade_request_bills_exact_calendar_months_until_license_expiry(): void
    {
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-07-02 09:00:00'));
        Storage::fake('public');
        $this->createDefaultAdminRole();
        $this->createPackages();

        $platformUser = PlatformUser::query()->create([
            'code' => 'PU'.str_pad((string) random_int(0, 99999999), 8, '0', STR_PAD_LEFT),
            'name' => 'Calendar User',
            'email' => 'calendar-user@example.com',
            'phone' => '09111111112',
            'password' => 'secret123',
            'status' => 'active',
        ]);

        $this->actingAs($platformUser, 'platformuser');

        $tenant = app(TenantManagementService::class)->createTenant(new TenantCreate(
            name: 'Calendar Tenant',
            code: 'calendar-tenant',
            subdomain: 'calendar-subdomain',
            createdByAdmin: false,
            planType: null,
        ));
        $tenant->license()->update([
            'expires_at' => CarbonImmutable::parse('2026-10-02 00:00:00'),
        ]);

        $detail = app(TenantRequestService::class)->createRequest(new TenantRequestCreate(
            tenantId: $tenant->id,
            requestType: 'plan_change',
            requestedPlanType: 'basic',
        ));

        $this->assertSame('150000.00', $detail->totalCost);
        $this->assertDatabaseHas('tenant_requests', [
            'id' => $detail->id,
            'total_cost' => '150000.00',
        ]);
    }

    public function test_platform_user_can_submit_payment_screenshot_for_request(): void
    {
        Storage::fake('local');
        $this->createDefaultAdminRole();
        $this->createPackages();

        $platformUser = PlatformUser::query()->create([
            'code' => 'PU'.str_pad((string) random_int(0, 99999999), 8, '0', STR_PAD_LEFT),
            'name' => 'Payment User',
            'email' => 'payment-user@example.com',
            'phone' => '09222222222',
            'password' => 'secret123',
            'status' => 'active',
        ]);

        $this->actingAs($platformUser, 'platformuser');

        $tenant = app(TenantManagementService::class)->createTenant(new TenantCreate(
            name: 'Payment Tenant',
            code: 'payment-tenant',
            subdomain: 'payment-subdomain',
            createdByAdmin: false,
            planType: null,
        ));

        $requestDetail = app(TenantRequestService::class)->createRequest(new TenantRequestCreate(
            tenantId: $tenant->id,
            requestType: 'plan_change',
            requestedPlanType: 'premium',
        ));

        $submitted = app(TenantRequestService::class)->submitPaymentScreenshot(new TenantRequestPaymentSubmit(
            tenantRequestId: $requestDetail->id,
            updateKey: $requestDetail->updateKey,
            paymentScreenshot: UploadedFile::fake()->image('payment.png'),
            paymentReference: 'PAY-001',
            note: 'Paid by KBZPay',
        ));

        $this->assertSame('pending_approval', $submitted->requestStatus);

        $this->assertDatabaseHas('tenant_requests', [
            'id' => $requestDetail->id,
            'request_status' => 'pending_approval',
        ]);

        $this->assertDatabaseHas('manual_payment_requests', [
            'tenant_request_id' => $requestDetail->id,
            'payment_reference' => 'PAY-001',
            'status' => 'submitted',
        ]);

        $attachment = \App\Models\PlatformModule\ManualPaymentAttachment::query()->first();
        $this->assertNotNull($attachment);
        Storage::disk('local')->assertExists($attachment->file_path);

        $platformAdmin = PlatformAdmin::query()->create([
            'code' => 'PA'.str_pad((string) random_int(0, 99999999), 8, '0', STR_PAD_LEFT),
            'name' => 'Download Admin',
            'username' => 'downloadadmin',
            'email' => 'download-admin@example.com',
            'password' => 'secret123',
            'status' => 'active',
        ]);
        $this->actingAs($platformAdmin, 'platformadmin');

        $downloadUrl = URL::temporarySignedRoute(
            'admin.payment-requests.attachments.download',
            now()->addMinutes(10),
            [
                'paymentRequest' => $attachment->manual_payment_request_id,
                'attachment' => $attachment->id,
            ]
        );

        $this->get($downloadUrl)->assertDownload(basename($attachment->file_path));
    }

    public function test_platform_user_can_submit_payment_screenshot_from_billing_route(): void
    {
        Storage::fake('local');
        $this->createDefaultAdminRole();
        $this->createPackages();

        $platformUser = PlatformUser::query()->create([
            'code' => 'PU'.str_pad((string) random_int(0, 99999999), 8, '0', STR_PAD_LEFT),
            'name' => 'Billing Route User',
            'email' => 'billing-route@example.com',
            'phone' => '09222222223',
            'password' => 'secret123',
            'status' => 'active',
        ]);

        $this->actingAs($platformUser, 'platformuser');

        $tenant = app(TenantManagementService::class)->createTenant(new TenantCreate(
            name: 'Billing Route Tenant',
            code: 'billing-route-tenant',
            subdomain: 'billing-route-subdomain',
            createdByAdmin: false,
            planType: null,
        ));

        $requestDetail = app(TenantRequestService::class)->createRequest(new TenantRequestCreate(
            tenantId: $tenant->id,
            requestType: 'plan_change',
            requestedPlanType: 'premium',
        ));

        $this->post(route('platform.billing.payment.submit', $requestDetail->id), [
            'update_key' => $requestDetail->updateKey,
            'payment_screenshot' => UploadedFile::fake()->image('billing-route.png'),
        ])->assertRedirect(route('platform.billing.index'));

        $this->assertDatabaseHas('tenant_requests', [
            'id' => $requestDetail->id,
            'request_status' => 'pending_approval',
            'update_key' => 1,
        ]);
    }

    public function test_billing_route_redirects_with_error_when_payment_submission_throws_api_exception(): void
    {
        Storage::fake('public');
        $this->createDefaultAdminRole();
        $this->createPackages();

        $platformUser = PlatformUser::query()->create([
            'code' => 'PU'.str_pad((string) random_int(0, 99999999), 8, '0', STR_PAD_LEFT),
            'name' => 'Billing Error User',
            'email' => 'billing-error@example.com',
            'phone' => '09222222224',
            'password' => 'secret123',
            'status' => 'active',
        ]);

        $this->actingAs($platformUser, 'platformuser');

        $tenant = app(TenantManagementService::class)->createTenant(new TenantCreate(
            name: 'Billing Error Tenant',
            code: 'billing-error-tenant',
            subdomain: 'billing-error-subdomain',
            createdByAdmin: false,
            planType: null,
        ));

        $requestDetail = app(TenantRequestService::class)->createRequest(new TenantRequestCreate(
            tenantId: $tenant->id,
            requestType: 'plan_change',
            requestedPlanType: 'premium',
        ));

        $this->from(route('platform.billing.index'))
            ->post(route('platform.billing.payment.submit', $requestDetail->id), [
                'update_key' => $requestDetail->updateKey + 1,
                'payment_reference' => 'STALE-KEY',
                'note' => 'This should be preserved.',
                'payment_screenshot' => UploadedFile::fake()->image('stale-key.png'),
            ])
            ->assertRedirect(route('platform.billing.index'))
            ->assertSessionHas('error', 'This Item is already updated.Please Refresh')
            ->assertSessionHasInput('payment_reference', 'STALE-KEY')
            ->assertSessionHasInput('note', 'This should be preserved.');

        $this->assertDatabaseHas('tenant_requests', [
            'id' => $requestDetail->id,
            'request_status' => 'waiting_payment',
            'update_key' => 0,
        ]);
    }

    public function test_extension_request_uses_current_plan_pricing(): void
    {
        $this->createDefaultAdminRole();
        $this->createPackages();

        $platformUser = PlatformUser::query()->create([
            'code' => 'PU'.str_pad((string) random_int(0, 99999999), 8, '0', STR_PAD_LEFT),
            'name' => 'Basic User',
            'email' => 'basic-user@example.com',
            'phone' => '09333333333',
            'password' => 'secret123',
            'status' => 'active',
        ]);

        $this->actingAs($platformUser, 'platformuser');

        $tenant = app(TenantManagementService::class)->createTenant(new TenantCreate(
            name: 'Basic Tenant',
            code: 'basic-tenant',
            subdomain: 'basic-subdomain',
            createdByAdmin: false,
            planType: null,
        ));

        $tenant->license()->update([
            'plan_type' => 'basic',
        ]);

        $detail = app(TenantRequestService::class)->createRequest(new TenantRequestCreate(
            tenantId: $tenant->id,
            requestType: 'extension',
            extensionMonths: 3,
        ));

        $this->assertSame('extension', $detail->requestType);
        $this->assertSame('basic', $detail->requestedPlanType);
        $this->assertSame(3, $detail->extensionMonths);
        $this->assertSame('142500.00', $detail->totalCost);
    }

    public function test_trial_package_cannot_be_extended(): void
    {
        $this->createDefaultAdminRole();
        $this->createPackages();

        $platformUser = PlatformUser::query()->create([
            'code' => 'PU'.str_pad((string) random_int(0, 99999999), 8, '0', STR_PAD_LEFT),
            'name' => 'Trial User',
            'email' => 'trial-user@example.com',
            'phone' => '09444444444',
            'password' => 'secret123',
            'status' => 'active',
        ]);

        $this->actingAs($platformUser, 'platformuser');

        $tenant = app(TenantManagementService::class)->createTenant(new TenantCreate(
            name: 'Trial Tenant',
            code: 'trial-tenant',
            subdomain: 'trial-subdomain',
            createdByAdmin: false,
            planType: null,
        ));

        $this->expectException(InvalidTenantRequest::class);

        app(TenantRequestService::class)->createRequest(new TenantRequestCreate(
            tenantId: $tenant->id,
            requestType: 'extension',
            extensionMonths: 1,
        ));
    }

    public function test_platform_admin_can_accept_or_decline_request(): void
    {
        Mail::fake();
        Storage::fake('local');
        $this->createDefaultAdminRole();
        $this->createPackages();

        $platformUser = PlatformUser::query()->create([
            'code' => 'PU'.str_pad((string) random_int(0, 99999999), 8, '0', STR_PAD_LEFT),
            'name' => 'Approval User',
            'email' => 'approval-user@example.com',
            'phone' => '09555555555',
            'password' => 'secret123',
            'status' => 'active',
        ]);

        $platformAdmin = PlatformAdmin::query()->create([
            'code' => 'PA'.str_pad((string) random_int(0, 99999999), 8, '0', STR_PAD_LEFT),
            'name' => 'Platform Admin',
            'username' => 'platformadmin1',
            'email' => 'platform-admin@example.com',
            'password' => 'secret123',
            'status' => 'active',
        ]);

        $this->actingAs($platformUser, 'platformuser');

        $tenant = app(TenantManagementService::class)->createTenant(new TenantCreate(
            name: 'Approval Tenant',
            code: 'approval-tenant',
            subdomain: 'approval-subdomain',
            createdByAdmin: false,
            planType: null,
        ));

        $requestDetail = app(TenantRequestService::class)->createRequest(new TenantRequestCreate(
            tenantId: $tenant->id,
            requestType: 'plan_change',
            requestedPlanType: 'basic',
        ));

        app(TenantRequestService::class)->submitPaymentScreenshot(new TenantRequestPaymentSubmit(
            tenantRequestId: $requestDetail->id,
            updateKey: $requestDetail->updateKey,
            paymentScreenshot: UploadedFile::fake()->image('approval.png'),
        ));

        $this->actingAs($platformAdmin, 'platformadmin');

        $accepted = app(TenantRequestService::class)->acceptRequest($requestDetail->id, 'Accepted by admin');

        $this->assertSame('approved', $accepted->requestStatus);
        $this->assertSame('Accepted by admin', $accepted->adminReviewNote);
        Mail::assertQueued(\App\Mail\PaymentRequestReviewedMail::class, function ($mail): bool {
            return $mail->queue === 'mail';
        });
    }

    protected function createDefaultAdminRole(): TenantRole
    {
        TenantRole::query()->create([
            'tenant_id' => null,
            'name' => 'Owner',
            'description' => 'Default owner role',
            'is_default' => true,
            'permissions' => config('tenant_permissions.roles.Owner.permissions'),
        ]);

        return TenantRole::query()->create([
            'tenant_id' => null,
            'name' => 'Admin',
            'description' => 'Default admin role',
            'is_default' => true,
            'permissions' => config('tenant_permissions.roles.Admin.permissions'),
        ]);
    }

    protected function createPackages(): void
    {
        Package::query()->create([
            'code' => 'basic',
            'name' => 'Basic',
            'description' => 'Basic package',
            'price' => 50000.00,
            'is_active' => true,
        ]);

        Package::query()->create([
            'code' => 'premium',
            'name' => 'Premium',
            'description' => 'Premium package',
            'price' => 100000.00,
            'is_active' => true,
        ]);
    }
}
