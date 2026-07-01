<?php

namespace App\Repository;

use App\Models\PlatformModule\PaymentQrImage;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;

class PaymentQrRepository
{
    public function paginate(int $perPage = 15): LengthAwarePaginator
    {
        return PaymentQrImage::query()
            ->where('is_deleted', false)
            ->with('uploader')
            ->orderByDesc('is_active')
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->paginate($perPage);
    }

    public function create(array $data): PaymentQrImage
    {
        return PaymentQrImage::query()->create($data);
    }

    public function find(int $id): ?PaymentQrImage
    {
        return PaymentQrImage::query()
            ->where('is_deleted', false)
            ->with('uploader')
            ->find($id);
    }

    public function active(): ?PaymentQrImage
    {
        return PaymentQrImage::query()
            ->where('is_deleted', false)
            ->where('is_active', true)
            ->with('uploader')
            ->latest('activated_at')
            ->latest('id')
            ->first();
    }

    public function deactivateAllExcept(int $id): void
    {
        PaymentQrImage::query()
            ->where('is_deleted', false)
            ->whereKeyNot($id)
            ->update([
                'is_active' => false,
                'update_key' => DB::raw('update_key + 1'),
                'updated_at' => now(),
            ]);
    }

    public function update(PaymentQrImage $qrImage, array $data): PaymentQrImage
    {
        $qrImage->update($data);

        return $qrImage->refresh();
    }
}
