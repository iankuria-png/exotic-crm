<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class DbScanConfigVersion extends Model
{
    protected $table = 'db_scan_config_versions';

    protected $guarded = [];

    public $timestamps = false;

    protected $casts = [
        'created_at' => 'datetime',
    ];

    public function decoded(): array
    {
        return json_decode((string) $this->config, true) ?: [];
    }
}
