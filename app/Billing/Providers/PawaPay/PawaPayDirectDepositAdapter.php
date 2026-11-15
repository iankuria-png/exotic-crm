<?php

namespace App\Billing\Providers\PawaPay;

use App\Models\{BillingProviderProfile, PayLinkCharge};
use App\Services\BillingModeService;
use App\Billing\Support\BillingSurface;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Cache;
use RuntimeException;

/** Direct deposits have no hosted fallback. NOT_FOUND remains financially unresolved. */
class PawaPayDirectDepositAdapter
{
    public function context(PayLinkCharge $charge): array
    {
        $profile = BillingProviderProfile::findOrFail($charge->provider_profile_id);
        if ($profile->provider_type_key !== 'pawapay' || $profile->environment !== $charge->environment) throw new RuntimeException('Pinned provider identity changed.');
        return app(BillingModeService::class)->profileBackedProviderContext($charge->platform, $profile, $charge->binding_id, false, $charge->environment, BillingSurface::PayLink);
    }

    private function request(array $context)
    {
        $key = (string) data_get($context, 'provider_credentials.api_key');
        if ($key === '' || !in_array($context['environment'] ?? '', ['sandbox', 'production'], true)) throw new RuntimeException('Pinned pawaPay credentials are unavailable.');
        return Http::withToken($key)->acceptJson()->timeout(20)->connectTimeout(5)->withoutRedirecting();
    }

    private function base(array $context): string
    {
        return $context['environment'] === 'production' ? 'https://api.pawapay.io' : 'https://api.sandbox.pawapay.io';
    }

    private function market(array $context): array
    {
        $country=strtoupper((string)($context['provider_profile_country_code']??''));
        return match($country) {
            'KE','KEN'=>['country'=>'KEN','currency'=>'KES'],
            'SN','SEN'=>['country'=>'SEN','currency'=>'XOF'],
            'UG','UGA'=>['country'=>'UGA','currency'=>'UGX'],
            default=>throw new RuntimeException('This direct payment market needs a team check.'),
        };
    }

    public function configuration(array $context, ?string $amount = null, ?string $predictedProvider = null): array
    {
        $market=$this->market($context);
        $key='pay-link:pawapay-conf:'.hash('sha256',($context['provider_profile_id']??'').':'.$context['environment'].':'.data_get($context,'provider_credentials.api_key',''));
        $providers=Cache::remember($key,300,function()use($context,$market){
            $r=$this->request($context)->get($this->base($context).'/v2/active-conf');
            abort_unless($r->successful(),409,'Mobile money is temporarily unavailable.');
            $body=$r->json();abort_unless(data_get($body,'signatureConfiguration.signedRequestsOnly')===false,409,'The payment connection needs a team check.');
            $out=[];
            foreach($body['countries']??[] as $country)if(($country['country']??'')===$market['country'])foreach($country['providers']??[] as $provider){
                $name=$provider['provider']??'';
                if($market['country']==='KEN'&&$name!=='MPESA_KEN')continue;
                foreach($provider['currencies']??[] as $currency)if(($currency['currency']??'')===$market['currency']){
                    $operations=$currency['operationTypes']??[];
                    $deposit=is_array($operations)?($operations['DEPOSIT']??null):null;
                    if(!is_array($deposit))foreach(is_array($operations)?$operations:[] as $operation){
                        if(is_array($operation)&&is_array($operation['DEPOSIT']??null)){$deposit=$operation['DEPOSIT'];break;}
                    }
                    if(is_array($deposit)&&($deposit['authType']??'')==='PROVIDER_AUTH'&&($deposit['pinPrompt']??'')==='AUTOMATIC'
                        &&($deposit['decimalsInAmount']??'')==='NONE'&&($deposit['status']??'')==='OPERATIONAL'){
                        $deposit['minTransactionLimit']=array_key_exists('minAmount',$deposit)?$deposit['minAmount']:($deposit['minTransactionLimit']??null);
                        $deposit['maxTransactionLimit']=array_key_exists('maxAmount',$deposit)?$deposit['maxAmount']:($deposit['maxTransactionLimit']??null);
                        $out[$name]=$deposit;
                    }
                }
            }
            abort_unless($out,409,'Mobile money is unavailable for this market.');return $out;
        });
        $conf=$predictedProvider!==null?($providers[$predictedProvider]??null):reset($providers);
        abort_unless(is_array($conf),422,'This mobile-money network is unavailable.');
        if($amount!==null){
            $min=(string)($conf['minTransactionLimit']??'');$max=(string)($conf['maxTransactionLimit']??'');
            abort_unless(preg_match('/^[0-9]+(?:\.[0-9]+)?$/D',$min)&&preg_match('/^[0-9]+(?:\.[0-9]+)?$/D',$max)
                &&bccomp($amount,$min,2)>=0&&bccomp($amount,$max,2)<=0&&bccomp($amount,(string)(int)$amount,2)===0,422,'This amount is unavailable on this network.');
        }
        return $conf;
    }

    public function predict(array $context, string $phone): array
    {
        $market=$this->market($context);
        $r=$this->request($context)->post($this->base($context).'/v2/predict-provider',['phoneNumber'=>$phone]);
        abort_unless($r->successful()&&$r->json('country')===$market['country']&&$r->json('phoneNumber')===$phone
            &&is_string($r->json('provider')),422,'Check your mobile-money number.');
        $provider=$r->json('provider');
        $this->configuration($context,null,$provider);
        return ['phoneNumber'=>$phone,'provider'=>$provider,'country'=>$market['country']];
    }

    public function initiate(PayLinkCharge $c): array
    {
        $context = $this->context($c);
        $r = $this->request($context)->post($this->base($context).'/v2/deposits', $c->request_json);
        if (!$r->successful()) return ['state' => 'uncertain'];
        $status = strtoupper((string) $r->json('status'));
        // A mismatched acknowledgement never releases the reservation.
        if ($r->json('depositId') !== $c->deposit_id) return ['state' => 'uncertain'];
        return ['state' => match ($status) { 'ACCEPTED', 'DUPLICATE_IGNORED' => 'pending', 'REJECTED' => 'failed', default => 'uncertain' },
            'provider_status' => $status, 'failure_code' => substr(preg_replace('/[^A-Z0-9_]/', '', (string) $r->json('failureReason.failureCode')),0,100), 'failure_message'=>substr((string)$r->json('failureReason.failureMessage'),0,300)];
    }

    public function verify(PayLinkCharge $c): array
    {
        $context = $this->context($c);
        $r = $this->request($context)->get($this->base($context).'/v2/deposits/'.$c->deposit_id);
        if (!$r->successful() || $r->json('status') !== 'FOUND') return ['status' => 'pending', 'uncertain' => true, 'data' => []];
        $d = $r->json('data');
        if (!is_array($d) || ($d['depositId'] ?? '') !== $c->deposit_id) return ['status' => 'pending', 'uncertain' => true, 'data' => []];
        $s = strtoupper((string) ($d['status'] ?? ''));
        if (in_array($s, ['COMPLETED', 'FAILED'], true)) {
            $amount = (string) ($d['amount'] ?? '');
            $phone = data_get($d, 'payer.accountDetails.phoneNumber') ?? data_get($d, 'payer.accountDetails.phoneNUmber');
            $amountMatches = preg_match('/^[0-9]+(?:\.[0-9]{1,2})?$/D', $amount)
                && bccomp($amount, (string) $c->amount, 2) === 0;
            if (!$amountMatches || ($d['currency'] ?? '') !== ($c->currency==='CFA'?'XOF':$c->currency) || $phone !== $c->phone
                || data_get($d, 'payer.accountDetails.provider') !== data_get($c->request_json, 'payer.accountDetails.provider')
                || ($d['clientReferenceId'] ?? '') !== $c->public_ref) return ['status' => 'pending', 'review' => 'provider_identity_mismatch', 'data' => []];
        }
        return ['status' => match ($s) { 'COMPLETED' => 'completed', 'FAILED' => 'failed', default => 'pending' }, 'data' => $d];
    }
}
