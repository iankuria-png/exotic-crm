<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class McpStaffAlias extends Model
{
    protected $fillable = ['user_id', 'agent_alias', 'active', 'updated_by'];

    protected $casts = ['active' => 'boolean'];

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
