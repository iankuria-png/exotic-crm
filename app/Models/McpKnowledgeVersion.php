<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class McpKnowledgeVersion extends Model
{
    protected $guarded = [];

    protected $casts = ['validation_report' => 'array', 'validated_at' => 'datetime', 'promoted_at' => 'datetime'];

    public function documents()
    {
        return $this->hasMany(McpKnowledgeDocument::class, 'version_id');
    }
}
