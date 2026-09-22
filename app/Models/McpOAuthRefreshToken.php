<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class McpOAuthRefreshToken extends Model
{
    protected $table = 'mcp_oauth_refresh_tokens';

    protected $fillable = ['token_hash', 'client_id', 'user_id', 'personal_access_token_id', 'abilities', 'expires_at', 'revoked_at'];

    protected $casts = ['abilities' => 'array', 'expires_at' => 'datetime', 'revoked_at' => 'datetime'];
}
