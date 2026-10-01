<?php

namespace App\Http\Controllers\PlatformModule\Admin;

use App\Http\Controllers\Controller;
use App\Services\PlatformModule\CatalogUnitAdministrationService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\View\View;

class AdminCatalogUnitController extends Controller
{
    public function __construct(private CatalogUnitAdministrationService $service)
    {
    }

    public function index(): View
    {
        return view('platform.admin.catalog-units.index', [
            'units' => $this->service->list(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = Validator::make($request->all(), [
            'code' => ['required', 'string', 'max:40', 'regex:/^[a-zA-Z0-9_-]+$/'],
            'name' => ['required', 'string', 'max:100'],
            'symbol' => ['nullable', 'string', 'max:30'],
        ])->validate();

        $this->service->create($data);

        return back()->with('status', 'Catalog unit created.');
    }

    public function update(Request $request, int $id): RedirectResponse
    {
        $data = Validator::make($request->all(), [
            'name' => ['required', 'string', 'max:100'],
            'symbol' => ['nullable', 'string', 'max:30'],
            'is_active' => ['required', 'boolean'],
        ])->validate();

        $this->service->update($id, $data);

        return back()->with('status', 'Catalog unit updated.');
    }
}
