<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class SmsLog extends Model
{
    protected $fillable = ['phone', 'message', 'status', 'response', 'payment_id', 'sent_at', 'result_code'];

}
