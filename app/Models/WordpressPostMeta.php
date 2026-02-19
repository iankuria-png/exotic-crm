<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class WordpressPostMeta extends Model
{
    protected $table = 'postmeta';
    protected $primaryKey = 'meta_id';
    public $timestamps = false;

    protected $fillable = ['post_id', 'meta_key', 'meta_value'];
    
    
}