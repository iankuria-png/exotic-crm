<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Platform extends Model
{
    protected $fillable = [
        'name', 'domain', 'country', 'is_active',
        'db_host', 'db_name', 'db_user', 'db_pass', 'db_prefix', 'product_id',
        'wp_api_url', 'wp_api_user', 'wp_api_password',
        'phone_prefix', 'timezone', 'currency_code',
        'sync_last_checked_at', 'sync_last_synced_at',
        'sync_last_scope', 'sync_last_status',
        'sync_last_error', 'sync_last_result',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'sync_last_checked_at' => 'datetime',
        'sync_last_synced_at' => 'datetime',
        'sync_last_result' => 'array',
    ];
    
    public function getConnectionConfig()
    {
        return [
            'driver' => 'mysql',
            'host' => $this->db_host,
            'port' => 3306,
            'database' => $this->db_name,
            'username' => $this->db_user,
            'password' => $this->db_pass,
            'charset' => 'utf8mb4',
            'collation' => 'utf8mb4_unicode_ci',
            'prefix' => $this->db_prefix ?? '',
        ];
    }
    
    public function product()
    {
        return $this->belongsTo(Product::class);
    }
    
    // Add relationship to users
    public function users()
    {
        return $this->belongsToMany(User::class, 'user_platforms');
    }
}
