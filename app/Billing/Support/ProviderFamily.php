<?php

namespace App\Billing\Support;

enum ProviderFamily: string
{
    case Daraja = 'daraja';
    case Kopokopo = 'kopokopo';
    case Pawapay = 'pawapay';
    case Elemitech = 'elemitech';
    case Dusupay = 'dusupay';
    case Nowpayments = 'nowpayments';
    case Pesapal = 'pesapal';
    case Paystack = 'paystack';
    case Paypal = 'paypal';
}
