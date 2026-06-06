<?php

namespace App\Http\Controllers\TenantModule;

use App\DataObjects\RequestObjects\OnlineSyncLogEntry;
use App\DataObjects\RequestObjects\OnlineSyncPushRequest;
use App\Http\Controllers\Controller;
use App\Services\OnlineSyncService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class OnlineSyncController extends Controller
{
    public function __construct(
        private OnlineSyncService $onlineSyncService,
    ) {
    }

    public function push(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'syncLogs' => ['required', 'array', 'max:100'],
            'syncLogs.*.id' => ['nullable', 'integer'],
            'syncLogs.*.tableName' => ['required', 'string', 'max:100'],
            'syncLogs.*.activityType' => ['required', 'string', 'max:30'],
            'syncLogs.*.recordId' => ['nullable', 'string', 'max:100'],
            'syncLogs.*.recordData' => ['nullable', 'string'],
            'syncLogs.*.createdAt' => ['nullable', 'string', 'max:50'],
        ]);

        $result = $this->onlineSyncService->push(new OnlineSyncPushRequest(
            syncLogs: array_map(
                fn (array $log): OnlineSyncLogEntry => new OnlineSyncLogEntry(
                    id: $log['id'] ?? null,
                    tableName: $log['tableName'],
                    activityType: $log['activityType'],
                    recordId: $log['recordId'] ?? null,
                    recordData: $log['recordData'] ?? null,
                    createdAt: $log['createdAt'] ?? null,
                ),
                $validated['syncLogs']
            )
        ));

        if ($result->failed > 0) {
            return $this->errorResponse('Online sync completed with failures.', $result->toArray(), 207);
        }

        return $this->successResponse($result->toArray());
    }
}
