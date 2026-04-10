<?php

namespace App\Support;

enum LinkedPaymentAction: string
{
    case REVERSE = 'reverse';
    case INVALIDATE = 'invalidate';
    case NONE = 'none';
}
