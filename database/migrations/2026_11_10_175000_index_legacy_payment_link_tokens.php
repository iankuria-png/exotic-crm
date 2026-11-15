<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            $table->char('link_proxy_token_hash', 64)->nullable()->index();
        });
        // Bounded hydration, resumable command also available after deployment.
        DB::table('payments')->select(['id', 'payment_data'])->whereNotNull('payment_data')->chunkById(250, function ($rows) {
            foreach ($rows as $row) {
                $hash = data_get(json_decode($row->payment_data, true), 'link_proxy.token_hash');
                if (is_string($hash) && preg_match('/^[a-f0-9]{64}$/D', $hash)) {
                    DB::table('payments')->where('id', $row->id)->whereNull('link_proxy_token_hash')->update(['link_proxy_token_hash' => $hash]);
                }
            }
        });
    }

    public function down(): void
    {
        // Additive compatibility column is deliberately retained on code rollback.
    }
};
