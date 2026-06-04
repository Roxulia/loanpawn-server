<?php

namespace App\Services\PlatformModule;

use App\DataObjects\RequestObjects\PlatformSupportTicketCreate;
use App\DataObjects\RequestObjects\PlatformSupportTicketReply;
use App\Events\PlatformSupportTicketCreated;
use App\Events\PlatformSupportTicketMessageCreated;
use App\Events\PlatformSupportTicketStatusChanged;
use App\Exceptions\InvalidTenantRequest;
use App\Exceptions\TenantAccessDenied;
use App\Exceptions\TenantNotFound;
use App\Models\PlatformModule\PlatformAdmin;
use App\Models\PlatformModule\PlatformSupportTicket;
use App\Models\PlatformModule\PlatformSupportTicketMessage;
use App\Models\PlatformModule\PlatformUser;
use App\Repository\PlatformSupportTicketRepository;
use App\Services\TableIdGenerationService;
use App\Support\LogsServiceOperations;
use App\Utility\FileStorageUtility;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;

class PlatformSupportTicketService
{
    use LogsServiceOperations;

    public const TYPE_BUGS = 'bugs';
    public const TYPE_FEATURES = 'features';
    public const TYPE_SUPPORT = 'support';
    public const STATUS_PENDING = 'pending';
    public const STATUS_OPEN = 'open';
    public const STATUS_RESOLVED = 'resolved';

    public function __construct(
        private PlatformSupportTicketRepository $repository,
        private AuthService $authService,
        private FileStorageUtility $fileStorageUtility,
        private TableIdGenerationService $tableIdGenerationService,
    ) {
    }

    public function listForCurrentPlatformUser(int $perPage = 15): LengthAwarePaginator
    {
        $platformUser = $this->currentPlatformUser();

        return $this->repository->paginateByPlatformUser($platformUser->id, $perPage);
    }

    public function listForAdmin(int $perPage = 15): LengthAwarePaginator
    {
        $this->currentPlatformAdmin();

        return $this->repository->paginateAll($perPage);
    }

    public function createForCurrentPlatformUser(PlatformSupportTicketCreate $request): PlatformSupportTicket
    {
        return $this->runLoggedOperation(__METHOD__, function () use ($request): PlatformSupportTicket {
            $platformUser = $this->currentPlatformUser();
            $type = $this->normalizeType($request->type);

            $ticket = DB::transaction(function () use ($platformUser, $request, $type): PlatformSupportTicket {
                $ticket = $this->repository->createTicket([
                    'code' => $this->tableIdGenerationService->generateForPlatform('platform_support_tickets', CarbonImmutable::now()),
                    'platform_user_id' => $platformUser->id,
                    'subject' => $request->subject,
                    'type' => $type,
                    'status' => self::STATUS_PENDING,
                ]);

                $message = $this->repository->createMessage([
                    'platform_support_ticket_id' => $ticket->id,
                    'sender_type' => 'platform_user',
                    'platform_user_id' => $platformUser->id,
                    'message' => $request->message,
                ]);

                $this->storeAttachments($ticket, $message, $request->attachments, 'platform_user', $platformUser->id);

                return $this->repository->findOwnedByPlatformUser($ticket->id, $platformUser->id) ?? $ticket;
            });

            event(new PlatformSupportTicketCreated($ticket));

            return $ticket;
        });
    }

    public function findOwnedTicket(int $ticketId): PlatformSupportTicket
    {
        $platformUser = $this->currentPlatformUser();
        $ticket = $this->repository->findOwnedByPlatformUser($ticketId, $platformUser->id);

        if (! $ticket) {
            throw new TenantAccessDenied('Support ticket is not available for this platform user.');
        }

        return $this->repository->resetUserUnreadReplies($ticket);
    }

    public function findTicketForAdmin(int $ticketId): PlatformSupportTicket
    {
        $this->currentPlatformAdmin();
        $ticket = $this->repository->findForAdmin($ticketId);

        if (! $ticket) {
            throw new TenantNotFound('Support ticket is not found.');
        }

        return $ticket;
    }

    public function replyAsPlatformUser(PlatformSupportTicketReply $request): PlatformSupportTicket
    {
        return $this->runLoggedOperation(__METHOD__, function () use ($request): PlatformSupportTicket {
            $platformUser = $this->currentPlatformUser();
            $ticket = $this->repository->findOwnedByPlatformUser($request->ticketId, $platformUser->id);

            if (! $ticket) {
                throw new TenantAccessDenied('Support ticket is not available for this platform user.');
            }

            $this->ensureCanReply($ticket);

            $ticket = DB::transaction(function () use ($ticket, $request, $platformUser): PlatformSupportTicket {
                $message = $this->repository->createMessage([
                    'platform_support_ticket_id' => $ticket->id,
                    'sender_type' => 'platform_user',
                    'platform_user_id' => $platformUser->id,
                    'message' => $request->message,
                ]);

                $this->storeAttachments($ticket, $message, $request->attachments, 'platform_user', $platformUser->id);
                $ticket->touch();

                return $this->repository->findOwnedByPlatformUser($ticket->id, $platformUser->id) ?? $ticket;
            });

            $message = $ticket->messages->last();
            if ($message instanceof PlatformSupportTicketMessage) {
                event(new PlatformSupportTicketMessageCreated($ticket, $message, 'admin'));
            }

            return $ticket;
        });
    }

    public function replyAsAdmin(PlatformSupportTicketReply $request): PlatformSupportTicket
    {
        return $this->runLoggedOperation(__METHOD__, function () use ($request): PlatformSupportTicket {
            $admin = $this->currentPlatformAdmin();
            $ticket = $this->repository->findForAdmin($request->ticketId);

            if (! $ticket) {
                throw new TenantNotFound('Support ticket is not found.');
            }

            $this->ensureCanReply($ticket);

            $statusChanged = false;

            $ticket = DB::transaction(function () use ($ticket, $request, $admin, &$statusChanged): PlatformSupportTicket {
                if ($ticket->status === self::STATUS_PENDING) {
                    $ticket = $this->repository->updateTicket($ticket, [
                        'status' => self::STATUS_OPEN,
                        'opened_at' => now(),
                        'update_key' => $ticket->update_key + 1,
                    ]);
                    $statusChanged = true;
                }

                $message = $this->repository->createMessage([
                    'platform_support_ticket_id' => $ticket->id,
                    'sender_type' => 'platform_admin',
                    'platform_admin_id' => $admin->id,
                    'message' => $request->message,
                ]);

                $this->storeAttachments($ticket, $message, $request->attachments, 'platform_admin', $admin->id);
                $this->repository->incrementUserUnreadReplies($ticket);
                $ticket->touch();

                return $this->repository->findForAdmin($ticket->id) ?? $ticket;
            });

            $message = $ticket->messages->last();
            if ($statusChanged) {
                event(new PlatformSupportTicketStatusChanged($ticket));
            }
            if ($message instanceof PlatformSupportTicketMessage) {
                event(new PlatformSupportTicketMessageCreated($ticket, $message, 'platform_user'));
            }

            return $ticket;
        });
    }

    public function openAsAdmin(int $ticketId): PlatformSupportTicket
    {
        return $this->runLoggedOperation(__METHOD__, function () use ($ticketId): PlatformSupportTicket {
            $this->currentPlatformAdmin();
            $ticket = $this->findTicketForAdmin($ticketId);

            if ($ticket->status !== self::STATUS_PENDING) {
                throw new InvalidTenantRequest('Only pending support tickets can be opened.');
            }

            $ticket = $this->repository->updateTicket($ticket, [
                'status' => self::STATUS_OPEN,
                'opened_at' => now(),
                'update_key' => $ticket->update_key + 1,
            ]);

            event(new PlatformSupportTicketStatusChanged($ticket));

            return $ticket;
        });
    }

    public function resolveAsAdmin(int $ticketId): PlatformSupportTicket
    {
        return $this->runLoggedOperation(__METHOD__, function () use ($ticketId): PlatformSupportTicket {
            $admin = $this->currentPlatformAdmin();
            $ticket = $this->findTicketForAdmin($ticketId);

            if (! in_array($ticket->status, [self::STATUS_PENDING, self::STATUS_OPEN], true)) {
                throw new InvalidTenantRequest('Only pending or open support tickets can be resolved.');
            }

            $ticket = $this->repository->updateTicket($ticket, [
                'status' => self::STATUS_RESOLVED,
                'resolved_at' => now(),
                'resolved_by' => $admin->id,
                'update_key' => $ticket->update_key + 1,
            ]);

            event(new PlatformSupportTicketStatusChanged($ticket));

            return $ticket;
        });
    }

    protected function normalizeType(string $type): string
    {
        $type = strtolower($type);

        if (! in_array($type, [self::TYPE_BUGS, self::TYPE_FEATURES, self::TYPE_SUPPORT], true)) {
            throw new InvalidTenantRequest('Unsupported support ticket type.');
        }

        return $type;
    }

    protected function ensureCanReply(PlatformSupportTicket $ticket): void
    {
        if ($ticket->status === self::STATUS_RESOLVED) {
            throw new InvalidTenantRequest('Resolved support tickets cannot receive new messages.');
        }
    }

    /**
     * @param array<int, UploadedFile> $attachments
     */
    protected function storeAttachments(
        PlatformSupportTicket $ticket,
        PlatformSupportTicketMessage $message,
        array $attachments,
        string $uploaderType,
        int $uploaderId,
    ): void {
        foreach ($attachments as $index => $attachment) {
            if (! $attachment instanceof UploadedFile) {
                continue;
            }

            $path = $this->fileStorageUtility->uploadFile(
                $attachment,
                'support-tickets/'.$ticket->code.'/messages/'.$message->id,
                'public',
                'attachment_'.($index + 1)
            );

            $this->repository->createAttachment([
                'code' => $this->tableIdGenerationService->generateForPlatform('platform_support_ticket_attachments', CarbonImmutable::now()),
                'platform_support_ticket_message_id' => $message->id,
                'file_path' => $path,
                'file_type' => $attachment->getMimeType(),
                'original_name' => $attachment->getClientOriginalName(),
                'file_size' => $attachment->getSize(),
                'uploaded_by_type' => $uploaderType,
                'uploaded_by_user_id' => $uploaderType === 'platform_user' ? $uploaderId : null,
                'uploaded_by_admin_id' => $uploaderType === 'platform_admin' ? $uploaderId : null,
            ]);
        }
    }

    protected function currentPlatformUser(): PlatformUser
    {
        return $this->authService->getCurrentUser('platformuser');
    }

    protected function currentPlatformAdmin(): PlatformAdmin
    {
        return $this->authService->getCurrentUser('platformadmin');
    }
}
