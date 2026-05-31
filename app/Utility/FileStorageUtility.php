<?php

namespace App\Utility;

use App\Exceptions\InvalidUploadFile;
use App\Exceptions\StoredFileNotFound;
use Illuminate\Contracts\Filesystem\Factory as FilesystemFactory;
use Illuminate\Http\UploadedFile;
use Symfony\Component\HttpFoundation\StreamedResponse;

class FileStorageUtility
{
    public function __construct(
        private FilesystemFactory $filesystemFactory
    ) {
    }

    public function uploadFile(
        UploadedFile $file,
        string $directory,
        string $disk = 'public',
        ?string $fileNamePrefix = null
    ): string {
        $this->ensureValidUpload($file, MessageCodes::$messages['eb013']);

        return $this->storeFile($file, $directory, $disk, $fileNamePrefix);
    }

    public function uploadImage(
        UploadedFile $file,
        string $directory,
        string $disk = 'public',
        ?string $fileNamePrefix = null
    ): string {
        $this->ensureValidUpload($file, MessageCodes::$messages['eb014']);

        if (! str_starts_with((string) $file->getMimeType(), 'image/')) {
            throw new InvalidUploadFile(MessageCodes::$messages['eb014']);
        }

        return $this->storeFile($file, $directory, $disk, $fileNamePrefix);
    }

    public function retrieveFile(
        string $path,
        string $disk = 'public',
        ?string $downloadName = null
    ): StreamedResponse {
        $storage = $this->filesystemFactory->disk($disk);
        $this->ensureStoredFileExists($path, $disk);

        return $storage->download($path, $downloadName ?? basename($path));
    }

    public function retrieveImage(string $path, string $disk = 'public'): StreamedResponse
    {
        $storage = $this->filesystemFactory->disk($disk);
        $this->ensureStoredFileExists($path, $disk);

        return $storage->response($path);
    }

    public function getFileUrl(string $path, string $disk = 'public'): string
    {
        $this->ensureStoredFileExists($path, $disk);

        return $this->filesystemFactory->disk($disk)->url($path);
    }

    protected function storeFile(
        UploadedFile $file,
        string $directory,
        string $disk,
        ?string $fileNamePrefix
    ): string {
        $storage = $this->filesystemFactory->disk($disk);
        $fileName = $this->buildFileName($file, $fileNamePrefix);
        $storedPath = $storage->putFileAs(trim($directory, '/'), $file, $fileName);

        if ($storedPath === false) {
            throw new InvalidUploadFile(MessageCodes::$messages['eb013']);
        }

        return $storedPath;
    }

    protected function buildFileName(UploadedFile $file, ?string $fileNamePrefix): string
    {
        $extension = $file->getClientOriginalExtension() ?: $file->extension() ?: 'bin';
        $prefix = $fileNamePrefix ? trim($fileNamePrefix, '_- ') : pathinfo($file->getClientOriginalName(), PATHINFO_FILENAME);
        $prefix = preg_replace('/[^A-Za-z0-9_-]/', '_', $prefix ?? 'file') ?: 'file';

        return $prefix.'_'.uniqid().'.'.$extension;
    }

    protected function ensureValidUpload(UploadedFile $file, string $message): void
    {
        if (! $file->isValid()) {
            throw new InvalidUploadFile($message);
        }
    }

    protected function ensureStoredFileExists(string $path, string $disk): void
    {
        if (! $this->filesystemFactory->disk($disk)->exists($path)) {
            throw new StoredFileNotFound();
        }
    }
}
