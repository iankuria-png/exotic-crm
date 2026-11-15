<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ClientPayLink extends Model
{
    protected $guarded = [];
    protected $hidden = ['code_hash', 'code_encrypted'];
    protected $casts = ['code_encrypted' => 'encrypted', 'revoked_at' => 'datetime', 'last_opened_at' => 'datetime'];

    public function client() { return $this->belongsTo(Client::class); }
    public function platform() { return $this->belongsTo(Platform::class); }

    public function url(): string
    {
        $domain=rtrim((string)$this->platform->domain,'/');$host=(string)parse_url($domain,PHP_URL_HOST);
        if (app()->environment(['local','testing']) && (in_array($host,['127.0.0.1','localhost'],true)||str_ends_with($host,'.local')) && str_starts_with($domain,'http://')) return $domain.'/pay/'.$this->code_encrypted;
        return 'https://'.preg_replace('#^https?://#', '', rtrim($this->platform->domain, '/')).'/pay/'.$this->code_encrypted;
    }
}
