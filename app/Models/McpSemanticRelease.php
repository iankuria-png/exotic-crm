<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class McpSemanticRelease extends Model
{
    protected $guarded = [];

    protected $casts = ['activated_at' => 'datetime'];
}
