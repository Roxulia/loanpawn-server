<?php

namespace Tests\Feature\Utility;

use App\Exceptions\InvalidUploadFile;
use App\Exceptions\StoredFileNotFound;
use App\Utility\FileStorageUtility;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class FileStorageUtilityTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_can_upload_a_regular_file(): void
    {
        Storage::fake('public');

        $file = UploadedFile::fake()->create('agreement.pdf', 100, 'application/pdf');

        $path = app(FileStorageUtility::class)->uploadFile($file, 'tenant-requests/attachments', 'public', 'agreement');

        Storage::disk('public')->assertExists($path);
        $this->assertStringStartsWith('tenant-requests/attachments/agreement_', $path);
        $this->assertStringEndsWith('.pdf', $path);
    }

    public function test_it_can_upload_an_image_and_return_a_url(): void
    {
        Storage::fake('public');

        $image = UploadedFile::fake()->image('logo.png');

        $path = app(FileStorageUtility::class)->uploadImage($image, 'tenants/1/branding', 'public', 'logo');
        $url = app(FileStorageUtility::class)->getFileUrl($path, 'public');

        Storage::disk('public')->assertExists($path);
        $this->assertStringStartsWith('/storage/', parse_url($url, PHP_URL_PATH) ?? '');
    }

    public function test_it_rejects_non_image_upload_for_image_method(): void
    {
        Storage::fake('public');

        $file = UploadedFile::fake()->create('document.pdf', 50, 'application/pdf');

        $this->expectException(InvalidUploadFile::class);

        app(FileStorageUtility::class)->uploadImage($file, 'tenants/1/branding', 'public', 'logo');
    }

    public function test_it_throws_when_retrieving_missing_file(): void
    {
        Storage::fake('public');

        $this->expectException(StoredFileNotFound::class);

        app(FileStorageUtility::class)->retrieveFile('missing/file.pdf', 'public');
    }
}
