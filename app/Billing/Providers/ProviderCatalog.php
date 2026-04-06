<?php

namespace App\Billing\Providers;

use App\Billing\Contracts\BillingProviderAdapter;
use App\Billing\Providers\Daraja\DarajaAdapter;
use App\Billing\Providers\Daraja\MpesaStkCompatibilityAdapter;
use App\Billing\Providers\DusuPay\DusuPayAdapter;
use App\Billing\Providers\ElemiTech\ElemiTechAdapter;
use App\Billing\Providers\KopoKopo\KopoKopoAdapter;
use App\Billing\Providers\NOWPayments\NowPaymentsAdapter;
use App\Billing\Providers\PawaPay\PawaPayAdapter;
use App\Billing\Providers\Paystack\PaystackAdapter;
use App\Billing\Providers\Paypal\PaypalAdapter;
use App\Billing\Providers\Pesapal\PesapalAdapter;
use App\Billing\Support\BillingSurface;
use App\Billing\Support\ExecutionMode;
use App\Billing\Support\ProviderCapabilitySet;
use App\Billing\Support\ProviderFamily;

final class ProviderCatalog
{
    /**
     * @return list<BillingProviderAdapter>
     */
    public static function adapters(): array
    {
        return [
            new PesapalAdapter(),
            new PaystackAdapter(),
            new MpesaStkCompatibilityAdapter(),
            new DarajaAdapter(),
            new KopoKopoAdapter(),
            new PawaPayAdapter(),
            new ElemiTechAdapter(),
            new DusuPayAdapter(),
            new NowPaymentsAdapter(),
            new PaypalAdapter(),
            new StaticProviderAdapter(new ProviderDefinition(
                key: 'payment_link_static',
                label: 'Static Payment Link URL',
                family: ProviderFamily::Static,
                capabilities: new ProviderCapabilitySet(
                    surfaces: [BillingSurface::SubscriptionLink],
                    executionModes: [ExecutionMode::Direct],
                ),
                meta: [
                    'status' => 'compatibility',
                    'description' => 'Legacy static URL payment link. Stores a direct payment URL in config_json for use in operator send-link flows.',
                ]
            )),
        ];
    }

    /**
     * @return list<ProviderDefinition>
     */
    public static function definitions(): array
    {
        return array_map(
            static fn (BillingProviderAdapter $adapter): ProviderDefinition => $adapter->definition(),
            self::adapters()
        );
    }
}
