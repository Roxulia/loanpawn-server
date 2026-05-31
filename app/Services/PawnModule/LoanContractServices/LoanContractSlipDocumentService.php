<?php

namespace App\Services\PawnModule\LoanContractServices;

use App\DataObjects\RequestObjects\SlipDocumentRenderRequest;
use App\DataObjects\ResponseObjects\SlipDocumentLayoutConfig;
use App\Models\CoreModule\TenantBranding;
use App\Models\PawnModule\PawnCollateralItem;
use App\Models\PawnModule\PawnLoanContractSlip;
use App\Services\PawnModule\SlipDocumentBarcodeService;
use App\Services\PawnModule\SlipDocumentService;
use App\Services\PlatformModule\TenantServices\TenantDetailService;
use App\Services\TenantModule\TenantBrandingSlipLayoutService;
use App\Services\TenantModule\TenantUserPermissionService;
use Illuminate\Support\Str;
use Mpdf\Output\Destination;

class LoanContractSlipDocumentService
{
    protected const DEFAULT_DECIMAL_PLACES = 2;
    protected const INTEREST_RATE_DECIMAL_PLACES = 4;
    protected const EMPTY_BINARY_RESPONSE = '';
    protected const PDF_FILE_SUFFIX = '-slip.pdf';
    protected const JEWELLERY_TYPE = 'Jewellery';
    protected const UNKNOWN_MATERIAL = 'Unknown Material';
    protected const NUMERIC_TRIM_CHAR = '0';
    protected const DECIMAL_POINT = '.';
    protected const DUMMY_PREVIEW_SLIP_NO = 'DUMMY-SLIP-0001';

    public function __construct(
        private LookUpService $loanContractLookUpService,
        private TenantDetailService $tenantDetailService,
        private TenantBrandingSlipLayoutService $tenantBrandingSlipLayoutService,
        private TenantUserPermissionService $permissionService,
        private SlipDocumentService $slipDocumentService,
        private SlipDocumentBarcodeService $barcodeService,
    ) {
    }

    public function getLayoutConfig(): SlipDocumentLayoutConfig
    {
        $this->permissionService->authorizeLoanContractList();

        return $this->slipDocumentService->getLayoutConfig();
    }

    public function previewHtml(SlipDocumentRenderRequest $request): string
    {
        $this->permissionService->authorizeLoanContractList();
        $paper = $this->slipDocumentService->resolvePaperSettings($request);
        $branding = $this->tenantBrandingSlipLayoutService->getCurrentTenantBrandingModel();
        $tenantDetail = $this->tenantDetailService->getCurrentTenant();
        $context = $request->slipNo === self::DUMMY_PREVIEW_SLIP_NO
            ? $this->buildDummyLoanContractSlipContext($branding, $paper, $tenantDetail)
            : $this->buildLoanContractSlipContext($this->resolveSlip($request->slipNo), $branding, $paper, $tenantDetail);

        return view('pawn.slips.preview', [
            'paper' => $paper,
            'fontStack' => config('slip_document.fonts.preview_stack'),
            'headerHtml' => $this->slipDocumentService->renderLayout($branding->slip_header_layout ?? [], 'header', $context),
            'footerHtml' => $this->slipDocumentService->renderLayout($branding->slip_footer_layout ?? [], 'footer', $context),
            'document' => $context,
        ])->render();
    }

    /**
     * @return array{filename: string, content: string}
     */
    public function downloadPdf(SlipDocumentRenderRequest $request): array
    {
        $html = $this->previewHtml($request);
        $paper = $this->slipDocumentService->resolvePaperSettings($request);
        $mpdf = $this->slipDocumentService->buildMpdf($paper);
        $mpdf->WriteHTML($html);

        return [
            'filename' => Str::slug($request->slipNo).self::PDF_FILE_SUFFIX,
            'content' => $mpdf->Output(self::EMPTY_BINARY_RESPONSE, Destination::STRING_RETURN),
        ];
    }

    protected function resolveSlip(string $slipNo): PawnLoanContractSlip
    {
        return $this->loanContractLookUpService->findModelBySlipNo($slipNo)
            ->loadMissing(['customer', 'interestType', 'slipItems.materialType']);
    }

    protected function buildLoanContractSlipContext(PawnLoanContractSlip $slip, TenantBranding $branding, array $paper, object $tenantDetail): array
    {
        $logoDataUri = $this->slipDocumentService->imageDataUri($branding->logo_path);
        $barcodeSvg = $this->barcodeService->renderSvg($slip->slip_no);
        $items = $slip->slipItems
            ->map(function (PawnCollateralItem $item): array {
                return [
                    'name' => $item->name,
                    'type' => $item->type,
                    'quantity' => (int) $item->quantity,
                    'minimumRetailPrice' => number_format((float) $item->minimum_retail_price, self::DEFAULT_DECIMAL_PLACES, self::DECIMAL_POINT, self::EMPTY_BINARY_RESPONSE),
                    'estimatedValue' => number_format((float) $item->estimated_value, self::DEFAULT_DECIMAL_PLACES, self::DECIMAL_POINT, self::EMPTY_BINARY_RESPONSE),
                    'description' => $this->collateralDescription($item),
                ];
            })
            ->all();

        return [
            'paper' => $paper,
            'slip' => [
                'id' => $slip->id,
                'slipNo' => $slip->slip_no,
                'createdDate' => $slip->created_date?->toDateString(),
                'expireDate' => $slip->expire_date?->toDateString(),
                'loanAmount' => number_format((float) $slip->loan_amount, self::DEFAULT_DECIMAL_PLACES, self::DECIMAL_POINT, self::EMPTY_BINARY_RESPONSE),
                'interestRate' => number_format((float) $slip->interest_rate, self::INTEREST_RATE_DECIMAL_PLACES, self::DECIMAL_POINT, self::EMPTY_BINARY_RESPONSE),
                'status' => $slip->status,
                'notes' => $slip->notes,
                'interestType' => $slip->interestType?->name,
            ],
            'customer' => [
                'name' => $slip->customer?->name,
                'email' => $slip->customer?->email,
                'phone' => $slip->customer?->phone,
                'address' => $slip->customer?->address,
            ],
            'tenant' => [
                'logoDataUri' => $logoDataUri,
                'name' => $tenantDetail->name,
                'phone' => $tenantDetail->tenant_contact?->phone,
                'address' => $tenantDetail->tenant_contact?->address,
                'branding' => $branding,
            ],
            'barcodeSvg' => $barcodeSvg,
            'items' => $items,
        ];
    }

    protected function buildDummyLoanContractSlipContext(TenantBranding $branding, array $paper, object $tenantDetail): array
    {
        $logoDataUri = $this->slipDocumentService->imageDataUri($branding->logo_path);
        $barcodeSvg = $this->barcodeService->renderSvg(self::DUMMY_PREVIEW_SLIP_NO);

        return [
            'paper' => $paper,
            'slip' => [
                'id' => 0,
                'slipNo' => self::DUMMY_PREVIEW_SLIP_NO,
                'createdDate' => now()->toDateString(),
                'expireDate' => now()->addDays(90)->toDateString(),
                'loanAmount' => number_format(1500000, self::DEFAULT_DECIMAL_PLACES, self::DECIMAL_POINT, self::EMPTY_BINARY_RESPONSE),
                'interestRate' => number_format(3.5, self::INTEREST_RATE_DECIMAL_PLACES, self::DECIMAL_POINT, self::EMPTY_BINARY_RESPONSE),
                'status' => 'active',
                'notes' => 'Dummy preview slip for testing the loan contract document template.',
                'interestType' => 'Monthly',
            ],
            'customer' => [
                'name' => 'Daw Mya Mya',
                'email' => 'mya.preview@example.com',
                'phone' => '+95 9 123 456 789',
                'address' => 'No. 12, Merchant Road, Yangon',
            ],
            'tenant' => [
                'logoDataUri' => $logoDataUri,
                'name' => $tenantDetail->name,
                'phone' => $tenantDetail->tenant_contact?->phone,
                'address' => $tenantDetail->tenant_contact?->address,
                'branding' => $branding,
            ],
            'barcodeSvg' => $barcodeSvg,
            'items' => [
                [
                    'name' => 'Gold Ring',
                    'type' => self::JEWELLERY_TYPE,
                    'quantity' => 1,
                    'minimumRetailPrice' => number_format(1800000, self::DEFAULT_DECIMAL_PLACES, self::DECIMAL_POINT, self::EMPTY_BINARY_RESPONSE),
                    'estimatedValue' => number_format(1650000, self::DEFAULT_DECIMAL_PLACES, self::DECIMAL_POINT, self::EMPTY_BINARY_RESPONSE),
                    'description' => 'Gold, 1 kyat 2 pal 0 yway, gemstones included',
                ],
                [
                    'name' => 'Laptop',
                    'type' => 'Electronics',
                    'quantity' => 1,
                    'minimumRetailPrice' => number_format(850000, self::DEFAULT_DECIMAL_PLACES, self::DECIMAL_POINT, self::EMPTY_BINARY_RESPONSE),
                    'estimatedValue' => number_format(700000, self::DEFAULT_DECIMAL_PLACES, self::DECIMAL_POINT, self::EMPTY_BINARY_RESPONSE),
                    'description' => 'Used business laptop | Lenovo',
                ],
            ],
        ];
    }

    protected function collateralDescription(PawnCollateralItem $item): string
    {
        if ($item->type === self::JEWELLERY_TYPE) {
            $material = $item->materialType?->name ?? self::UNKNOWN_MATERIAL;

            return trim(sprintf(
                '%s, %s kyat %s pal %s yway%s',
                $material,
                rtrim(rtrim((string) $item->kyat, self::NUMERIC_TRIM_CHAR), self::DECIMAL_POINT),
                rtrim(rtrim((string) $item->pal, self::NUMERIC_TRIM_CHAR), self::DECIMAL_POINT),
                rtrim(rtrim((string) $item->yway, self::NUMERIC_TRIM_CHAR), self::DECIMAL_POINT),
                $item->contains_gemstones ? ', gemstones included' : ''
            ));
        }

        return trim(implode(' | ', array_filter([
            $item->description,
            $item->brand_name,
        ])));
    }
}
