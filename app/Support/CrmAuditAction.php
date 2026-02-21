<?php

namespace App\Support;

final class CrmAuditAction
{
    public const CLIENT_CREATE = 'client_create';
    public const LEAD_CREATE = 'lead_create';
    public const LEAD_ASSIGN = 'lead_assign';
    public const LEAD_STATUS_UPDATE = 'lead_status_update';
    public const LEAD_ARCHIVE = 'lead_archive';
    public const LEAD_DELETE = 'lead_delete';
    public const LEAD_SCRAPE_INTAKE = 'lead_scrape_intake';
    public const ROLE_UPDATE = 'role_update';
    public const INTEGRATION_PLATFORM_CREATE = 'integration_platform_create';
    public const INTEGRATION_PLATFORM_UPDATE = 'integration_platform_update';
    public const INTEGRATION_CONNECTION_TEST = 'integration_connection_test';
    public const INTEGRATION_SYNC_RUN = 'integration_sync_run';
    public const DEAL_ACTIVATE = 'deal_activate';
    public const DEAL_DEACTIVATE = 'deal_deactivate';
    public const DEAL_EXTEND = 'deal_extend';
    public const CONVERSATION_SMS_SENT = 'conversation_sms_sent';
    public const CONVERSATION_SMS_FAILED = 'conversation_sms_failed';
    public const RENEWAL_SMS_SENT = 'renewal_sms_sent';
    public const RENEWAL_SMS_FAILED = 'renewal_sms_failed';
    public const RENEWAL_PAUSE = 'renewal_pause';
    public const RENEWAL_RESUME = 'renewal_resume';
    public const PAYMENT_MATCH_AUTO = 'payment_match_auto';
    public const PAYMENT_MATCH_CONFIRM = 'payment_match_confirm';
    public const PAYMENT_MATCH_BATCH = 'payment_match_batch';
}
