<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Activation extends Model
{
    protected $fillable = [
        'post_id',
        'platform_id', // Added
        'product_id',  // Added
        'activated_at',
        'expires_at',
        'deactivated_at',
        'is_free_trial' // Added
    ];
    
    protected $dates = [
        'activated_at',
        'expires_at',
        'deactivated_at'
    ];
    
    public function escortPost()
    {
        return $this->belongsTo(WordpressPost::class, 'post_id', 'ID');
    }
    
    public function platform()
    {
        return $this->belongsTo(Platform::class);
    }
    
    public function product()
    {
        return $this->belongsTo(Product::class);
    }
}
