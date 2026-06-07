<?php

namespace App\Services;

use App\Exceptions\TenantNotFound;
use App\Models\PlatformModule\Tenant;
use App\Models\PlatformTableCode;
use App\Models\TableId;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class TableIdGenerationService extends BaseTenantService
{
    private const GENERATED_HEX_MAX_LENGTH = 3;
    private const TENANT_CODE_HEX_MAX_LENGTH = 4;

    public function generate(string $tableName, CarbonImmutable $date): string
    {
        $tenantId = $this->resolveCurrentTenantId();
        return $this->generateForTenant($tenantId, $tableName, $date);
    }

    public function generateForTenant(int $tenantId, string $tableName, CarbonImmutable $date): string
    {
        $tenant = Tenant::query()->find($tenantId);

        if ($tenant === null) {
            throw new TenantNotFound('Tenant is not found.');
        }

        $year = (int) $date->format('Y');
        $month = (int) $date->format('m');

        $tableId = TableId::query()
            ->where('tenant_id', $tenantId)
            ->where('table_name', $tableName)
            ->where('current_year', $year)
            ->where('current_month', $month)
            ->lockForUpdate()
            ->first();

        if ($tableId === null) {
            $tableId = new TableId();
            $tableId->tenant_id = $tenantId;
            $tableId->table_name = $tableName;
            $tableId->current_year = $year;
            $tableId->current_month = $month;
            $tableId->current_id = 0;
        }

        $tableId->current_id = (int) $tableId->current_id + 1;
        $tableId->save();

        return $this->generatedCode($tableName, $date, (int) $tableId->current_id, $tenant->tenant_code);
    }

    public function generateForPlatform(string $tableName,CarbonImmutable $date)
    {
        $year = (int) $date->format('Y');
        $month = (int) $date->format('m');

        $tableId = PlatformTableCode::query()
            ->where('table_name', $tableName)
            ->where('current_year', $year)
            ->where('current_month', $month)
            ->lockForUpdate()
            ->first();

        if ($tableId === null) {
            $tableId = new PlatformTableCode();
            $tableId->table_name = $tableName;
            $tableId->current_year = $year;
            $tableId->current_month = $month;
            $tableId->current_id = 0;
        }

        $tableId->current_id = (int) $tableId->current_id + 1;
        $tableId->save();

        return $this->generatedCode($tableName,$date, (int) $tableId->current_id,"");
    }

    public function generateTenantCodeSuffix(CarbonImmutable $date): string
    {
        $year = (int) $date->format('Y');

        return DB::transaction(function () use ($year): string {
            $tableId = PlatformTableCode::query()
                ->where('table_name', 'tenants')
                ->where('current_year', $year)
                ->where('current_month', 0)
                ->lockForUpdate()
                ->first();

            if ($tableId === null) {
                $tableId = new PlatformTableCode();
                $tableId->table_name = 'tenants';
                $tableId->current_year = $year;
                $tableId->current_month = 0;
                $tableId->current_id = 0;
            }

            $tableId->current_id = (int) $tableId->current_id + 1;
            $tableId->save();

            return str_pad(
                $this->hexToken($year, (int) $tableId->current_id, self::TENANT_CODE_HEX_MAX_LENGTH),
                self::TENANT_CODE_HEX_MAX_LENGTH,
                '0',
                STR_PAD_LEFT
            );
        });
    }

    protected function prefixFor(string $tableName): string
    {
        return (string) config("code_generation.prefixes.{$tableName}", '');
    }

    protected function generatedCode(string $tableName, CarbonImmutable $date, int $currentId,string $tenantCode): string
    {
        return sprintf(
            '%s%s%s%s',
            $this->prefixFor($tableName),
            $date->format('Ym'),
            $tenantCode,
            str_pad(
                $this->hexToken(0, $currentId, self::GENERATED_HEX_MAX_LENGTH),
                self::GENERATED_HEX_MAX_LENGTH,
                '0',
                STR_PAD_LEFT
            )
        );
    }

    protected function hexToken(int $period, int $currentId, int $maxLength): string
    {
        $hex = strtoupper(dechex($period + $currentId));
        return strlen($hex) <= $maxLength
            ? $hex
            : substr($hex, -$maxLength);
    }
}
