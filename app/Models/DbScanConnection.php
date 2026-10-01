<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class DbScanConnection extends Model
{
    protected $table = 'db_scan_connections';

    protected $guarded = [];

    protected $casts = [
        'username' => 'encrypted',
        'password' => 'encrypted',
        'tls_ca' => 'encrypted',
        'enabled' => 'boolean',
        'port' => 'integer',
        'config_version' => 'integer',
        'preflight_config_version' => 'integer',
        'preflight_at' => 'datetime',
        'capabilities' => 'array',
        'revision' => 'integer',
    ];

    protected $hidden = ['username', 'password', 'tls_ca'];

    public function platform()
    {
        return $this->belongsTo(Platform::class);
    }

    /** Preflight proof is only valid for the exact credential/config version it tested. */
    public function preflightValid(): bool
    {
        return $this->preflight_status === 'passed'
            && (int) $this->preflight_config_version === (int) $this->config_version;
    }
}
