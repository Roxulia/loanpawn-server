<?php

namespace App\Http\Controllers\TenantModule;

use App\DataObjects\RequestObjects\AccountingDayScheduleUpdate;
use App\Http\Controllers\Controller;
use App\Services\TenantModule\TenantAccountingDayService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class TenantAccountingDayController extends Controller
{
    public function __construct(private TenantAccountingDayService $service) {}

    public function current(): JsonResponse
    {
        return $this->successResponse($this->service->current()?->toArray());
    }

    public function index(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        if ($validator->fails()) {
            return $this->validationErrorResponse($validator->errors());
        }

        return $this->successResponse(
            $this->service->list((int) ($validator->validated()['per_page'] ?? 15))->toArray(),
        );
    }

    public function show(string $businessDate): JsonResponse
    {
        $validator = Validator::make(['business_date' => $businessDate], [
            'business_date' => ['required', 'date_format:Y-m-d'],
        ]);

        if ($validator->fails()) {
            return $this->validationErrorResponse($validator->errors());
        }

        return $this->successResponse($this->service->summary($businessDate)->toArray());
    }

    public function open(): JsonResponse
    {
        return $this->successResponse($this->service->openCurrent()->toArray());
    }

    public function close(): JsonResponse
    {
        return $this->successResponse($this->service->closeCurrent()->toArray());
    }

    public function schedule(): JsonResponse
    {
        return $this->successResponse($this->service->schedule()->toArray());
    }

    public function updateSchedule(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'days' => ['required', 'array', 'min:1', 'max:7'],
            'days.*.weekday' => ['required', 'integer', 'between:0,6', 'distinct'],
            'days.*.is_enabled' => ['required', 'boolean'],
            'days.*.open_time' => ['required', 'date_format:H:i'],
            'days.*.close_time' => ['required', 'date_format:H:i'],
        ]);

        if ($validator->fails()) {
            return $this->validationErrorResponse($validator->errors());
        }

        foreach ($validator->validated()['days'] as $index => $day) {
            if ($day['close_time'] <= $day['open_time']) {
                return $this->validationErrorResponse([
                    "days.{$index}.close_time" => ['Close time must be after open time.'],
                ]);
            }
        }

        return $this->successResponse(
            $this->service->updateSchedule(new AccountingDayScheduleUpdate($validator->validated()['days']))->toArray(),
        );
    }
}
