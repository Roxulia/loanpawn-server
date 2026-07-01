<?php

namespace App\Events;

use App\Models\PlatformModule\PlatformSupportTicket;
use App\Models\PlatformModule\PlatformSupportTicketMessage;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class PlatformSupportTicketMessageCreated implements ShouldBroadcast
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public PlatformSupportTicket $ticket,
        public PlatformSupportTicketMessage $message,
        public string $recipientType,
    ) {
    }

    public function broadcastOn(): array
    {
        $channels = [
            new PrivateChannel('platform.support-ticket.'.$this->ticket->id),
        ];
        Log::info('Broadcasting PlatformSupportTicketMessageCreated to channels: '.collect($channels)->map(fn ($channel) => $channel->name)->join(', '));

        if ($this->recipientType === 'admin') {
            $channels[] = new PrivateChannel('platform.admin.issued-tickets');
        } else {
            $channels[] = new PrivateChannel('platform.user.'.$this->ticket->platform_user_id.'.customer-service');
        }

        return $channels;
    }

    public function broadcastAs(): string
    {
        return 'platform.support-ticket.message.created';
    }

    public function broadcastWith(): array
    {
        return [
            'ticket' => $this->ticketSummary(),
            'message' => $this->messageSummary(),
        ];
    }

    protected function ticketSummary(): array
    {
        $ticket = $this->ticket->loadMissing('platformUser');

        return [
            'id' => $ticket->id,
            'code' => $ticket->code,
            'subject' => $ticket->subject,
            'type' => $ticket->type,
            'typeLabel' => ucfirst($ticket->type),
            'status' => $ticket->status,
            'userName' => $ticket->platformUser?->name ?? '-',
            'messagesCount' => $ticket->messages()->count(),
            'userUnreadRepliesCount' => (int) $ticket->user_unread_replies_count,
            'createdAt' => $ticket->created_at?->format('Y-m-d') ?? '-',
            'updatedAt' => $ticket->updated_at?->format('Y-m-d') ?? '-',
            'createdAtIso' => $ticket->created_at?->toISOString(),
            'updatedAtIso' => $ticket->updated_at?->toISOString(),
            'adminDetailUrl' => route('admin.issued-tickets.show', $ticket->code),
            'userDetailUrl' => route('platform.customer-service.show', $ticket->code),
        ];
    }

    protected function messageSummary(): array
    {
        $message = $this->message->loadMissing('attachments');

        return [
            'id' => $message->id,
            'ticketId' => $message->platform_support_ticket_id,
            'senderType' => $message->sender_type,
            'senderLabel' => $message->sender_type === 'platform_admin' ? 'Admin' : 'Platform User',
            'message' => $message->message,
            'createdAt' => $message->created_at?->format('Y-m-d H:i') ?? '-',
            'createdAtIso' => $message->created_at?->toISOString(),
            'attachments' => $message->attachments
                ->where('is_deleted', false)
                ->map(fn ($attachment): array => [
                    'name' => $attachment->original_name ?? $attachment->file_path,
                    'url' => asset('storage/'.$attachment->file_path),
                    'type' => $attachment->file_type ?? '-',
                ])
                ->values()
                ->all(),
        ];
    }
}
