<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PlatformSetting extends Model
{
    protected $fillable = [
        'key',
        'brand_name',
        'assets_disk',
        'logo_light_path',
        'logo_dark_path',
        'favicon_path',
    ];
}
