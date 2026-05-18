<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class KycDocument extends Model
{
    use HasFactory;

    protected $fillable = [
        'subject_id',
        'kind',
        'storage_driver',
        'mime',
        'byte_size',
        'sha256',
        'original_filename',
        's3_disk',
        's3_key',
        'uploaded_at',
    ];

    protected $casts = [
        'byte_size' => 'integer',
        'uploaded_at' => 'datetime',
    ];

    public function subject()
    {
        return $this->belongsTo(KycSubject::class, 'subject_id');
    }

    public function blob()
    {
        return $this->hasOne(KycDocumentBlob::class, 'document_id');
    }
}
