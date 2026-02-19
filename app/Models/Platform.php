<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Platform extends Model
{
    protected $fillable = [
        'name', 'domain', 'country', 'is_active',
        'db_host', 'db_name', 'db_user', 'db_pass', 'db_prefix', 'product_id',
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