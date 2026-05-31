<?php

namespace App\Http\Controllers\PawnModule;

use App\DataObjects\RequestObjects\SlipDocumentRenderRequest;
use App\Http\Controllers\Controller;
use App\Services\PawnModule\LoanContractServices\LoanContractSlipDocumentService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Symfony\Component\HttpFoundation\Response;

class SlipDocumentController extends Controller
{
    public function __construct(
        private LoanContractSlipDocumentService $loanContractSlipDocumentService,
    ) {
    }

    public function config(): Response
    {
        return response()->json([
            'data' => $this->loanContractSlipDocumentService->getLayoutConfig()->toArray(),
        ]);
    }

    public function preview(Request $request, string $slipNo): Response
    {
        $renderRequest = $this->validateRenderRequest($request, $slipNo);
        $html = $this->loanContractSlipDocumentService->previewHtml($renderRequest);

        return response($html, 200, ['Content-Type' => 'text/html; charset=UTF-8']);
    }

    public function download(Request $request, string $slipNo): Response
    {
        $renderRequest = $this->validateRenderRequest($request, $slipNo);
        $pdf = $this->loanContractSlipDocumentService->downloadPdf($renderRequest);

        return response($pdf['content'], 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'attachment; filename="'.$pdf['filename'].'"',
        ]);
    }

    protected function validateRenderRequest(Request $request, string $slipNo): SlipDocumentRenderRequest
    {
        $validator = Validator::make([
            ...$request->all(),
            'slip_no' => $slipNo,
        ], [
            'slip_no' => ['required', 'string', 'max:100'],
            'paper_type' => ['required', 'string'],
            'orientation' => ['nullable', 'string', 'in:portrait,landscape'],
        ]);

        if ($validator->fails()) {
            abort(response()->json([
                'message' => 'Validation failed.',
                'errors' => $validator->errors(),
            ], 422));
        }

        $validated = $validator->validated();

        return new SlipDocumentRenderRequest(
            slipNo: $validated['slip_no'],
            paperType: $validated['paper_type'],
            orientation: $validated['orientation'] ?? 'portrait',
        );
    }
}
