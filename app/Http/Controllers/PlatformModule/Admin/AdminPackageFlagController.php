<?php

namespace App\Http\Controllers\PlatformModule\Admin;

use App\DataObjects\RequestObjects\PackageFlagMatrixUpdate;
use App\Http\Controllers\Controller;
use App\Services\PlatformModule\PackageService;
use App\Utility\MessageCode;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class AdminPackageFlagController extends Controller
{
    public function __construct(
        private PackageService $packageService,
    ) {}

    public function index(): View
    {
        return view('platform.admin.package-flags.index', $this->packageService->flagMatrix());
    }

    public function update(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'features' => ['required', 'array'],
            'features.*' => ['required', 'boolean'],
            'packages' => ['required', 'array'],
            'packages.*' => ['required', 'boolean'],
            'mappings' => ['required', 'array'],
            'mappings.*' => ['required', 'boolean'],
        ], [], __('validation.attributes'));

        $payload = new PackageFlagMatrixUpdate(
            featureFlags: $validated['features'],
            packageFlags: $validated['packages'],
            mappingFlags: $validated['mappings'],
        );

        $this->packageService->updateFlags(
            $payload->featureFlags,
            $payload->packageFlags,
            $payload->mappingFlags,
        );

        return redirect()
            ->route('admin.package-flags.index')
            ->with('status', $this->responseMessage(MessageCode::PlatformPackageFlagsUpdated));
    }

    public function updatePlans(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'packages' => ['required', 'array'],
            'packages.*' => ['required', 'boolean'],
        ], [], __('validation.attributes'));

        $this->packageService->updatePlanFlags($validated['packages']);

        return redirect()
            ->route('admin.package-flags.index')
            ->with('status', $this->responseMessage(MessageCode::PlatformPackageFlagsUpdated));
    }

    public function storeFeature(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'code' => ['required', 'string', 'max:80', 'unique:features,code'],
            'description' => ['nullable', 'string'],
        ], [], __('validation.attributes'));

        $this->packageService->createFeature($validated);

        return redirect()
            ->route('admin.package-flags.index')
            ->with('status', $this->responseMessage(MessageCode::PlatformPackageFlagsUpdated));
    }

    public function updateFeatures(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'features' => ['required', 'array'],
            'features.*' => ['required', 'boolean'],
        ], [], __('validation.attributes'));

        $this->packageService->updateFeatureFlags($validated['features']);

        return redirect()
            ->route('admin.package-flags.index')
            ->with('status', $this->responseMessage(MessageCode::PlatformPackageFlagsUpdated));
    }

    public function updateFeatureAssignment(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'assignments' => ['required', 'array'],
            'assignments.*' => ['required', 'array'],
            'assignments.*.*' => ['required', 'boolean'],
        ], [], __('validation.attributes'));

        $this->packageService->updateFeatureAssignments($validated['assignments']);

        return redirect()
            ->route('admin.package-flags.index')
            ->with('status', $this->responseMessage(MessageCode::PlatformPackageFlagsUpdated));
    }
}
