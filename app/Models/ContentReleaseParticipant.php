<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ContentReleaseParticipant extends Model
{
    use HasFactory;

    protected $fillable = [
        'content_declaration_id',
        'display_name',
        'release_status',
        'id_document_id',
        'release_document_id',
        'reviewed_by_user_id',
        'reviewed_at',
        'review_note',
    ];

    protected $casts = [
        'reviewed_at' => 'datetime',
    ];

    public function declaration()
    {
        return $this->belongsTo(ContentComplianceDeclaration::class, 'content_declaration_id');
    }

    public function idDocument()
    {
        return $this->belongsTo(KycDocument::class, 'id_document_id');
    }

    public function releaseDocument()
    {
        return $this->belongsTo(KycDocument::class, 'release_document_id');
    }

    public function reviewer()
    {
        return $this->belongsTo(User::class, 'reviewed_by_user_id');
    }
}
