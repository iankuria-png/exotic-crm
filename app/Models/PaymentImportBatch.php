<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PaymentImportBatch extends Model
{
    protected $fillable = [
        'platform_id',
        'uploaded_by',
        'file_name',
        'file_mime',
        'status',
        'total_rows',
        'valid_rows',
        'invalid_rows',
        'duplicate_rows',
        'committed_rows',
        'reason',
        'metadata',
        'committed_at',
    ];

    protected $casts = [
        'metadata' => 'array',
        'committed_at' => 'datetime',
    ];

    public function platform()
    {
        return $this->belongsTo(Platform::class);
    }

    public function uploader()
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }

    public function rows()
    {
        return $this->hasMany(PaymentImportRow::class, 'batch_id');
    }

    public function payments()
    {
        return $this->hasMany(Payment::class, 'import_batch_id');
    }
}
