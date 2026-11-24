<?php

namespace App\Services\Ewms;

final class EwmsKpiAccess
{
    public const ABILITY = 'ewms-kpi:evidence.read';

    public const ROLE = 'ewms_kpi_integration';

    public const RECORDS_PATH = 'api/internal/ewms/kpi/payment-evidence';

    public const SUMMARY_PATH = self::RECORDS_PATH.'/summary';

    public const CONTRACT = 'cs-payment-evidence-v1';

    public static function allowedPlatformIds(): array
    {
        $ids = config('ewms.allowed_platform_ids', []);
        if (! is_array($ids)) {
            return [];
        }
        foreach ($ids as $id) {
            if (! is_scalar($id) || ! preg_match('/^[1-9][0-9]*$/D', (string) $id) || filter_var($id, FILTER_VALIDATE_INT) === false) {
                return [];
            }
        }

        return array_values(array_unique(array_map('intval', $ids)));
    }
}
