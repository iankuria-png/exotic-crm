<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class McpKnowledgeSyncRun extends Model
{
    protected $guarded = [];

    protected $casts = ['started_at' => 'datetime', 'heartbeat_at' => 'datetime', 'finished_at' => 'datetime'];
}
