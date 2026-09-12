<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class McpKnowledgeDocument extends Model
{
    protected $guarded = [];

    protected $casts = ['audiences' => 'array', 'lifecycle_stages' => 'array', 'departments' => 'array'];

    public function chunks()
    {
        return $this->hasMany(McpKnowledgeChunk::class, 'document_id');
    }

    public function version()
    {
        return $this->belongsTo(McpKnowledgeVersion::class, 'version_id');
    }
}
