<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class McpOAuthAuthorizationCode extends Model
{
    protected $table = 'mcp_oauth_authorization_codes';

    protected $fillable = ['code_hash', 'client_id', 'user_id', 'redirect_uri', 'code_challenge', 'code_challenge_method', 'abilities', 'expires_at', 'consumed_at'];

    protected $casts = ['abilities' => 'array', 'expires_at' => 'datetime', 'consumed_at' => 'datetime'];
}
