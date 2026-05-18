<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class KycDocumentBlob extends Model
{
    use HasFactory;

    protected $primaryKey = 'document_id';
    public $incrementing = false;

    protected $fillable = [
        'document_id',
        'body',
    ];

    public function document()
    {
        return $this->belongsTo(KycDocument::class, 'document_id');
    }
}
