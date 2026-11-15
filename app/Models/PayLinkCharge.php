<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PayLinkCharge extends Model
{
    protected $guarded = [];
    protected $hidden = ['phone', 'session_proof_hash', 'recovery_token_hash', 'recovery_token_encrypted', 'request_json', 'request_fingerprint', 'idempotency_hash'];
    protected $casts = ['phone' => 'encrypted', 'recovery_token_encrypted' => 'encrypted', 'request_json' => 'encrypted:array', 'amount' => 'decimal:2', 'provider_evidence' => 'array', 'activation_result' => 'array', 'next_reconcile_at' => 'datetime', 'dispatch_started_at' => 'datetime', 'final_at' => 'datetime'];

    public function payment() { return $this->belongsTo(Payment::class); }
    public function client() { return $this->belongsTo(Client::class); }
    public function platform() { return $this->belongsTo(Platform::class); }
    public function link() { return $this->belongsTo(ClientPayLink::class, 'pay_link_id'); }
}
