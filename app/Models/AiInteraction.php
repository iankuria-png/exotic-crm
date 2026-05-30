<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AiInteraction extends Model
{
    protected $fillable = [
        'feature',
        'user_id',
        'prompt',
        'prompt_hash',
        'generated_sql',
        'result_summary',
        'provider',
        'status',
        'error_message',
        'provider_attempts',
        'latency_ms',
        'input_tokens',
        'output_tokens',
        'est_cost_usd',
    ];

    protected $casts = [
        'provider_attempts' => 'array',
        'latency_ms' => 'integer',
        'input_tokens' => 'integer',
        'output_tokens' => 'integer',
        'est_cost_usd' => 'decimal:6',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
