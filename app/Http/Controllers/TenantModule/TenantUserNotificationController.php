<?php

namespace App\Http\Controllers\TenantModule;

use App\DataObjects\ResponseObjects\TenantUserNotificationResource;
use App\Http\Controllers\Controller;
use App\Services\TenantModule\TenantUserNotificationService;
use App\Utility\MessageCode;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class TenantUserNotificationController extends Controller
{
    public function __construct(private TenantUserNotificationService $service) {}

    public function index(Request $request): JsonResponse
    {
        $perPage = max(1, min(50, (int) $request->query('per_page', 20)));

        return $this->successResponse($this->service->list($perPage)->toArray());
    }

    public function markRead(string $id): JsonResponse
    {
        return $this->successResponse(
            TenantUserNotificationResource::fromModel($this->service->markRead($id))->toArray(),
            $this->responseMessage(MessageCode::TenantNotificationRead),
        );
    }

    public function markAllRead(): JsonResponse
    {
        $this->service->markAllRead();

        return $this->successResponse(
            ['unread_count' => 0],
            $this->responseMessage(MessageCode::TenantNotificationsRead),
        );
    }
}
