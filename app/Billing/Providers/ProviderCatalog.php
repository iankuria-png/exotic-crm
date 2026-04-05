<?php

namespace App\Billing\Providers;

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

final class ProviderCatalog
{
    /**
     * @return list<AbstractProviderAdapter>
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
        ];
    }

    /**
     * @return list<ProviderDefinition>
     */
    public static function definitions(): array
    {
        return array_map(
            static fn (AbstractProviderAdapter $adapter): ProviderDefinition => $adapter->definition(),
            self::adapters()
        );
    }
}
