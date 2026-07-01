<?php

namespace Tests\Feature\PlatformModule;

use App\Models\PlatformModule\ManualPaymentRequest;
use App\Models\PlatformModule\PlatformAdmin;
use App\Models\PlatformModule\PlatformUser;
use App\Models\PlatformModule\TenantRequest;
use App\Services\PlatformModule\Telegram\PlatformAdminTelegramNotificationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class TelegramWebhookTest extends TestCase
{
    use RefreshDatabase;

    public function test_reject_callback_declines_pending_tenant_request_for_linked_admin(): void
    {
        config([
            'services.telegram.notifications_enabled' => true,
            'services.telegram.bot_token' => 'test-token',
            'services.telegram.webhook_secret' => 'telegram-secret',
        ]);
        Http::fake([
            'https://api.telegram.org/*' => Http::response(['ok' => true]),
        ]);

        $admin = $this->platformAdmin('123456');
        $user = $this->platformUser();
        $tenantRequest = $this->tenantRequest($user);

        ManualPaymentRequest::query()->create([
            'code' => 'MP0000001',
            'platform_user_id' => $user->id,
            'tenant_request_id' => $tenantRequest->id,
            'amount' => 1000,
            'currency' => 'MMK',
            'status' => 'submitted',
        ]);

        $this->postJson('/api/telegram/webhook', [
            'callback_query' => [
                'id' => 'callback-1',
                'from' => ['id' => 123456],
                'data' => PlatformAdminTelegramNotificationService::callbackData($tenantRequest->id, 'reject'),
            ],
        ], [
            'X-Telegram-Bot-Api-Secret-Token' => 'telegram-secret',
        ])->assertNoContent();

        $this->assertDatabaseHas('tenant_requests', [
            'id' => $tenantRequest->id,
            'request_status' => 'declined',
            'reviewed_by' => $admin->id,
        ]);
        $this->assertDatabaseHas('manual_payment_requests', [
            'tenant_request_id' => $tenantRequest->id,
            'status' => 'rejected',
            'reviewed_by' => $admin->id,
        ]);
    }

    public function test_webhook_rejects_invalid_secret(): void
    {
        config([
            'services.telegram.webhook_secret' => 'telegram-secret',
        ]);

        $this->postJson('/api/telegram/webhook', [
            'callback_query' => ['id' => 'callback-1'],
        ], [
            'X-Telegram-Bot-Api-Secret-Token' => 'wrong',
        ])->assertForbidden();
    }

    public function test_invalid_callback_data_replies_to_chat_with_error_message(): void
    {
        config([
            'services.telegram.notifications_enabled' => false,
            'services.telegram.bot_token' => 'test-token',
            'services.telegram.webhook_secret' => 'telegram-secret',
        ]);
        Http::fake([
            'https://api.telegram.org/*' => Http::response(['ok' => true]),
        ]);

        $this->platformAdmin('123456');

        $this->postJson('/api/telegram/webhook', [
            'callback_query' => [
                'id' => 'callback-1',
                'from' => ['id' => 123456],
                'message' => ['chat' => ['id' => 123456]],
                'data' => 'bad-callback-data',
            ],
        ], [
            'X-Telegram-Bot-Api-Secret-Token' => 'telegram-secret',
        ])->assertNoContent();

        Http::assertSent(function ($request): bool {
            return str_contains($request->url(), '/sendMessage')
                && $request['chat_id'] === '123456'
                && $request['text'] === 'Error occur : Invalid or expired action.';
        });
    }

    public function test_callback_data_signature_must_be_valid(): void
    {
        $valid = PlatformAdminTelegramNotificationService::callbackData(10, 'approve');

        $this->assertSame([
            'tenant_request_id' => 10,
            'action' => 'approve',
        ], PlatformAdminTelegramNotificationService::parseCallbackData($valid));

        $this->assertNull(PlatformAdminTelegramNotificationService::parseCallbackData($valid.'x'));
    }

    private function platformAdmin(string $telegramChatId): PlatformAdmin
    {
        return PlatformAdmin::query()->create([
            'code' => 'PA0000001',
            'name' => 'Platform Admin',
            'username' => 'admin',
            'email' => 'admin@example.com',
            'telegram_chat_id' => $telegramChatId,
            'password' => Hash::make('Password@123'),
            'status' => 'active',
        ]);
    }

    private function platformUser(): PlatformUser
    {
        return PlatformUser::query()->create([
            'code' => 'PU0000001',
            'name' => 'Platform User',
            'email' => 'owner@example.com',
            'password' => Hash::make('Password@123'),
            'status' => 'active',
        ]);
    }

    private function tenantRequest(PlatformUser $user): TenantRequest
    {
        return TenantRequest::query()->create([
            'code' => 'TR0000001',
            'platform_user_id' => $user->id,
            'request_type' => 'extension',
            'requested_plan_type' => 'basic',
            'extension_months' => 1,
            'total_cost' => 1000,
            'currency' => 'MMK',
            'request_status' => 'pending_approval',
        ]);
    }
}
