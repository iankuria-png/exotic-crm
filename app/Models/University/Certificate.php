<?php

namespace App\Models\University;

use App\Models\User;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Storage;

class Certificate extends Model
{
    use HasFactory;

    protected $table = 'university_certificates';

    protected $fillable = [
        'user_id',
        'certification_id',
        'attempt_id',
        'certificate_code',
        'issued_at',
        'expires_at',
        'pdf_path',
        'revoked_at',
    ];

    protected $casts = [
        'issued_at' => 'datetime',
        'expires_at' => 'datetime',
        'revoked_at' => 'datetime',
    ];

    public function getPdfUrlAttribute(): ?string
    {
        return $this->pdf_path ? Storage::disk('public')->url($this->pdf_path) : null;
    }

    public function user()
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function certification()
    {
        return $this->belongsTo(Certification::class, 'certification_id');
    }

    public function attempt()
    {
        return $this->belongsTo(Attempt::class, 'attempt_id');
    }
}
