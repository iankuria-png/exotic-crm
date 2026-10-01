<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class DbScanSetting extends Model
{
    protected $table = 'db_scan_settings';

    protected $guarded = [];

    protected $casts = [
        'enabled' => 'boolean',
        'paused' => 'boolean',
        'emergency_stop' => 'boolean',
        'limits' => 'array',
        'revision' => 'integer',
    ];

    public static function current(): self
    {
        return static::query()->orderBy('id')->first()
            ?? static::query()->create(['enabled' => false, 'paused' => false, 'emergency_stop' => false, 'revision' => 1]);
    }
}
