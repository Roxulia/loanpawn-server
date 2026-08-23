<?php

namespace App\Http\Controllers\TenantModule;

use App\Http\Controllers\Controller;
use App\Services\TenantModule\FinancialUnitService;
use Illuminate\Http\JsonResponse;

class FinancialUnitController extends Controller
{
    public function __construct(private FinancialUnitService $service) {}

    public function index(): JsonResponse
    {
        return $this->successResponse($this->service->options());
    }
}
