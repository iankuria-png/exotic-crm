<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use App\Models\Platform;

class WordpressPost extends Model
{
    // Remove the hardcoded connection
    protected $table = 'posts';
    protected $primaryKey = 'ID';
    public $incrementing = true;
    public $timestamps = false;

    protected $fillable = [
        'ID',
        'post_author',
        'post_date',
        'post_date_gmt',
        'post_content',
        'post_title',
        'post_excerpt',
        'post_status',
        'comment_status',
        'ping_status',
        'post_password',
        'post_name',
        'to_ping',
        'pinged',
        'post_modified',
        'post_modified_gmt',
        'post_content_filtered',
        'post_parent',
        'guid',
        'menu_order',
        'post_type',
        'post_mime_type',
        'comment_count',
    ];
    
    public function phone()
    {
        return $this->hasOne(WordpressPostMeta::class, 'post_id', 'ID')
                    ->where('meta_key', 'phone');
    }
}
