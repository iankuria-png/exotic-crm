<?php
require __DIR__.'/../../vendor/autoload.php';
$app=require __DIR__.'/../../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
if (($argv[1] ?? '') === '--cleanup') {
    if (!app()->environment('local')) throw new RuntimeException('Local only.');
    if (is_file('/tmp/rebates-crm-auth.json')) {
        $auth = json_decode(file_get_contents('/tmp/rebates-crm-auth.json'), true);
        Laravel\Sanctum\PersonalAccessToken::whereKey($auth['token_id'])->where('name', 'wallet-rebates-local-qa')->delete();
        unlink('/tmp/rebates-crm-auth.json');
    }
    exit;
}
if (($argv[1] ?? '') !== '--apply-local-qa') throw new RuntimeException('Use --apply-local-qa: publishes sandbox defaults and creates a temporary admin session.');
if (!app()->environment('local')) throw new RuntimeException('Local only.');
$c=App\Models\Client::with('platform')->findOrFail(2514);
$ctx=app(App\Services\BillingModeService::class)->walletContext($c->platform);
if ($ctx['environment']!=='sandbox' || $c->wp_post_id!=97184 || !str_contains($c->platform->wp_api_url,'exotic.local')) throw new RuntimeException('Fixture/environment mismatch.');
$admin=App\Models\User::where('role','admin')->where('status','active')->firstOrFail();
$svc=app(App\Services\Rebates\RebateProgramService::class);
$s=$svc->forPlatform($c->platform);
$d=$svc->defaults(); $d['rollout_mode']='sandbox';$d['test_client_ids']=[2514];
$s=$svc->save($c->platform,$d,$s->draft_revision,$admin->id);
$s=$svc->publish($c->platform,$s->draft_revision,'Local automated sandbox QA',$admin->id);
$config=app(App\Services\WalletSyncService::class)->syncPlatformConfig($c->platform,$c);
$balance=app(App\Services\WalletSyncService::class)->syncClientBalance($c);
$token=$admin->createToken('wallet-rebates-local-qa');
file_put_contents('/tmp/rebates-crm-auth.json',json_encode(['token'=>$token->plainTextToken,'user'=>$admin->toArray(),'token_id'=>$token->accessToken->id]));chmod('/tmp/rebates-crm-auth.json',0600);
echo json_encode(['revision'=>$s->published_revision,'config_sync'=>$config['status'],'balance_sync'=>$balance['status'],'wp_post_id'=>$c->wp_post_id]);
