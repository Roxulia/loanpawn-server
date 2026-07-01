<?php

namespace App\Services\PlatformModule\Telegram;

use Illuminate\Contracts\Filesystem\Factory as FilesystemFactory;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class TelegramBotService
{
    public function __construct(
        private FilesystemFactory $filesystemFactory,
    ) {
    }

    public function sendMessage(string $chatId, string $text, array $buttons = []): void
    {
        if (! $this->notificationsEnabled()) {
            return;
        }

        $this->sendBotMessage($chatId, $text, $buttons);
    }

    public function sendSystemMessage(string $chatId, string $text, array $buttons = []): void
    {
        if (! $this->hasBotToken()) {
            return;
        }

        $this->sendBotMessage($chatId, $text, $buttons);
    }

    private function sendBotMessage(string $chatId, string $text, array $buttons = []): void
    {
        $this->post('sendMessage', [
            'chat_id' => $chatId,
            'text' => $text,
            'parse_mode' => 'HTML',
            'reply_markup' => $this->replyMarkup($buttons),
        ]);
    }

    public function sendPhoto(string $chatId, string $disk, string $path, string $caption, array $buttons = []): void
    {
        if (! $this->notificationsEnabled()) {
            return;
        }

        $storage = $this->filesystemFactory->disk($disk);

        if (! $storage->exists($path)) {
            Log::warning('Telegram photo not sent because file is missing.', [
                'disk' => $disk,
                'path' => $path,
            ]);

            return;
        }

        $stream = $storage->readStream($path);

        if ($stream === false) {
            Log::warning('Telegram photo not sent because file stream could not be opened.', [
                'disk' => $disk,
                'path' => $path,
            ]);

            return;
        }

        try {
            $response = Http::attach('photo', $stream, basename($path))
                ->post($this->apiUrl('sendPhoto'), [
                    'chat_id' => $chatId,
                    'caption' => $caption,
                    'parse_mode' => 'HTML',
                    'reply_markup' => $this->replyMarkup($buttons),
                ]);

            if ($response->failed()) {
                Log::warning('Telegram sendPhoto request failed.', [
                    'status' => $response->status(),
                    'body' => $response->body(),
                ]);
            }
        } finally {
            if (is_resource($stream)) {
                fclose($stream);
            }
        }
    }

    public function answerCallbackQuery(string $callbackQueryId, string $text, bool $alert = false): void
    {
        if (! $this->hasBotToken()) {
            return;
        }

        $this->post('answerCallbackQuery', [
            'callback_query_id' => $callbackQueryId,
            'text' => $text,
            'show_alert' => $alert,
        ]);
    }

    public function enabled(): bool
    {
        return $this->notificationsEnabled();
    }

    public function notificationsEnabled(): bool
    {
        return (bool) config('services.telegram.notifications_enabled')
            && $this->hasBotToken();
    }

    public function hasBotToken(): bool
    {
        return filled(config('services.telegram.bot_token'));
    }

    private function post(string $method, array $payload): void
    {
        $response = Http::post($this->apiUrl($method), array_filter(
            $payload,
            fn ($value) => $value !== null
        ));

        if ($response->failed()) {
            Log::warning('Telegram API request failed.', [
                'method' => $method,
                'status' => $response->status(),
                'body' => $response->body(),
            ]);
        }
    }

    private function apiUrl(string $method): string
    {
        return 'https://api.telegram.org/bot'.config('services.telegram.bot_token').'/'.$method;
    }

    private function replyMarkup(array $buttons): ?string
    {
        if ($buttons === []) {
            return null;
        }

        return json_encode(['inline_keyboard' => $buttons], JSON_THROW_ON_ERROR);
    }
}
