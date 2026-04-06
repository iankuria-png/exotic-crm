<?php

namespace App\Billing\Providers;

use App\Billing\Providers\Schemas\DarajaSchema;
use App\Billing\Providers\Schemas\DusuPaySchema;
use App\Billing\Providers\Schemas\ElemiTechSchema;
use App\Billing\Providers\Schemas\KopoKopoSchema;
use App\Billing\Providers\Schemas\MpesaStkSchema;
use App\Billing\Providers\Schemas\NowPaymentsSchema;
use App\Billing\Providers\Schemas\PawaPaySchema;
use App\Billing\Providers\Schemas\PaypalSchema;
use App\Billing\Providers\Schemas\PaystackSchema;
use App\Billing\Providers\Schemas\PesapalSchema;
use App\Billing\Providers\Schemas\StaticPaymentLinkSchema;

final class ProviderSchemaCatalog
{
    /**
     * @return list<object>
     */
    public static function schemas(): array
    {
        return [
            new PesapalSchema(),
            new PaystackSchema(),
            new MpesaStkSchema(),
            new DarajaSchema(),
            new KopoKopoSchema(),
            new PawaPaySchema(),
            new ElemiTechSchema(),
            new DusuPaySchema(),
            new NowPaymentsSchema(),
            new PaypalSchema(),
            new StaticPaymentLinkSchema(),
        ];
    }
}
