<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ClientSyncExclusion extends Model
{
    use HasFactory;

    public $timestamps = false;

    protected $fillable = [
        'platform_id',
        'wp_post_id',
        'reason',
        'deleted_by',
        'created_at',
    ];

    protected $casts = [
        'platform_id' => 'integer',
        'wp_post_id' => 'integer',
        'deleted_by' => 'integer',
        'created_at' => 'datetime',
    ];

    public function platform()
    {
        return $this->belongsTo(Platform::class);
    }

    public function actor()
    {
        return $this->belongsTo(User::class, 'deleted_by');
    }
}
