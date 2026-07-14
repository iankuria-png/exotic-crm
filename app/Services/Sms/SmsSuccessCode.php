<?php

namespace App\Services\Sms;

/**
 * Shared success-code matcher for the plain-HTTP SMS panels (BulkSMS Ghana,
 * KullSMS, BlueSMS Uganda). These gateways signal success with a status code
 * in the response body — either bare ("1701") or code-prefixed with a
 * delimiter ("1701|<message-id>", "1000 sent").
 */
class SmsSuccessCode
{
    public static function matches(string $body, string $successCode): bool
    {
        $successCode = trim($successCode);
        if ($successCode === '') {
            return false;
        }

        $body = trim($body);
        if ($body === $successCode) {
            return true;
        }

        foreach ([' ', ':', '-', '|', ','] as $delimiter) {
            if (str_starts_with($body, $successCode . $delimiter)) {
                return true;
            }
        }

        return false;
    }
}
