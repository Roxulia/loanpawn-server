<?php

namespace App\Services\PlatformModule;

use App\Models\PlatformModule\PaymentQrImage;
use App\Repository\PaymentQrRepository;
use App\Utility\FileStorageUtility;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\StreamedResponse;

class PaymentQrService
{
    private const DISK = 'local';

    private const DIRECTORY = 'kpay-qr';

    public function __construct(
        private PaymentQrRepository $repository,
        private FileStorageUtility $fileStorageUtility,
    ) {
    }

    public function paginateForAdmin(): LengthAwarePaginator
    {
        return $this->repository->paginate();
    }

    public function active(): ?PaymentQrImage
    {
        return $this->repository->active();
    }

    public function upload(UploadedFile $image): PaymentQrImage
    {
        $path = $this->fileStorageUtility->uploadImage($image, self::DIRECTORY, self::DISK, 'kpay_qr');

        return $this->repository->create([
            'file_path' => $path,
            'original_name' => $image->getClientOriginalName(),
            'mime_type' => $image->getMimeType(),
            'uploaded_by' => Auth::guard('platformadmin')->id(),
        ]);
    }

    public function activate(int $id): PaymentQrImage
    {
        return DB::transaction(function () use ($id): PaymentQrImage {
            $qrImage = $this->findOrFail($id);
            $this->repository->deactivateAllExcept($qrImage->id);

            return $this->repository->update($qrImage, [
                'is_active' => true,
                'activated_at' => now(),
                'update_key' => $qrImage->update_key + 1,
            ]);
        });
    }

    public function streamImage(int $id): StreamedResponse
    {
        $qrImage = $this->findOrFail($id);

        return $this->fileStorageUtility->retrieveImage($qrImage->file_path, self::DISK);
    }

    private function findOrFail(int $id): PaymentQrImage
    {
        $qrImage = $this->repository->find($id);

        if (! $qrImage) {
            abort(404);
        }

        return $qrImage;
    }
}
