<?php

namespace App\Http\Controllers\PlatformModule\Admin;

use App\DataObjects\RequestObjects\PlatformUserUpsert;
use App\Http\Controllers\Controller;
use App\Rules\PasswordRules;
use App\Services\PlatformModule\PlatformUserService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class AdminPlatformUserController extends Controller
{
    public function __construct(
        private PlatformUserService $platformUserService,
    ) {
    }

    public function index(): View
    {
        return view('platform.admin.platform-users.index', [
            'platformUsers' => $this->platformUserService->paginateAll(),
        ]);
    }

    public function create(): View
    {
        return view('platform.admin.platform-users.create');
    }

    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate($this->rules());

        $this->platformUserService->create($this->payload($validated));

        return redirect()
            ->route('admin.platform-users.index')
            ->with('status', 'Platform user created.');
    }

    public function edit(int $platformUser): View
    {
        return view('platform.admin.platform-users.edit', [
            'platformUser' => $this->platformUserService->findById($platformUser),
        ]);
    }

    public function update(Request $request, int $platformUser): RedirectResponse
    {
        $validated = $request->validate($this->rules($platformUser, false));

        $this->platformUserService->update($platformUser, $this->payload($validated));

        return redirect()
            ->route('admin.platform-users.edit', $platformUser)
            ->with('status', 'Platform user updated.');
    }

    public function destroy(int $platformUser): RedirectResponse
    {
        $this->platformUserService->delete($platformUser);

        return redirect()
            ->route('admin.platform-users.index')
            ->with('status', 'Platform user deleted.');
    }

    private function rules(?int $platformUserId = null, bool $requirePassword = true): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255', Rule::unique('platform_users', 'email')->ignore($platformUserId)],
            'phone' => ['nullable', 'string', 'max:20'],
            'password' => [$requirePassword ? 'required' : 'nullable', 'string', PasswordRules::strong(), 'confirmed'],
            'status' => ['required', 'string', 'in:active,inactive,suspended'],
        ];
    }

    private function payload(array $validated): PlatformUserUpsert
    {
        return new PlatformUserUpsert(
            name: $validated['name'],
            email: $validated['email'],
            phone: $validated['phone'] ?? null,
            password: $validated['password'] ?? null,
            status: $validated['status'],
        );
    }
}
