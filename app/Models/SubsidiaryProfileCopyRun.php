<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class SubsidiaryProfileCopyRun extends Model
{
    use HasUuids;

    public const ACTIVE = ['queued', 'preparing', 'copying', 'verifying'];

    protected $table = 'subsidiary_profile_copy_runs';

    protected $guarded = [];

    protected $hidden = ['snapshot_json'];

    protected $casts = ['snapshot_json' => 'array', 'media_manifest_json' => 'array', 'progress_json' => 'array', 'lease_until' => 'datetime',
        'cancellation_requested_at' => 'datetime', 'verified_at' => 'datetime', 'version' => 'integer', 'fence' => 'integer', 'duration_days' => 'integer', 'policy_version' => 'integer'];

    public function items()
    {
        return $this->hasMany(SubsidiaryProfileCopyItem::class, 'run_id')->orderBy('position');
    }
}
