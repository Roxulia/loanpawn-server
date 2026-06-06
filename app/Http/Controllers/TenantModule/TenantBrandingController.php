<?php

namespace App\Http\Controllers\TenantModule;

use App\DataObjects\RequestObjects\TenantBrandingSlipLayoutUpdate;
use App\Http\Controllers\Controller;
use App\Services\TenantModule\TenantBrandingSlipLayoutService;
use App\Utility\MessageCode;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class TenantBrandingController extends Controller
{
    public function __construct(
        private TenantBrandingSlipLayoutService $tenantBrandingSlipLayoutService,
    ) {
    }

    public function showSlipLayouts(): JsonResponse
    {
        return $this->successResponse($this->tenantBrandingSlipLayoutService->getCurrentTenantLayouts()->toArray());
    }

    public function updateSlipLayouts(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'slip_header_layout' => ['nullable', 'array'],
            'slip_footer_layout' => ['nullable', 'array'],
            'update_key' => ['nullable', 'integer', 'min:0'],
        ]);

        if ($validator->fails()) {
            return $this->validationErrorResponse($validator->errors());
        }

        $validated = $validator->validated();
        $branding = $this->tenantBrandingSlipLayoutService->updateCurrentTenantLayouts(
            new TenantBrandingSlipLayoutUpdate(
                slipHeaderLayout: $validated['slip_header_layout'] ?? null,
                slipFooterLayout: $validated['slip_footer_layout'] ?? null,
                updateKey: $validated['update_key'] ?? 0
            )
        );

        return $this->successResponse($branding->toArray(), $this->responseMessage(MessageCode::TenantSlipLayoutUpdated));
    }
}
