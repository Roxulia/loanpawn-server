<?php

namespace App\Http\Controllers\PlatformModule\Admin;

use App\DataObjects\RequestObjects\PlatformSupportTicketReply;
use App\Http\Controllers\Controller;
use App\Services\PlatformModule\PlatformSupportTicketService;
use App\Utility\MessageCode;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class AdminIssuedTicketController extends Controller
{
    public function __construct(
        private PlatformSupportTicketService $ticketService,
    ) {
    }

    public function index(): View
    {
        return view('platform.admin.issued-tickets.index', [
            'tickets' => $this->ticketService->listForAdmin(),
        ]);
    }

    public function show(string $ticketCode): View
    {
        return view('platform.admin.issued-tickets.show', [
            'ticket' => $this->ticketService->findTicketForAdmin($ticketCode),
        ]);
    }

    public function reply(Request $request, string $ticketCode): RedirectResponse
    {
        $validated = $request->validate([
            'message' => ['required', 'string', 'max:5000'],
            'attachments' => ['nullable', 'array', 'max:5'],
            'attachments.*' => ['file', 'max:4096', 'mimes:jpg,jpeg,png,gif,webp,pdf,doc,docx,xls,xlsx,txt,csv,log'],
        ], [], __('validation.attributes'));

        $this->ticketService->replyAsAdmin(new PlatformSupportTicketReply(
            ticketCode: $ticketCode,
            message: $validated['message'],
            attachments: $validated['attachments'] ?? [],
        ));

        return redirect()
            ->route('admin.issued-tickets.show', $ticketCode)
            ->with('status', $this->responseMessage(MessageCode::PlatformSupportMessageAdded));
    }

    public function changeStatus(Request $request, string $ticketCode): RedirectResponse
    {
        $validated = $request->validate([
            'status' => ['required', 'string', 'in:open,resolved'],
        ], [], __('validation.attributes'));

        $ticket = $this->ticketService->changeStatusAsAdmin($ticketCode, $validated['status']);
        $messageCode = $ticket->status === PlatformSupportTicketService::STATUS_RESOLVED
            ? MessageCode::PlatformSupportTicketResolved
            : MessageCode::PlatformSupportTicketOpened;

        return redirect()
            ->route('admin.issued-tickets.show', $ticketCode)
            ->with('status', $this->responseMessage($messageCode));
    }

    public function open(string $ticketCode): RedirectResponse
    {
        $this->ticketService->openAsAdmin($ticketCode);

        return redirect()
            ->route('admin.issued-tickets.show', $ticketCode)
            ->with('status', $this->responseMessage(MessageCode::PlatformSupportTicketOpened));
    }

    public function resolve(string $ticketCode): RedirectResponse
    {
        $this->ticketService->resolveAsAdmin($ticketCode);

        return redirect()
            ->route('admin.issued-tickets.show', $ticketCode)
            ->with('status', $this->responseMessage(MessageCode::PlatformSupportTicketResolved));
    }
}
