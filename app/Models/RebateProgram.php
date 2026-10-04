<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class RebateProgram extends Model
{
    protected $guarded = ['id'];

    protected $casts = ['draft_revision' => 'integer', 'published_revision' => 'integer', 'draft_json' => 'array', 'test_client_ids' => 'array', 'kill_switch' => 'boolean', 'starts_at' => 'datetime', 'ends_at' => 'datetime'];

    public function platform()
    {
        return $this->belongsTo(Platform::class);
    }

    public function revisions()
    {
        return $this->hasMany(RebateProgramRevision::class);
    }
}
