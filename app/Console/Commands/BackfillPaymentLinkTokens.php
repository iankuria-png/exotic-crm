<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class BackfillPaymentLinkTokens extends Command
{
    protected $signature = 'billing:backfill-link-tokens {--apply : Populate the additive indexed hashes}';
    protected $description = 'Count or backfill legacy JSON payment-link hashes without printing tokens';

    public function handle(): int
    {
        $missing = $invalid = 0;
        DB::table('payments')->select(['id', 'payment_data', 'link_proxy_token_hash'])->whereNotNull('payment_data')->chunkById(250, function ($rows) use (&$missing, &$invalid) {
            foreach ($rows as $row) {
                $hash = data_get(json_decode($row->payment_data, true), 'link_proxy.token_hash');
                if ($hash === null) continue;
                if (!is_string($hash) || !preg_match('/^[a-f0-9]{64}$/D', $hash)) { $invalid++; continue; }
                if ($row->link_proxy_token_hash !== $hash) {
                    $missing++;
                    if ($this->option('apply')) DB::table('payments')->where('id', $row->id)->update(['link_proxy_token_hash' => $hash]);
                }
            }
        });
        $this->line(json_encode(['missing_before' => $missing, 'invalid' => $invalid, 'applied' => (bool) $this->option('apply')]));

        return $invalid > 0 || ($missing > 0 && !$this->option('apply')) ? self::FAILURE : self::SUCCESS;
    }
}
