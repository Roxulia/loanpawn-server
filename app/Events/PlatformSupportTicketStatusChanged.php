<?php

namespace App\Events;

use App\Models\PlatformModule\PlatformSupportTicket;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class PlatformSupportTicketStatusChanged implements ShouldBroadcast
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public PlatformSupportTicket $ticket,
    ) {
    }

    public function broadcastOn(): array
    {
        return [
            new PrivateChannel('platform.user.'.$this->ticket->platform_user_id.'.customer-service'),
            new PrivateChannel('platform.support-ticket.'.$this->ticket->id),
        ];
    }

    public function broadcastAs(): string
    {
        return 'platform.support-ticket.status.changed';
    }

    public function broadcastWith(): array
    {
        return [
            'ticket' => $this->ticketSummary(),
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
}
