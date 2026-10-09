<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class DbContainmentMarket extends Model
{
    protected $table = 'db_containment_markets';

    protected $guarded = [];

    public $incrementing = false;

    protected $keyType = 'string';

    protected $casts = ['configuration' => 'encrypted:array', 'enabled' => 'boolean', 'filesystem_enabled' => 'boolean', 'quarantine_enabled' => 'boolean'];

    protected $primaryKey = 'platform_id';

    protected $hidden = ['configuration'];
}
