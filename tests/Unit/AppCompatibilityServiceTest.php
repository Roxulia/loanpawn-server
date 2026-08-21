<?php

namespace Tests\Unit;

use App\DataObjects\RequestObjects\AppVersionRequest;
use App\Services\AppCompatibilityService;
use RuntimeException;
use Tests\TestCase;

class AppCompatibilityServiceTest extends TestCase
{
    public function test_it_accepts_equal_and_newer_versions(): void
    {
        config(['lonepawn.frontend_min_supported_version' => '1.2.0']);
        $service = app(AppCompatibilityService::class);

        $this->assertTrue($service->check(new AppVersionRequest('1.2.0'))->isSupported);
        $this->assertTrue($service->check(new AppVersionRequest('1.3.0'))->isSupported);
    }

    public function test_it_rejects_older_missing_and_malformed_versions(): void
    {
        config(['lonepawn.frontend_min_supported_version' => '1.2.0']);
        $service = app(AppCompatibilityService::class);

        $this->assertFalse($service->check(new AppVersionRequest('1.1.9'))->isSupported);
        $this->assertFalse($service->check(new AppVersionRequest(null))->isSupported);
        $this->assertFalse($service->check(new AppVersionRequest('latest'))->isSupported);
    }

    public function test_it_fails_when_server_version_configuration_is_invalid(): void
    {
        config(['lonepawn.frontend_min_supported_version' => 'invalid']);

        $this->expectException(RuntimeException::class);

        app(AppCompatibilityService::class)->check(new AppVersionRequest('1.2.0'));
    }
}
