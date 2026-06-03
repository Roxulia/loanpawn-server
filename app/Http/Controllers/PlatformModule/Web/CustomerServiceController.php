<?php

namespace App\Http\Controllers\PlatformModule\Web;

use App\DataObjects\RequestObjects\PlatformSupportTicketCreate;
use App\DataObjects\RequestObjects\PlatformSupportTicketReply;
use App\Http\Controllers\Controller;
use App\Services\PlatformModule\PlatformSupportTicketService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class CustomerServiceController extends Controller
{
    public function __construct(
        private PlatformSupportTicketService $ticketService,
    ) {
    }

    public function index(): View
    {
        return view('platform.customer-service.index', [
            'tickets' => $this->ticketService->listForCurrentPlatformUser(),
        ]);
    }

    public function create(Request $request): View
    {
        return view('platform.customer-service.create', [
            'prefillType' => $request->query('type'),
            'prefillMessage' => $request->query('message'),
            'prefillSubject' => $request->query('subject'),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $validated = $this->validateTicket($request);

        $ticket = $this->ticketService->createForCurrentPlatformUser(new PlatformSupportTicketCreate(
            subject: $validated['subject'],
            type: $validated['type'],
            message: $validated['message'],
            attachments: $validated['attachments'] ?? [],
        ));

        return redirect()
            ->route('platform.customer-service.show', $ticket->id)
            ->with('status', 'Support ticket created. Please wait for admin response.');
    }

    public function show(int $ticket): View
    {
        return view('platform.customer-service.show', [
            'ticket' => $this->ticketService->findOwnedTicket($ticket),
        ]);
    }

    public function reply(Request $request, int $ticket): RedirectResponse
    {
        $validated = $this->validateReply($request);

        $this->ticketService->replyAsPlatformUser(new PlatformSupportTicketReply(
            ticketId: $ticket,
            message: $validated['message'],
            attachments: $validated['attachments'] ?? [],
        ));

        return redirect()
            ->route('platform.customer-service.show', $ticket)
            ->with('status', 'Message added.');
    }

    private function validateTicket(Request $request): array
    {
        return $request->validate([
            'subject' => ['required', 'string', 'max:180'],
            'type' => ['required', 'in:bugs,features,support'],
            'message' => ['required', 'string', 'max:5000'],
            'attachments' => ['nullable', 'array', 'max:5'],
            'attachments.*' => ['file', 'max:4096', 'mimes:jpg,jpeg,png,gif,webp,pdf,doc,docx,xls,xlsx,txt,csv,log'],
        ]);
    }

    private function validateReply(Request $request): array
    {
        return $request->validate([
            'message' => ['required', 'string', 'max:5000'],
            'attachments' => ['nullable', 'array', 'max:5'],
            'attachments.*' => ['file', 'max:4096', 'mimes:jpg,jpeg,png,gif,webp,pdf,doc,docx,xls,xlsx,txt,csv,log'],
        ]);
    }
}
