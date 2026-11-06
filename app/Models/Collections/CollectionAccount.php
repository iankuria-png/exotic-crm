<?php

namespace App\Models\Collections;

use Illuminate\Database\Eloquent\Model;

class CollectionAccount extends Model
{
    protected $table = 'collection_accounts';

    protected $guarded = [];

    public $incrementing = false;

    protected $keyType = 'string';

    protected $casts = ['account_identifier' => 'encrypted', 'policy' => 'array', 'verified_at' => 'datetime', 'suspended_at' => 'datetime'];

    protected $hidden = ['account_identifier', 'account_fingerprint'];
}
