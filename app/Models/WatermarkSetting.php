<?php

namespace App\Models;

use App\Support\Watermark\WatermarkTuning;
use Illuminate\Database\Eloquent\Model;

class WatermarkSetting extends Model
{
    protected $table = 'watermark_settings';

    public $incrementing = false;

    protected $keyType = 'int';

    private const SINGLETON_ID = 1;

    protected $fillable = ['id', 'enabled', 'tuning', 'updated_by'];

    protected $casts = [
        'enabled' => 'boolean',
        'tuning' => 'array',
        'updated_by' => 'integer',
    ];

    /**
     * The one row, created on first read so the caller never has to care.
     */
    public static function current(): self
    {
        return static::query()->firstOrCreate(
            ['id' => self::SINGLETON_ID],
            ['enabled' => true, 'tuning' => (new WatermarkTuning())->toArray()]
        );
    }

    public function tuning(): WatermarkTuning
    {
        return WatermarkTuning::fromArray(is_array($this->tuning) ? $this->tuning : []);
    }
}
