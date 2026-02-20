<?php

namespace App\Support;

final class CrmAuditAction
{
    public const LEAD_STATUS_UPDATE = 'lead_status_update';
    public const DEAL_ACTIVATE = 'deal_activate';
    public const DEAL_DEACTIVATE = 'deal_deactivate';
    public const DEAL_EXTEND = 'deal_extend';
    public const CONVERSATION_SMS_SENT = 'conversation_sms_sent';
    public const CONVERSATION_SMS_FAILED = 'conversation_sms_failed';
    public const RENEWAL_SMS_SENT = 'renewal_sms_sent';
    public const RENEWAL_SMS_FAILED = 'renewal_sms_failed';
    public const PAYMENT_MATCH_AUTO = 'payment_match_auto';
    public const PAYMENT_MATCH_CONFIRM = 'payment_match_confirm';
    public const PAYMENT_MATCH_BATCH = 'payment_match_batch';
}
