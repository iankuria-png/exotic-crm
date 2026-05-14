<?php

namespace App\Models\University;

use App\Models\User;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class DailyQuote extends Model
{
    use HasFactory;

    protected $table = 'university_daily_quotes';

    protected $fillable = [
        'quote_date',
        'quote',
        'author',
        'source_label',
        'category',
        'submitted_by',
    ];

    protected $casts = [
        'quote_date' => 'date',
    ];

    public function submitter()
    {
        return $this->belongsTo(User::class, 'submitted_by');
    }
}
