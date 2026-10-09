<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class DbContainmentFileObservation extends Model
{
    protected $table = 'db_containment_file_observations';

    protected $guarded = [];

    public $incrementing = false;

    protected $keyType = 'string';

    protected $casts = ['metadata' => 'array', 'observed_at' => 'datetime'];

    protected $hidden = ['sealed_identity'];
}
