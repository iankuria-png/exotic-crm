<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Mailgun, Postmark, AWS and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    'mailgun' => [
        'domain' => env('MAILGUN_DOMAIN'),
        'secret' => env('MAILGUN_SECRET'),
        'endpoint' => env('MAILGUN_ENDPOINT', 'api.mailgun.net'),
        'scheme' => 'https',
    ],

    'postmark' => [
        'token' => env('POSTMARK_TOKEN'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'google' => [
        'client_id' => env('GOOGLE_CLIENT_ID'),
        'client_secret' => env('GOOGLE_CLIENT_SECRET'),
        'redirect' => env('GOOGLE_REDIRECT_URI'),
    ],

    'crm_auth' => [
        'password_login_enabled' => filter_var(env('CRM_PASSWORD_LOGIN_ENABLED', true), FILTER_VALIDATE_BOOL),
        'emergency_password_login' => filter_var(env('CRM_AUTH_EMERGENCY_PASSWORD_LOGIN', true), FILTER_VALIDATE_BOOL),
        'google_allowed_domains' => env('GOOGLE_ALLOWED_DOMAINS', ''),
        'google_allowed_emails' => env('GOOGLE_ALLOWED_EMAILS', ''),
    ],

    'cybersource' => [
        'access_key' => env('CYBERSOURCE_ACCESS_KEY'),
        'profile_id' => env('CYBERSOURCE_PROFILE_ID'),
        'secret_key' => env('CYBERSOURCE_SECRET_KEY'),
        'test_mode' => env('CYBERSOURCE_TEST_MODE', true),
    ],

    'kopokopo' => [
        'client_id' => env('KOPOKOPO_CLIENT_ID'),
        'client_secret' => env('KOPOKOPO_CLIENT_SECRET'),
        'api_key' => env('KOPOKOPO_API_KEY'),
        'base_url' => env('KOPOKOPO_BASE_URL'),
        'till_number' => env('KOPOKOPO_TILL_NUMBER'),
    ],

    'django' => [
        'base_url' => env('DJANGO_API_BASE', 'https://polytech.co.ke/payment_service/api/payments'),
    ],

    'payment_link' => [
        'path' => env('PAYMENT_LINK_PATH', '/pay'),
    ],

    'sms' => [
        'enabled' => filter_var(env('SMS_ENABLED', false), FILTER_VALIDATE_BOOL),
        'active_provider' => env('SMS_ACTIVE_PROVIDER', 'legacy_gateway'),
        'fallback_provider' => env('SMS_FALLBACK_PROVIDER', 'none'),
        'gateway_url' => env('SMS_GATEWAY_URL'),
        'org_code' => env('SMS_ORG_CODE', '76'),
        'default_prefix' => env('SMS_DEFAULT_PREFIX', '254'),
    ],

    'support_board' => [
        'tenant_user_index_ttl_minutes' => (int) env('SUPPORT_BOARD_TENANT_USER_INDEX_TTL_MINUTES', 10),
        'tenant_user_index_max_pages' => (int) env('SUPPORT_BOARD_TENANT_USER_INDEX_MAX_PAGES', 25),
        'host_aliases' => [
            'exoticrwanda.com' => ['exoticrw.com'],
        ],
    ],

    'whatsapp' => [
        'meta_default_api_version' => env('WHATSAPP_META_API_VERSION', 'v25.0'),
    ],

    'exotic_crm_sync' => [
        'shared_key' => env('EXOTIC_CRM_SYNC_SHARED_KEY'),
        'shared_key_platform_ids' => env('EXOTIC_CRM_SYNC_SHARED_KEY_PLATFORM_IDS', ''),
    ],

    'africastalking' => [
        'endpoint' => env('AFRICASTALKING_ENDPOINT', 'https://api.africastalking.com/version1/messaging'),
        'username' => env('AFRICASTALKING_USERNAME'),
        'api_key' => env('AFRICASTALKING_API_KEY'),
        'sender_id' => env('AFRICASTALKING_SENDER_ID'),
    ],

    'sendgrid' => [
        'api_key' => env('SENDGRID_API_KEY'),
        'from' => env('SENDGRID_FROM', env('MAIL_FROM_ADDRESS')),
    ],

    'push_campaigns' => [
        'inline_dry_run_max_rows' => env('PUSH_UPLOAD_INLINE_DRY_RUN_MAX_ROWS'),
        'auto_match_enabled' => filter_var(env('PUSH_CAMPAIGNS_AUTO_MATCH_ENABLED', true), FILTER_VALIDATE_BOOL),
        'auto_match_min_score' => (int) env('PUSH_CAMPAIGNS_AUTO_MATCH_MIN_SCORE', 85),
        'auto_match_min_margin' => (int) env('PUSH_CAMPAIGNS_AUTO_MATCH_MIN_MARGIN', 15),
    ],

    'seo_engine' => [
        'enabled' => filter_var(env('SEO_ENGINE_ENABLED', false), FILTER_VALIDATE_BOOL),
        'providers' => array_filter(array_map(
            'trim',
            explode(',', env('SEO_PROVIDERS', 'claude,openai,gemini,deepseek'))
        )),
        'platform_allowlist' => array_values(array_filter(array_map(
            'intval',
            explode(',', env('SEO_ENGINE_PLATFORM_ALLOWLIST', ''))
        ))),
        'claude' => [
            'api_key' => env('ANTHROPIC_API_KEY'),
            'model'   => env('SEO_CLAUDE_MODEL'),
        ],
        'openai' => [
            'api_key' => env('OPENAI_API_KEY'),
            'model'   => env('SEO_OPENAI_MODEL'),
        ],
        'gemini' => [
            'api_key' => env('GEMINI_API_KEY'),
            'model'   => env('SEO_GEMINI_MODEL'),
        ],
        'deepseek' => [
            'api_key' => env('DEEPSEEK_API_KEY'),
            'model'   => env('SEO_DEEPSEEK_MODEL'),
        ],
        'scorer_weights' => [
            'word_count'   => (int) env('SEO_WEIGHT_WORD_COUNT', 25),
            'links'        => (int) env('SEO_WEIGHT_LINKS', 25),
            'completeness' => (int) env('SEO_WEIGHT_COMPLETENESS', 25),
            'media'        => (int) env('SEO_WEIGHT_MEDIA', 25),
        ],
    ],

    'reporting_fx' => [
        'enabled' => filter_var(env('REPORTING_FX_ENABLED', false), FILTER_VALIDATE_BOOL),
        'default_currency' => env('REPORTING_CURRENCY', 'USD'),
        'provider' => env('REPORTING_FX_PROVIDER', 'currencyapi'),
        'api_key' => env('CURRENCYAPI_API_KEY'),
        'base_url' => env('CURRENCYAPI_BASE_URL', 'https://api.currencyapi.com/v3'),
        'allow_user_override' => filter_var(env('REPORTING_FX_ALLOW_USER_OVERRIDE', true), FILTER_VALIDATE_BOOL),
        'stale_days' => (int) env('REPORTING_FX_STALE_DAYS', 7),
    ],

];
