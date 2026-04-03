<?php

namespace App\Billing\Support;

enum BillingSurface: string
{
    case SubscriptionLink = 'subscription_link';
    case SubscriptionPush = 'subscription_push';
    case SubscriptionInvoice = 'subscription_invoice';
    case WalletFunding = 'wallet_funding';
    case WalletAutoRenew = 'wallet_auto_renew';
    case ManualConfirmation = 'manual_confirmation';
    case ProxyHostedCheckout = 'proxy_hosted_checkout';
    case SelfCheckout = 'self_checkout';
}
