<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class McpSchemaAudit extends Model
{
    protected $guarded = [];

    protected $casts = ['affected_capabilities' => 'array', 'rule_ids' => 'array', 'started_at' => 'datetime', 'completed_at' => 'datetime'];
}
