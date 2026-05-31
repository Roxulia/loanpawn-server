<?php

namespace App\Support;

use App\Models\PlatformModule\Tenant;

class TenantContext
{
    protected ?int $tenantId = null;
    protected ?string $tenantName = null;

    public function set(null|int|Tenant $tenant): void
    {
        if ($tenant instanceof Tenant) {
            $this->tenantId = $tenant->getKey();
            $this->tenantName = $tenant->name;
            return;
        }

        $this->tenantId = $tenant;
        $this->tenantName = null;
    }

    public function clear(): void
    {
        $this->tenantId = null;
        $this->tenantName = null;
    }

    public function id(): ?int
    {
        return $this->tenantId;
    }

    public function name(): ?string
    {
        return $this->tenantName;
    }

    public function hasTenant(): bool
    {
        return $this->tenantId !== null;
    }
}
