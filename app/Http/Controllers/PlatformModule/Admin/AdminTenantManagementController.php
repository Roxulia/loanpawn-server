<?php

namespace App\Http\Controllers\PlatformModule\Admin;

use App\Http\Controllers\Controller;
use App\Repository\TenantRepository;
use Illuminate\View\View;
use Illuminate\Http\Request;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use App\Models\PlatformModule\Package;
use App\Models\PlatformModule\Tenant;
use App\Models\PlatformModule\TenantRequest;
use App\Models\PlatformModule\TenantLicensePlanTransition;

class AdminTenantManagementController extends Controller
{
    public function __construct(
        private TenantRepository $tenantRepository,
    ) {
    }

    public function index(): View
    {
        return view('platform.admin.tenants.index', [
            'tenants' => $this->tenantRepository->paginateAll(),
            'plans' => Package::query()->with('category')->where('is_active', true)->where('is_deleted', false)->orderBy('category_id')->orderBy('rank')->get(),
        ]);
    }

    public function changePlan(Request $request, Tenant $tenant): RedirectResponse
    {
        $validated = $request->validate([
            'plan_id' => ['required', 'integer', 'exists:packages,id'],
            'effective' => ['required', 'in:immediate,scheduled'],
            'duration_months' => ['nullable', 'integer', 'in:1,3,6,12'],
        ]);
        $plan = Package::query()->where('is_active', true)->where('is_deleted', false)->findOrFail($validated['plan_id']);
        $license = $tenant->license()->firstOrFail();
        $adminId = Auth::guard('platformadmin')->id();

        if ($validated['effective'] === 'scheduled' && empty($validated['duration_months'])) {
            return back()->with('error', 'Choose a duration for a scheduled plan change.');
        }

        DB::transaction(function () use ($validated, $plan, $license, $tenant, $adminId): void {
            if ($validated['effective'] === 'immediate') {
                $license->update([
                    'plan_id' => $plan->id,
                    'plan_type' => $plan->code,
                    'status' => 'active',
                    'approved_by' => $adminId,
                    'update_key' => $license->update_key + 1,
                ]);
                $tenant->update(['category_id' => $plan->category_id, 'update_key' => $tenant->update_key + 1]);
                return;
            }

            if ($license->scheduledPlanTransition()->exists()) {
                abort(422, 'This tenant already has a scheduled plan change.');
            }
            $startsAt = $license->expires_at ?? now();
            $tenantRequest = TenantRequest::query()->create([
                'code' => 'ADMIN-'.Str::upper(Str::random(12)),
                'tenant_id' => $tenant->id,
                'requested_category_id' => $plan->category_id,
                'requested_plan_id' => $plan->id,
                'platform_user_id' => $tenant->platform_user_id,
                'request_type' => 'plan_change',
                'requested_plan_type' => $plan->code,
                'extension_months' => (int) $validated['duration_months'],
                'total_cost' => 0,
                'currency' => 'MMK',
                'business_info' => ['admin_direct' => true],
                'request_status' => 'approved',
                'reviewed_by' => $adminId,
                'reviewed_at' => now(),
            ]);
            TenantLicensePlanTransition::query()->create([
                'tenant_license_id' => $license->id,
                'tenant_request_id' => $tenantRequest->id,
                'from_plan_id' => $license->plan_id,
                'to_plan_id' => $plan->id,
                'from_plan_type' => $license->plan?->code ?? $license->plan_type,
                'to_plan_type' => $plan->code,
                'starts_at' => $startsAt,
                'expires_at' => $startsAt->copy()->addMonths((int) $validated['duration_months']),
                'status' => 'scheduled',
                'approved_by' => $adminId,
            ]);
        });

        return back()->with('status', 'Tenant category and plan updated.');
    }
}
