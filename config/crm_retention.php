<?php

return [
    /*
    |--------------------------------------------------------------------------
    | CRM operational-history retention
    |--------------------------------------------------------------------------
    |
    | Retention insight history powers a 90-day client trend chart. Audit logs
    | are operational and security records, so their longer window remains an
    | explicit deployment setting rather than an assumption in command code.
    |
    */

    'client_retention_insight_history_days' => (int) env('CRM_RETENTION_INSIGHT_HISTORY_DAYS', 90),

    'audit_log_days' => (int) env('CRM_AUDIT_LOG_RETENTION_DAYS', 365),

    // Bound one scheduled run so an initial multi-million-row backlog drains
    // in short transactions instead of competing with the shared DB server.
    'prune_chunk_size' => (int) env('CRM_HISTORY_PRUNE_CHUNK_SIZE', 5_000),
    'prune_max_batches' => (int) env('CRM_HISTORY_PRUNE_MAX_BATCHES', 25),
];
