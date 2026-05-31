<?php

namespace App\Models\CoreModule;

use App\Traits\BelongToTenant;
use Illuminate\Database\Eloquent\Model;

class TenantBranding extends Model
{
    use BelongToTenant;

    protected $table = 'tenant_branding';

    protected $fillable = [
        'tenant_id',
        'tenant_code',
        'logo_path',
        'favicon_path',
        'primary_color',
        'secondary_color',
        'accent_color',
        'slip_header_text',
        'slip_header_layout',
        'slip_footer_text',
        'slip_footer_layout',
    ];

    protected function casts(): array
    {
        return [
            'slip_header_layout' => 'array',
            'slip_footer_layout' => 'array',
        ];
    }

}
