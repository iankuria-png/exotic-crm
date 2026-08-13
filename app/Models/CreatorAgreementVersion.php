<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class CreatorAgreementVersion extends Model
{
    use HasFactory;

    protected $fillable = [
        'version_key',
        'title',
        'body_html',
        'body_sha256',
        'source_url',
        'published_at',
        'retired_at',
    ];

    protected $casts = [
        'published_at' => 'datetime',
        'retired_at' => 'datetime',
    ];

    public function acceptances()
    {
        return $this->hasMany(CreatorAgreementAcceptance::class, 'agreement_version_id');
    }
}
