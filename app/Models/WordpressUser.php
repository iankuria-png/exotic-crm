<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class WordpressUser extends Model
{
    protected $table = 'users';
    protected $primaryKey = 'ID';
    public $timestamps = false;

    // Remove the hardcoded connection
    // protected $connection = 'wordpress';
}