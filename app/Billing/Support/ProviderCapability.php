<?php

namespace App\Billing\Support;

enum ProviderCapability: string
{
    case Webhooks = 'webhooks';
    case Polling = 'polling';
    case ProxySupported = 'proxy_supported';
    case StatusQueries = 'status_queries';
    case Retries = 'retries';
    case Refunds = 'refunds';
    case PartialAmountTolerance = 'partial_amount_tolerance';
    case SandboxAvailable = 'sandbox_available';
    case AdultContentSuitable = 'adult_content_suitable';
    case MerchantAccountRequired = 'merchant_account_required';
}
