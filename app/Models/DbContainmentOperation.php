<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class DbContainmentOperation extends Model
{
    protected $table = 'db_containment_operations';

    protected $guarded = [];

    public $incrementing = false;

    protected $keyType = 'string';

    protected $casts = ['cache_requests' => 'encrypted:array', 'selection' => 'array', 'preview' => 'array', 'result' => 'array', 'expires_at' => 'datetime', 'approved_at' => 'datetime', 'cancel_requested_at' => 'datetime'];

    protected $hidden = ['sealed_intent', 'credential_fingerprint', 'cache_requests'];

    public function backup()
    {
        return $this->hasOne(DbContainmentBackup::class, 'operation_id');
    }
}
