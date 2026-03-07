<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\Deal;
use App\Models\Payment;
use App\Models\Platform;
use App\Models\Product;
use App\Models\WalletTransaction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class WalletSchemaFactoryTest extends TestCase
{
    use RefreshDatabase;

    public function test_wallet_schema_columns_exist_and_factories_create_usable_records(): void
    {
        $this->assertTrue(Schema::hasTable('wallet_transactions'));
        $this->assertTrue(Schema::hasColumn('clients', 'wallet_balance'));
        $this->assertTrue(Schema::hasColumn('clients', 'wallet_currency'));
        $this->assertTrue(Schema::hasColumn('payments', 'purpose'));
        $this->assertTrue(Schema::hasColumn('payments', 'wallet_transaction_id'));
        $this->assertTrue(Schema::hasColumn('platforms', 'wallet_settings'));

        $platform = Platform::factory()->create([
            'currency_code' => 'TZS',
            'wallet_settings' => [
                'enabled' => true,
                'currency_code' => 'TZS',
                'show_refresh_button' => true,
            ],
        ]);

        $client = Client::factory()->create([
            'platform_id' => $platform->id,
        ]);

        $product = Product::factory()->create([
            'platform_id' => $platform->id,
            'currency' => 'TZS',
        ]);

        $payment = Payment::factory()->create([
            'platform_id' => $platform->id,
            'product_id' => $product->id,
            'client_id' => $client->id,
            'currency' => 'TZS',
        ]);

        $walletTransaction = WalletTransaction::factory()->create([
            'client_id' => $client->id,
            'platform_id' => $platform->id,
            'payment_id' => $payment->id,
            'currency_code' => 'TZS',
        ]);

        $payment->forceFill([
            'purpose' => 'wallet_topup',
            'wallet_transaction_id' => $walletTransaction->id,
            'provider_key' => 'mpesa_stk',
            'provider_environment' => 'sandbox',
        ])->save();

        $deal = Deal::factory()->create([
            'platform_id' => $platform->id,
            'client_id' => $client->id,
            'product_id' => $product->id,
            'payment_id' => $payment->id,
            'currency' => 'TZS',
        ]);

        $walletTransaction->forceFill([
            'deal_id' => $deal->id,
        ])->save();

        $this->assertSame('TZS', $client->fresh()->wallet_currency);
        $this->assertSame('wallet_topup', $payment->fresh()->purpose);
        $this->assertSame($walletTransaction->id, $payment->fresh()->wallet_transaction_id);
        $this->assertSame('TZS', $walletTransaction->fresh()->currency_code);
        $this->assertSame($platform->id, $walletTransaction->fresh()->platform_id);
        $this->assertTrue($platform->fresh()->wallet_settings['show_refresh_button']);

        $this->assertDatabaseHas('wallet_transactions', [
            'id' => $walletTransaction->id,
            'client_id' => $client->id,
            'payment_id' => $payment->id,
            'deal_id' => $deal->id,
            'currency_code' => 'TZS',
        ]);
    }
}
