<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ClientNote extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'client_id', 'author_id', 'note_type',
        'content', 'follow_up_at',
    ];

    protected $casts = [
        'follow_up_at' => 'datetime',
        'created_at' => 'datetime',
    ];

    public function client()
    {
        return $this->belongsTo(Client::class);
    }

    public function author()
    {
        return $this->belongsTo(User::class, 'author_id');
    }

    public function scopeWithPendingFollowUp($query)
    {
        return $query->whereNotNull('follow_up_at')
            ->where('follow_up_at', '>=', now());
    }
}
