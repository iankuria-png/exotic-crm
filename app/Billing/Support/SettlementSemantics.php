<?php

namespace App\Billing\Support;

enum SettlementSemantics: string
{
    case Immediate = 'immediate';
    case Delayed = 'delayed';
    case ConfirmationBased = 'confirmation_based';
    case InvoiceExpiryBased = 'invoice_expiry_based';
}
