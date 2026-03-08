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
    public const USER_CREATE = 'user_create';
    public const ROLE_UPDATE = 'role_update';
    public const INTEGRATION_PLATFORM_CREATE = 'integration_platform_create';
    public const INTEGRATION_PLATFORM_UPDATE = 'integration_platform_update';
    public const INTEGRATION_CONNECTION_TEST = 'integration_connection_test';
    public const INTEGRATION_SYNC_RUN = 'integration_sync_run';
    public const SCRAPER_SOURCE_CREATE = 'scraper_source_create';
    public const SCRAPER_SOURCE_UPDATE = 'scraper_source_update';
    public const SCRAPER_RUN = 'scraper_run';
    public const DEAL_ACTIVATE = 'deal_activate';
    public const DEAL_DEACTIVATE = 'deal_deactivate';
    public const DEAL_EXTEND = 'deal_extend';
    public const DEAL_RENEW = 'deal_renew';
    public const DEAL_FREE_TRIAL = 'deal_free_trial';
    public const CONVERSATION_SMS_SENT = 'conversation_sms_sent';
    public const CONVERSATION_SMS_FAILED = 'conversation_sms_failed';
    public const RENEWAL_SMS_SENT = 'renewal_sms_sent';
    public const RENEWAL_SMS_FAILED = 'renewal_sms_failed';
    public const RENEWAL_PAUSE = 'renewal_pause';
    public const RENEWAL_RESUME = 'renewal_resume';
    public const CAMPAIGN_RUN_CONFIGURED = 'campaign_run_configured';
    public const PAYMENT_MATCH_AUTO = 'payment_match_auto';
    public const PAYMENT_MATCH_CONFIRM = 'payment_match_confirm';
    public const PAYMENT_MATCH_BATCH = 'payment_match_batch';
    public const PAYMENT_RETRY_STK = 'payment_retry_stk';
    public const PAYMENT_SEND_LINK = 'payment_send_link';
    public const PAYMENT_CREATE_SUBSCRIPTION = 'payment_create_subscription';
    public const PAYMENT_MANUAL_CLOSE = 'payment_manual_close';
    public const PAYMENT_IMPORT_PREVIEW = 'payment_import_preview';
    public const PAYMENT_IMPORT_COMMIT = 'payment_import_commit';
    public const PAYMENT_REVIEW_STATE_UPDATE = 'payment_review_state_update';
    public const CLIENT_PROFILE_EDIT = 'client_profile_edit';
    public const CLIENT_HEALTH_RESOLVE = 'client_health_resolve';
    public const CLIENT_CREDENTIAL_SEND = 'client_credential_send';
    public const CLIENT_CREDENTIAL_RETRY = 'client_credential_retry';
    public const CLIENT_WALLET_TOPUP = 'client_wallet_topup';
    public const CLIENT_WALLET_ADJUSTMENT = 'client_wallet_adjustment';
    public const WALLET_PIN_UPDATE = 'wallet_pin_update';
    public const LEAD_RECONCILE = 'lead_reconcile';
    public const PUSH_CAMPAIGN_CREATE = 'push_campaign_create';
    public const PUSH_CAMPAIGN_EXECUTE = 'push_campaign_execute';
    public const PUSH_CAMPAIGN_SCHEDULE = 'push_campaign_schedule';
    public const PUSH_NOTIFICATION_SENT = 'push_notification_sent';
    public const PUSH_NOTIFICATION_FAILED = 'push_notification_failed';
    public const PAYMENT_IMPORT_MPESA_XML = 'payment_import_mpesa_xml';
    public const PAYMENT_MPESA_CONFIRM_SUBSCRIPTION = 'payment_mpesa_confirm_subscription';
}
