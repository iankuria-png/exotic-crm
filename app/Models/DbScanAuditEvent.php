<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class DbScanAuditEvent extends Model
{
    protected $table = 'db_scan_audit_events';

    protected $guarded = [];

    public $timestamps = false;

    protected $casts = [
        'before' => 'array',
        'after' => 'array',
        'created_at' => 'datetime',
    ];
}
