<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ErrorLogOccurrence extends Model
{
    use HasFactory;

    protected $table = 'error_log_occurrences';

    public $timestamps = false;

    protected $fillable = [
        'group_id',
        'occurred_at',
        'trace',
        'context',
        'url',
        'method',
        'user_id',
        'ip',
        'platform_id',
    ];

    protected $casts = [
        'occurred_at' => 'datetime',
        'context' => 'array',
    ];

    public function group(): BelongsTo
    {
        return $this->belongsTo(ErrorLogGroup::class, 'group_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }
}
