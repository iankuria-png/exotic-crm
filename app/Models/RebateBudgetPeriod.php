<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class RebateBudgetPeriod extends Model
{
    protected $guarded = ['id'];

    protected $casts = ['budget_amount' => 'decimal:2', 'issued_amount' => 'decimal:2'];
}
