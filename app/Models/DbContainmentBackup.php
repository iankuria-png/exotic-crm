<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class DbContainmentBackup extends Model
{
    protected $table = 'db_containment_backups';

    protected $guarded = [];

    public $incrementing = false;

    protected $keyType = 'string';

    protected $casts = ['expires_at' => 'datetime', 'purged_at' => 'datetime'];

    protected $hidden = ['storage_key', 'cipher_digest'];
}
