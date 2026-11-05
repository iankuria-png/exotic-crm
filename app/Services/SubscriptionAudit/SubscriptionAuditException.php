<?php

namespace App\Services\SubscriptionAudit;

class SubscriptionAuditException extends \RuntimeException
{
    public function __construct(string $message, public readonly string $reason = 'invalid_audit_request', public readonly int $httpStatus = 422, public readonly int $rpcCode = -32602)
    {
        parent::__construct($message, $rpcCode);
    }
}
