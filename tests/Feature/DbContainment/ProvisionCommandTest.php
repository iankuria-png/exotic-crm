<?php

namespace Tests\Feature\DbContainment;

use App\Models\DbContainmentMarket;
use App\Models\Platform;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class ProvisionCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_provision_command_imports_encrypted_market_settings_without_enabling_actions(): void
    {
        $platform = Platform::query()->create(['name' => 'Synthetic', 'domain' => 'fixture.test', 'country' => 'Synthetic', 'currency_code' => 'USD', 'is_active' => true]);
        config(['db_containment.enabled' => false]);
        $file = tempnam(sys_get_temp_dir(), 'containment-provision-');
        $secret = 'synthetic-never-production-signing-secret';
        file_put_contents($file, json_encode(['enabled' => false, 'filesystem_enabled' => false, 'quarantine_enabled' => false, 'configuration' => ['cache_secret' => $secret]], JSON_THROW_ON_ERROR));
        chmod($file, 0600);
        try {
            $this->artisan('crm:db-containment-provision', ['platform' => $platform->id, 'file' => $file])
                ->expectsOutput('Encrypted containment configuration saved for market '.$platform->id.'. Global switches and canary prerequisites still apply.')
                ->assertSuccessful();
            $market = DbContainmentMarket::query()->findOrFail($platform->id);
            $this->assertFalse($market->enabled);
            $this->assertFalse($market->filesystem_enabled);
            $this->assertFalse($market->quarantine_enabled);
            $this->assertSame($secret, $market->configuration['cache_secret']);
            $this->assertStringNotContainsString($secret, DB::table('db_containment_markets')->where('platform_id', $platform->id)->value('configuration'));
            $this->assertFalse(config('db_containment.enabled'));
            $this->assertSame(0, DB::table('db_containment_operations')->count());
        } finally {
            unlink($file);
        }
    }

    public function test_publicly_readable_config_file_is_refused_without_saving_settings(): void
    {
        $platform = Platform::query()->create(['name' => 'Synthetic', 'domain' => 'fixture.test', 'country' => 'Synthetic', 'currency_code' => 'USD', 'is_active' => true]);
        $file = tempnam(sys_get_temp_dir(), 'containment-provision-');
        file_put_contents($file, '{}');
        chmod($file, 0644);
        try {
            $this->artisan('crm:db-containment-provision', ['platform' => $platform->id, 'file' => $file])
                ->expectsOutput('Use a private 0600 regular config file outside public/.')
                ->assertFailed();
            $this->assertSame(0, DbContainmentMarket::query()->count());
        } finally {
            unlink($file);
        }
    }
}
