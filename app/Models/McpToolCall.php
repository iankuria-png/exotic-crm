<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class McpToolCall extends Model
{
    public $timestamps = false;

    protected $table = 'mcp_tool_calls';

    protected $fillable = [
        'token_id',
        'user_id',
        'tool',
        'argument_summary',
        'generated_sql_sha256',
        'generated_sql_redacted',
        'platform_scope',
        'status',
        'refusal_reason',
        'row_count',
        'bytes_out',
        'latency_ms',
        'request_id',
        'created_at',
    ];

    protected $casts = [
        'argument_summary' => 'array',
        'platform_scope' => 'array',
        'created_at' => 'datetime',
    ];
}
