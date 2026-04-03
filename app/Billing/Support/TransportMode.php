<?php

namespace App\Billing\Support;

enum TransportMode: string
{
    case Redirect = 'redirect';
    case Push = 'push';
    case ServerToServerCollection = 'server_to_server_collection';
    case InvoiceAddress = 'invoice_address';
    case ManualConfirmation = 'manual_confirmation';
    case InternalLedger = 'internal_ledger';
    case DjangoProxy = 'django_proxy';
}
