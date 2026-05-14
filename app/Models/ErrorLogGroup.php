<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ErrorLogGroup extends Model
{
    use HasFactory;

    protected $table = 'error_log_groups';

    protected $fillable = [
        'signature',
        'level',
        'exception_class',
        'message',
        'file',
        'line',
        'source',
        'occurrence_count',
        'first_seen_at',
        'last_seen_at',
        'last_occurrence_id',
        'resolved_at',
        'resolved_by',
        'notes',
    ];

    protected $casts = [
        'first_seen_at' => 'datetime',
        'last_seen_at' => 'datetime',
        'resolved_at' => 'datetime',
        'occurrence_count' => 'integer',
        'line' => 'integer',
    ];

    public function occurrences(): HasMany
    {
        return $this->hasMany(ErrorLogOccurrence::class, 'group_id');
    }

    public function latestOccurrence(): BelongsTo
    {
        return $this->belongsTo(ErrorLogOccurrence::class, 'last_occurrence_id');
    }

    public function resolver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'resolved_by');
    }

    public function scopeUnresolved(Builder $query): Builder
    {
        return $query->whereNull('resolved_at');
    }

    public function scopeResolved(Builder $query): Builder
    {
        return $query->whereNotNull('resolved_at');
    }

    public function toIncident(): array
    {
        $severity = match ($this->level) {
            'emergency', 'alert', 'critical' => 'high',
            'error' => 'medium',
            default => 'low',
        };

        $category = match ($this->source) {
            'queue_job' => 'background',
            'log' => 'application',
            default => 'runtime',
        };

        $shortMessage = mb_strimwidth((string) $this->message, 0, 140, '…');
        $title = $this->exception_class
            ? class_basename($this->exception_class) . ': ' . $shortMessage
            : $shortMessage;

        return [
            'severity' => $severity,
            'category' => $category,
            'title' => $title,
            'summary' => $this->file
                ? sprintf('%s:%d', $this->file, $this->line ?? 0)
                : null,
            'suggested_action' => $this->resolved_at
                ? 'Resolved. Reopen if it recurs after intervention.'
                : 'Investigate the trace, then click Mark resolved to silence repeats.',
        ];
    }
}
