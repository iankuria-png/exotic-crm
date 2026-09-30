<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Policy and campaign free passes now wait as `granted` until the escort claims them at the
 * end of the private-content journey; the term starts at claim. Resumable on MySQL.
 */
return new class extends Migration
{
    private const CLAIMABLE = ['policy_new_subscription', 'campaign_active_subscription', 'campaign_selected'];

    public function up(): void
    {
        if (! Schema::hasColumn('client_monetization_passes', 'claimed_at')) {
            Schema::table('client_monetization_passes', fn (Blueprint $t) => $t->timestamp('claimed_at')->nullable());
        }
        $unused = fn ($q) => $q->whereNotExists(fn ($assets) => $assets->select(DB::raw(1))->from('premium_content_assets')->whereColumn('premium_content_assets.client_id', 'client_monetization_passes.client_id')->where('premium_content_assets.status', '!=', 'deleted'));
        // Granted free passes the escort has not started using return to "claim your free pass".
        DB::table('client_monetization_passes')->whereIn('grant_source', self::CLAIMABLE)->whereIn('status', ['active', 'queued'])->where('expires_at', '>', now())->whereNull('claimed_at')
            ->where($unused)->update(['status' => 'granted', 'active_marker' => null, 'updated_at' => now()]);
        // Passes already in use count as claimed when they were granted.
        DB::table('client_monetization_passes')->whereIn('grant_source', self::CLAIMABLE)->whereIn('status', ['active', 'queued', 'expired'])->whereNull('claimed_at')->update(['claimed_at' => DB::raw('created_at')]);
    }

    public function down(): void
    {
        DB::table('client_monetization_passes')->where('status', 'granted')->update(['status' => 'queued', 'active_marker' => null]);
        if (Schema::hasColumn('client_monetization_passes', 'claimed_at')) {
            Schema::table('client_monetization_passes', fn (Blueprint $t) => $t->dropColumn('claimed_at'));
        }
    }
};
