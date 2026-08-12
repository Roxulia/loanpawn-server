<?php

namespace App\Http\Controllers\PlatformModule\Admin;

use App\Http\Controllers\Controller;
use App\Models\PlatformModule\Feature;
use App\Models\PlatformModule\Package;
use App\Models\PlatformModule\PackageFeature;
use App\Models\PlatformModule\TenantCategory;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class AdminPlanManagementController extends Controller
{
    public function index(): View
    {
        return view('platform.admin.plans.index', [
            'categories' => TenantCategory::query()
                ->with(['packages' => fn ($query) => $query->orderBy('rank')])
                ->where('is_deleted', false)
                ->orderBy('name')
                ->get(),
            'features' => Feature::query()->where('is_deleted', false)->orderBy('name')->get(),
        ]);
    }

    public function storeCategory(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'code' => ['required', 'string', 'max:60', 'regex:/^[a-z0-9]+(?:-[a-z0-9]+)*$/', 'unique:tenant_categories,code'],
            'description' => ['nullable', 'string', 'max:1000'],
        ]);

        TenantCategory::query()->create($validated + ['is_active' => false]);

        return back()->with('status', 'Tenant category created. Add its trial plan before using it.');
    }

    public function updateCategory(Request $request, TenantCategory $category): RedirectResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'description' => ['nullable', 'string', 'max:1000'],
            'is_active' => ['required', 'boolean'],
        ]);

        if ((bool) $validated['is_active'] && ! $category->packages()->where('is_trial', true)->where('is_active', true)->where('is_deleted', false)->exists()) {
            return back()->with('error', 'An active category must have one active trial plan.');
        }

        $category->update($validated + ['update_key' => $category->update_key + 1]);

        return back()->with('status', 'Tenant category updated.');
    }

    public function destroyCategory(TenantCategory $category): RedirectResponse
    {
        if ($category->tenants()->exists() || $category->packages()->exists()) {
            $category->update(['is_active' => false, 'is_deleted' => true, 'update_key' => $category->update_key + 1]);
            return back()->with('status', 'Referenced category archived.');
        }

        $category->delete();
        return back()->with('status', 'Unused category deleted.');
    }

    public function updatePlan(Request $request, Package $plan): RedirectResponse
    {
        $validated = $this->validatePlan($request, $plan);
        $category = TenantCategory::query()->where('is_deleted', false)->findOrFail($validated['category_id']);

        if ($message = $this->planInvariantError($validated, $category, $plan)) {
            return back()->withInput()->with('error', $message);
        }

        $isReferenced = $plan->licenses()->exists() || $plan->requestedBy()->exists();
        if ($isReferenced && (int) $validated['category_id'] !== (int) $plan->category_id) {
            return back()->with('error', 'A referenced plan cannot move to another category.');
        }

        $plan->update($validated + ['update_key' => $plan->update_key + 1]);
        return back()->with('status', 'Plan updated.');
    }

    public function destroyPlan(Package $plan): RedirectResponse
    {
        if ($plan->is_trial && $plan->category?->is_active) {
            return back()->with('error', 'Create a replacement trial before removing this category trial.');
        }

        if ($plan->licenses()->exists() || $plan->requestedBy()->exists() || $plan->incomingTransitions()->exists()) {
            $plan->update(['is_active' => false, 'is_deleted' => true, 'update_key' => $plan->update_key + 1]);
            return back()->with('status', 'Referenced plan archived.');
        }

        $plan->delete();
        return back()->with('status', 'Unused plan deleted.');
    }

    private function validatePlan(Request $request, ?Package $plan = null): array
    {
        return $request->validate([
            'category_id' => ['required', 'integer', Rule::exists('tenant_categories', 'id')->where('is_deleted', false)],
            'code' => ['required', 'string', 'max:60', 'regex:/^[a-z0-9]+(?:-[a-z0-9]+)*$/', Rule::unique('packages', 'code')->ignore($plan?->id)],
            'name' => ['required', 'string', 'max:80'],
            'description' => ['nullable', 'string', 'max:1000'],
            'price' => ['required', 'numeric', 'min:0'],
            'rank' => ['required', 'integer', 'min:0'],
            'max_slip_per_month' => ['nullable', 'integer', 'min:0'],
            'max_staff_count' => ['nullable', 'integer', 'min:0'],
            'max_account_count' => ['nullable', 'integer', 'min:1'],
            'max_currency_type_count' => ['nullable', 'integer', 'min:0'],
            'max_exchange_pair_count' => ['nullable', 'integer', 'min:0'],
            'is_trial' => ['required', 'boolean'],
            'is_active' => ['required', 'boolean'],
        ]);
    }

    private function planInvariantError(array $data, TenantCategory $category, ?Package $plan = null): ?string
    {
        if ((bool) $data['is_trial'] && (float) $data['price'] !== 0.0) {
            return 'A trial plan must have a zero price.';
        }
        if (! (bool) $data['is_trial'] && (bool) $data['is_active'] && (float) $data['price'] <= 0) {
            return 'Set a positive price before activating a paid plan.';
        }
        if ($category->packages()->where('rank', $data['rank'])->when($plan, fn ($query) => $query->whereKeyNot($plan->id))->where('is_deleted', false)->exists()) {
            return 'Plan rank must be unique within its category.';
        }
        if ((bool) $data['is_trial'] && $category->packages()->where('is_trial', true)->when($plan, fn ($query) => $query->whereKeyNot($plan->id))->where('is_deleted', false)->exists()) {
            return 'A category can have only one trial plan.';
        }
        if ($plan?->is_trial && (! (bool) $data['is_trial'] || ! (bool) $data['is_active']) && $category->is_active) {
            return 'An active category must retain an active trial plan.';
        }
        return null;
    }
}
