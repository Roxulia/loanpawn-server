<?php

namespace App\Http\Controllers\TenantModule;

use App\Http\Controllers\Controller;
use App\Services\TenantModule\TenantRoleService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class TenantRoleController extends Controller
{
    public function __construct(
        private TenantRoleService $tenantRoleService,
    ) {
    }

    public function index(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'exclude_owner' => ['nullable', 'boolean'],
        ]);

        if ($validator->fails()) {
            return $this->validationErrorResponse($validator->errors());
        }

        return $this->successResponse($this->tenantRoleService->listOptions(
            excludeOwner: (bool) ($validator->validated()['exclude_owner'] ?? false),
        ));
    }
}
