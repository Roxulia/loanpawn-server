<?php

namespace App\Http\Controllers\PlatformModule\Admin;

use App\DataObjects\RequestObjects\PlatformSupportTicketReply;
use App\Http\Controllers\Controller;
use App\Services\PlatformModule\PlatformSupportTicketService;
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

    public function show(int $ticket): View
    {
        return view('platform.admin.issued-tickets.show', [
            'ticket' => $this->ticketService->findTicketForAdmin($ticket),
        ]);
    }

    public function reply(Request $request, int $ticket): RedirectResponse
    {
        $validated = $request->validate([
            'message' => ['required', 'string', 'max:5000'],
            'attachments' => ['nullable', 'array', 'max:5'],
            'attachments.*' => ['file', 'max:4096', 'mimes:jpg,jpeg,png,gif,webp,pdf,doc,docx,xls,xlsx,txt,csv,log'],
        ]);

        $this->ticketService->replyAsAdmin(new PlatformSupportTicketReply(
            ticketId: $ticket,
            message: $validated['message'],
            attachments: $validated['attachments'] ?? [],
        ));

        return redirect()
            ->route('admin.issued-tickets.show', $ticket)
            ->with('status', 'Message added.');
    }

    public function open(int $ticket): RedirectResponse
    {
        $this->ticketService->openAsAdmin($ticket);

        return redirect()
            ->route('admin.issued-tickets.show', $ticket)
            ->with('status', 'Ticket opened.');
    }

    public function resolve(int $ticket): RedirectResponse
    {
        $this->ticketService->resolveAsAdmin($ticket);

        return redirect()
            ->route('admin.issued-tickets.show', $ticket)
            ->with('status', 'Ticket resolved.');
    }
}
