<?php

namespace App\Repository;

use App\Models\PlatformModule\PlatformSupportTicket;
use App\Models\PlatformModule\PlatformSupportTicketAttachment;
use App\Models\PlatformModule\PlatformSupportTicketMessage;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

class PlatformSupportTicketRepository
{
    public function createTicket(array $data): PlatformSupportTicket
    {
        return PlatformSupportTicket::query()->create($data);
    }

    public function createMessage(array $data): PlatformSupportTicketMessage
    {
        return PlatformSupportTicketMessage::query()->create($data);
    }

    public function createAttachment(array $data): PlatformSupportTicketAttachment
    {
        return PlatformSupportTicketAttachment::query()->create($data);
    }

    public function paginateByPlatformUser(int $platformUserId, int $perPage = 15): LengthAwarePaginator
    {
        return PlatformSupportTicket::query()
            ->where('is_deleted', false)
            ->where('platform_user_id', $platformUserId)
            ->withCount('messages')
            ->orderByDesc('updated_at')
            ->orderByDesc('id')
            ->paginate($perPage);
    }

    public function paginateAll(int $perPage = 15): LengthAwarePaginator
    {
        return PlatformSupportTicket::query()
            ->where('is_deleted', false)
            ->with('platformUser')
            ->withCount('messages')
            ->orderByRaw("CASE status WHEN 'pending' THEN 1 WHEN 'open' THEN 2 ELSE 3 END")
            ->orderByDesc('updated_at')
            ->orderByDesc('id')
            ->paginate($perPage);
    }

    public function findOwnedByPlatformUser(int $ticketId, int $platformUserId): ?PlatformSupportTicket
    {
        return PlatformSupportTicket::query()
            ->where('is_deleted', false)
            ->where('platform_user_id', $platformUserId)
            ->with([
                'platformUser',
                'resolver',
                'messages' => fn ($query) => $query->orderBy('id'),
                'messages.attachments' => fn ($query) => $query->where('is_deleted', false)->orderBy('id'),
                'messages.platformUser',
                'messages.platformAdmin',
            ])
            ->find($ticketId);
    }

    public function findForAdmin(int $ticketId): ?PlatformSupportTicket
    {
        return PlatformSupportTicket::query()
            ->where('is_deleted', false)
            ->with([
                'platformUser',
                'resolver',
                'messages' => fn ($query) => $query->orderBy('id'),
                'messages.attachments' => fn ($query) => $query->where('is_deleted', false)->orderBy('id'),
                'messages.platformUser',
                'messages.platformAdmin',
            ])
            ->find($ticketId);
    }

    public function updateTicket(PlatformSupportTicket $ticket, array $data): PlatformSupportTicket
    {
        $ticket->update($data);

        return $ticket->refresh();
    }

    public function incrementUserUnreadReplies(PlatformSupportTicket $ticket): void
    {
        PlatformSupportTicket::query()
            ->where('id', $ticket->id)
            ->increment('user_unread_replies_count');
    }

    public function resetUserUnreadReplies(PlatformSupportTicket $ticket): PlatformSupportTicket
    {
        if ((int) $ticket->user_unread_replies_count === 0) {
            return $ticket;
        }

        $ticket->update(['user_unread_replies_count' => 0]);

        return $ticket->refresh();
    }
}
