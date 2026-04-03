<?php

namespace App\Billing\Support;

enum ProviderOperationType: string
{
    case Initiate = 'initiate';
    case StatusQuery = 'status_query';
    case WebhookVerify = 'webhook_verify';
    case Reconcile = 'reconcile';
    case Refund = 'refund';
    case Cancel = 'cancel';
}
