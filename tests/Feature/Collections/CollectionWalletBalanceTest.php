<?php

namespace Tests\Feature\Collections;

use App\Services\Collections\TzMpesaParser;
use Laravel\Sanctum\Sanctum;

class CollectionWalletBalanceTest extends CollectionWorkflowTestCase
{
    public function test_parser_reads_each_balance_wording_and_never_guesses(): void
    {
        $parser = app(TzMpesaParser::class);
        $this->assertSame(246734962, $parser->balanceMinor('DKA603FNAJ Confirmed.You have received Tsh35,000.00 from 255722643839 - ESTERIA AGREY LUPAPA  on 10/11/26 at 12:25 PM New M-Pesa balance is Tsh2,467,349.62.'));
        $this->assertSame(10000000, $parser->balanceMinor('... on 30/10/26 at 14:59. New M-Pesa balance Tsh100,000.00'));
        $this->assertSame(90000000, $parser->balanceMinor('On 30/10/26, 2:59 PM Receive Tsh35,000 from FIXTURE NAME and balance Tsh900,000.'));
        $this->assertSame(12345000, $parser->balanceMinor('Umepokea Tsh35,000 ... Salio jipya la M-Pesa ni Tsh123,450.00'));
        $this->assertNull($parser->balanceMinor('ABC Confirmed. You have received Tsh35,000.00 from 255700000001 - PAYER on 30/10/26 at 14:59.'));
    }

    public function test_receipt_rows_drawer_and_latest_wallet_show_the_sms_balance(): void
    {
        [$account, $device, , , $admin] = $this->purchase(false);
        config(['collection.workspace_enabled' => true]);
        Sanctum::actingAs($admin);
        $first = $this->received($device, 'FIRST123456');
        $this->received($device, 'SECOND12345');
        $list = $this->getJson('/api/crm/collections/receipts')->assertOk();
        $this->assertSame(90000000, $list->json('data.0.wallet_balance.minor'));
        $this->assertSame('TZS', $list->json('data.0.wallet_balance.currency'));
        $this->assertSame(90000000, $list->json('wallet_balances.0.minor'));
        $this->assertSame($account->id, $list->json('wallet_balances.0.account_id'));
        $this->getJson('/api/crm/collections/receipts/'.$first->id)->assertOk()->assertJsonPath('wallet_balance.minor', 90000000);
    }
}
