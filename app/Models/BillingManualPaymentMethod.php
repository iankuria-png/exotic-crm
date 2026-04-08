<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class BillingManualPaymentMethod extends Model
{
    use HasFactory;

    protected $fillable = [
        'market_id',
        'method_key',
        'enabled',
        'display_name',
        'instruction_intro',
        'instruction_footer',
        'proof_required',
        'sender_name_required',
        'transaction_id_required',
        'auto_activate_on_submission',
        'details_json',
    ];

    protected $casts = [
        'enabled' => 'boolean',
        'proof_required' => 'boolean',
        'sender_name_required' => 'boolean',
        'transaction_id_required' => 'boolean',
        'auto_activate_on_submission' => 'boolean',
        'details_json' => 'array',
    ];

    public function market()
    {
        return $this->belongsTo(Platform::class, 'market_id');
    }

    public function platform()
    {
        return $this->market();
    }
}
