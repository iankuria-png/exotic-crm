<?php

namespace App\Models\Faq;

use App\Models\User;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Storage;

class Feedback extends Model
{
    use HasFactory;

    protected $table = 'faq_feedback';

    protected $fillable = [
        'article_id',
        'user_id',
        'kind',
        'helpful',
        'title',
        'comment',
        'severity',
        'context_path',
        'context_meta',
        'screenshot_disk_path',
        'status',
        'duplicate_of_id',
        'admin_notes',
        'resolved_by',
        'resolved_at',
        'status_changed_at',
        'last_seen_at',
        'status_history',
    ];

    protected $casts = [
        'helpful' => 'boolean',
        'context_meta' => 'array',
        'resolved_at' => 'datetime',
        'status_changed_at' => 'datetime',
        'last_seen_at' => 'datetime',
        'status_history' => 'array',
    ];

    protected $appends = [
        'screenshot_url',
    ];

    public function article()
    {
        return $this->belongsTo(Article::class, 'article_id');
    }

    public function user()
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function duplicateOf()
    {
        return $this->belongsTo(self::class, 'duplicate_of_id');
    }

    public function resolver()
    {
        return $this->belongsTo(User::class, 'resolved_by');
    }

    public function votes()
    {
        return $this->hasMany(FeedbackVote::class, 'feedback_id');
    }

    public function comments()
    {
        return $this->hasMany(FeedbackComment::class, 'feedback_id')->orderBy('created_at');
    }

    public function getScreenshotUrlAttribute(): ?string
    {
        if (!$this->screenshot_disk_path) {
            return null;
        }

        return Storage::disk('public')->url($this->screenshot_disk_path);
    }
}
