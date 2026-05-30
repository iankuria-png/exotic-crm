<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Briefing extends Model
{
    protected $fillable = [
        'briefing_run_id',
        'audience',
        'scope_platform_ids',
        'scope_hash',
        'period',
        'period_start',
        'period_end',
        'summary_sms',
        'body_full',
        'generated_by',
    ];

    protected $casts = [
        'scope_platform_ids' => 'array',
        'period_start' => 'datetime',
        'period_end' => 'datetime',
    ];

    public function run(): BelongsTo
    {
        return $this->belongsTo(BriefingRun::class, 'briefing_run_id');
    }

    public function recipients(): HasMany
    {
        return $this->hasMany(BriefingRecipient::class);
    }

    /**
     * Normalize a platform-id set (null = org-wide) into a stable dedupe hash.
     *
     * @param  int[]|null  $platformIds
     */
    public static function scopeHashFor(?array $platformIds): string
    {
        if ($platformIds === null || $platformIds === []) {
            return hash('sha256', 'org-wide');
        }

        $normalized = collect($platformIds)
            ->map(fn ($id) => (int) $id)
            ->filter(fn (int $id) => $id > 0)
            ->unique()
            ->sort()
            ->values()
            ->all();

        return $normalized === []
            ? hash('sha256', 'org-wide')
            : hash('sha256', implode(',', $normalized));
    }

    public function decodedBody(): array
    {
        if (is_array($this->body_full)) {
            return $this->body_full;
        }

        $decoded = json_decode((string) $this->body_full, true);

        return is_array($decoded) ? $decoded : [];
    }
}
