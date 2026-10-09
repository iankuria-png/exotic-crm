<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class DbContainmentCampaign extends Model
{
    protected $table = 'db_containment_campaigns';

    protected $guarded = [];

    public $incrementing = false;

    protected $keyType = 'string';

    protected $casts = ['members' => 'array', 'expires_at' => 'datetime', 'approved_at' => 'datetime'];
}
