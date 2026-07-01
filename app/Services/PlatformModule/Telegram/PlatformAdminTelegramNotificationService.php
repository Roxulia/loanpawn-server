<?php

namespace App\Services\PlatformModule\Telegram;

use App\Models\PlatformModule\ManualPaymentAttachment;
use App\Models\PlatformModule\PlatformSupportTicket;
use App\Models\PlatformModule\PlatformSupportTicketAttachment;
use App\Models\PlatformModule\TenantRequest;
use App\Repository\PlatformAdminRepository;

class PlatformAdminTelegramNotificationService
{
    public function __construct(
        private PlatformAdminRepository $adminRepository,
        private TelegramBotService $telegramBotService,
    ) {
    }

    public function sendTenantRequestCreated(int $tenantRequestId): void
    {
        $tenantRequest = TenantRequest::query()
            ->with(['tenant', 'platformUser'])
            ->find($tenantRequestId);

        if (! $tenantRequest) {
            return;
        }

        $this->sendToAdmins($this->tenantRequestText('New tenant request', $tenantRequest));
    }

    public function sendTenantRequestUpdated(int $tenantRequestId): void
    {
        $tenantRequest = TenantRequest::query()
            ->with(['tenant', 'platformUser', 'reviewer'])
            ->find($tenantRequestId);

        if (! $tenantRequest) {
            return;
        }

        $this->sendToAdmins($this->tenantRequestText('Tenant request updated', $tenantRequest));
    }

    public function sendPaymentScreenshot(int $manualPaymentAttachmentId): void
    {
        $attachment = ManualPaymentAttachment::query()
            ->with(['manualPaymentRequest.tenantRequest.tenant', 'manualPaymentRequest.platformUser'])
            ->find($manualPaymentAttachmentId);

        $paymentRequest = $attachment?->manualPaymentRequest;
        $tenantRequest = $paymentRequest?->tenantRequest;

        if (! $attachment || ! $paymentRequest || ! $tenantRequest) {
            return;
        }

        $caption = $this->tenantRequestText('Payment screenshot received', $tenantRequest)
            ."\nPayment reference: ".$this->value($paymentRequest->payment_reference)
            ."\nPayment note: ".$this->value($paymentRequest->note);

        $buttons = [[
            [
                'text' => 'Approve',
                'callback_data' => self::callbackData($tenantRequest->id, 'approve'),
            ],
            [
                'text' => 'Reject',
                'callback_data' => self::callbackData($tenantRequest->id, 'reject'),
            ],
        ]];

        foreach ($this->adminRepository->activeTelegramAdmins() as $admin) {
            $this->telegramBotService->sendPhoto(
                (string) $admin->telegram_chat_id,
                'local',
                $attachment->file_path,
                $caption,
                $buttons
            );
        }
    }

    public function sendSupportTicketCreated(int $ticketId): void
    {
        $ticket = PlatformSupportTicket::query()
            ->with(['platformUser'])
            ->find($ticketId);

        if (! $ticket) {
            return;
        }

        $this->sendToAdmins($this->supportTicketText('New support ticket', $ticket));
    }

    public function sendSupportTicketAttachment(int $attachmentId): void
    {
        $attachment = PlatformSupportTicketAttachment::query()
            ->with(['message.ticket.platformUser'])
            ->find($attachmentId);

        $message = $attachment?->message;
        $ticket = $message?->ticket;

        if (! $attachment || ! $message || ! $ticket || $attachment->uploaded_by_type !== 'platform_user') {
            return;
        }

        $text = $this->supportTicketText('Support ticket attachment uploaded', $ticket)
            ."\nAttachment: ".$this->value($attachment->original_name)
            ."\nType: ".$this->value($attachment->file_type);

        $this->sendToAdmins($text);
    }

    public static function callbackData(int $tenantRequestId, string $action): string
    {
        $actionCode = $action === 'approve' ? 'a' : 'r';
        $payload = "tr:{$tenantRequestId}:{$actionCode}";
        $signature = substr(hash_hmac('sha256', $payload, config('app.key')), 0, 16);

        return "{$payload}:{$signature}";
    }

    public static function parseCallbackData(string $callbackData): ?array
    {
        $parts = explode(':', $callbackData);

        if (count($parts) !== 4 || $parts[0] !== 'tr' || ! ctype_digit($parts[1])) {
            return null;
        }

        $payload = implode(':', array_slice($parts, 0, 3));
        $expectedSignature = substr(hash_hmac('sha256', $payload, config('app.key')), 0, 16);

        if (! hash_equals($expectedSignature, $parts[3])) {
            return null;
        }

        $action = match ($parts[2]) {
            'a' => 'approve',
            'r' => 'reject',
            default => null,
        };

        if ($action === null) {
            return null;
        }

        return [
            'tenant_request_id' => (int) $parts[1],
            'action' => $action,
        ];
    }

    private function sendToAdmins(string $text): void
    {
        foreach ($this->adminRepository->activeTelegramAdmins() as $admin) {
            $this->telegramBotService->sendMessage((string) $admin->telegram_chat_id, $text);
        }
    }

    private function tenantRequestText(string $title, TenantRequest $tenantRequest): string
    {
        return '<b>'.$this->escape($title).'</b>'
            ."\nCode: ".$this->escape($tenantRequest->code)
            ."\nTenant: ".$this->escape($tenantRequest->tenant?->name ?? '-')
            ."\nOwner: ".$this->escape($tenantRequest->platformUser?->email ?? '-')
            ."\nType: ".$this->escape($tenantRequest->request_type ?? '-')
            ."\nPlan: ".$this->escape($tenantRequest->requested_plan_type ?? '-')
            ."\nStatus: ".$this->escape($tenantRequest->request_status)
            ."\nAmount: ".$this->escape($tenantRequest->total_cost.' '.$tenantRequest->currency);
    }

    private function supportTicketText(string $title, PlatformSupportTicket $ticket): string
    {
        return '<b>'.$this->escape($title).'</b>'
            ."\nCode: ".$this->escape($ticket->code)
            ."\nOwner: ".$this->escape($ticket->platformUser?->email ?? '-')
            ."\nType: ".$this->escape($ticket->type)
            ."\nStatus: ".$this->escape($ticket->status)
            ."\nSubject: ".$this->escape($ticket->subject);
    }

    private function value(?string $value): string
    {
        return $this->escape($value === null || $value === '' ? '-' : $value);
    }

    private function escape(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
