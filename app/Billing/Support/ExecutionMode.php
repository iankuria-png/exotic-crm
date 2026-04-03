<?php

namespace App\Billing\Support;

enum ExecutionMode: string
{
    case Direct = 'direct';
    case Proxy = 'proxy';
}
