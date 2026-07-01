<?php

namespace App\Http\Controllers\PlatformModule;

use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Repository\PlatformAdminRepository;
use App\Services\PlatformModule\Telegram\PlatformAdminTelegramNotificationService;
use App\Services\PlatformModule\Telegram\TelegramBotService;
use App\Services\PlatformModule\TenantRequestService;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Log;
use Throwable;

class TelegramWebhookController extends Controller
{
    public function __construct(
        private PlatformAdminRepository $adminRepository,
        private TenantRequestService $tenantRequestService,
        private TelegramBotService $telegramBotService,
    ) {
    }

    public function __invoke(Request $request): Response
    {
        if (! $this->hasValidSecret($request)) {
            abort(403);
        }

        $callbackQuery = $request->input('callback_query');

        if (! is_array($callbackQuery)) {
            return response()->noContent();
        }

        $callbackQueryId = (string) ($callbackQuery['id'] ?? '');
        $fromId = data_get($callbackQuery, 'from.id');
        $chatId = data_get($callbackQuery, 'message.chat.id') ?? $fromId;
        $callbackData = (string) ($callbackQuery['data'] ?? '');

        if ($callbackQueryId === '' || $fromId === null || $callbackData === '') {
            return response()->noContent();
        }

        $admin = $this->adminRepository->findActiveByTelegramChatId((string) $fromId);

        if (! $admin) {
            $this->replyCallbackError(
                $callbackQueryId,
                (string) $chatId,
                'This Telegram account is not linked to an active platform admin.'
            );

            return response()->noContent();
        }

        $payload = PlatformAdminTelegramNotificationService::parseCallbackData($callbackData);

        if ($payload === null) {
            $this->replyCallbackError($callbackQueryId, (string) $chatId, 'Invalid or expired action.');

            return response()->noContent();
        }

        try {
            if ($payload['action'] === 'approve') {
                $this->tenantRequestService->acceptRequestAsAdmin($payload['tenant_request_id'], $admin);
                $this->telegramBotService->answerCallbackQuery($callbackQueryId, 'Tenant request approved.');
            } else {
                $this->tenantRequestService->declineRequestAsAdmin($payload['tenant_request_id'], $admin);
                $this->telegramBotService->answerCallbackQuery($callbackQueryId, 'Tenant request rejected.');
            }
        } catch (ApiException $exception) {
            $this->replyCallbackError($callbackQueryId, (string) $chatId, $exception->getMessage());
        } catch (Throwable $exception) {
            Log::error('Telegram callback failed.', [
                'exception' => $exception,
                'payload' => $payload,
                'telegram_from_id' => $fromId,
            ]);
            $this->replyCallbackError($callbackQueryId, (string) $chatId, 'Unable to complete this action.');
        }

        return response()->noContent();
    }

    private function hasValidSecret(Request $request): bool
    {
        $secret = (string) config('services.telegram.webhook_secret');

        return $secret !== ''
            && hash_equals($secret, (string) $request->header('X-Telegram-Bot-Api-Secret-Token', ''));
    }

    private function replyCallbackError(string $callbackQueryId, string $chatId, string $message): void
    {
        $message = trim($message) !== '' ? $message : 'Unknown error.';

        $this->telegramBotService->answerCallbackQuery($callbackQueryId, $message, true);
        $this->telegramBotService->sendSystemMessage(
            $chatId,
            'Error occur : '.htmlspecialchars($message, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')
        );
    }
}
