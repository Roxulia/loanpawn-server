<?php

namespace App\Http\Controllers\PlatformModule\Web;

use App\DataObjects\RequestObjects\PlatformSupportTicketCreate;
use App\DataObjects\RequestObjects\PlatformSupportTicketReply;
use App\Http\Controllers\Controller;
use App\Services\PlatformModule\PlatformSupportTicketService;
use App\Utility\MessageCode;
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
            ->route('platform.customer-service.show', $ticket->code)
            ->with('status', $this->responseMessage(MessageCode::PlatformSupportTicketCreated));
    }

    public function show(string $ticketCode): View
    {
        return view('platform.customer-service.show', [
            'ticket' => $this->ticketService->findOwnedTicket($ticketCode),
        ]);
    }

    public function reply(Request $request, string $ticketCode): RedirectResponse
    {
        $validated = $this->validateReply($request);

        $this->ticketService->replyAsPlatformUser(new PlatformSupportTicketReply(
            ticketCode: $ticketCode,
            message: $validated['message'],
            attachments: $validated['attachments'] ?? [],
        ));

        return redirect()
            ->route('platform.customer-service.show', $ticketCode)
            ->with('status', $this->responseMessage(MessageCode::PlatformSupportMessageAdded));
    }

    private function validateTicket(Request $request): array
    {
        return $request->validate([
            'subject' => ['required', 'string', 'max:180'],
            'type' => ['required', 'in:bugs,features,support'],
            'message' => ['required', 'string', 'max:5000'],
            'attachments' => ['nullable', 'array', 'max:5'],
            'attachments.*' => ['file', 'max:4096', 'mimes:jpg,jpeg,png,gif,webp,pdf,doc,docx,xls,xlsx,txt,csv,log'],
        ], [], __('validation.attributes'));
    }

    private function validateReply(Request $request): array
    {
        return $request->validate([
            'message' => ['required', 'string', 'max:5000'],
            'attachments' => ['nullable', 'array', 'max:5'],
            'attachments.*' => ['file', 'max:4096', 'mimes:jpg,jpeg,png,gif,webp,pdf,doc,docx,xls,xlsx,txt,csv,log'],
        ], [], __('validation.attributes'));
    }
}
