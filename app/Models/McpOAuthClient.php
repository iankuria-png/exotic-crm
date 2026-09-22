<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class McpOAuthClient extends Model
{
    protected $table = 'mcp_oauth_clients';

    protected $fillable = ['client_id', 'client_name', 'redirect_uris', 'grant_abilities', 'active'];

    protected $casts = ['redirect_uris' => 'array', 'grant_abilities' => 'array', 'active' => 'boolean'];
}
