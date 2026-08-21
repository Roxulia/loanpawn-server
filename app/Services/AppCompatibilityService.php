<?php

namespace App\Services;

use App\DataObjects\RequestObjects\AppVersionRequest;
use App\DataObjects\ResponseObjects\AppCompatibilityResource;
use RuntimeException;

class AppCompatibilityService
{
    private const VERSION_PATTERN = '/^\d+\.\d+\.\d+(?:-[0-9A-Za-z.-]+)?(?:\+[0-9A-Za-z.-]+)?$/';

    public function check(AppVersionRequest $request): AppCompatibilityResource
    {
        $minimumVersion = $this->minimumSupportedVersion();
        $installedVersion = $request->installedVersion;
        $isSupported = $installedVersion !== null
            && $this->isValidVersion($installedVersion)
            && version_compare($installedVersion, $minimumVersion, '>=');

        return new AppCompatibilityResource(
            installedVersion: $installedVersion,
            minimumSupportedVersion: $minimumVersion,
            isSupported: $isSupported,
        );
    }

    private function minimumSupportedVersion(): string
    {
        $version = config('lonepawn.frontend_min_supported_version');

        if (! is_string($version) || ! $this->isValidVersion($version)) {
            throw new RuntimeException('FRONTEND_MIN_SUPPORTED_VERSION must be a semantic version.');
        }

        return $version;
    }

    private function isValidVersion(string $version): bool
    {
        return preg_match(self::VERSION_PATTERN, $version) === 1;
    }
}
