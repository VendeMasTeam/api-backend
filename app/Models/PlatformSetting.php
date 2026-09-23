<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PlatformSetting extends Model
{
    protected $fillable = [
        'key',
        'brand_name',
        'logo_disk',
        'logo_path',
    ];
}
