<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class McpKnowledgeChunk extends Model
{
    protected $guarded = [];

    public function document()
    {
        return $this->belongsTo(McpKnowledgeDocument::class, 'document_id');
    }
}
