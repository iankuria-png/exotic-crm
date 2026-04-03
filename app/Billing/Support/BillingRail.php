<?php

namespace App\Billing\Support;

enum BillingRail: string
{
    case Card = 'card';
    case MobileMoney = 'mobile_money';
    case BankTransfer = 'bank_transfer';
    case Crypto = 'crypto';
    case WalletBalance = 'wallet_balance';
}
