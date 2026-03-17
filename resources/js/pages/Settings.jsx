import React, { useEffect, useMemo, useState } from 'react';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import api from '../services/api';
import DataTable from '../components/DataTable';
import ConfirmDialog from '../components/ConfirmDialog';
import MetricCard from '../components/MetricCard';
import PageHeader from '../components/PageHeader';
import StatusBadge from '../components/StatusBadge';
import SystemHealthWorkspace from '../components/SystemHealthWorkspace';
import { useAuth } from '../hooks/useAuth';
import useDashboardWidgets from '../hooks/useDashboardWidgets';
import { useToast } from '../components/ToastProvider';

const baseTabs = [
    { id: 'integrations', label: 'Integrations' },
    { id: 'templates', label: 'Templates' },
    { id: 'logs', label: 'Webhook Logs' },
    { id: 'roles', label: 'Roles & Permissions' },
    { id: 'dashboard', label: 'Dashboard' },
    { id: 'health', label: 'System Health' },
];
const defaultDurationOptions = [
    { key: '1_week', label: '1 Week', days: 7 },
    { key: '2_weeks', label: '2 Weeks', days: 14 },
    { key: '1_month', label: '1 Month', days: 30 },
    { key: '2_months', label: '2 Months', days: 60 },
    { key: '3_months', label: '3 Months', days: 90 },
    { key: '6_months', label: '6 Months', days: 180 },
    { key: '1_year', label: '1 Year', days: 365 },
];
const paymentLinkModeOptions = [
    { value: 'static_url', label: 'Static URL' },
    { value: 'proxy_hosted_checkout', label: 'CRM Proxy Checkout' },
];
const paymentLinkProxyWalletProviders = ['paystack', 'pesapal'];

function statusChip(status) {
    if (['connected', 'healthy', 'success'].includes(status)) return 'bg-emerald-50 text-emerald-700 ring-emerald-200';
    if (['configured_disabled', 'partial', 'degraded', 'pending'].includes(status)) return 'bg-amber-50 text-amber-700 ring-amber-200';
    if (['deferred', 'unknown'].includes(status)) return 'bg-slate-100 text-slate-700 ring-slate-300';
    return 'bg-rose-50 text-rose-700 ring-rose-200';
}

function formatDateTime(value) {
    if (!value) return 'Never';
    const date = new Date(value);
    if (Number.isNaN(date.getTime())) return 'Never';
    return date.toLocaleString();
}

function buildPlatformEditor(platform) {
    if (!platform) {
        return null;
    }

    return {
        name: platform.platform_name || '',
        domain: platform.domain || '',
        country: platform.country || '',
        is_active: Boolean(platform.is_active),
        wp_api_url: platform.wp_sync?.api_url || '',
        wp_api_user: platform.wp_sync?.api_user || '',
        wp_api_password: '',
        currency_code: platform.currency || 'KES',
        timezone: platform.timezone || 'Africa/Nairobi',
        phone_prefix: platform.phone_prefix || '254',
        support_chat_url: platform.support_chat_url || '',
    };
}

function defaultPlatformForm() {
    return {
        name: '',
        domain: '',
        country: '',
        is_active: false,
        wp_api_url: '',
        wp_api_user: '',
        wp_api_password: '',
        currency_code: 'KES',
        timezone: 'Africa/Nairobi',
        phone_prefix: '254',
        support_chat_url: '',
    };
}

function buildPackageEditor(platform) {
    const currency = platform?.currency || 'KES';
    const serverRows = Array.isArray(platform?.packages) ? platform.packages : [];

    const rows = serverRows.map((row) => ({
        id: row.id || null,
        name: row.name || '',
        display_name: row.display_name || '',
        tier: row.tier || 'custom',
        sort_order: Number(row.sort_order || 0),
        is_active: Boolean(row.is_active),
        is_archived: Boolean(row.is_archived),
        prices: Array.isArray(row.prices) && row.prices.length > 0
            ? row.prices.map((p) => ({
                id: p.id || null,
                duration_key: p.duration_key,
                duration_label: p.duration_label,
                duration_days: p.duration_days,
                price: Number(p.price || 0),
                is_active: Boolean(p.is_active),
                sort_order: Number(p.sort_order || 0),
            }))
            : [],
    }));

    return {
        reason: 'Updated market package catalog from settings workspace',
        rows,
        currency,
    };
}

function newPackageRow(sortOrder = 0) {
    return {
        id: null,
        name: '',
        display_name: '',
        tier: 'custom',
        sort_order: sortOrder,
        is_active: true,
        is_archived: false,
        prices: [{ id: null, duration_key: '1_month', duration_label: '1 Month', duration_days: 30, price: 0, is_active: true, sort_order: 10 }],
    };
}

function newPriceRow(sortOrder = 0) {
    return { id: null, duration_key: '', duration_label: '', duration_days: 30, price: 0, is_active: true, sort_order: sortOrder };
}

function smsProviderLabel(providerId) {
    if (providerId === 'africastalking') return "Africa's Talking";
    if (providerId === 'legacy_gateway') return 'Legacy Gateway';
    return 'None';
}

function defaultSmsProviderForm() {
    return {
        enabled: false,
        active_provider: 'legacy_gateway',
        fallback_provider: 'none',
        default_prefix: '254',
        reason: 'Updated SMS provider routing settings',
        legacy_gateway: {
            gateway_url: '',
            org_code: '76',
        },
        africastalking: {
            endpoint: 'https://api.africastalking.com/version1/messaging',
            username: '',
            api_key: '',
            sender_id: '',
        },
    };
}

function buildSmsProviderForm(smsProvider) {
    const fallback = defaultSmsProviderForm();
    if (!smsProvider) {
        return {
            form: fallback,
            apiKeyConfigured: false,
        };
    }

    return {
        form: {
            ...fallback,
            enabled: Boolean(smsProvider.enabled),
            active_provider: smsProvider.active_provider || 'legacy_gateway',
            fallback_provider: smsProvider.fallback_provider || 'none',
            default_prefix: smsProvider.default_prefix || '254',
            legacy_gateway: {
                gateway_url: smsProvider.legacy_gateway?.gateway_url || '',
                org_code: smsProvider.legacy_gateway?.org_code || '76',
            },
            africastalking: {
                endpoint: smsProvider.africastalking?.endpoint || fallback.africastalking.endpoint,
                username: smsProvider.africastalking?.username || '',
                api_key: '',
                sender_id: smsProvider.africastalking?.sender_id || '',
            },
        },
        apiKeyConfigured: Boolean(smsProvider.africastalking?.api_key_configured),
    };
}

function pushProviderLabel(providerId) {
    if (providerId === 'webpushr') return 'WebPushr';
    if (providerId === 'wonderpush') return 'WonderPush';
    if (providerId === 'izooto') return 'iZooto';
    return 'Unknown';
}

function walletProviderLabel(providerId) {
    if (providerId === 'pesapal') return 'Pesapal';
    if (providerId === 'paystack') return 'Paystack';
    if (providerId === 'mpesa_stk') return 'M-Pesa STK';
    return providerId?.replaceAll('_', ' ') || 'Unknown';
}

function paymentLinkModeLabel(mode) {
    if (mode === 'proxy_hosted_checkout') return 'CRM proxy';
    return 'Static URL';
}

function paymentLinkProviderOptionLabel(provider) {
    const baseLabel = provider?.label?.trim() || provider?.key?.trim() || 'Provider';

    return `${baseLabel} (${paymentLinkModeLabel(provider?.mode)})`;
}

function paymentLinkReadinessState(provider, selectedPlatform, walletSystemConfig) {
    if (provider?.mode !== 'proxy_hosted_checkout') {
        return null;
    }

    const walletProviderKey = provider?.wallet_provider_key || '';
    const environment = provider?.environment || 'sandbox';
    const credentials = selectedPlatform?.wallet?.credentials?.[walletProviderKey]?.[environment] || {};
    const billingDomain = String(walletSystemConfig?.billing_domains?.[environment] || '').trim();

    const credentialsReady = walletProviderKey === 'paystack'
        ? Boolean(credentials.public_key_configured && credentials.secret_key_configured)
        : walletProviderKey === 'pesapal'
            ? Boolean(credentials.consumer_key_configured && credentials.consumer_secret_configured)
            : false;
    const billingReady = billingDomain !== '';

    if (credentialsReady && billingReady) {
        return {
            tone: 'emerald',
            label: 'Ready',
            detail: `${walletProviderLabel(walletProviderKey)} ${environment} credentials and billing domain are configured.`,
        };
    }

    const missing = [];
    if (!credentialsReady) {
        missing.push(`${walletProviderLabel(walletProviderKey || 'provider')} ${environment} credentials`);
    }
    if (!billingReady) {
        missing.push(`${environment} billing domain`);
    }

    return {
        tone: credentialsReady || billingReady ? 'amber' : 'rose',
        label: credentialsReady || billingReady ? 'Needs setup' : 'Blocked',
        detail: `Missing ${missing.join(' and ')}.`,
    };
}

function paymentLinkReadinessClasses(tone) {
    if (tone === 'emerald') return 'border-emerald-200 bg-emerald-50 text-emerald-800';
    if (tone === 'amber') return 'border-amber-200 bg-amber-50 text-amber-800';
    return 'border-rose-200 bg-rose-50 text-rose-800';
}

function defaultPushPlatformConfig(defaultProvider = 'webpushr') {
    return {
        active_provider: defaultProvider,
        fallback_provider: 'none',
        webpushr: {
            api_key: '',
            auth_token: '',
            api_key_configured: false,
            auth_token_configured: false,
        },
        wonderpush: {
            access_token: '',
            project_id: '',
            access_token_configured: false,
        },
        izooto: {
            api_token: '',
            api_token_configured: false,
        },
    };
}

function defaultPushProviderForm() {
    return {
        enabled: false,
        default_provider: 'webpushr',
        reason: 'Updated push provider routing settings',
        platforms: {},
    };
}

function buildPushProviderForm(pushProvider, platformRows = []) {
    const fallback = defaultPushProviderForm();
    const defaultProvider = pushProvider?.default_provider || fallback.default_provider;
    const base = {
        ...fallback,
        enabled: Boolean(pushProvider?.enabled),
        default_provider: defaultProvider,
        platforms: {},
    };

    const availablePlatformIds = new Set((platformRows || []).map((platform) => String(platform.platform_id)));
    const storedPlatforms = pushProvider?.platforms && typeof pushProvider.platforms === 'object'
        ? pushProvider.platforms
        : {};

    Object.entries(storedPlatforms).forEach(([platformId, rawConfig]) => {
        if (availablePlatformIds.size > 0 && !availablePlatformIds.has(String(platformId))) {
            return;
        }

        const merged = defaultPushPlatformConfig(defaultProvider);
        const next = rawConfig && typeof rawConfig === 'object' ? rawConfig : {};

        merged.active_provider = next.active_provider || merged.active_provider;
        merged.fallback_provider = next.fallback_provider || merged.fallback_provider;
        merged.webpushr = {
            ...merged.webpushr,
            ...(next.webpushr || {}),
            api_key: '',
            auth_token: '',
            api_key_configured: Boolean(next.webpushr?.api_key_configured),
            auth_token_configured: Boolean(next.webpushr?.auth_token_configured),
        };
        merged.wonderpush = {
            ...merged.wonderpush,
            ...(next.wonderpush || {}),
            access_token: '',
            access_token_configured: Boolean(next.wonderpush?.access_token_configured),
            project_id: next.wonderpush?.project_id || '',
        };
        merged.izooto = {
            ...merged.izooto,
            ...(next.izooto || {}),
            api_token: '',
            api_token_configured: Boolean(next.izooto?.api_token_configured),
        };

        base.platforms[String(platformId)] = merged;
    });

    (platformRows || []).forEach((platform) => {
        const key = String(platform.platform_id);
        if (!base.platforms[key]) {
            base.platforms[key] = defaultPushPlatformConfig(defaultProvider);
        }
    });

    return base;
}

function defaultScraperRules() {
    return {
        row_selector: '',
        name_selector: '',
        phone_selector: '',
        email_selector: '',
        link_selector: '',
    };
}

function defaultScraperForm(platformId = '') {
    return {
        platform_id: platformId ? String(platformId) : '',
        name: '',
        source_url: '',
        parser_profile: 'contact_cards',
        fetch_schedule: 'manual_only',
        dedupe_mode: 'phone_or_email',
        parser_rules: defaultScraperRules(),
        is_active: true,
        compliance_ack_robots: false,
        compliance_ack_tos: false,
        compliance_notes: '',
        reason: 'Created scraper source from settings',
    };
}

function defaultPaymentLinkProviderForm() {
    return {
        active_provider: 'primary',
        providers: [
            {
                key: 'primary',
                label: 'Primary',
                mode: 'static_url',
                enabled: true,
                wallet_provider_key: 'paystack',
                environment: 'sandbox',
                url: '',
                base_url: '',
                path: '/pay',
            },
        ],
        reason: 'Updated payment link provider routing',
    };
}

function buildPaymentLinkProviderForm(platform) {
    const fallback = defaultPaymentLinkProviderForm();
    const config = platform?.payment_link_providers;
    if (!config || typeof config !== 'object') {
        return fallback;
    }

    const providerEntries = Object.entries(config.providers || {})
        .map(([key, provider]) => ({
            key,
            label: provider?.label || key,
            mode: provider?.mode || 'static_url',
            enabled: provider?.enabled !== false,
            wallet_provider_key: provider?.wallet_provider_key || 'paystack',
            environment: provider?.environment || 'sandbox',
            url: provider?.url || '',
            base_url: provider?.base_url || '',
            path: provider?.path || '/pay',
        }));

    if (providerEntries.length === 0) {
        return fallback;
    }

    const enabledProviders = providerEntries.filter((provider) => provider.enabled);
    const active = config.active_provider && enabledProviders.some((provider) => provider.key === config.active_provider)
        ? config.active_provider
        : (enabledProviders[0]?.key || providerEntries[0].key);

    return {
        active_provider: active,
        providers: providerEntries,
        reason: 'Updated payment link provider routing',
    };
}

function defaultWalletSystemForm() {
    return {
        mode: 'disabled',
        default_currency: 'KES',
        max_single_topup_default: '50000.00',
        max_wallet_balance_default: '200000.00',
        billing_domains: {
            sandbox: '',
            production: '',
        },
        billing_branding: {
            sandbox: {
                business_name: 'Exotic Ads Test',
                description: 'Ad credit top-up',
            },
            production: {
                business_name: 'Exotic Ads',
                description: 'Ad credit top-up',
            },
        },
        redirect_delay_seconds: 3,
        wallet_refresh_rate_limit_seconds: 15,
        wallet_refresh_timeout_seconds: 15,
        topup_poll_interval_seconds: 10,
        smtp: {
            enabled: false,
            host: '',
            port: 587,
            username: '',
            password: '',
            password_configured: false,
            encryption: 'tls',
            from_address: '',
            from_name: '',
        },
        pin_set: false,
        pin_last_updated_at: null,
        reason: 'Updated wallet system settings',
    };
}

function buildWalletSystemForm(systemConfig) {
    const fallback = defaultWalletSystemForm();
    if (!systemConfig) {
        return fallback;
    }

    return {
        ...fallback,
        mode: systemConfig.mode || fallback.mode,
        default_currency: systemConfig.default_currency || fallback.default_currency,
        max_single_topup_default: systemConfig.max_single_topup_default || fallback.max_single_topup_default,
        max_wallet_balance_default: systemConfig.max_wallet_balance_default || fallback.max_wallet_balance_default,
        billing_domains: {
            sandbox: systemConfig.billing_domains?.sandbox || '',
            production: systemConfig.billing_domains?.production || '',
        },
        billing_branding: {
            sandbox: {
                business_name: systemConfig.billing_branding?.sandbox?.business_name || fallback.billing_branding.sandbox.business_name,
                description: systemConfig.billing_branding?.sandbox?.description || fallback.billing_branding.sandbox.description,
            },
            production: {
                business_name: systemConfig.billing_branding?.production?.business_name || fallback.billing_branding.production.business_name,
                description: systemConfig.billing_branding?.production?.description || fallback.billing_branding.production.description,
            },
        },
        redirect_delay_seconds: Number(systemConfig.redirect_delay_seconds || fallback.redirect_delay_seconds),
        wallet_refresh_rate_limit_seconds: Number(systemConfig.wallet_refresh_rate_limit_seconds || fallback.wallet_refresh_rate_limit_seconds),
        wallet_refresh_timeout_seconds: Number(systemConfig.wallet_refresh_timeout_seconds || fallback.wallet_refresh_timeout_seconds),
        topup_poll_interval_seconds: Number(systemConfig.topup_poll_interval_seconds || fallback.topup_poll_interval_seconds),
        smtp: {
            enabled: Boolean(systemConfig.smtp?.enabled),
            host: systemConfig.smtp?.host || '',
            port: Number(systemConfig.smtp?.port || fallback.smtp.port),
            username: systemConfig.smtp?.username || '',
            password: '',
            password_configured: Boolean(systemConfig.smtp?.password_configured),
            encryption: systemConfig.smtp?.encryption || fallback.smtp.encryption,
            from_address: systemConfig.smtp?.from_address || '',
            from_name: systemConfig.smtp?.from_name || '',
        },
        pin_set: Boolean(systemConfig.pin_set),
        pin_last_updated_at: systemConfig.pin_last_updated_at || null,
        reason: 'Updated wallet system settings',
    };
}

function defaultWalletPinForm() {
    return {
        pin: '',
        pin_confirmation: '',
        reason: 'Updated wallet operator PIN',
    };
}

function defaultWalletPlatformForm(currency = 'KES') {
    return {
        enabled: false,
        mode_override: 'inherit',
        currency_code: currency,
        max_single_topup: '50000.00',
        max_wallet_balance: '200000.00',
        topup_presets: ['500.00', '1000.00', '2000.00', '5000.00'],
        allow_combined_topup_subscribe: true,
        show_refresh_button: true,
        recent_transactions_limit: 10,
        providers: {
            pesapal: { enabled: false, min_amount: '100.00', max_amount: '150000.00' },
            paystack: { enabled: false, min_amount: '100.00', max_amount: '500000.00' },
            mpesa_stk: { enabled: false, min_amount: '100.00', max_amount: '150000.00' },
        },
        reason: 'Updated platform wallet settings',
    };
}

function buildWalletPlatformForm(platform) {
    const fallback = defaultWalletPlatformForm(platform?.currency || 'KES');
    const wallet = platform?.wallet;
    if (!wallet) {
        return fallback;
    }

    return {
        ...fallback,
        enabled: Boolean(wallet.enabled),
        mode_override: wallet.mode_override || 'inherit',
        currency_code: wallet.currency_code || fallback.currency_code,
        max_single_topup: wallet.max_single_topup || fallback.max_single_topup,
        max_wallet_balance: wallet.max_wallet_balance || fallback.max_wallet_balance,
        topup_presets: Array.isArray(wallet.topup_presets) && wallet.topup_presets.length > 0
            ? wallet.topup_presets.map((value) => String(value))
            : fallback.topup_presets,
        allow_combined_topup_subscribe: Boolean(wallet.allow_combined_topup_subscribe),
        show_refresh_button: Boolean(wallet.show_refresh_button),
        recent_transactions_limit: Number(wallet.recent_transactions_limit || fallback.recent_transactions_limit),
        providers: {
            pesapal: {
                enabled: Boolean(wallet.providers?.pesapal?.enabled),
                min_amount: wallet.providers?.pesapal?.min_amount || fallback.providers.pesapal.min_amount,
                max_amount: wallet.providers?.pesapal?.max_amount || fallback.providers.pesapal.max_amount,
            },
            paystack: {
                enabled: Boolean(wallet.providers?.paystack?.enabled),
                min_amount: wallet.providers?.paystack?.min_amount || fallback.providers.paystack.min_amount,
                max_amount: wallet.providers?.paystack?.max_amount || fallback.providers.paystack.max_amount,
            },
            mpesa_stk: {
                enabled: Boolean(wallet.providers?.mpesa_stk?.enabled),
                min_amount: wallet.providers?.mpesa_stk?.min_amount || fallback.providers.mpesa_stk.min_amount,
                max_amount: wallet.providers?.mpesa_stk?.max_amount || fallback.providers.mpesa_stk.max_amount,
            },
        },
        reason: 'Updated platform wallet settings',
    };
}

function buildWalletProvidersForm(platform) {
    const credentials = platform?.wallet?.credentials || {};

    return {
        pesapal: {
            sandbox: {
                consumer_key: '',
                consumer_secret: '',
                consumer_key_configured: Boolean(credentials.pesapal?.sandbox?.consumer_key_configured),
                consumer_secret_configured: Boolean(credentials.pesapal?.sandbox?.consumer_secret_configured),
                ipn_id: credentials.pesapal?.sandbox?.ipn_id || '',
            },
            production: {
                consumer_key: '',
                consumer_secret: '',
                consumer_key_configured: Boolean(credentials.pesapal?.production?.consumer_key_configured),
                consumer_secret_configured: Boolean(credentials.pesapal?.production?.consumer_secret_configured),
                ipn_id: credentials.pesapal?.production?.ipn_id || '',
            },
        },
        paystack: {
            sandbox: {
                public_key: '',
                secret_key: '',
                public_key_configured: Boolean(credentials.paystack?.sandbox?.public_key_configured),
                secret_key_configured: Boolean(credentials.paystack?.sandbox?.secret_key_configured),
            },
            production: {
                public_key: '',
                secret_key: '',
                public_key_configured: Boolean(credentials.paystack?.production?.public_key_configured),
                secret_key_configured: Boolean(credentials.paystack?.production?.secret_key_configured),
            },
        },
        mpesa_stk: {
            sandbox: {
                transport: credentials.mpesa_stk?.sandbox?.transport || 'django_proxy',
                payment_service_base_url: credentials.mpesa_stk?.sandbox?.payment_service_base_url || '',
                organization_code: credentials.mpesa_stk?.sandbox?.organization_code || '76',
                callback_base_url: credentials.mpesa_stk?.sandbox?.callback_base_url || '',
            },
            production: {
                transport: credentials.mpesa_stk?.production?.transport || 'django_proxy',
                payment_service_base_url: credentials.mpesa_stk?.production?.payment_service_base_url || '',
                organization_code: credentials.mpesa_stk?.production?.organization_code || '76',
                callback_base_url: credentials.mpesa_stk?.production?.callback_base_url || '',
            },
        },
        wp_to_crm: credentials.wp_to_crm || {
            sandbox: { bearer_key_configured: false, hmac_configured: false, bearer_last_rotated_at: null, hmac_last_rotated_at: null },
            production: { bearer_key_configured: false, hmac_configured: false, bearer_last_rotated_at: null, hmac_last_rotated_at: null },
        },
        reason: 'Updated wallet provider credentials',
    };
}

function buildScraperEditor(source) {
    if (!source) {
        return null;
    }

    return {
        id: source.id,
        platform_id: source.platform_id ? String(source.platform_id) : '',
        name: source.name || '',
        source_url: source.source_url || '',
        parser_profile: source.parser_profile || 'contact_cards',
        fetch_schedule: source.fetch_schedule || 'manual_only',
        dedupe_mode: source.dedupe_mode || 'phone_or_email',
        parser_rules: {
            ...defaultScraperRules(),
            ...(source.parser_rules || {}),
        },
        is_active: Boolean(source.is_active),
        compliance_ack_robots: Boolean(source.compliance_ack_robots),
        compliance_ack_tos: Boolean(source.compliance_ack_tos),
        compliance_notes: source.compliance_notes || '',
        reason: 'Updated scraper source from settings',
    };
}

function scraperStatusLabel(status) {
    if (!status) return 'never run';
    return String(status).replaceAll('_', ' ');
}

function scraperProfileLabel(profile) {
    if (profile === 'profile_links') return 'Profile links';
    if (profile === 'contact_cards') return 'Contact cards';
    return profile?.replaceAll('_', ' ') || 'Unknown';
}

function scraperScheduleLabel(schedule) {
    if (schedule === 'manual_only') return 'Manual only';
    if (schedule === 'daily') return 'Daily';
    if (schedule === 'weekly') return 'Weekly';
    return schedule?.replaceAll('_', ' ') || 'Unknown';
}

function dedupeModeLabel(mode) {
    if (mode === 'phone_or_email') return 'Phone or email';
    if (mode === 'phone_only') return 'Phone only';
    if (mode === 'email_only') return 'Email only';
    if (mode === 'source_url') return 'Source URL';
    return mode?.replaceAll('_', ' ') || 'Unknown';
}

function IntegrationsWorkspace({
    canCreateMarkets,
    canEditPaymentLinks,
    canManagePushProviders,
    canManageWalletSystem,
    canManageWalletPlatforms,
    currentUserEmail,
}) {
    const queryClient = useQueryClient();
    const toast = useToast();
    const [integrationArea, setIntegrationArea] = useState('overview');
    const [selectedPlatformId, setSelectedPlatformId] = useState(null);
    const [editor, setEditor] = useState(null);
    const [createOpen, setCreateOpen] = useState(false);
    const [createForm, setCreateForm] = useState(defaultPlatformForm());
    const [testReason, setTestReason] = useState('Connection health check from settings');
    const [syncForm, setSyncForm] = useState({
        scope: 'leads',
        mode: 'delta',
        dry_run: true,
        per_page: 100,
        reason: 'Manual sync run from integrations workspace',
    });
    const [syncConfirmOpen, setSyncConfirmOpen] = useState(false);
    const [latestSyncResult, setLatestSyncResult] = useState(null);
    const [smsProviderForm, setSmsProviderForm] = useState(defaultSmsProviderForm());
    const [smsProviderApiKeyConfigured, setSmsProviderApiKeyConfigured] = useState(false);
    const [smsTestForm, setSmsTestForm] = useState({
        phone: '',
        message: 'This is a test message from ExoticCRM settings.',
        reason: 'SMS provider test dispatch',
    });
    const [smsTestConfirmOpen, setSmsTestConfirmOpen] = useState(false);
    const [latestSmsTestResult, setLatestSmsTestResult] = useState(null);
    const [pushProviderForm, setPushProviderForm] = useState(defaultPushProviderForm());
    const [pushPlatformId, setPushPlatformId] = useState('');
    const [pushTestForm, setPushTestForm] = useState({
        title: 'Quick profile update',
        message: 'Test notification from ExoticCRM push settings.',
        target_url: '',
        icon_url: '',
        reason: 'Push provider test dispatch',
    });
    const [pushTestConfirmOpen, setPushTestConfirmOpen] = useState(false);
    const [latestPushTestResult, setLatestPushTestResult] = useState(null);
    const [walletSystemForm, setWalletSystemForm] = useState(defaultWalletSystemForm());
    const [walletPinForm, setWalletPinForm] = useState(defaultWalletPinForm());
    const [walletPlatformForm, setWalletPlatformForm] = useState(defaultWalletPlatformForm());
    const [walletProvidersForm, setWalletProvidersForm] = useState(buildWalletProvidersForm(null));
    const [walletProviderTestForm, setWalletProviderTestForm] = useState({
        provider: 'pesapal',
        environment: 'sandbox',
        reason: 'Wallet provider connectivity test',
    });
    const [walletGuideEnvironment, setWalletGuideEnvironment] = useState('sandbox');
    const [walletDomainTestForm, setWalletDomainTestForm] = useState({
        environment: 'sandbox',
        reason: 'Wallet billing domain DNS check',
    });
    const [walletSslTestForm, setWalletSslTestForm] = useState({
        environment: 'sandbox',
        reason: 'Wallet billing SSL reachability check',
    });
    const [walletAppTestForm, setWalletAppTestForm] = useState({
        environment: 'sandbox',
        reason: 'Wallet billing app reachability check',
    });
    const [walletEmailTestForm, setWalletEmailTestForm] = useState({
        to_email: currentUserEmail || '',
        reason: 'Wallet SMTP test email',
    });
    const [walletCredentialRotationForm, setWalletCredentialRotationForm] = useState({
        environment: 'sandbox',
        credential: 'bearer',
        reason: 'Rotate wallet WP credential',
    });
    const [latestWalletProviderTest, setLatestWalletProviderTest] = useState(null);
    const [latestWalletDomainTest, setLatestWalletDomainTest] = useState(null);
    const [latestWalletSslTest, setLatestWalletSslTest] = useState(null);
    const [latestWalletAppTest, setLatestWalletAppTest] = useState(null);
    const [latestWalletEmailTest, setLatestWalletEmailTest] = useState(null);
    const [walletCredentialReveal, setWalletCredentialReveal] = useState(null);
    const [selectedScraperSourceId, setSelectedScraperSourceId] = useState(null);
    const [scraperEditor, setScraperEditor] = useState(null);
    const [scraperCreateOpen, setScraperCreateOpen] = useState(false);
    const [scraperCreateForm, setScraperCreateForm] = useState(defaultScraperForm());
    const [scraperRunConfirmOpen, setScraperRunConfirmOpen] = useState(false);
    const [scraperRunForm, setScraperRunForm] = useState({
        dry_run: true,
        max_candidates: 50,
        reason: 'Dry-run scraper execution from settings',
    });
    const [latestScraperRunResult, setLatestScraperRunResult] = useState(null);
    const [paymentLinkForm, setPaymentLinkForm] = useState(defaultPaymentLinkProviderForm());
    const [packageEditor, setPackageEditor] = useState(null);
    const paymentLinkReadOnly = !canEditPaymentLinks;

    const { data, isLoading } = useQuery({
        queryKey: ['settings-integrations'],
        queryFn: () => api.get('/crm/settings/integrations').then((response) => response.data),
    });

    const services = data?.services || {};
    const walletConfig = data?.wallet || {};
    const walletSystemConfig = walletConfig.system || null;
    const walletModeOptions = walletConfig.mode_options || ['disabled', 'sandbox', 'production'];
    const walletEnvironmentOptions = walletConfig.environment_options || ['sandbox', 'production'];
    const walletProviderKeys = walletConfig.provider_keys || ['pesapal', 'paystack', 'mpesa_stk'];
    const smsProviderConfig = services.sms_provider || null;
    const pushProviderConfig = services.push_provider || null;
    const activeProviderLabel = smsProviderLabel(smsProviderConfig?.active_provider || 'legacy_gateway');
    const pushDefaultLabel = pushProviderLabel(pushProviderConfig?.default_provider || 'webpushr');
    const pushConfiguredCount = Object.values(pushProviderConfig?.platforms || {}).length;
    const serviceRows = [
        {
            key: 'wallet',
            label: 'Wallet System',
            status: services.wallet_system?.status || 'configured_disabled',
            detail: `Mode: ${walletSystemConfig?.mode || 'disabled'} • ${services.wallet_system?.enabled_markets || 0} enabled markets`,
        },
        {
            key: 'sms',
            label: 'SMS Routing',
            status: services.sms_gateway?.status || 'pending',
            detail: `Active: ${activeProviderLabel} • ${services.sms_gateway?.enabled ? 'Dispatch enabled' : 'Dispatch disabled'}`,
        },
        {
            key: 'push',
            label: 'Push Routing',
            status: pushProviderConfig?.enabled
                ? (pushConfiguredCount > 0 ? 'connected' : 'configured_disabled')
                : 'pending',
            detail: `Default: ${pushDefaultLabel} • ${pushProviderConfig?.enabled ? 'Dispatch enabled' : 'Dispatch disabled'}`,
        },
        {
            key: 'kopokopo',
            label: 'KopoKopo',
            status: services.kopokopo?.status || 'pending',
            detail: services.kopokopo?.base_url || 'Base URL not configured',
        },
        {
            key: 'payment_service',
            label: 'Payment Service (Django)',
            status: services.payment_service?.status || 'pending',
            detail: services.payment_service?.base_url
                ? `${services.payment_service.base_url} • Link path: ${services.payment_service.payment_link_path || '/pay'}`
                : 'DJANGO_API_BASE not configured',
        },
        {
            key: 'sendgrid',
            label: 'SendGrid',
            status: services.sendgrid?.status || 'deferred',
            detail: services.sendgrid?.note || 'Deferred',
        },
    ];

    const platformRows = data?.platforms || [];
    const selectedPlatform = platformRows.find((platform) => platform.platform_id === selectedPlatformId) || null;
    const enabledPaymentLinkProviders = useMemo(() => (
        (paymentLinkForm.providers || [])
            .map((provider) => ({
                ...provider,
                key: provider.key.trim(),
                label: provider.label?.trim() || provider.key.trim(),
            }))
            .filter((provider) => provider.key !== '' && Boolean(provider.enabled))
    ), [paymentLinkForm.providers]);
    const scraperSources = data?.scraper?.sources || [];
    const scraperRuns = data?.scraper?.recent_runs || [];
    const scraperProfiles = data?.scraper?.parser_profiles || ['contact_cards', 'profile_links'];
    const scraperSchedules = data?.scraper?.fetch_schedules || ['manual_only', 'daily', 'weekly'];
    const scraperDedupeModes = data?.scraper?.dedupe_modes || ['phone_or_email', 'phone_only', 'email_only', 'source_url'];
    const selectedScraperSource = scraperSources.find((source) => source.id === selectedScraperSourceId) || null;

    useEffect(() => {
        const params = new URLSearchParams(window.location.search);
        const requestedArea = params.get('integrationArea');
        const shouldOpenCreate = params.get('createMarket');
        const allowedAreas = new Set(['overview', 'wallet', 'markets', 'payment_links', 'sms', 'push', 'scraper']);
        if (requestedArea && allowedAreas.has(requestedArea)) {
            setIntegrationArea(requestedArea);
        }
        if (shouldOpenCreate === '1' && canCreateMarkets) {
            setCreateOpen(true);
            params.delete('createMarket');
            const nextSearch = params.toString();
            window.history.replaceState({}, '', `${window.location.pathname}${nextSearch ? `?${nextSearch}` : ''}`);
        }

        const requestedPlatform = Number(params.get('platform_id'));
        if (requestedPlatform > 0) {
            setSelectedPlatformId(requestedPlatform);
        }
    }, [canCreateMarkets]);

    useEffect(() => {
        if (!platformRows.length) {
            setSelectedPlatformId(null);
            setEditor(null);
            return;
        }

        if (!selectedPlatformId || !platformRows.some((platform) => platform.platform_id === selectedPlatformId)) {
            setSelectedPlatformId(platformRows[0].platform_id);
        }
    }, [platformRows, selectedPlatformId]);

    useEffect(() => {
        setWalletSystemForm(buildWalletSystemForm(walletSystemConfig));
    }, [walletSystemConfig]);

    useEffect(() => {
        if (!walletEnvironmentOptions.length) {
            return;
        }

        if (!walletEnvironmentOptions.includes(walletGuideEnvironment)) {
            setWalletGuideEnvironment(walletEnvironmentOptions[0]);
        }
    }, [walletEnvironmentOptions, walletGuideEnvironment]);

    useEffect(() => {
        if (!selectedPlatform) {
            return;
        }

        setEditor(buildPlatformEditor(selectedPlatform));
        setLatestSyncResult(selectedPlatform.sync?.last_result || null);
        setPaymentLinkForm(buildPaymentLinkProviderForm(selectedPlatform));
        setPackageEditor(buildPackageEditor(selectedPlatform));
        setWalletPlatformForm(buildWalletPlatformForm(selectedPlatform));
        setWalletProvidersForm(buildWalletProvidersForm(selectedPlatform));
        setWalletCredentialReveal(null);
    }, [selectedPlatformId, selectedPlatform]);

    useEffect(() => {
        const nextActiveProvider = enabledPaymentLinkProviders.some((provider) => provider.key === paymentLinkForm.active_provider)
            ? paymentLinkForm.active_provider
            : (enabledPaymentLinkProviders[0]?.key || '');

        if (nextActiveProvider === paymentLinkForm.active_provider) {
            return;
        }

        setPaymentLinkForm((current) => ({
            ...current,
            active_provider: nextActiveProvider,
        }));
    }, [enabledPaymentLinkProviders, paymentLinkForm.active_provider]);

    useEffect(() => {
        if (!scraperSources.length) {
            setSelectedScraperSourceId(null);
            setScraperEditor(null);
            setLatestScraperRunResult(null);
            return;
        }

        if (!selectedScraperSourceId || !scraperSources.some((source) => source.id === selectedScraperSourceId)) {
            setSelectedScraperSourceId(scraperSources[0].id);
        }
    }, [scraperSources, selectedScraperSourceId]);

    useEffect(() => {
        if (!selectedScraperSource) {
            return;
        }

        setScraperEditor(buildScraperEditor(selectedScraperSource));
        setLatestScraperRunResult(selectedScraperSource.last_run_summary || null);
    }, [selectedScraperSourceId, selectedScraperSource]);

    useEffect(() => {
        if (!scraperCreateOpen) {
            return;
        }

        if (!scraperCreateForm.platform_id && platformRows.length > 0) {
            setScraperCreateForm((current) => ({
                ...current,
                platform_id: String(platformRows[0].platform_id),
            }));
        }
    }, [scraperCreateOpen, scraperCreateForm.platform_id, platformRows]);

    useEffect(() => {
        const smsState = buildSmsProviderForm(smsProviderConfig);
        setSmsProviderForm(smsState.form);
        setSmsProviderApiKeyConfigured(smsState.apiKeyConfigured);
    }, [smsProviderConfig]);

    useEffect(() => {
        const nextPushForm = buildPushProviderForm(pushProviderConfig, platformRows);
        setPushProviderForm(nextPushForm);

        const availableIds = Object.keys(nextPushForm.platforms || {});
        if (!availableIds.length) {
            setPushPlatformId('');
            return;
        }

        if (!pushPlatformId || !availableIds.includes(String(pushPlatformId))) {
            setPushPlatformId(availableIds[0]);
        }
    }, [pushProviderConfig, platformRows, pushPlatformId]);

    useEffect(() => {
        setWalletEmailTestForm((current) => ({
            ...current,
            to_email: current.to_email || currentUserEmail || '',
        }));
    }, [currentUserEmail]);

    useEffect(() => {
        const selectedPlatformForPush = platformRows.find((platform) => String(platform.platform_id) === String(pushPlatformId));
        if (!selectedPlatformForPush?.domain) {
            return;
        }

        setPushTestForm((current) => {
            if (current.target_url.trim()) {
                return current;
            }

            return {
                ...current,
                target_url: `https://${selectedPlatformForPush.domain}`,
            };
        });
    }, [platformRows, pushPlatformId]);

    const createPlatformMutation = useMutation({
        mutationFn: (payload) => api.post('/crm/settings/integrations/platforms', payload).then((response) => response.data),
        onSuccess: (response) => {
            const createdPlatformName = response?.platform?.platform_name || response?.platform?.name || 'new market';
            queryClient.invalidateQueries({ queryKey: ['settings-integrations'] });
            setCreateOpen(false);
            setCreateForm(defaultPlatformForm());
            setSelectedPlatformId(response?.platform?.platform_id || null);
            setIntegrationArea('markets');
            setSyncForm((current) => ({
                ...current,
                scope: 'clients',
                mode: 'full',
                dry_run: false,
                per_page: 100,
                reason: `Initial full client sync for ${createdPlatformName}`,
            }));
            toast.success(
                response?.activation_deferred
                    ? 'Market created in draft mode. Configure packages and pricing, then activate and run initial full sync.'
                    : 'Market integration profile created. Configure packages and run initial full sync to onboard records.'
            );
        },
        onError: (error) => {
            toast.error(error?.response?.data?.message || 'Failed to create market profile.');
        },
    });

    const updatePlatformMutation = useMutation({
        mutationFn: ({ platformId, payload }) => api.patch(`/crm/settings/integrations/platforms/${platformId}`, payload).then((response) => response.data),
        onSuccess: (response) => {
            queryClient.invalidateQueries({ queryKey: ['settings-integrations'] });
            setEditor(buildPlatformEditor(response?.platform));
            toast.success('Market integration profile updated.');
        },
        onError: (error) => {
            toast.error(error?.response?.data?.message || 'Failed to update market profile.');
        },
    });

    const savePackageCatalogMutation = useMutation({
        mutationFn: ({ platformId, payload }) => api.patch(`/crm/settings/integrations/platforms/${platformId}/packages`, payload).then((response) => response.data),
        onSuccess: (response) => {
            queryClient.invalidateQueries({ queryKey: ['settings-integrations'] });
            setPackageEditor(buildPackageEditor(response?.platform || null));
            toast.success('Market package catalog saved.');
        },
        onError: (error) => {
            toast.error(error?.response?.data?.message || 'Failed to update market package catalog.');
        },
    });

    const testConnectionMutation = useMutation({
        mutationFn: ({ platformId, payload }) => api.post(`/crm/settings/integrations/platforms/${platformId}/test-connection`, payload).then((response) => response.data),
        onSuccess: (response) => {
            queryClient.invalidateQueries({ queryKey: ['settings-integrations'] });
            toast.success('Connection test passed.');
            setLatestSyncResult(response?.platform?.sync?.last_result || latestSyncResult);
        },
        onError: (error) => {
            toast.error(error?.response?.data?.message || 'Connection test failed.');
        },
    });

    const savePaymentLinkProvidersMutation = useMutation({
        mutationFn: ({ platformId, payload }) => api.patch(`/crm/settings/integrations/platforms/${platformId}/payment-link-providers`, payload).then((response) => response.data),
        onSuccess: (response) => {
            queryClient.invalidateQueries({ queryKey: ['settings-integrations'] });
            const updatedPlatform = response?.platform || null;
            if (updatedPlatform) {
                setPaymentLinkForm(buildPaymentLinkProviderForm(updatedPlatform));
            }
            toast.success('Payment link providers updated.');
        },
        onError: (error) => {
            toast.error(error?.response?.data?.message || 'Failed to update payment link providers.');
        },
    });

    const runSyncMutation = useMutation({
        mutationFn: ({ platformId, payload }) => api.post(`/crm/settings/integrations/platforms/${platformId}/sync`, payload).then((response) => response.data),
        onSuccess: (response) => {
            queryClient.invalidateQueries({ queryKey: ['settings-integrations'] });
            setLatestSyncResult(response?.result || null);
            toast.success(response?.status === 'partial' ? 'Sync completed with warnings.' : 'Sync completed successfully.');
            setSyncConfirmOpen(false);
        },
        onError: (error) => {
            toast.error(error?.response?.data?.message || 'Manual sync failed.');
        },
    });

    const createScraperSourceMutation = useMutation({
        mutationFn: (payload) => api.post('/crm/settings/integrations/scraper-sources', payload).then((response) => response.data),
        onSuccess: (response) => {
            queryClient.invalidateQueries({ queryKey: ['settings-integrations'] });
            const sourceId = Number(response?.source?.id || 0);
            if (sourceId > 0) {
                setSelectedScraperSourceId(sourceId);
            }
            setScraperCreateOpen(false);
            setScraperCreateForm(defaultScraperForm(platformRows[0]?.platform_id));
            toast.success('Scraper source created.');
        },
        onError: (error) => {
            toast.error(error?.response?.data?.message || 'Failed to create scraper source.');
        },
    });

    const updateScraperSourceMutation = useMutation({
        mutationFn: ({ sourceId, payload }) => api.patch(`/crm/settings/integrations/scraper-sources/${sourceId}`, payload).then((response) => response.data),
        onSuccess: (response) => {
            queryClient.invalidateQueries({ queryKey: ['settings-integrations'] });
            setScraperEditor(buildScraperEditor(response?.source || null));
            toast.success('Scraper source updated.');
        },
        onError: (error) => {
            toast.error(error?.response?.data?.message || 'Failed to update scraper source.');
        },
    });

    const runScraperSourceMutation = useMutation({
        mutationFn: ({ sourceId, payload }) => api.post(`/crm/settings/integrations/scraper-sources/${sourceId}/run`, payload).then((response) => response.data),
        onSuccess: (response) => {
            queryClient.invalidateQueries({ queryKey: ['settings-integrations'] });
            setLatestScraperRunResult(response?.result || null);
            setScraperRunConfirmOpen(false);
            const status = response?.result?.status;
            if (status === 'partial') {
                toast.warning('Scraper run completed with warnings.');
                return;
            }
            toast.success(status === 'success' ? 'Scraper run completed.' : 'Scraper run finished.');
        },
        onError: (error) => {
            const payload = error?.response?.data?.result;
            if (payload) {
                setLatestScraperRunResult(payload);
            }
            toast.error(error?.response?.data?.message || payload?.message || 'Scraper run failed.');
        },
    });

    const saveSmsProviderMutation = useMutation({
        mutationFn: (payload) => api.patch('/crm/settings/integrations/sms-provider', payload).then((response) => response.data),
        onSuccess: (response) => {
            queryClient.invalidateQueries({ queryKey: ['settings-integrations'] });
            const smsState = buildSmsProviderForm(response?.sms_provider || null);
            setSmsProviderForm(smsState.form);
            setSmsProviderApiKeyConfigured(smsState.apiKeyConfigured);
            toast.success('SMS provider settings saved.');
        },
        onError: (error) => {
            toast.error(error?.response?.data?.message || 'Failed to save SMS provider settings.');
        },
    });

    const testSmsProviderMutation = useMutation({
        mutationFn: (payload) => api.post('/crm/settings/integrations/sms-provider/test', payload).then((response) => response.data),
        onSuccess: (response) => {
            setLatestSmsTestResult(response?.result || null);
            setSmsTestConfirmOpen(false);
            toast.success('SMS test dispatch sent successfully.');
        },
        onError: (error) => {
            const result = error?.response?.data?.result || null;
            if (result) {
                setLatestSmsTestResult(result);
            }
            const resultMessage = result?.provider_response || null;
            toast.error(resultMessage || error?.response?.data?.message || 'SMS test dispatch failed.');
        },
    });

    const savePushProviderMutation = useMutation({
        mutationFn: (payload) => api.patch('/crm/settings/integrations/push-provider', payload).then((response) => response.data),
        onSuccess: (response) => {
            queryClient.invalidateQueries({ queryKey: ['settings-integrations'] });
            setPushProviderForm(buildPushProviderForm(response?.push_provider || null, platformRows));
            toast.success('Push provider settings saved.');
        },
        onError: (error) => {
            toast.error(error?.response?.data?.message || 'Failed to save push provider settings.');
        },
    });

    const saveWalletSystemMutation = useMutation({
        mutationFn: (payload) => api.patch('/crm/settings/wallet', payload).then((response) => response.data),
        onSuccess: (response) => {
            queryClient.invalidateQueries({ queryKey: ['settings-integrations'] });
            setWalletSystemForm(buildWalletSystemForm(response?.system || null));
            toast.success('Wallet system settings saved.');
        },
        onError: (error) => {
            toast.error(error?.response?.data?.message || 'Failed to save wallet system settings.');
        },
    });

    const updateWalletPinMutation = useMutation({
        mutationFn: (payload) => api.patch('/crm/settings/wallet/pin', payload).then((response) => response.data),
        onSuccess: (response) => {
            queryClient.invalidateQueries({ queryKey: ['settings-integrations'] });
            const system = buildWalletSystemForm(response?.system || null);
            setWalletSystemForm((current) => ({
                ...current,
                pin_set: system.pin_set,
                pin_last_updated_at: system.pin_last_updated_at,
            }));
            setWalletPinForm(defaultWalletPinForm());
            toast.success('Wallet PIN updated.');
        },
        onError: (error) => {
            toast.error(error?.response?.data?.message || 'Failed to update wallet PIN.');
        },
    });

    const saveWalletPlatformMutation = useMutation({
        mutationFn: ({ platformId, payload }) => api.patch(`/crm/settings/integrations/platforms/${platformId}/wallet`, payload).then((response) => response.data),
        onSuccess: (response) => {
            queryClient.invalidateQueries({ queryKey: ['settings-integrations'] });
            if (response?.platform) {
                setWalletPlatformForm(buildWalletPlatformForm(response.platform));
                setWalletProvidersForm(buildWalletProvidersForm(response.platform));
            }
            toast.success('Platform wallet settings saved.');
        },
        onError: (error) => {
            toast.error(error?.response?.data?.message || 'Failed to save platform wallet settings.');
        },
    });

    const saveWalletProvidersMutation = useMutation({
        mutationFn: ({ platformId, payload }) => api.patch(`/crm/settings/integrations/platforms/${platformId}/wallet/providers`, payload).then((response) => response.data),
        onSuccess: (response) => {
            queryClient.invalidateQueries({ queryKey: ['settings-integrations'] });
            if (response?.platform) {
                setWalletProvidersForm(buildWalletProvidersForm(response.platform));
            }
            toast.success('Wallet provider credentials saved.');
        },
        onError: (error) => {
            toast.error(error?.response?.data?.message || 'Failed to save wallet provider credentials.');
        },
    });

    const rotateWalletCredentialMutation = useMutation({
        mutationFn: ({ platformId, payload }) => api.post(`/crm/settings/integrations/platforms/${platformId}/wallet/wp-credentials/rotate`, payload).then((response) => response.data),
        onSuccess: (response) => {
            queryClient.invalidateQueries({ queryKey: ['settings-integrations'] });
            setWalletCredentialReveal({
                environment: response?.environment,
                credential: response?.credential,
                revealed: response?.revealed || {},
            });
            toast.success('Wallet WP credential rotated. Copy the revealed value now.');
        },
        onError: (error) => {
            toast.error(error?.response?.data?.message || 'Failed to rotate wallet credential.');
        },
    });

    const testWalletProviderMutation = useMutation({
        mutationFn: ({ platformId, payload }) => api.post(`/crm/settings/integrations/platforms/${platformId}/wallet/providers/test`, payload).then((response) => response.data),
        onSuccess: (response) => {
            setLatestWalletProviderTest(response?.result || null);
            toast.success('Wallet provider test completed.');
        },
        onError: (error) => {
            const result = error?.response?.data?.result || null;
            if (result) {
                setLatestWalletProviderTest(result);
            }
            toast.error(error?.response?.data?.message || result?.message || 'Wallet provider test failed.');
        },
    });

    const testWalletDomainMutation = useMutation({
        mutationFn: (payload) => api.post('/crm/settings/wallet/test-domain', payload).then((response) => response.data),
        onSuccess: (response) => {
            setLatestWalletDomainTest(response?.result || null);
            toast.success('Wallet domain test completed.');
        },
        onError: (error) => {
            const result = error?.response?.data?.result || null;
            if (result) {
                setLatestWalletDomainTest(result);
            }
            toast.error(error?.response?.data?.message || result?.message || 'Wallet domain test failed.');
        },
    });

    const testWalletSslMutation = useMutation({
        mutationFn: (payload) => api.post('/crm/settings/wallet/test-ssl', payload).then((response) => response.data),
        onSuccess: (response) => {
            setLatestWalletSslTest(response?.result || null);
            toast.success('Wallet SSL test completed.');
        },
        onError: (error) => {
            const result = error?.response?.data?.result || null;
            if (result) {
                setLatestWalletSslTest(result);
            }
            toast.error(error?.response?.data?.message || result?.message || 'Wallet SSL test failed.');
        },
    });

    const testWalletAppMutation = useMutation({
        mutationFn: (payload) => api.post('/crm/settings/wallet/test-app', payload).then((response) => response.data),
        onSuccess: (response) => {
            setLatestWalletAppTest(response?.result || null);
            toast.success('Wallet billing app test completed.');
        },
        onError: (error) => {
            const result = error?.response?.data?.result || null;
            if (result) {
                setLatestWalletAppTest(result);
            }
            toast.error(error?.response?.data?.message || result?.message || 'Wallet billing app test failed.');
        },
    });

    const testWalletEmailMutation = useMutation({
        mutationFn: (payload) => api.post('/crm/settings/wallet/test-email', payload).then((response) => response.data),
        onSuccess: (response) => {
            setLatestWalletEmailTest(response?.result || null);
            toast.success('Wallet test email sent.');
        },
        onError: (error) => {
            const result = error?.response?.data?.result || null;
            if (result) {
                setLatestWalletEmailTest(result);
            }
            toast.error(error?.response?.data?.message || result?.message || 'Wallet test email failed.');
        },
    });

    const updateWalletSystemField = (field, value) => {
        setWalletSystemForm((current) => ({
            ...current,
            [field]: value,
        }));
    };

    const updateWalletSystemDomain = (environment, value) => {
        setWalletSystemForm((current) => ({
            ...current,
            billing_domains: {
                ...current.billing_domains,
                [environment]: value,
            },
        }));
    };

    const updateWalletSystemBranding = (environment, field, value) => {
        setWalletSystemForm((current) => ({
            ...current,
            billing_branding: {
                ...current.billing_branding,
                [environment]: {
                    ...current.billing_branding[environment],
                    [field]: value,
                },
            },
        }));
    };

    const updateWalletSystemSmtp = (field, value) => {
        setWalletSystemForm((current) => ({
            ...current,
            smtp: {
                ...current.smtp,
                [field]: value,
            },
        }));
    };

    const saveWalletSystemConfig = () => {
        saveWalletSystemMutation.mutate({
            mode: walletSystemForm.mode,
            default_currency: walletSystemForm.default_currency.trim().toUpperCase(),
            max_single_topup_default: walletSystemForm.max_single_topup_default,
            max_wallet_balance_default: walletSystemForm.max_wallet_balance_default,
            billing_domains: {
                sandbox: walletSystemForm.billing_domains.sandbox.trim() || null,
                production: walletSystemForm.billing_domains.production.trim() || null,
            },
            billing_branding: {
                sandbox: {
                    business_name: walletSystemForm.billing_branding.sandbox.business_name.trim(),
                    description: walletSystemForm.billing_branding.sandbox.description.trim(),
                },
                production: {
                    business_name: walletSystemForm.billing_branding.production.business_name.trim(),
                    description: walletSystemForm.billing_branding.production.description.trim(),
                },
            },
            redirect_delay_seconds: Number(walletSystemForm.redirect_delay_seconds || 3),
            wallet_refresh_rate_limit_seconds: Number(walletSystemForm.wallet_refresh_rate_limit_seconds || 15),
            wallet_refresh_timeout_seconds: Number(walletSystemForm.wallet_refresh_timeout_seconds || 15),
            topup_poll_interval_seconds: Number(walletSystemForm.topup_poll_interval_seconds || 10),
            smtp: {
                enabled: Boolean(walletSystemForm.smtp.enabled),
                host: walletSystemForm.smtp.host.trim(),
                port: Number(walletSystemForm.smtp.port || 587),
                username: walletSystemForm.smtp.username.trim(),
                password: walletSystemForm.smtp.password.trim() || null,
                encryption: walletSystemForm.smtp.encryption.trim(),
                from_address: walletSystemForm.smtp.from_address.trim() || null,
                from_name: walletSystemForm.smtp.from_name.trim(),
            },
            reason: walletSystemForm.reason.trim(),
        });
    };

    const saveWalletPin = () => {
        updateWalletPinMutation.mutate({
            pin: walletPinForm.pin.trim(),
            pin_confirmation: walletPinForm.pin_confirmation.trim(),
            reason: walletPinForm.reason.trim(),
        });
    };

    const updateWalletPlatformField = (field, value) => {
        setWalletPlatformForm((current) => ({
            ...current,
            [field]: value,
        }));
    };

    const updateWalletPlatformProviderField = (provider, field, value) => {
        setWalletPlatformForm((current) => ({
            ...current,
            providers: {
                ...current.providers,
                [provider]: {
                    ...current.providers[provider],
                    [field]: value,
                },
            },
        }));
    };

    const updateWalletTopupPreset = (index, value) => {
        setWalletPlatformForm((current) => ({
            ...current,
            topup_presets: current.topup_presets.map((preset, presetIndex) => (
                presetIndex === index ? value : preset
            )),
        }));
    };

    const addWalletTopupPreset = () => {
        setWalletPlatformForm((current) => ({
            ...current,
            topup_presets: [...current.topup_presets, ''],
        }));
    };

    const removeWalletTopupPreset = (index) => {
        setWalletPlatformForm((current) => ({
            ...current,
            topup_presets: current.topup_presets.filter((_, presetIndex) => presetIndex !== index),
        }));
    };

    const saveWalletPlatformConfig = () => {
        if (!selectedPlatform) {
            return;
        }

        const topupPresets = walletPlatformForm.topup_presets
            .map((value) => value.trim())
            .filter(Boolean);

        if (topupPresets.length === 0) {
            toast.error('Add at least one wallet top-up preset before saving.');
            return;
        }

        const invalidProviderRange = Object.entries(walletPlatformForm.providers || {}).find(([, providerConfig]) => (
            Number(providerConfig?.min_amount || 0) > Number(providerConfig?.max_amount || 0)
        ));

        if (invalidProviderRange) {
            toast.error(`${walletProviderLabel(invalidProviderRange[0])} minimum amount cannot exceed the maximum amount.`);
            return;
        }

        saveWalletPlatformMutation.mutate({
            platformId: selectedPlatform.platform_id,
            payload: {
                enabled: Boolean(walletPlatformForm.enabled),
                mode_override: walletPlatformForm.mode_override || 'inherit',
                currency_code: walletPlatformForm.currency_code.trim().toUpperCase(),
                max_single_topup: walletPlatformForm.max_single_topup.trim(),
                max_wallet_balance: walletPlatformForm.max_wallet_balance.trim(),
                topup_presets: topupPresets,
                allow_combined_topup_subscribe: Boolean(walletPlatformForm.allow_combined_topup_subscribe),
                show_refresh_button: Boolean(walletPlatformForm.show_refresh_button),
                recent_transactions_limit: Number(walletPlatformForm.recent_transactions_limit || 10),
                providers: {
                    pesapal: {
                        enabled: Boolean(walletPlatformForm.providers.pesapal.enabled),
                        min_amount: walletPlatformForm.providers.pesapal.min_amount.trim(),
                        max_amount: walletPlatformForm.providers.pesapal.max_amount.trim(),
                    },
                    paystack: {
                        enabled: Boolean(walletPlatformForm.providers.paystack.enabled),
                        min_amount: walletPlatformForm.providers.paystack.min_amount.trim(),
                        max_amount: walletPlatformForm.providers.paystack.max_amount.trim(),
                    },
                    mpesa_stk: {
                        enabled: Boolean(walletPlatformForm.providers.mpesa_stk.enabled),
                        min_amount: walletPlatformForm.providers.mpesa_stk.min_amount.trim(),
                        max_amount: walletPlatformForm.providers.mpesa_stk.max_amount.trim(),
                    },
                },
                reason: walletPlatformForm.reason.trim(),
            },
        });
    };

    const updateWalletProviderCredentialField = (provider, environment, field, value) => {
        setWalletProvidersForm((current) => ({
            ...current,
            [provider]: {
                ...current[provider],
                [environment]: {
                    ...current[provider][environment],
                    [field]: value,
                },
            },
        }));
    };

    const saveWalletProvidersConfig = () => {
        if (!selectedPlatform) {
            return;
        }

        saveWalletProvidersMutation.mutate({
            platformId: selectedPlatform.platform_id,
            payload: {
                pesapal: {
                    sandbox: {
                        consumer_key: walletProvidersForm.pesapal.sandbox.consumer_key.trim(),
                        consumer_secret: walletProvidersForm.pesapal.sandbox.consumer_secret.trim(),
                        ipn_id: walletProvidersForm.pesapal.sandbox.ipn_id.trim(),
                    },
                    production: {
                        consumer_key: walletProvidersForm.pesapal.production.consumer_key.trim(),
                        consumer_secret: walletProvidersForm.pesapal.production.consumer_secret.trim(),
                        ipn_id: walletProvidersForm.pesapal.production.ipn_id.trim(),
                    },
                },
                paystack: {
                    sandbox: {
                        public_key: walletProvidersForm.paystack.sandbox.public_key.trim(),
                        secret_key: walletProvidersForm.paystack.sandbox.secret_key.trim(),
                    },
                    production: {
                        public_key: walletProvidersForm.paystack.production.public_key.trim(),
                        secret_key: walletProvidersForm.paystack.production.secret_key.trim(),
                    },
                },
                mpesa_stk: {
                    sandbox: {
                        transport: walletProvidersForm.mpesa_stk.sandbox.transport,
                        payment_service_base_url: walletProvidersForm.mpesa_stk.sandbox.payment_service_base_url.trim() || null,
                        organization_code: walletProvidersForm.mpesa_stk.sandbox.organization_code.trim(),
                        callback_base_url: walletProvidersForm.mpesa_stk.sandbox.callback_base_url.trim() || null,
                    },
                    production: {
                        transport: walletProvidersForm.mpesa_stk.production.transport,
                        payment_service_base_url: walletProvidersForm.mpesa_stk.production.payment_service_base_url.trim() || null,
                        organization_code: walletProvidersForm.mpesa_stk.production.organization_code.trim(),
                        callback_base_url: walletProvidersForm.mpesa_stk.production.callback_base_url.trim() || null,
                    },
                },
                reason: walletProvidersForm.reason.trim(),
            },
        });
    };

    const copyToClipboard = async (value, successMessage, failureMessage) => {
        if (!value) {
            return;
        }

        try {
            if (typeof navigator === 'undefined' || !navigator.clipboard?.writeText) {
                throw new Error('Clipboard unavailable');
            }

            await navigator.clipboard.writeText(value);
            toast.success(successMessage);
        } catch (_error) {
            toast.error(failureMessage);
        }
    };

    const copyWalletReveal = async (label, value) => {
        if (!value) {
            return;
        }

        await copyToClipboard(
            value,
            `${label} copied to clipboard.`,
            `Copy ${label.toLowerCase()} manually from the reveal panel.`
        );
    };

    const copyWalletGuideValue = async (label, value) => {
        await copyToClipboard(
            value,
            `${label} copied to clipboard.`,
            `Copy ${label.toLowerCase()} manually from the setup guide.`
        );
    };

    const testPushProviderMutation = useMutation({
        mutationFn: (payload) => api.post('/crm/settings/integrations/push-provider/test', payload).then((response) => response.data),
        onSuccess: (response) => {
            setLatestPushTestResult(response?.result || null);
            setPushTestConfirmOpen(false);
            toast.success('Push provider test notification sent.');
        },
        onError: (error) => {
            const result = error?.response?.data?.result || null;
            if (result) {
                setLatestPushTestResult(result);
            }
            const message = error?.response?.data?.message || 'Push provider test failed.';
            toast.error(message);
        },
    });

    const updateSmsProviderField = (section, key, value) => {
        setSmsProviderForm((current) => ({
            ...current,
            [section]: {
                ...current[section],
                [key]: value,
            },
        }));
    };

    const saveSmsProviderConfig = () => {
        const payload = {
            enabled: Boolean(smsProviderForm.enabled),
            active_provider: smsProviderForm.active_provider,
            fallback_provider: smsProviderForm.fallback_provider,
            default_prefix: smsProviderForm.default_prefix.trim(),
            legacy_gateway: {
                gateway_url: smsProviderForm.legacy_gateway.gateway_url.trim(),
                org_code: smsProviderForm.legacy_gateway.org_code.trim(),
            },
            africastalking: {
                endpoint: smsProviderForm.africastalking.endpoint.trim(),
                username: smsProviderForm.africastalking.username.trim(),
                sender_id: smsProviderForm.africastalking.sender_id.trim(),
            },
            reason: smsProviderForm.reason.trim(),
        };

        const submittedApiKey = smsProviderForm.africastalking.api_key.trim();
        if (submittedApiKey) {
            payload.africastalking.api_key = submittedApiKey;
        }

        saveSmsProviderMutation.mutate(payload);
    };

    const ensurePushPlatformConfig = (platformId, currentForm) => {
        const key = String(platformId || '');
        const next = currentForm || pushProviderForm;
        if (!key) {
            return next;
        }

        const existing = next.platforms?.[key];
        if (existing) {
            return next;
        }

        return {
            ...next,
            platforms: {
                ...(next.platforms || {}),
                [key]: defaultPushPlatformConfig(next.default_provider || 'webpushr'),
            },
        };
    };

    const updatePushProviderField = (field, value) => {
        setPushProviderForm((current) => ({
            ...current,
            [field]: value,
        }));
    };

    const updatePushPlatformField = (platformId, field, value) => {
        setPushProviderForm((current) => {
            const withPlatform = ensurePushPlatformConfig(platformId, current);
            const key = String(platformId);

            return {
                ...withPlatform,
                platforms: {
                    ...withPlatform.platforms,
                    [key]: {
                        ...withPlatform.platforms[key],
                        [field]: value,
                    },
                },
            };
        });
    };

    const updatePushProviderCredentialField = (platformId, providerKey, field, value) => {
        setPushProviderForm((current) => {
            const withPlatform = ensurePushPlatformConfig(platformId, current);
            const key = String(platformId);
            const platformConfig = withPlatform.platforms[key];

            return {
                ...withPlatform,
                platforms: {
                    ...withPlatform.platforms,
                    [key]: {
                        ...platformConfig,
                        [providerKey]: {
                            ...(platformConfig?.[providerKey] || {}),
                            [field]: value,
                        },
                    },
                },
            };
        });
    };

    const savePushProviderConfig = () => {
        const platformsPayload = {};
        const invalidFallback = [];

        Object.entries(pushProviderForm.platforms || {}).forEach(([platformId, config]) => {
            const activeProvider = config?.active_provider || pushProviderForm.default_provider || 'webpushr';
            const fallbackProvider = config?.fallback_provider || 'none';

            if (fallbackProvider !== 'none' && fallbackProvider === activeProvider) {
                invalidFallback.push(platformId);
            }

            const webpushr = config?.webpushr || {};
            const wonderpush = config?.wonderpush || {};
            const izooto = config?.izooto || {};

            const platformPayload = {
                active_provider: activeProvider,
                fallback_provider: fallbackProvider,
                webpushr: {
                    auth_token: webpushr.auth_token?.trim() || undefined,
                    api_key: webpushr.api_key?.trim() || undefined,
                },
                wonderpush: {
                    access_token: wonderpush.access_token?.trim() || undefined,
                    project_id: wonderpush.project_id?.trim() || '',
                },
                izooto: {
                    api_token: izooto.api_token?.trim() || undefined,
                },
            };

            if (!platformPayload.webpushr.auth_token) delete platformPayload.webpushr.auth_token;
            if (!platformPayload.webpushr.api_key) delete platformPayload.webpushr.api_key;
            if (!platformPayload.wonderpush.access_token) delete platformPayload.wonderpush.access_token;
            if (!platformPayload.izooto.api_token) delete platformPayload.izooto.api_token;

            platformsPayload[String(platformId)] = platformPayload;
        });

        if (invalidFallback.length > 0) {
            toast.error('Fallback provider must be different from active provider for all configured markets.');
            return;
        }

        savePushProviderMutation.mutate({
            enabled: Boolean(pushProviderForm.enabled),
            default_provider: pushProviderForm.default_provider,
            platforms: platformsPayload,
            reason: pushProviderForm.reason.trim(),
        });
    };

    const updatePaymentLinkProvider = (index, field, value) => {
        setPaymentLinkForm((current) => ({
            ...current,
            providers: current.providers.map((provider, providerIndex) => (
                providerIndex === index
                    ? { ...provider, [field]: value }
                    : provider
            )),
        }));
    };

    const addPaymentLinkProvider = () => {
        setPaymentLinkForm((current) => ({
            ...current,
            providers: [
                ...current.providers,
                {
                    key: '',
                    label: '',
                    mode: 'static_url',
                    enabled: true,
                    wallet_provider_key: 'paystack',
                    environment: 'sandbox',
                    url: '',
                    base_url: '',
                    path: '/pay',
                },
            ],
        }));
    };

    const removePaymentLinkProvider = (index) => {
        setPaymentLinkForm((current) => {
            if (current.providers.length <= 1) {
                return current;
            }

            const nextProviders = current.providers.filter((_, providerIndex) => providerIndex !== index);
            const nextKeys = new Set(
                nextProviders
                    .filter((provider) => provider.enabled && provider.key.trim() !== '')
                    .map((provider) => provider.key.trim())
            );
            const nextActive = nextKeys.has(current.active_provider)
                ? current.active_provider
                : (nextProviders.find((provider) => provider.enabled && provider.key.trim() !== '')?.key || '');

            return {
                ...current,
                active_provider: nextActive,
                providers: nextProviders,
            };
        });
    };

    const savePaymentLinkProviders = () => {
        if (!selectedPlatform) {
            return;
        }

        const normalizedProviders = paymentLinkForm.providers
            .map((provider) => ({
                key: provider.key.trim(),
                label: provider.label.trim(),
                mode: provider.mode || 'static_url',
                enabled: Boolean(provider.enabled),
                wallet_provider_key: provider.wallet_provider_key || 'paystack',
                environment: provider.environment || 'sandbox',
                url: provider.url.trim(),
                base_url: provider.base_url.trim(),
                path: provider.path.trim(),
            }))
            .filter((provider) => provider.key);

        if (!normalizedProviders.length) {
            toast.error('Add at least one payment link provider before saving.');
            return;
        }

        const keySet = new Set(normalizedProviders.map((provider) => provider.key));
        if (keySet.size !== normalizedProviders.length) {
            toast.error('Provider keys must be unique.');
            return;
        }

        const invalidStaticProvider = normalizedProviders.find((provider) => (
            provider.mode === 'static_url' && !provider.url && !provider.base_url
        ));
        if (invalidStaticProvider) {
            toast.error(`${invalidStaticProvider.label || invalidStaticProvider.key} needs a direct URL or base URL.`);
            return;
        }

        const invalidProxyProvider = normalizedProviders.find((provider) => (
            provider.mode === 'proxy_hosted_checkout'
            && (!provider.wallet_provider_key || !provider.environment)
        ));
        if (invalidProxyProvider) {
            toast.error(`${invalidProxyProvider.label || invalidProxyProvider.key} needs a wallet provider and environment.`);
            return;
        }

        const enabledProviders = normalizedProviders.filter((provider) => provider.enabled);
        if (!enabledProviders.length) {
            toast.error('Enable at least one payment link provider before saving.');
            return;
        }

        const enabledKeys = new Set(enabledProviders.map((provider) => provider.key));
        const activeProvider = enabledKeys.has(paymentLinkForm.active_provider)
            ? paymentLinkForm.active_provider
            : enabledProviders[0].key;

        const providersPayload = normalizedProviders.reduce((acc, provider) => {
            acc[provider.key] = {
                label: provider.label || provider.key,
                mode: provider.mode,
                enabled: provider.enabled,
                wallet_provider_key: provider.mode === 'proxy_hosted_checkout'
                    ? provider.wallet_provider_key
                    : null,
                environment: provider.mode === 'proxy_hosted_checkout'
                    ? provider.environment
                    : null,
                url: provider.mode === 'static_url' ? (provider.url || null) : null,
                base_url: provider.mode === 'static_url' ? (provider.base_url || null) : null,
                path: provider.mode === 'static_url' ? (provider.path || null) : null,
            };
            return acc;
        }, {});

        savePaymentLinkProvidersMutation.mutate({
            platformId: selectedPlatform.platform_id,
            payload: {
                payment_link_providers: {
                    active_provider: activeProvider,
                    providers: providersPayload,
                },
                reason: paymentLinkForm.reason.trim() || 'Updated payment link provider routing',
            },
        });
    };

    const updatePackageRow = (rowIndex, field, value) => {
        setPackageEditor((current) => {
            if (!current) return current;
            return {
                ...current,
                rows: current.rows.map((row, i) => {
                    if (i !== rowIndex) return row;
                    if (field === 'is_active') return { ...row, is_active: Boolean(value) };
                    return { ...row, [field]: value };
                }),
            };
        });
    };

    const updatePriceRow = (rowIndex, priceIndex, field, value) => {
        setPackageEditor((current) => {
            if (!current) return current;
            return {
                ...current,
                rows: current.rows.map((row, i) => {
                    if (i !== rowIndex) return row;
                    return {
                        ...row,
                        prices: row.prices.map((price, j) => {
                            if (j !== priceIndex) return price;
                            if (field === 'duration_key') {
                                const preset = defaultDurationOptions.find((d) => d.key === value);
                                return {
                                    ...price,
                                    duration_key: value,
                                    duration_label: preset ? preset.label : price.duration_label,
                                    duration_days: preset ? preset.days : price.duration_days,
                                };
                            }
                            if (field === 'price') return { ...price, price: Number(value || 0) };
                            if (field === 'is_active') return { ...price, is_active: Boolean(value) };
                            return { ...price, [field]: value };
                        }),
                    };
                }),
            };
        });
    };

    const addPackageRow = () => {
        setPackageEditor((current) => {
            if (!current) return current;
            const maxSort = current.rows.reduce((max, r) => Math.max(max, r.sort_order || 0), 0);
            return { ...current, rows: [...current.rows, newPackageRow(maxSort + 10)] };
        });
    };

    const removePackageRow = (rowIndex) => {
        setPackageEditor((current) => {
            if (!current) return current;
            return { ...current, rows: current.rows.filter((_, i) => i !== rowIndex) };
        });
    };

    const addPriceRow = (rowIndex) => {
        setPackageEditor((current) => {
            if (!current) return current;
            return {
                ...current,
                rows: current.rows.map((row, i) => {
                    if (i !== rowIndex) return row;
                    const maxSort = row.prices.reduce((max, p) => Math.max(max, p.sort_order || 0), 0);
                    return { ...row, prices: [...row.prices, newPriceRow(maxSort + 10)] };
                }),
            };
        });
    };

    const removePriceRow = (rowIndex, priceIndex) => {
        setPackageEditor((current) => {
            if (!current) return current;
            return {
                ...current,
                rows: current.rows.map((row, i) => {
                    if (i !== rowIndex) return row;
                    return { ...row, prices: row.prices.filter((_, j) => j !== priceIndex) };
                }),
            };
        });
    };

    const savePackageCatalog = () => {
        if (!selectedPlatform || !packageEditor) return;

        const rows = packageEditor.rows.filter((r) => r.name.trim());

        for (const row of rows) {
            if (row.is_active) {
                const hasActivePrice = row.prices.some((p) => p.is_active && Number(p.price) > 0 && p.duration_key);
                if (!hasActivePrice) {
                    toast.error(`${row.name || 'Unnamed package'} is active but has no priced duration. Add at least one active price > 0.`);
                    return;
                }
            }

            for (const price of row.prices) {
                if (!price.duration_key) {
                    toast.error(`${row.name || 'Unnamed package'} has a duration row without a key. Select a duration or remove the row.`);
                    return;
                }
            }
        }

        if (rows.length === 0) {
            toast.error('Add at least one package before saving.');
            return;
        }

        savePackageCatalogMutation.mutate({
            platformId: selectedPlatform.platform_id,
            payload: {
                packages: rows.map((row) => ({
                    id: row.id || undefined,
                    name: row.name.trim().toUpperCase(),
                    display_name: row.display_name.trim() || undefined,
                    tier: row.tier || undefined,
                    sort_order: row.sort_order,
                    is_active: Boolean(row.is_active),
                    is_archived: Boolean(row.is_archived),
                    prices: row.prices.map((p) => ({
                        id: p.id || undefined,
                        duration_key: p.duration_key,
                        duration_label: p.duration_label || p.duration_key.replace(/_/g, ' '),
                        duration_days: p.duration_days || 30,
                        price: Number(p.price || 0),
                        is_active: Boolean(p.is_active),
                        sort_order: p.sort_order,
                    })),
                })),
                reason: packageEditor.reason?.trim() || 'Updated market package catalog from settings workspace',
            },
        });
    };

    const connectedServices = serviceRows.filter((item) => ['connected', 'healthy', 'success'].includes(item.status)).length;
    const wpReadyMarkets = platformRows.filter((item) => item.wp_sync?.credentials_ready).length;
    const syncErrors = platformRows.filter((item) => item.sync?.last_status === 'error').length;
    const packageReadyMarkets = platformRows.filter((item) => item.package_setup?.can_go_live).length;
    const smsReady = smsProviderForm.active_provider === 'africastalking'
        ? Boolean(smsProviderForm.africastalking.username.trim()) && (smsProviderApiKeyConfigured || Boolean(smsProviderForm.africastalking.api_key.trim()))
        : Boolean(smsProviderForm.legacy_gateway.gateway_url.trim()) && Boolean(smsProviderForm.legacy_gateway.org_code.trim());
    const fallbackInvalid = smsProviderForm.fallback_provider !== 'none'
        && smsProviderForm.fallback_provider === smsProviderForm.active_provider;
    const fallbackOptions = [
        { value: 'none', label: 'No fallback' },
        { value: 'legacy_gateway', label: 'Legacy Gateway' },
        { value: 'africastalking', label: "Africa's Talking" },
    ];
    const pushProviderOptions = [
        { value: 'webpushr', label: 'WebPushr' },
        { value: 'wonderpush', label: 'WonderPush' },
        { value: 'izooto', label: 'iZooto' },
    ];
    const pushFallbackOptions = [
        { value: 'none', label: 'No fallback' },
        ...pushProviderOptions,
    ];
    const selectedPushConfig = pushPlatformId
        ? (pushProviderForm.platforms?.[String(pushPlatformId)] || defaultPushPlatformConfig(pushProviderForm.default_provider))
        : null;
    const selectedPushPlatform = platformRows.find((platform) => String(platform.platform_id) === String(pushPlatformId)) || null;
    const selectedPushProvider = selectedPushConfig?.active_provider || pushProviderForm.default_provider || 'webpushr';
    const selectedPushReady = selectedPushProvider === 'webpushr'
        ? Boolean(selectedPushConfig?.webpushr?.auth_token?.trim() || selectedPushConfig?.webpushr?.auth_token_configured)
            && Boolean(selectedPushConfig?.webpushr?.api_key?.trim() || selectedPushConfig?.webpushr?.api_key_configured)
        : selectedPushProvider === 'wonderpush'
            ? Boolean(selectedPushConfig?.wonderpush?.project_id?.trim())
                && Boolean(selectedPushConfig?.wonderpush?.access_token?.trim() || selectedPushConfig?.wonderpush?.access_token_configured)
            : Boolean(selectedPushConfig?.izooto?.api_token?.trim() || selectedPushConfig?.izooto?.api_token_configured);
    const pushFallbackInvalid = Boolean(selectedPushConfig)
        && selectedPushConfig.fallback_provider !== 'none'
        && selectedPushConfig.fallback_provider === selectedPushConfig.active_provider;
    const pushReadyPlatforms = Object.values(pushProviderForm.platforms || {}).filter((config) => {
        const active = config?.active_provider || pushProviderForm.default_provider || 'webpushr';
        if (active === 'webpushr') {
            return Boolean((config?.webpushr?.auth_token || config?.webpushr?.auth_token_configured) && (config?.webpushr?.api_key || config?.webpushr?.api_key_configured));
        }
        if (active === 'wonderpush') {
            return Boolean(config?.wonderpush?.project_id) && Boolean(config?.wonderpush?.access_token || config?.wonderpush?.access_token_configured);
        }
        return Boolean(config?.izooto?.api_token || config?.izooto?.api_token_configured);
    }).length;
    const pushConfiguredPlatforms = Object.keys(pushProviderForm.platforms || {}).length;
    const walletSystemReadOnly = !canManageWalletSystem;
    const walletPlatformReadOnly = !canManageWalletPlatforms;
    const walletEnabledMarkets = platformRows.filter((platform) => Boolean(platform.wallet?.enabled)).length;
    const walletActiveMarkets = platformRows.filter((platform) => (platform.wallet?.effective_mode || 'disabled') !== 'disabled').length;
    const selectedWalletConfig = selectedPlatform?.wallet || null;
    const selectedWalletEffectiveMode = selectedWalletConfig?.effective_mode || 'disabled';
    const selectedWalletProvidersEnabled = Object.values(walletPlatformForm.providers || {}).filter((provider) => provider?.enabled).length;
    const selectedWalletWpCredentials = walletProvidersForm.wp_to_crm || {};
    const walletGuideDomain = walletSystemForm.billing_domains?.[walletGuideEnvironment]?.trim() || '';
    const walletGuideBaseUrl = walletGuideDomain || 'https://billing.example.com';
    const walletGuideProxyTarget = (typeof window !== 'undefined' ? window.location.origin : '') || 'https://crm.example.com';
    const walletGuideHealthUrl = `${walletGuideBaseUrl.replace(/\/$/, '')}/api/billing/health`;
    const walletGuideCompleteUrl = `${walletGuideBaseUrl.replace(/\/$/, '')}/billing/complete?payment={transaction_uuid}`;
    const walletGuidePaystackWebhookUrl = `${walletGuideBaseUrl.replace(/\/$/, '')}/api/billing/paystack/webhook`;
    const walletGuidePesapalWebhookUrl = `${walletGuideBaseUrl.replace(/\/$/, '')}/api/billing/pesapal/webhook`;
    const walletGuideMpesaCallbackUrl = `${walletGuideBaseUrl.replace(/\/$/, '')}/api/billing/mpesa/callback`;
    const walletGuideDomainResult = latestWalletDomainTest?.environment === walletGuideEnvironment ? latestWalletDomainTest : null;
    const walletGuideSslResult = latestWalletSslTest?.environment === walletGuideEnvironment ? latestWalletSslTest : null;
    const walletGuideAppResult = latestWalletAppTest?.environment === walletGuideEnvironment ? latestWalletAppTest : null;
    const walletGuideNginxSnippet = [
        'server {',
        `    server_name ${walletGuideDomain || 'billing.example.com'};`,
        '    location / {',
        `        proxy_pass ${walletGuideProxyTarget};`,
        '        proxy_set_header Host $host;',
        '        proxy_set_header X-Forwarded-Proto $scheme;',
        '        proxy_set_header X-Forwarded-For $proxy_add_x_forwarded_for;',
        '    }',
        '}',
    ].join('\n');
    const walletGuideApacheSnippet = [
        '<VirtualHost *:443>',
        `    ServerName ${walletGuideDomain || 'billing.example.com'}`,
        '    SSLProxyEngine On',
        '    ProxyPreserveHost On',
        `    ProxyPass / ${walletGuideProxyTarget}/`,
        `    ProxyPassReverse / ${walletGuideProxyTarget}/`,
        '</VirtualHost>',
    ].join('\n');

    const selectedHasCredentials = Boolean(selectedPlatform?.wp_sync?.credentials_ready);
    const selectedPackageSetup = selectedPlatform?.package_setup || null;
    const selectedPackagesReady = Boolean(selectedPackageSetup?.can_go_live);
    const showInitialFullSyncCta = Boolean(
        selectedPlatform
        && selectedHasCredentials
        && !selectedPlatform.sync?.last_synced_at,
    );
    const activeScraperSources = scraperSources.filter((source) => source.is_active).length;
    const scraperBlockedOrFailed = scraperSources.filter((source) => ['blocked', 'error'].includes(source.last_run_status)).length;
    const selectedScraperRules = scraperEditor?.parser_rules || defaultScraperRules();
    const selectedScraperCompliant = Boolean(scraperEditor?.compliance_ack_robots) && Boolean(scraperEditor?.compliance_ack_tos);
    const integrationAreas = [
        { id: 'overview', label: 'Overview', hint: 'Service health' },
        { id: 'wallet', label: 'Wallet', hint: `${walletSystemConfig?.mode || 'disabled'} • ${walletActiveMarkets}/${platformRows.length || 0} live` },
        { id: 'markets', label: 'Markets', hint: `${platformRows.length} configured` },
        { id: 'payment_links', label: 'Payment Links', hint: paymentLinkReadOnly ? 'Read-only' : 'Editable routing' },
        { id: 'sms', label: 'SMS Routing', hint: smsProviderForm.enabled ? 'Enabled' : 'Disabled' },
        { id: 'push', label: 'Push Routing', hint: `${pushReadyPlatforms}/${pushConfiguredPlatforms || 0} ready` },
        { id: 'scraper', label: 'Scraper', hint: `${scraperSources.length} sources` },
    ];
    const openInitialFullSync = () => {
        if (!selectedPlatform) {
            return;
        }

        setSyncForm((current) => ({
            ...current,
            scope: 'clients',
            mode: 'full',
            dry_run: false,
            per_page: 100,
            reason: `Initial full client sync for ${selectedPlatform.platform_name}`,
        }));
        setSyncConfirmOpen(true);
    };

    return (
        <div className="space-y-4">
            <section className="grid gap-4 md:grid-cols-5">
                <MetricCard
                    label="Connected Services"
                    value={connectedServices.toLocaleString()}
                    meta="runtime integration health"
                    tone="success"
                />
                <MetricCard
                    label="Markets Configured"
                    value={platformRows.length.toLocaleString()}
                    meta="platform runtime profiles"
                    tone="accent"
                />
                <MetricCard
                    label="WP Sync Ready"
                    value={wpReadyMarkets.toLocaleString()}
                    meta="markets with credentials"
                    tone="default"
                />
                <MetricCard
                    label="Sync Errors"
                    value={syncErrors.toLocaleString()}
                    meta="markets requiring intervention"
                    tone={syncErrors > 0 ? 'danger' : 'success'}
                />
                <MetricCard
                    label="Packages Ready"
                    value={packageReadyMarkets.toLocaleString()}
                    meta="markets ready to go live"
                    tone={packageReadyMarkets < platformRows.length ? 'warning' : 'success'}
                />
            </section>

            <section className="crm-surface p-2">
                <div className="flex flex-wrap gap-2">
                    {integrationAreas.map((area) => (
                        <button
                            key={area.id}
                            type="button"
                            onClick={() => setIntegrationArea(area.id)}
                            aria-pressed={integrationArea === area.id}
                            className={`min-h-11 rounded-lg px-3 py-2 text-left transition focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-teal-500 focus-visible:ring-offset-2 ${
                                integrationArea === area.id
                                    ? 'bg-white text-slate-900 ring-1 ring-slate-200 shadow-sm'
                                    : 'text-slate-600 hover:bg-slate-100 hover:text-slate-800'
                            }`}
                        >
                            <p className="text-sm font-semibold">{area.label}</p>
                            <p className="text-[11px] text-slate-500">{area.hint}</p>
                        </button>
                    ))}
                </div>
            </section>

            {integrationArea === 'overview' ? (
                <section className="crm-surface overflow-hidden">
                    <header className="crm-panel-header">
                        <div>
                            <h3 className="crm-panel-title">Service Integrations</h3>
                            <p className="crm-panel-subtitle">Live status for SMS, payment, and deferred email channels.</p>
                        </div>
                    </header>
                    <div className="divide-y divide-slate-100">
                        {isLoading ? (
                            <p className="p-4 text-sm text-slate-500">Loading service health...</p>
                        ) : serviceRows.map((service) => (
                            <div key={service.key} className="flex flex-wrap items-center justify-between gap-3 p-4">
                                <div>
                                    <p className="text-sm font-semibold text-slate-900">{service.label}</p>
                                    <p className="text-xs text-slate-500">{service.detail}</p>
                                </div>
                                <span className={`inline-flex items-center rounded-md px-2.5 py-0.5 text-xs font-medium ring-1 ring-inset ${statusChip(service.status)}`}>
                                    {service.status.replaceAll('_', ' ')}
                                </span>
                            </div>
                        ))}
                    </div>
                </section>
            ) : null}

            {integrationArea === 'sms' ? (
                <section className="crm-surface overflow-hidden">
                    <header className="crm-panel-header">
                        <div>
                            <h3 className="crm-panel-title">SMS Provider Routing</h3>
                            <p className="crm-panel-subtitle">Choose an active SMS provider, set fallback behavior, and validate delivery from settings.</p>
                        </div>
                    </header>

                    <div className="grid gap-4 p-4 xl:grid-cols-12">
                        <div className="space-y-4 xl:col-span-7">
                            <section className="rounded-lg border border-slate-200 bg-slate-50 p-3">
                                <h4 className="text-sm font-semibold text-slate-900">Routing Controls</h4>
                                <p className="mt-1 text-xs text-slate-500">These settings define which provider is used first and what happens if dispatch fails.</p>

                            <div className="mt-3 grid gap-3 md:grid-cols-2">
                                <label className="md:col-span-2 flex items-center gap-2 text-sm text-slate-700">
                                    <input
                                        type="checkbox"
                                        checked={Boolean(smsProviderForm.enabled)}
                                        onChange={(event) => setSmsProviderForm((current) => ({ ...current, enabled: event.target.checked }))}
                                        className="h-4 w-4 rounded border-slate-300 text-teal-700 focus:ring-teal-200"
                                    />
                                    Enable SMS dispatch for operational events
                                </label>

                                <div>
                                    <label htmlFor="sms-active-provider" className="mb-1 block text-sm font-medium text-slate-700">Active provider</label>
                                    <select
                                        id="sms-active-provider"
                                        value={smsProviderForm.active_provider}
                                        onChange={(event) => setSmsProviderForm((current) => ({ ...current, active_provider: event.target.value }))}
                                        className="crm-select w-full"
                                    >
                                        <option value="legacy_gateway">Legacy Gateway</option>
                                        <option value="africastalking">Africa&apos;s Talking</option>
                                    </select>
                                </div>

                                <div>
                                    <label htmlFor="sms-fallback-provider" className="mb-1 block text-sm font-medium text-slate-700">Fallback provider</label>
                                    <select
                                        id="sms-fallback-provider"
                                        value={smsProviderForm.fallback_provider}
                                        onChange={(event) => setSmsProviderForm((current) => ({ ...current, fallback_provider: event.target.value }))}
                                        className="crm-select w-full"
                                    >
                                        {fallbackOptions.map((option) => (
                                            <option
                                                key={option.value}
                                                value={option.value}
                                                disabled={option.value !== 'none' && option.value === smsProviderForm.active_provider}
                                            >
                                                {option.label}
                                            </option>
                                        ))}
                                    </select>
                                </div>

                                <div>
                                    <label htmlFor="sms-default-prefix" className="mb-1 block text-sm font-medium text-slate-700">Default phone prefix</label>
                                    <input
                                        id="sms-default-prefix"
                                        value={smsProviderForm.default_prefix}
                                        onChange={(event) => setSmsProviderForm((current) => ({ ...current, default_prefix: event.target.value }))}
                                        className="crm-input"
                                        placeholder="254"
                                    />
                                </div>

                                <div className="md:col-span-2">
                                    <label htmlFor="sms-config-reason" className="mb-1 block text-sm font-medium text-slate-700">Change reason</label>
                                    <textarea
                                        id="sms-config-reason"
                                        rows={2}
                                        value={smsProviderForm.reason}
                                        onChange={(event) => setSmsProviderForm((current) => ({ ...current, reason: event.target.value }))}
                                        className="crm-input"
                                        placeholder="Reason for updating SMS routing"
                                    />
                                </div>
                            </div>
                        </section>

                        <section className="rounded-lg border border-slate-200 bg-white p-3">
                            <h4 className="text-sm font-semibold text-slate-900">Legacy Gateway</h4>
                            <p className="mt-1 text-xs text-slate-500">Existing SMS connector used in current operations.</p>
                            <div className="mt-3 grid gap-3 md:grid-cols-2">
                                <input
                                    value={smsProviderForm.legacy_gateway.gateway_url}
                                    onChange={(event) => updateSmsProviderField('legacy_gateway', 'gateway_url', event.target.value)}
                                    className="crm-input md:col-span-2"
                                    placeholder="Gateway URL"
                                />
                                <input
                                    value={smsProviderForm.legacy_gateway.org_code}
                                    onChange={(event) => updateSmsProviderField('legacy_gateway', 'org_code', event.target.value)}
                                    className="crm-input"
                                    placeholder="Org code"
                                />
                            </div>
                        </section>

                        <section className="rounded-lg border border-slate-200 bg-white p-3">
                            <h4 className="text-sm font-semibold text-slate-900">Africa&apos;s Talking</h4>
                            <p className="mt-1 text-xs text-slate-500">Use this provider for managed delivery with API-key authentication.</p>
                            <div className="mt-3 grid gap-3 md:grid-cols-2">
                                <input
                                    value={smsProviderForm.africastalking.endpoint}
                                    onChange={(event) => updateSmsProviderField('africastalking', 'endpoint', event.target.value)}
                                    className="crm-input md:col-span-2"
                                    placeholder="API endpoint"
                                />
                                <input
                                    value={smsProviderForm.africastalking.username}
                                    onChange={(event) => updateSmsProviderField('africastalking', 'username', event.target.value)}
                                    className="crm-input"
                                    placeholder="Username"
                                />
                                <input
                                    value={smsProviderForm.africastalking.sender_id}
                                    onChange={(event) => updateSmsProviderField('africastalking', 'sender_id', event.target.value)}
                                    className="crm-input"
                                    placeholder="Sender ID (optional)"
                                />
                                <input
                                    type="password"
                                    value={smsProviderForm.africastalking.api_key}
                                    onChange={(event) => updateSmsProviderField('africastalking', 'api_key', event.target.value)}
                                    className="crm-input md:col-span-2"
                                    placeholder="API key (leave blank to keep current key)"
                                />
                            </div>
                            <p className={`mt-2 text-xs ${smsProviderApiKeyConfigured ? 'text-emerald-700' : 'text-amber-700'}`}>
                                {smsProviderApiKeyConfigured
                                    ? 'API key is already stored. Add a new value only when rotating credentials.'
                                    : 'No API key is currently configured for Africa\'s Talking.'}
                            </p>
                        </section>

                        {fallbackInvalid ? (
                            <p className="rounded-md border border-rose-200 bg-rose-50 px-2 py-1 text-xs text-rose-700">
                                Fallback provider must be different from the active provider.
                            </p>
                        ) : null}

                        {smsProviderForm.enabled && !smsReady ? (
                            <p className="rounded-md border border-amber-200 bg-amber-50 px-2 py-1 text-xs text-amber-700">
                                Active provider credentials are incomplete. Complete required fields before saving or sending tests.
                            </p>
                        ) : null}

                        <div className="flex justify-end">
                            <button
                                type="button"
                                onClick={saveSmsProviderConfig}
                                disabled={saveSmsProviderMutation.isPending || !smsProviderForm.reason.trim() || fallbackInvalid || !smsProviderForm.default_prefix.trim() || (smsProviderForm.enabled && !smsReady)}
                                className="crm-btn-primary disabled:cursor-not-allowed disabled:opacity-60"
                            >
                                {saveSmsProviderMutation.isPending ? 'Saving...' : 'Save SMS settings'}
                            </button>
                        </div>
                    </div>

                        <div className="space-y-4 xl:col-span-5">
                            <section className="rounded-lg border border-slate-200 bg-white p-3">
                            <h4 className="text-sm font-semibold text-slate-900">Test Dispatch</h4>
                            <p className="mt-1 text-xs text-slate-500">Send a controlled SMS to verify routing and provider response in real time.</p>
                            <div className="mt-3 space-y-3">
                                <input
                                    value={smsTestForm.phone}
                                    onChange={(event) => setSmsTestForm((current) => ({ ...current, phone: event.target.value }))}
                                    className="crm-input"
                                    placeholder="Phone (example: +254712000000)"
                                />
                                <textarea
                                    rows={4}
                                    value={smsTestForm.message}
                                    onChange={(event) => setSmsTestForm((current) => ({ ...current, message: event.target.value }))}
                                    className="crm-input"
                                    placeholder="Test message content"
                                />
                                <input
                                    value={smsTestForm.reason}
                                    onChange={(event) => setSmsTestForm((current) => ({ ...current, reason: event.target.value }))}
                                    className="crm-input"
                                    placeholder="Reason for test dispatch"
                                />
                            </div>
                            <div className="mt-3 flex justify-end">
                                <button
                                    type="button"
                                    onClick={() => setSmsTestConfirmOpen(true)}
                                    disabled={testSmsProviderMutation.isPending || !smsReady || !smsProviderForm.enabled || !smsTestForm.phone.trim() || !smsTestForm.message.trim() || !smsTestForm.reason.trim()}
                                    className="crm-btn-secondary disabled:cursor-not-allowed disabled:opacity-60"
                                >
                                    {testSmsProviderMutation.isPending ? 'Sending...' : 'Send test SMS'}
                                </button>
                            </div>
                            {!smsProviderForm.enabled ? (
                                <p className="mt-2 text-xs text-amber-700">Enable SMS dispatch before sending a provider test message.</p>
                            ) : null}
                        </section>

                        {latestSmsTestResult ? (
                            <section className="rounded-lg border border-slate-200 bg-slate-50 p-3">
                                <div className="flex flex-wrap items-center justify-between gap-2">
                                    <h4 className="text-sm font-semibold text-slate-900">Latest SMS Test Result</h4>
                                    <span className={`inline-flex items-center rounded-md px-2 py-0.5 text-xs font-medium ring-1 ring-inset ${statusChip(latestSmsTestResult.success ? 'success' : 'failed')}`}>
                                        {latestSmsTestResult.success ? 'success' : 'failed'}
                                    </span>
                                </div>
                                <div className="mt-2 space-y-1 text-xs text-slate-600">
                                    <p><span className="font-semibold text-slate-800">Provider:</span> {smsProviderLabel(latestSmsTestResult.provider)}</p>
                                    <p><span className="font-semibold text-slate-800">Status:</span> {latestSmsTestResult.status || 'unknown'}</p>
                                    <p><span className="font-semibold text-slate-800">Phone:</span> {latestSmsTestResult.phone || '--'}</p>
                                    <p className="break-all"><span className="font-semibold text-slate-800">Response:</span> {latestSmsTestResult.provider_response || 'No provider response message.'}</p>
                                    {latestSmsTestResult.fallback_attempted ? (
                                        <p><span className="font-semibold text-slate-800">Fallback:</span> Attempted from {smsProviderLabel(latestSmsTestResult.fallback_from || smsProviderForm.active_provider)}</p>
                                    ) : null}
                                </div>
                            </section>
                        ) : null}
                        </div>
                    </div>
                </section>
            ) : null}

            {integrationArea === 'push' ? (
                <section className="crm-surface overflow-hidden">
                    <header className="crm-panel-header">
                        <div>
                            <h3 className="crm-panel-title">Push Provider Routing</h3>
                            <p className="crm-panel-subtitle">Configure provider credentials per market and validate notification delivery using a real push test.</p>
                        </div>
                    </header>

                    <div className="grid gap-4 p-4 xl:grid-cols-12">
                        <div className="space-y-4 xl:col-span-7">
                            {!canManagePushProviders ? (
                                <p className="rounded-md border border-amber-200 bg-amber-50 px-3 py-2 text-xs text-amber-800">
                                    Read-only access: only admin and sub-admin roles can update push routing settings.
                                </p>
                            ) : null}

                            <section className="rounded-lg border border-slate-200 bg-slate-50 p-3">
                                <h4 className="text-sm font-semibold text-slate-900">Routing Controls</h4>
                                <p className="mt-1 text-xs text-slate-500">Define the global default provider and per-market provider/fallback behavior.</p>

                                <div className="mt-3 grid gap-3 md:grid-cols-2">
                                    <label className="md:col-span-2 flex items-center gap-2 text-sm text-slate-700">
                                        <input
                                            type="checkbox"
                                            checked={Boolean(pushProviderForm.enabled)}
                                            onChange={(event) => updatePushProviderField('enabled', event.target.checked)}
                                            disabled={!canManagePushProviders}
                                            className="h-4 w-4 rounded border-slate-300 text-teal-700 focus:ring-teal-200"
                                        />
                                        Enable push dispatch for campaigns
                                    </label>

                                    <div>
                                        <label className="mb-1 block text-sm font-medium text-slate-700">Default provider</label>
                                        <select
                                            value={pushProviderForm.default_provider}
                                            onChange={(event) => updatePushProviderField('default_provider', event.target.value)}
                                            disabled={!canManagePushProviders}
                                            className="crm-select w-full"
                                        >
                                            {pushProviderOptions.map((option) => (
                                                <option key={option.value} value={option.value}>{option.label}</option>
                                            ))}
                                        </select>
                                    </div>

                                    <div>
                                        <label className="mb-1 block text-sm font-medium text-slate-700">Market</label>
                                        <select
                                            value={pushPlatformId || ''}
                                            onChange={(event) => setPushPlatformId(event.target.value)}
                                            className="crm-select w-full"
                                        >
                                            {(platformRows || []).map((platform) => (
                                                <option key={platform.platform_id} value={String(platform.platform_id)}>
                                                    {platform.platform_name} ({platform.country || '—'})
                                                </option>
                                            ))}
                                        </select>
                                    </div>

                                    <div>
                                        <label className="mb-1 block text-sm font-medium text-slate-700">Active provider</label>
                                        <select
                                            value={selectedPushConfig?.active_provider || pushProviderForm.default_provider}
                                            onChange={(event) => updatePushPlatformField(pushPlatformId, 'active_provider', event.target.value)}
                                            disabled={!canManagePushProviders || !pushPlatformId}
                                            className="crm-select w-full"
                                        >
                                            {pushProviderOptions.map((option) => (
                                                <option key={option.value} value={option.value}>{option.label}</option>
                                            ))}
                                        </select>
                                    </div>

                                    <div>
                                        <label className="mb-1 block text-sm font-medium text-slate-700">Fallback provider</label>
                                        <select
                                            value={selectedPushConfig?.fallback_provider || 'none'}
                                            onChange={(event) => updatePushPlatformField(pushPlatformId, 'fallback_provider', event.target.value)}
                                            disabled={!canManagePushProviders || !pushPlatformId}
                                            className="crm-select w-full"
                                        >
                                            {pushFallbackOptions.map((option) => (
                                                <option
                                                    key={option.value}
                                                    value={option.value}
                                                    disabled={option.value !== 'none' && option.value === (selectedPushConfig?.active_provider || '')}
                                                >
                                                    {option.label}
                                                </option>
                                            ))}
                                        </select>
                                    </div>

                                    <div className="md:col-span-2">
                                        <label className="mb-1 block text-sm font-medium text-slate-700">Change reason</label>
                                        <textarea
                                            rows={2}
                                            value={pushProviderForm.reason}
                                            onChange={(event) => updatePushProviderField('reason', event.target.value)}
                                            disabled={!canManagePushProviders}
                                            className="crm-input"
                                            placeholder="Reason for updating push routing"
                                        />
                                    </div>
                                </div>
                            </section>

                            <section className="rounded-lg border border-slate-200 bg-white p-3">
                                <h4 className="text-sm font-semibold text-slate-900">WebPushr Credentials</h4>
                                <p className="mt-1 text-xs text-slate-500">Required when WebPushr is selected as active or fallback provider.</p>
                                <div className="mt-3 grid gap-3 md:grid-cols-2">
                                    <input
                                        type="password"
                                        value={selectedPushConfig?.webpushr?.api_key || ''}
                                        onChange={(event) => updatePushProviderCredentialField(pushPlatformId, 'webpushr', 'api_key', event.target.value)}
                                        disabled={!canManagePushProviders || !pushPlatformId}
                                        className="crm-input"
                                        placeholder="API key (leave blank to keep current)"
                                    />
                                    <input
                                        type="password"
                                        value={selectedPushConfig?.webpushr?.auth_token || ''}
                                        onChange={(event) => updatePushProviderCredentialField(pushPlatformId, 'webpushr', 'auth_token', event.target.value)}
                                        disabled={!canManagePushProviders || !pushPlatformId}
                                        className="crm-input"
                                        placeholder="Auth token (leave blank to keep current)"
                                    />
                                </div>
                                <p className="mt-2 text-xs text-slate-500">
                                    Stored keys:
                                    {' '}
                                    {selectedPushConfig?.webpushr?.api_key_configured ? 'API key configured' : 'API key missing'}
                                    {' • '}
                                    {selectedPushConfig?.webpushr?.auth_token_configured ? 'Auth token configured' : 'Auth token missing'}
                                </p>
                            </section>

                            <section className="rounded-lg border border-slate-200 bg-white p-3">
                                <h4 className="text-sm font-semibold text-slate-900">WonderPush Credentials</h4>
                                <div className="mt-3 grid gap-3 md:grid-cols-2">
                                    <input
                                        type="password"
                                        value={selectedPushConfig?.wonderpush?.access_token || ''}
                                        onChange={(event) => updatePushProviderCredentialField(pushPlatformId, 'wonderpush', 'access_token', event.target.value)}
                                        disabled={!canManagePushProviders || !pushPlatformId}
                                        className="crm-input"
                                        placeholder="Access token (leave blank to keep current)"
                                    />
                                    <input
                                        value={selectedPushConfig?.wonderpush?.project_id || ''}
                                        onChange={(event) => updatePushProviderCredentialField(pushPlatformId, 'wonderpush', 'project_id', event.target.value)}
                                        disabled={!canManagePushProviders || !pushPlatformId}
                                        className="crm-input"
                                        placeholder="Project ID"
                                    />
                                </div>
                                <p className="mt-2 text-xs text-slate-500">
                                    Stored token:
                                    {' '}
                                    {selectedPushConfig?.wonderpush?.access_token_configured ? 'configured' : 'missing'}
                                </p>
                            </section>

                            <section className="rounded-lg border border-slate-200 bg-white p-3">
                                <h4 className="text-sm font-semibold text-slate-900">iZooto Credentials</h4>
                                <div className="mt-3">
                                    <input
                                        type="password"
                                        value={selectedPushConfig?.izooto?.api_token || ''}
                                        onChange={(event) => updatePushProviderCredentialField(pushPlatformId, 'izooto', 'api_token', event.target.value)}
                                        disabled={!canManagePushProviders || !pushPlatformId}
                                        className="crm-input"
                                        placeholder="API token (leave blank to keep current)"
                                    />
                                </div>
                                <p className="mt-2 text-xs text-slate-500">
                                    Stored token:
                                    {' '}
                                    {selectedPushConfig?.izooto?.api_token_configured ? 'configured' : 'missing'}
                                </p>
                            </section>

                            {pushFallbackInvalid ? (
                                <p className="rounded-md border border-rose-200 bg-rose-50 px-2 py-1 text-xs text-rose-700">
                                    Fallback provider must be different from the active provider.
                                </p>
                            ) : null}

                            <div className="flex justify-end">
                                <button
                                    type="button"
                                    onClick={savePushProviderConfig}
                                    disabled={!canManagePushProviders || savePushProviderMutation.isPending || !pushProviderForm.reason.trim() || pushFallbackInvalid}
                                    className="crm-btn-primary disabled:cursor-not-allowed disabled:opacity-60"
                                >
                                    {savePushProviderMutation.isPending ? 'Saving...' : 'Save push settings'}
                                </button>
                            </div>
                        </div>

                        <div className="space-y-4 xl:col-span-5">
                            <section className="rounded-lg border border-slate-200 bg-white p-3">
                                <h4 className="text-sm font-semibold text-slate-900">Test Notification</h4>
                                <p className="mt-1 text-xs text-slate-500">Sends a real push notification to all subscribers for the selected market.</p>
                                <div className="mt-3 space-y-3">
                                    <input
                                        value={pushTestForm.title}
                                        onChange={(event) => setPushTestForm((current) => ({ ...current, title: event.target.value }))}
                                        className="crm-input"
                                        placeholder="Notification title"
                                    />
                                    <textarea
                                        rows={3}
                                        value={pushTestForm.message}
                                        onChange={(event) => setPushTestForm((current) => ({ ...current, message: event.target.value }))}
                                        className="crm-input"
                                        placeholder="Notification message"
                                    />
                                    <input
                                        value={pushTestForm.target_url}
                                        onChange={(event) => setPushTestForm((current) => ({ ...current, target_url: event.target.value }))}
                                        className="crm-input"
                                        placeholder="Target URL"
                                    />
                                    <input
                                        value={pushTestForm.icon_url}
                                        onChange={(event) => setPushTestForm((current) => ({ ...current, icon_url: event.target.value }))}
                                        className="crm-input"
                                        placeholder="Icon URL (optional)"
                                    />
                                    <input
                                        value={pushTestForm.reason}
                                        onChange={(event) => setPushTestForm((current) => ({ ...current, reason: event.target.value }))}
                                        className="crm-input"
                                        placeholder="Reason for push test"
                                    />
                                </div>

                                <div className="mt-3 flex justify-end">
                                    <button
                                        type="button"
                                        onClick={() => setPushTestConfirmOpen(true)}
                                        disabled={
                                            testPushProviderMutation.isPending
                                            || !canManagePushProviders
                                            || !pushProviderForm.enabled
                                            || !pushPlatformId
                                            || !selectedPushReady
                                            || !pushTestForm.title.trim()
                                            || !pushTestForm.message.trim()
                                            || !pushTestForm.target_url.trim()
                                            || !pushTestForm.reason.trim()
                                        }
                                        className="crm-btn-secondary disabled:cursor-not-allowed disabled:opacity-60"
                                    >
                                        {testPushProviderMutation.isPending ? 'Sending...' : 'Send test push'}
                                    </button>
                                </div>

                                {!pushProviderForm.enabled ? (
                                    <p className="mt-2 text-xs text-amber-700">Enable push dispatch before sending a test notification.</p>
                                ) : null}
                                {pushProviderForm.enabled && !selectedPushReady ? (
                                    <p className="mt-2 text-xs text-amber-700">Selected provider credentials are incomplete for this market.</p>
                                ) : null}
                            </section>

                            {latestPushTestResult ? (
                                <section className="rounded-lg border border-slate-200 bg-slate-50 p-3">
                                    <div className="flex flex-wrap items-center justify-between gap-2">
                                        <h4 className="text-sm font-semibold text-slate-900">Latest Push Test Result</h4>
                                        <span className={`inline-flex items-center rounded-md px-2 py-0.5 text-xs font-medium ring-1 ring-inset ${statusChip(latestPushTestResult.success ? 'success' : 'failed')}`}>
                                            {latestPushTestResult.success ? 'success' : 'failed'}
                                        </span>
                                    </div>
                                    <div className="mt-2 space-y-1 text-xs text-slate-600">
                                        <p><span className="font-semibold text-slate-800">Provider:</span> {pushProviderLabel(latestPushTestResult.provider)}</p>
                                        <p><span className="font-semibold text-slate-800">Notification ID:</span> {latestPushTestResult.provider_notification_id || 'n/a'}</p>
                                        <p className="break-all"><span className="font-semibold text-slate-800">Response:</span> {JSON.stringify(latestPushTestResult.provider_response || {})}</p>
                                        {latestPushTestResult.fallback_attempted ? (
                                            <p><span className="font-semibold text-slate-800">Fallback:</span> Attempted</p>
                                        ) : null}
                                    </div>
                                </section>
                            ) : null}

                            <section className="rounded-lg border border-slate-200 bg-slate-50 p-3">
                                <h4 className="text-sm font-semibold text-slate-900">Selected Market</h4>
                                <p className="mt-1 text-xs text-slate-600">
                                    {selectedPushPlatform
                                        ? `${selectedPushPlatform.platform_name} (${selectedPushPlatform.country || '—'})`
                                        : 'No market selected.'}
                                </p>
                                <p className="mt-1 text-xs text-slate-600">Active provider: {pushProviderLabel(selectedPushProvider)}</p>
                                <p className="mt-1 text-xs text-slate-600">Fallback: {selectedPushConfig?.fallback_provider === 'none' ? 'No fallback' : pushProviderLabel(selectedPushConfig?.fallback_provider)}</p>
                            </section>
                        </div>
                    </div>
                </section>
            ) : null}

            {integrationArea === 'wallet' ? (
                <section className="crm-surface overflow-hidden">
                    <header className="crm-panel-header">
                        <div>
                            <h3 className="crm-panel-title">Wallet Configuration</h3>
                            <p className="crm-panel-subtitle">Manage wallet mode, market-level limits, provider credentials, and testing without leaving settings.</p>
                        </div>
                        <button
                            type="button"
                            onClick={() => queryClient.invalidateQueries({ queryKey: ['settings-integrations'] })}
                            className="crm-btn-secondary px-3 py-2"
                        >
                            Refresh
                        </button>
                    </header>

                    <div className="grid gap-4 p-4 xl:grid-cols-12">
                        <div className="space-y-4 xl:col-span-7">
                            {!canManageWalletSystem ? (
                                <p className="rounded-md border border-amber-200 bg-amber-50 px-3 py-2 text-xs text-amber-800">
                                    Global wallet settings are read-only for this role. Only admin can change wallet mode, billing domains, and SMTP.
                                </p>
                            ) : null}

                            <section className="rounded-lg border border-slate-200 bg-slate-50 p-3">
                                <div className="flex flex-wrap items-center justify-between gap-2">
                                    <div>
                                        <h4 className="text-sm font-semibold text-slate-900">System Mode</h4>
                                        <p className="mt-1 text-xs text-slate-500">Choose whether wallet flows are disabled, sandboxed, or fully live across markets.</p>
                                    </div>
                                    <span className={`inline-flex items-center rounded-md px-2 py-0.5 text-xs font-medium ring-1 ring-inset ${statusChip(walletSystemConfig?.mode === 'production' ? 'connected' : walletSystemConfig?.mode === 'sandbox' ? 'configured_disabled' : 'pending')}`}>
                                        {(walletSystemConfig?.mode || 'disabled').replaceAll('_', ' ')}
                                    </span>
                                </div>

                                <fieldset disabled={walletSystemReadOnly || saveWalletSystemMutation.isPending} className={walletSystemReadOnly ? 'opacity-70' : ''}>
                                    <div className="mt-3 grid gap-3 md:grid-cols-2">
                                        <div>
                                            <label className="mb-1 block text-sm font-medium text-slate-700">Mode</label>
                                            <select
                                                value={walletSystemForm.mode}
                                                onChange={(event) => updateWalletSystemField('mode', event.target.value)}
                                                className="crm-select w-full"
                                            >
                                                {walletModeOptions.map((mode) => (
                                                    <option key={mode} value={mode}>{mode.replaceAll('_', ' ')}</option>
                                                ))}
                                            </select>
                                        </div>

                                        <div>
                                            <label className="mb-1 block text-sm font-medium text-slate-700">Default currency</label>
                                            <input
                                                value={walletSystemForm.default_currency}
                                                onChange={(event) => updateWalletSystemField('default_currency', event.target.value.toUpperCase())}
                                                className="crm-input"
                                                placeholder="KES"
                                            />
                                        </div>

                                        <input
                                            value={walletSystemForm.max_single_topup_default}
                                            onChange={(event) => updateWalletSystemField('max_single_topup_default', event.target.value)}
                                            className="crm-input"
                                            placeholder="Default max single top-up"
                                        />
                                        <input
                                            value={walletSystemForm.max_wallet_balance_default}
                                            onChange={(event) => updateWalletSystemField('max_wallet_balance_default', event.target.value)}
                                            className="crm-input"
                                            placeholder="Default max wallet balance"
                                        />

                                        <input
                                            type="number"
                                            min="1"
                                            max="30"
                                            value={walletSystemForm.redirect_delay_seconds}
                                            onChange={(event) => updateWalletSystemField('redirect_delay_seconds', Number(event.target.value || 3))}
                                            className="crm-input"
                                            placeholder="Redirect delay"
                                        />
                                        <input
                                            type="number"
                                            min="1"
                                            max="120"
                                            value={walletSystemForm.topup_poll_interval_seconds}
                                            onChange={(event) => updateWalletSystemField('topup_poll_interval_seconds', Number(event.target.value || 10))}
                                            className="crm-input"
                                            placeholder="Top-up poll interval"
                                        />

                                        <input
                                            type="number"
                                            min="1"
                                            max="120"
                                            value={walletSystemForm.wallet_refresh_rate_limit_seconds}
                                            onChange={(event) => updateWalletSystemField('wallet_refresh_rate_limit_seconds', Number(event.target.value || 15))}
                                            className="crm-input"
                                            placeholder="Refresh rate limit"
                                        />
                                        <input
                                            type="number"
                                            min="1"
                                            max="120"
                                            value={walletSystemForm.wallet_refresh_timeout_seconds}
                                            onChange={(event) => updateWalletSystemField('wallet_refresh_timeout_seconds', Number(event.target.value || 15))}
                                            className="crm-input"
                                            placeholder="Refresh timeout"
                                        />

                                        <div className="md:col-span-2">
                                            <label className="mb-1 block text-sm font-medium text-slate-700">Change reason</label>
                                            <textarea
                                                rows={2}
                                                value={walletSystemForm.reason}
                                                onChange={(event) => updateWalletSystemField('reason', event.target.value)}
                                                className="crm-input"
                                                placeholder="Reason for wallet system change"
                                            />
                                        </div>
                                    </div>
                                </fieldset>

                                <div className="mt-3 flex justify-end">
                                    <button
                                        type="button"
                                        onClick={saveWalletSystemConfig}
                                        disabled={
                                            walletSystemReadOnly
                                            || saveWalletSystemMutation.isPending
                                            || !walletSystemForm.default_currency.trim()
                                            || !walletSystemForm.billing_branding.sandbox.business_name.trim()
                                            || !walletSystemForm.billing_branding.sandbox.description.trim()
                                            || !walletSystemForm.billing_branding.production.business_name.trim()
                                            || !walletSystemForm.billing_branding.production.description.trim()
                                            || !walletSystemForm.reason.trim()
                                        }
                                        className="crm-btn-primary disabled:cursor-not-allowed disabled:opacity-60"
                                    >
                                        {saveWalletSystemMutation.isPending ? 'Saving...' : 'Save wallet system'}
                                    </button>
                                </div>
                            </section>

                            <section className="rounded-lg border border-slate-200 bg-white p-3">
                                <h4 className="text-sm font-semibold text-slate-900">Billing Domains and Branding</h4>
                                <p className="mt-1 text-xs text-slate-500">Each environment needs its own billing URL and copy so payment pages and callbacks stay aligned.</p>

                                <fieldset disabled={walletSystemReadOnly || saveWalletSystemMutation.isPending} className={walletSystemReadOnly ? 'opacity-70' : ''}>
                                    <div className="mt-3 grid gap-3 xl:grid-cols-2">
                                        {walletEnvironmentOptions.map((environment) => (
                                            <div key={`wallet-branding-${environment}`} className="rounded-md border border-slate-200 bg-slate-50 p-3">
                                                <p className="text-xs font-semibold uppercase tracking-[0.12em] text-slate-500">{environment}</p>
                                                <div className="mt-2 space-y-3">
                                                    <input
                                                        value={walletSystemForm.billing_domains[environment]}
                                                        onChange={(event) => updateWalletSystemDomain(environment, event.target.value)}
                                                        className="crm-input"
                                                        placeholder="https://billing.example.com"
                                                    />
                                                    <input
                                                        value={walletSystemForm.billing_branding[environment].business_name}
                                                        onChange={(event) => updateWalletSystemBranding(environment, 'business_name', event.target.value)}
                                                        className="crm-input"
                                                        placeholder="Business name"
                                                    />
                                                    <textarea
                                                        rows={2}
                                                        value={walletSystemForm.billing_branding[environment].description}
                                                        onChange={(event) => updateWalletSystemBranding(environment, 'description', event.target.value)}
                                                        className="crm-input"
                                                        placeholder="Billing description"
                                                    />
                                                </div>
                                            </div>
                                        ))}
                                    </div>
                                </fieldset>
                            </section>

                            <section className="rounded-lg border border-slate-200 bg-white p-3">
                                <div className="flex flex-wrap items-center justify-between gap-2">
                                    <div>
                                        <h4 className="text-sm font-semibold text-slate-900">Billing Domain Setup Guide</h4>
                                        <p className="mt-1 text-xs text-slate-500">Step-by-step launch notes for DNS, SSL, reverse proxy, and provider callbacks for each billing environment.</p>
                                    </div>
                                    <select
                                        value={walletGuideEnvironment}
                                        onChange={(event) => setWalletGuideEnvironment(event.target.value)}
                                        className="crm-select w-full max-w-[220px]"
                                    >
                                        {walletEnvironmentOptions.map((environment) => (
                                            <option key={`wallet-guide-${environment}`} value={environment}>{environment}</option>
                                        ))}
                                    </select>
                                </div>

                                {!walletGuideDomain ? (
                                    <p className="mt-3 rounded-md border border-amber-200 bg-amber-50 px-3 py-2 text-xs text-amber-800">
                                        Enter a {walletGuideEnvironment} billing domain above before copying callback URLs or running the app reachability check.
                                    </p>
                                ) : null}

                                <div className="mt-3 grid gap-2 md:grid-cols-3">
                                    <div className="rounded-md border border-slate-200 bg-slate-50 px-3 py-2">
                                        <div className="flex items-center justify-between gap-2">
                                            <p className="text-[11px] font-semibold uppercase tracking-[0.1em] text-slate-500">DNS</p>
                                            <span className={`inline-flex items-center rounded-md px-2 py-0.5 text-[11px] font-medium ring-1 ring-inset ${statusChip(walletGuideDomainResult?.status || 'pending')}`}>
                                                {(walletGuideDomainResult?.status || 'pending').replaceAll('_', ' ')}
                                            </span>
                                        </div>
                                        <p className="mt-2 break-all text-xs text-slate-600">{walletGuideDomain || 'Billing domain not configured yet.'}</p>
                                    </div>
                                    <div className="rounded-md border border-slate-200 bg-slate-50 px-3 py-2">
                                        <div className="flex items-center justify-between gap-2">
                                            <p className="text-[11px] font-semibold uppercase tracking-[0.1em] text-slate-500">SSL</p>
                                            <span className={`inline-flex items-center rounded-md px-2 py-0.5 text-[11px] font-medium ring-1 ring-inset ${statusChip(walletGuideSslResult ? (walletGuideSslResult.ok ? 'success' : 'failed') : 'pending')}`}>
                                                {walletGuideSslResult ? (walletGuideSslResult.ok ? 'success' : 'failed') : 'pending'}
                                            </span>
                                        </div>
                                        <p className="mt-2 text-xs text-slate-600">{walletGuideSslResult?.message || 'Run the SSL test after the certificate is issued.'}</p>
                                    </div>
                                    <div className="rounded-md border border-slate-200 bg-slate-50 px-3 py-2">
                                        <div className="flex items-center justify-between gap-2">
                                            <p className="text-[11px] font-semibold uppercase tracking-[0.1em] text-slate-500">App</p>
                                            <span className={`inline-flex items-center rounded-md px-2 py-0.5 text-[11px] font-medium ring-1 ring-inset ${statusChip(walletGuideAppResult ? (walletGuideAppResult.ok ? 'success' : 'failed') : 'pending')}`}>
                                                {walletGuideAppResult ? (walletGuideAppResult.ok ? 'success' : 'failed') : 'pending'}
                                            </span>
                                        </div>
                                        <p className="mt-2 break-all text-xs text-slate-600">{walletGuideAppResult?.url || walletGuideHealthUrl}</p>
                                    </div>
                                </div>

                                <div className="mt-3 space-y-3">
                                    <div className="rounded-md border border-slate-200 bg-slate-50 p-3">
                                        <div className="flex flex-wrap items-center justify-between gap-2">
                                            <p className="text-sm font-semibold text-slate-900">1. DNS</p>
                                            <button
                                                type="button"
                                                onClick={() => copyWalletGuideValue('Billing domain', walletGuideDomain)}
                                                disabled={!walletGuideDomain}
                                                className="text-xs font-semibold text-teal-700 hover:text-teal-900 disabled:cursor-not-allowed disabled:opacity-50"
                                            >
                                                Copy domain
                                            </button>
                                        </div>
                                        <p className="mt-2 text-xs text-slate-600">Create an A or CNAME record for the billing hostname and point it to the CRM host that serves browser flows and `/api/billing/*` endpoints.</p>
                                        <code className="mt-2 block break-all rounded bg-slate-900/90 px-2 py-1.5 text-[11px] text-slate-100">{walletGuideDomain || 'https://billing.example.com'}</code>
                                    </div>

                                    <div className="rounded-md border border-slate-200 bg-slate-50 p-3">
                                        <p className="text-sm font-semibold text-slate-900">2. Web server / reverse proxy</p>
                                        <p className="mt-2 text-xs text-slate-600">Route the billing hostname to the CRM app so both browser pages (`/billing/*`) and API callbacks (`/api/billing/*`) resolve on the same host.</p>
                                        <div className="mt-3 grid gap-3 xl:grid-cols-2">
                                            <div>
                                                <p className="text-[11px] font-semibold uppercase tracking-[0.1em] text-slate-500">Nginx example</p>
                                                <pre className="mt-2 overflow-x-auto rounded bg-slate-950 px-3 py-2 text-[11px] leading-5 text-slate-100"><code>{walletGuideNginxSnippet}</code></pre>
                                            </div>
                                            <div>
                                                <p className="text-[11px] font-semibold uppercase tracking-[0.1em] text-slate-500">Apache example</p>
                                                <pre className="mt-2 overflow-x-auto rounded bg-slate-950 px-3 py-2 text-[11px] leading-5 text-slate-100"><code>{walletGuideApacheSnippet}</code></pre>
                                            </div>
                                        </div>
                                    </div>

                                    <div className="rounded-md border border-slate-200 bg-slate-50 p-3">
                                        <p className="text-sm font-semibold text-slate-900">3. SSL</p>
                                        <p className="mt-2 text-xs text-slate-600">Issue a certificate for the billing hostname, force HTTPS, then run both the SSL and app tests from the panel on the right.</p>
                                        <div className="mt-2 flex flex-wrap gap-2">
                                            <button
                                                type="button"
                                                onClick={() => copyWalletGuideValue('Billing health URL', walletGuideHealthUrl)}
                                                disabled={!walletGuideDomain}
                                                className="crm-btn-secondary px-3 py-1.5 text-xs disabled:cursor-not-allowed disabled:opacity-60"
                                            >
                                                Copy health URL
                                            </button>
                                            <button
                                                type="button"
                                                onClick={() => copyWalletGuideValue('Completion URL', walletGuideCompleteUrl)}
                                                disabled={!walletGuideDomain}
                                                className="crm-btn-secondary px-3 py-1.5 text-xs disabled:cursor-not-allowed disabled:opacity-60"
                                            >
                                                Copy completion URL
                                            </button>
                                        </div>
                                    </div>

                                    <div className="rounded-md border border-slate-200 bg-slate-50 p-3">
                                        <p className="text-sm font-semibold text-slate-900">4. Provider account registration</p>
                                        <p className="mt-2 text-xs text-slate-600">Register the billing callbacks with each provider, then store the market-specific credentials below in the provider credentials section.</p>
                                        <div className="mt-3 space-y-2">
                                            <div className="rounded-md border border-slate-200 bg-white p-2">
                                                <div className="flex flex-wrap items-center justify-between gap-2">
                                                    <p className="text-xs font-semibold text-slate-800">Paystack browser callback</p>
                                                    <button
                                                        type="button"
                                                        onClick={() => copyWalletGuideValue('Paystack callback URL', walletGuideCompleteUrl)}
                                                        disabled={!walletGuideDomain}
                                                        className="text-xs font-semibold text-teal-700 hover:text-teal-900 disabled:cursor-not-allowed disabled:opacity-50"
                                                    >
                                                        Copy
                                                    </button>
                                                </div>
                                                <code className="mt-2 block break-all rounded bg-slate-900/90 px-2 py-1.5 text-[11px] text-slate-100">{walletGuideCompleteUrl}</code>
                                            </div>
                                            <div className="rounded-md border border-slate-200 bg-white p-2">
                                                <div className="flex flex-wrap items-center justify-between gap-2">
                                                    <p className="text-xs font-semibold text-slate-800">Pesapal return/IPN setup</p>
                                                    <button
                                                        type="button"
                                                        onClick={() => copyWalletGuideValue('Pesapal callback URL', walletGuideCompleteUrl)}
                                                        disabled={!walletGuideDomain}
                                                        className="text-xs font-semibold text-teal-700 hover:text-teal-900 disabled:cursor-not-allowed disabled:opacity-50"
                                                    >
                                                        Copy
                                                    </button>
                                                </div>
                                                <code className="mt-2 block break-all rounded bg-slate-900/90 px-2 py-1.5 text-[11px] text-slate-100">{walletGuideCompleteUrl}</code>
                                                <p className="mt-2 text-[11px] text-slate-500">Current selected-market IPN ID: {walletProvidersForm.pesapal?.[walletGuideEnvironment]?.ipn_id || 'not configured'}</p>
                                            </div>
                                            <div className="rounded-md border border-slate-200 bg-white p-2">
                                                <div className="flex flex-wrap items-center justify-between gap-2">
                                                    <p className="text-xs font-semibold text-slate-800">M-Pesa callback</p>
                                                    <button
                                                        type="button"
                                                        onClick={() => copyWalletGuideValue('M-Pesa callback URL', walletGuideMpesaCallbackUrl)}
                                                        disabled={!walletGuideDomain}
                                                        className="text-xs font-semibold text-teal-700 hover:text-teal-900 disabled:cursor-not-allowed disabled:opacity-50"
                                                    >
                                                        Copy
                                                    </button>
                                                </div>
                                                <code className="mt-2 block break-all rounded bg-slate-900/90 px-2 py-1.5 text-[11px] text-slate-100">{walletGuideMpesaCallbackUrl}</code>
                                                <p className="mt-2 text-[11px] text-slate-500">
                                                    Transport: {walletProvidersForm.mpesa_stk?.[walletGuideEnvironment]?.transport || 'django_proxy'}
                                                    {' • '}
                                                    Upstream: {walletProvidersForm.mpesa_stk?.[walletGuideEnvironment]?.payment_service_base_url || 'not configured'}
                                                </p>
                                            </div>
                                            <div className="rounded-md border border-slate-200 bg-white p-2">
                                                <div className="flex flex-wrap items-center justify-between gap-2">
                                                    <p className="text-xs font-semibold text-slate-800">Generic webhook/API host</p>
                                                    <button
                                                        type="button"
                                                        onClick={() => copyWalletGuideValue('Billing API host', walletGuidePaystackWebhookUrl)}
                                                        disabled={!walletGuideDomain}
                                                        className="text-xs font-semibold text-teal-700 hover:text-teal-900 disabled:cursor-not-allowed disabled:opacity-50"
                                                    >
                                                        Copy Paystack URL
                                                    </button>
                                                </div>
                                                <p className="mt-2 break-all text-[11px] text-slate-600">Paystack: {walletGuidePaystackWebhookUrl}</p>
                                                <p className="mt-1 break-all text-[11px] text-slate-600">Pesapal: {walletGuidePesapalWebhookUrl}</p>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </section>

                            <section className="rounded-lg border border-slate-200 bg-white p-3">
                                <h4 className="text-sm font-semibold text-slate-900">Wallet SMTP</h4>
                                <p className="mt-1 text-xs text-slate-500">Used for wallet billing test messages and recovery notifications where SMTP delivery is enabled.</p>

                                <fieldset disabled={walletSystemReadOnly || saveWalletSystemMutation.isPending} className={walletSystemReadOnly ? 'opacity-70' : ''}>
                                    <div className="mt-3 grid gap-3 md:grid-cols-2">
                                        <label className="md:col-span-2 flex items-center gap-2 text-sm text-slate-700">
                                            <input
                                                type="checkbox"
                                                checked={Boolean(walletSystemForm.smtp.enabled)}
                                                onChange={(event) => updateWalletSystemSmtp('enabled', event.target.checked)}
                                                className="h-4 w-4 rounded border-slate-300 text-teal-700 focus:ring-teal-200"
                                            />
                                            Enable wallet SMTP delivery
                                        </label>

                                        <input
                                            value={walletSystemForm.smtp.host}
                                            onChange={(event) => updateWalletSystemSmtp('host', event.target.value)}
                                            className="crm-input"
                                            placeholder="SMTP host"
                                        />
                                        <input
                                            type="number"
                                            min="1"
                                            max="65535"
                                            value={walletSystemForm.smtp.port}
                                            onChange={(event) => updateWalletSystemSmtp('port', Number(event.target.value || 587))}
                                            className="crm-input"
                                            placeholder="Port"
                                        />

                                        <input
                                            value={walletSystemForm.smtp.username}
                                            onChange={(event) => updateWalletSystemSmtp('username', event.target.value)}
                                            className="crm-input"
                                            placeholder="SMTP username"
                                        />
                                        <input
                                            type="password"
                                            value={walletSystemForm.smtp.password}
                                            onChange={(event) => updateWalletSystemSmtp('password', event.target.value)}
                                            className="crm-input"
                                            placeholder="SMTP password (leave blank to keep current)"
                                        />

                                        <select
                                            value={walletSystemForm.smtp.encryption}
                                            onChange={(event) => updateWalletSystemSmtp('encryption', event.target.value)}
                                            className="crm-select"
                                        >
                                            <option value="tls">TLS</option>
                                            <option value="ssl">SSL</option>
                                            <option value="">None</option>
                                        </select>
                                        <input
                                            value={walletSystemForm.smtp.from_address}
                                            onChange={(event) => updateWalletSystemSmtp('from_address', event.target.value)}
                                            className="crm-input"
                                            placeholder="From email"
                                        />

                                        <input
                                            value={walletSystemForm.smtp.from_name}
                                            onChange={(event) => updateWalletSystemSmtp('from_name', event.target.value)}
                                            className="crm-input md:col-span-2"
                                            placeholder="From name"
                                        />
                                    </div>
                                </fieldset>

                                <p className={`mt-2 text-xs ${walletSystemForm.smtp.password_configured ? 'text-emerald-700' : 'text-amber-700'}`}>
                                    {walletSystemForm.smtp.password_configured
                                        ? 'SMTP password is already stored. Leave the password field blank to keep it.'
                                        : 'No SMTP password is currently stored.'}
                                </p>
                            </section>

                            <section className="rounded-lg border border-slate-200 bg-white p-3">
                                <div className="flex flex-wrap items-center justify-between gap-2">
                                    <div>
                                        <h4 className="text-sm font-semibold text-slate-900">Wallet PIN</h4>
                                        <p className="mt-1 text-xs text-slate-500">Required for manual wallet top-ups and balance adjustments from CRM client profiles.</p>
                                    </div>
                                    <span className={`inline-flex items-center rounded-md px-2 py-0.5 text-xs font-medium ring-1 ring-inset ${statusChip(walletSystemForm.pin_set ? 'success' : 'pending')}`}>
                                        {walletSystemForm.pin_set ? 'configured' : 'not set'}
                                    </span>
                                </div>

                                {!canManageWalletSystem ? (
                                    <p className="mt-3 rounded-md border border-amber-200 bg-amber-50 px-3 py-2 text-xs text-amber-800">
                                        Only admin can rotate the wallet PIN. Sales and sub-admin users will use the configured PIN on client wallet actions.
                                    </p>
                                ) : null}

                                <fieldset disabled={walletSystemReadOnly || updateWalletPinMutation.isPending} className={`mt-3 ${walletSystemReadOnly ? 'opacity-70' : ''}`}>
                                    <div className="grid gap-3 md:grid-cols-2">
                                        <div className="rounded-md border border-slate-200 bg-slate-50 px-3 py-2 md:col-span-2">
                                            <p className="text-[11px] font-semibold uppercase tracking-[0.1em] text-slate-500">Last rotated</p>
                                            <p className="mt-1 text-sm font-semibold text-slate-900">{formatDateTime(walletSystemForm.pin_last_updated_at)}</p>
                                        </div>

                                        <input
                                            type="password"
                                            inputMode="numeric"
                                            maxLength={6}
                                            value={walletPinForm.pin}
                                            onChange={(event) => setWalletPinForm((current) => ({ ...current, pin: event.target.value.replace(/\D/g, '').slice(0, 6) }))}
                                            className="crm-input"
                                            placeholder="New wallet PIN"
                                        />
                                        <input
                                            type="password"
                                            inputMode="numeric"
                                            maxLength={6}
                                            value={walletPinForm.pin_confirmation}
                                            onChange={(event) => setWalletPinForm((current) => ({ ...current, pin_confirmation: event.target.value.replace(/\D/g, '').slice(0, 6) }))}
                                            className="crm-input"
                                            placeholder="Confirm wallet PIN"
                                        />

                                        <textarea
                                            rows={2}
                                            value={walletPinForm.reason}
                                            onChange={(event) => setWalletPinForm((current) => ({ ...current, reason: event.target.value }))}
                                            className="crm-input md:col-span-2"
                                            placeholder="Reason for wallet PIN rotation"
                                        />
                                    </div>
                                </fieldset>

                                <div className="mt-3 flex justify-end">
                                    <button
                                        type="button"
                                        onClick={saveWalletPin}
                                        disabled={
                                            walletSystemReadOnly
                                            || updateWalletPinMutation.isPending
                                            || walletPinForm.pin.length < 4
                                            || walletPinForm.pin_confirmation.length < 4
                                            || !walletPinForm.reason.trim()
                                        }
                                        className="crm-btn-primary disabled:cursor-not-allowed disabled:opacity-60"
                                    >
                                        {updateWalletPinMutation.isPending ? 'Saving...' : (walletSystemForm.pin_set ? 'Rotate wallet PIN' : 'Set wallet PIN')}
                                    </button>
                                </div>
                            </section>

                            <section className="rounded-lg border border-slate-200 bg-slate-50 p-3">
                                <div className="flex flex-wrap items-center justify-between gap-2">
                                    <div>
                                        <h4 className="text-sm font-semibold text-slate-900">Market Wallet Controls</h4>
                                        <p className="mt-1 text-xs text-slate-500">Set limits, presets, provider availability, and refresh behavior for each market.</p>
                                    </div>
                                    {!canManageWalletPlatforms ? (
                                        <span className="rounded-md border border-amber-200 bg-amber-50 px-2 py-1 text-[11px] font-medium text-amber-800">Read-only</span>
                                    ) : null}
                                </div>

                                <div className="mt-3">
                                    <label className="mb-1 block text-sm font-medium text-slate-700">Market</label>
                                    <select
                                        value={selectedPlatformId || ''}
                                        onChange={(event) => setSelectedPlatformId(Number(event.target.value) || null)}
                                        className="crm-select max-w-xl"
                                    >
                                        {platformRows.map((platform) => (
                                            <option key={platform.platform_id} value={platform.platform_id}>
                                                {platform.platform_name} ({platform.country || '—'})
                                            </option>
                                        ))}
                                    </select>
                                </div>

                                {!selectedPlatform ? (
                                    <p className="mt-3 rounded-md border border-dashed border-slate-300 bg-white px-3 py-4 text-sm text-slate-500">
                                        Select a market to edit wallet controls.
                                    </p>
                                ) : (
                                    <>
                                        <fieldset disabled={walletPlatformReadOnly || saveWalletPlatformMutation.isPending} className={walletPlatformReadOnly ? 'opacity-70' : ''}>
                                            <div className="mt-3 grid gap-3 md:grid-cols-2">
                                                <label className="md:col-span-2 flex items-center gap-2 text-sm text-slate-700">
                                                    <input
                                                        type="checkbox"
                                                        checked={Boolean(walletPlatformForm.enabled)}
                                                        onChange={(event) => updateWalletPlatformField('enabled', event.target.checked)}
                                                        className="h-4 w-4 rounded border-slate-300 text-teal-700 focus:ring-teal-200"
                                                    />
                                                    Enable wallet for this market
                                                </label>

                                                <div>
                                                    <label className="mb-1 block text-sm font-medium text-slate-700">Mode override</label>
                                                    <select
                                                        value={walletPlatformForm.mode_override}
                                                        onChange={(event) => updateWalletPlatformField('mode_override', event.target.value)}
                                                        className="crm-select w-full"
                                                    >
                                                        <option value="inherit">Inherit system mode</option>
                                                        <option value="sandbox">Sandbox only</option>
                                                        <option value="production">Production only</option>
                                                    </select>
                                                </div>

                                                <div>
                                                    <label className="mb-1 block text-sm font-medium text-slate-700">Currency</label>
                                                    <input
                                                        value={walletPlatformForm.currency_code}
                                                        onChange={(event) => updateWalletPlatformField('currency_code', event.target.value.toUpperCase())}
                                                        className="crm-input"
                                                        placeholder="KES"
                                                    />
                                                </div>

                                                <input
                                                    value={walletPlatformForm.max_single_topup}
                                                    onChange={(event) => updateWalletPlatformField('max_single_topup', event.target.value)}
                                                    className="crm-input"
                                                    placeholder="Max single top-up"
                                                />
                                                <input
                                                    value={walletPlatformForm.max_wallet_balance}
                                                    onChange={(event) => updateWalletPlatformField('max_wallet_balance', event.target.value)}
                                                    className="crm-input"
                                                    placeholder="Max wallet balance"
                                                />

                                                <label className="flex items-center gap-2 text-sm text-slate-700">
                                                    <input
                                                        type="checkbox"
                                                        checked={Boolean(walletPlatformForm.allow_combined_topup_subscribe)}
                                                        onChange={(event) => updateWalletPlatformField('allow_combined_topup_subscribe', event.target.checked)}
                                                        className="h-4 w-4 rounded border-slate-300 text-teal-700 focus:ring-teal-200"
                                                    />
                                                    Allow combined top-up + subscribe
                                                </label>
                                                <label className="flex items-center gap-2 text-sm text-slate-700">
                                                    <input
                                                        type="checkbox"
                                                        checked={Boolean(walletPlatformForm.show_refresh_button)}
                                                        onChange={(event) => updateWalletPlatformField('show_refresh_button', event.target.checked)}
                                                        className="h-4 w-4 rounded border-slate-300 text-teal-700 focus:ring-teal-200"
                                                    />
                                                    Show escort refresh button
                                                </label>

                                                <div>
                                                    <label className="mb-1 block text-sm font-medium text-slate-700">Recent transactions limit</label>
                                                    <input
                                                        type="number"
                                                        min="1"
                                                        max="50"
                                                        value={walletPlatformForm.recent_transactions_limit}
                                                        onChange={(event) => updateWalletPlatformField('recent_transactions_limit', Number(event.target.value || 10))}
                                                        className="crm-input"
                                                    />
                                                </div>

                                                <div className="md:col-span-2">
                                                    <label className="mb-1 block text-sm font-medium text-slate-700">Top-up presets</label>
                                                    <div className="space-y-2">
                                                        {walletPlatformForm.topup_presets.map((preset, index) => (
                                                            <div key={`wallet-preset-${index}`} className="flex gap-2">
                                                                <input
                                                                    value={preset}
                                                                    onChange={(event) => updateWalletTopupPreset(index, event.target.value)}
                                                                    className="crm-input flex-1"
                                                                    placeholder="Preset amount"
                                                                />
                                                                <button
                                                                    type="button"
                                                                    onClick={() => removeWalletTopupPreset(index)}
                                                                    disabled={walletPlatformForm.topup_presets.length <= 1}
                                                                    className="rounded-md border border-rose-200 bg-rose-50 px-3 py-2 text-xs font-semibold text-rose-700 transition hover:bg-rose-100 disabled:cursor-not-allowed disabled:opacity-50"
                                                                >
                                                                    Remove
                                                                </button>
                                                            </div>
                                                        ))}
                                                    </div>
                                                    <button
                                                        type="button"
                                                        onClick={addWalletTopupPreset}
                                                        className="mt-2 text-xs font-semibold text-teal-700 hover:text-teal-900"
                                                    >
                                                        + Add preset
                                                    </button>
                                                </div>

                                                <div className="md:col-span-2">
                                                    <label className="mb-1 block text-sm font-medium text-slate-700">Change reason</label>
                                                    <textarea
                                                        rows={2}
                                                        value={walletPlatformForm.reason}
                                                        onChange={(event) => updateWalletPlatformField('reason', event.target.value)}
                                                        className="crm-input"
                                                        placeholder="Reason for platform wallet change"
                                                    />
                                                </div>
                                            </div>

                                            <div className="mt-4 grid gap-3 xl:grid-cols-3">
                                                {walletProviderKeys.map((providerKey) => (
                                                    <div key={`wallet-provider-controls-${providerKey}`} className="rounded-md border border-slate-200 bg-white p-3">
                                                        <label className="flex items-center gap-2 text-sm font-medium text-slate-800">
                                                            <input
                                                                type="checkbox"
                                                                checked={Boolean(walletPlatformForm.providers[providerKey]?.enabled)}
                                                                onChange={(event) => updateWalletPlatformProviderField(providerKey, 'enabled', event.target.checked)}
                                                                className="h-4 w-4 rounded border-slate-300 text-teal-700 focus:ring-teal-200"
                                                            />
                                                            {walletProviderLabel(providerKey)}
                                                        </label>
                                                        <div className="mt-3 grid gap-2">
                                                            <input
                                                                value={walletPlatformForm.providers[providerKey]?.min_amount || ''}
                                                                onChange={(event) => updateWalletPlatformProviderField(providerKey, 'min_amount', event.target.value)}
                                                                className="crm-input"
                                                                placeholder="Minimum amount"
                                                            />
                                                            <input
                                                                value={walletPlatformForm.providers[providerKey]?.max_amount || ''}
                                                                onChange={(event) => updateWalletPlatformProviderField(providerKey, 'max_amount', event.target.value)}
                                                                className="crm-input"
                                                                placeholder="Maximum amount"
                                                            />
                                                        </div>
                                                    </div>
                                                ))}
                                            </div>
                                        </fieldset>

                                        <div className="mt-3 flex justify-end">
                                            <button
                                                type="button"
                                                onClick={saveWalletPlatformConfig}
                                                disabled={
                                                    walletPlatformReadOnly
                                                    || saveWalletPlatformMutation.isPending
                                                    || !walletPlatformForm.currency_code.trim()
                                                    || !walletPlatformForm.reason.trim()
                                                }
                                                className="crm-btn-primary disabled:cursor-not-allowed disabled:opacity-60"
                                            >
                                                {saveWalletPlatformMutation.isPending ? 'Saving...' : 'Save market wallet'}
                                            </button>
                                        </div>
                                    </>
                                )}
                            </section>

                            {selectedPlatform ? (
                                <>
                                    <section className="rounded-lg border border-slate-200 bg-white p-3">
                                        <h4 className="text-sm font-semibold text-slate-900">Provider Credentials</h4>
                                        <p className="mt-1 text-xs text-slate-500">Credential updates are per market and environment. Blank secret fields keep the currently stored value.</p>

                                        <fieldset disabled={walletPlatformReadOnly || saveWalletProvidersMutation.isPending} className={walletPlatformReadOnly ? 'opacity-70' : ''}>
                                            <div className="mt-3 space-y-3">
                                                {walletProviderKeys.map((providerKey) => (
                                                    <div key={`wallet-provider-credentials-${providerKey}`} className="rounded-md border border-slate-200 bg-slate-50 p-3">
                                                        <div className="flex flex-wrap items-center justify-between gap-2">
                                                            <h5 className="text-sm font-semibold text-slate-900">{walletProviderLabel(providerKey)}</h5>
                                                            <span className="text-xs text-slate-500">{selectedPlatform.platform_name}</span>
                                                        </div>

                                                        <div className="mt-3 grid gap-3 xl:grid-cols-2">
                                                            {walletEnvironmentOptions.map((environment) => {
                                                                const providerConfig = walletProvidersForm[providerKey]?.[environment] || {};

                                                                return (
                                                                    <div key={`${providerKey}-${environment}`} className="rounded-md border border-slate-200 bg-white p-3">
                                                                        <div className="flex items-center justify-between gap-2">
                                                                            <p className="text-xs font-semibold uppercase tracking-[0.12em] text-slate-500">{environment}</p>
                                                                            {providerKey === 'pesapal' ? (
                                                                                <span className={`text-[11px] ${providerConfig.consumer_key_configured && providerConfig.consumer_secret_configured ? 'text-emerald-700' : 'text-amber-700'}`}>
                                                                                    {providerConfig.consumer_key_configured && providerConfig.consumer_secret_configured ? 'Secrets stored' : 'Secrets incomplete'}
                                                                                </span>
                                                                            ) : providerKey === 'paystack' ? (
                                                                                <span className={`text-[11px] ${providerConfig.public_key_configured && providerConfig.secret_key_configured ? 'text-emerald-700' : 'text-amber-700'}`}>
                                                                                    {providerConfig.public_key_configured && providerConfig.secret_key_configured ? 'Keys stored' : 'Keys incomplete'}
                                                                                </span>
                                                                            ) : (
                                                                                <span className="text-[11px] text-slate-500">{providerConfig.transport || 'django_proxy'}</span>
                                                                            )}
                                                                        </div>

                                                                        {providerKey === 'pesapal' ? (
                                                                            <div className="mt-3 grid gap-2">
                                                                                <input
                                                                                    value={providerConfig.consumer_key || ''}
                                                                                    onChange={(event) => updateWalletProviderCredentialField(providerKey, environment, 'consumer_key', event.target.value)}
                                                                                    className="crm-input"
                                                                                    placeholder="Consumer key"
                                                                                />
                                                                                <input
                                                                                    type="password"
                                                                                    value={providerConfig.consumer_secret || ''}
                                                                                    onChange={(event) => updateWalletProviderCredentialField(providerKey, environment, 'consumer_secret', event.target.value)}
                                                                                    className="crm-input"
                                                                                    placeholder="Consumer secret"
                                                                                />
                                                                                <input
                                                                                    value={providerConfig.ipn_id || ''}
                                                                                    onChange={(event) => updateWalletProviderCredentialField(providerKey, environment, 'ipn_id', event.target.value)}
                                                                                    className="crm-input"
                                                                                    placeholder="IPN ID"
                                                                                />
                                                                            </div>
                                                                        ) : null}

                                                                        {providerKey === 'paystack' ? (
                                                                            <div className="mt-3 grid gap-2">
                                                                                <input
                                                                                    value={providerConfig.public_key || ''}
                                                                                    onChange={(event) => updateWalletProviderCredentialField(providerKey, environment, 'public_key', event.target.value)}
                                                                                    className="crm-input"
                                                                                    placeholder="Public key"
                                                                                />
                                                                                <input
                                                                                    type="password"
                                                                                    value={providerConfig.secret_key || ''}
                                                                                    onChange={(event) => updateWalletProviderCredentialField(providerKey, environment, 'secret_key', event.target.value)}
                                                                                    className="crm-input"
                                                                                    placeholder="Secret key"
                                                                                />
                                                                            </div>
                                                                        ) : null}

                                                                        {providerKey === 'mpesa_stk' ? (
                                                                            <div className="mt-3 grid gap-2">
                                                                                <select
                                                                                    value={providerConfig.transport || 'django_proxy'}
                                                                                    onChange={(event) => updateWalletProviderCredentialField(providerKey, environment, 'transport', event.target.value)}
                                                                                    className="crm-select"
                                                                                >
                                                                                    <option value="django_proxy">Django proxy</option>
                                                                                    <option value="direct_provider">Direct provider</option>
                                                                                </select>
                                                                                <input
                                                                                    value={providerConfig.payment_service_base_url || ''}
                                                                                    onChange={(event) => updateWalletProviderCredentialField(providerKey, environment, 'payment_service_base_url', event.target.value)}
                                                                                    className="crm-input"
                                                                                    placeholder="Payment service base URL"
                                                                                />
                                                                                <input
                                                                                    value={providerConfig.organization_code || ''}
                                                                                    onChange={(event) => updateWalletProviderCredentialField(providerKey, environment, 'organization_code', event.target.value)}
                                                                                    className="crm-input"
                                                                                    placeholder="Organization code"
                                                                                />
                                                                                <input
                                                                                    value={providerConfig.callback_base_url || ''}
                                                                                    onChange={(event) => updateWalletProviderCredentialField(providerKey, environment, 'callback_base_url', event.target.value)}
                                                                                    className="crm-input"
                                                                                    placeholder="Callback base URL"
                                                                                />
                                                                            </div>
                                                                        ) : null}
                                                                    </div>
                                                                );
                                                            })}
                                                        </div>
                                                    </div>
                                                ))}
                                            </div>

                                            <div className="mt-3 grid gap-3 md:grid-cols-[1fr_auto]">
                                                <input
                                                    value={walletProvidersForm.reason}
                                                    onChange={(event) => setWalletProvidersForm((current) => ({ ...current, reason: event.target.value }))}
                                                    className="crm-input"
                                                    placeholder="Reason for provider credential update"
                                                />
                                                <button
                                                    type="button"
                                                    onClick={saveWalletProvidersConfig}
                                                    disabled={walletPlatformReadOnly || saveWalletProvidersMutation.isPending || !walletProvidersForm.reason.trim()}
                                                    className="crm-btn-primary disabled:cursor-not-allowed disabled:opacity-60"
                                                >
                                                    {saveWalletProvidersMutation.isPending ? 'Saving...' : 'Save provider credentials'}
                                                </button>
                                            </div>
                                        </fieldset>
                                    </section>

                                    <section className="rounded-lg border border-slate-200 bg-white p-3">
                                        <h4 className="text-sm font-semibold text-slate-900">WP to CRM Credentials</h4>
                                        <p className="mt-1 text-xs text-slate-500">Rotate bearer and HMAC secrets for the WordPress plugin. Revealed values are shown once and must be copied immediately.</p>

                                        <fieldset disabled={walletPlatformReadOnly || rotateWalletCredentialMutation.isPending} className={walletPlatformReadOnly ? 'opacity-70' : ''}>
                                            <div className="mt-3 grid gap-3 md:grid-cols-3">
                                                <select
                                                    value={walletCredentialRotationForm.environment}
                                                    onChange={(event) => setWalletCredentialRotationForm((current) => ({ ...current, environment: event.target.value }))}
                                                    className="crm-select"
                                                >
                                                    {walletEnvironmentOptions.map((environment) => (
                                                        <option key={`wallet-rotate-${environment}`} value={environment}>{environment}</option>
                                                    ))}
                                                </select>
                                                <select
                                                    value={walletCredentialRotationForm.credential}
                                                    onChange={(event) => setWalletCredentialRotationForm((current) => ({ ...current, credential: event.target.value }))}
                                                    className="crm-select"
                                                >
                                                    <option value="bearer">Bearer only</option>
                                                    <option value="hmac">HMAC only</option>
                                                    <option value="both">Bearer + HMAC</option>
                                                </select>
                                                <button
                                                    type="button"
                                                    onClick={() => rotateWalletCredentialMutation.mutate({
                                                        platformId: selectedPlatform.platform_id,
                                                        payload: {
                                                            environment: walletCredentialRotationForm.environment,
                                                            credential: walletCredentialRotationForm.credential,
                                                            reason: walletCredentialRotationForm.reason.trim(),
                                                        },
                                                    })}
                                                    disabled={!walletCredentialRotationForm.reason.trim()}
                                                    className="crm-btn-secondary disabled:cursor-not-allowed disabled:opacity-60"
                                                >
                                                    {rotateWalletCredentialMutation.isPending ? 'Rotating...' : 'Rotate credentials'}
                                                </button>
                                                <textarea
                                                    rows={2}
                                                    value={walletCredentialRotationForm.reason}
                                                    onChange={(event) => setWalletCredentialRotationForm((current) => ({ ...current, reason: event.target.value }))}
                                                    className="crm-input md:col-span-3"
                                                    placeholder="Reason for credential rotation"
                                                />
                                            </div>
                                        </fieldset>

                                        <div className="mt-3 grid gap-3 xl:grid-cols-2">
                                            {walletEnvironmentOptions.map((environment) => {
                                                const credentials = selectedWalletWpCredentials[environment] || {};

                                                return (
                                                    <div key={`wallet-wp-state-${environment}`} className="rounded-md border border-slate-200 bg-slate-50 p-3">
                                                        <p className="text-xs font-semibold uppercase tracking-[0.12em] text-slate-500">{environment}</p>
                                                        <div className="mt-2 space-y-1 text-xs text-slate-600">
                                                            <p>Bearer: {credentials.bearer_key_configured ? 'configured' : 'missing'}</p>
                                                            <p>Last bearer rotation: {formatDateTime(credentials.bearer_last_rotated_at)}</p>
                                                            <p>HMAC: {credentials.hmac_configured ? 'configured' : 'missing'}</p>
                                                            <p>Last HMAC rotation: {formatDateTime(credentials.hmac_last_rotated_at)}</p>
                                                        </div>
                                                    </div>
                                                );
                                            })}
                                        </div>

                                        {walletCredentialReveal ? (
                                            <div className="mt-3 rounded-md border border-teal-200 bg-teal-50/70 p-3">
                                                <div className="flex flex-wrap items-center justify-between gap-2">
                                                    <div>
                                                        <p className="text-sm font-semibold text-teal-900">One-time credential reveal</p>
                                                        <p className="text-xs text-teal-700">
                                                            {walletCredentialReveal.environment} • {walletCredentialReveal.credential}
                                                        </p>
                                                    </div>
                                                    <button
                                                        type="button"
                                                        onClick={() => setWalletCredentialReveal(null)}
                                                        className="text-xs font-semibold text-teal-700 hover:text-teal-900"
                                                    >
                                                        Clear
                                                    </button>
                                                </div>
                                                <div className="mt-3 space-y-2">
                                                    {Object.entries(walletCredentialReveal.revealed || {}).map(([key, value]) => (
                                                        <div key={`wallet-reveal-${key}`} className="rounded-md border border-teal-200 bg-white p-2">
                                                            <div className="flex flex-wrap items-center justify-between gap-2">
                                                                <p className="text-xs font-semibold text-slate-800">{key}</p>
                                                                <button
                                                                    type="button"
                                                                    onClick={() => copyWalletReveal(key, value)}
                                                                    className="text-xs font-semibold text-teal-700 hover:text-teal-900"
                                                                >
                                                                    Copy
                                                                </button>
                                                            </div>
                                                            <code className="mt-2 block break-all rounded bg-slate-900/90 px-2 py-1.5 text-[11px] text-slate-100">{value}</code>
                                                        </div>
                                                    ))}
                                                </div>
                                            </div>
                                        ) : null}
                                    </section>
                                </>
                            ) : null}
                        </div>

                        <div className="space-y-4 xl:col-span-5">
                            <section className="rounded-lg border border-slate-200 bg-white p-3">
                                <h4 className="text-sm font-semibold text-slate-900">Wallet Health</h4>
                                <div className="mt-3 grid gap-2 sm:grid-cols-2">
                                    <div className="rounded-md border border-slate-200 bg-slate-50 px-3 py-2">
                                        <p className="text-[11px] font-semibold uppercase tracking-[0.1em] text-slate-500">System mode</p>
                                        <p className="mt-1 text-lg font-semibold text-slate-900">{(walletSystemConfig?.mode || 'disabled').replaceAll('_', ' ')}</p>
                                    </div>
                                    <div className="rounded-md border border-slate-200 bg-slate-50 px-3 py-2">
                                        <p className="text-[11px] font-semibold uppercase tracking-[0.1em] text-slate-500">Markets enabled</p>
                                        <p className="mt-1 text-lg font-semibold text-slate-900">{walletEnabledMarkets}</p>
                                    </div>
                                    <div className="rounded-md border border-slate-200 bg-slate-50 px-3 py-2">
                                        <p className="text-[11px] font-semibold uppercase tracking-[0.1em] text-slate-500">Markets live</p>
                                        <p className="mt-1 text-lg font-semibold text-slate-900">{walletActiveMarkets}</p>
                                    </div>
                                    <div className="rounded-md border border-slate-200 bg-slate-50 px-3 py-2">
                                        <p className="text-[11px] font-semibold uppercase tracking-[0.1em] text-slate-500">Selected providers</p>
                                        <p className="mt-1 text-lg font-semibold text-slate-900">{selectedWalletProvidersEnabled}</p>
                                    </div>
                                    <div className="rounded-md border border-slate-200 bg-slate-50 px-3 py-2">
                                        <p className="text-[11px] font-semibold uppercase tracking-[0.1em] text-slate-500">Operator PIN</p>
                                        <p className="mt-1 text-lg font-semibold text-slate-900">{walletSystemForm.pin_set ? 'Configured' : 'Missing'}</p>
                                        <p className="mt-1 text-xs text-slate-500">Updated {formatDateTime(walletSystemForm.pin_last_updated_at)}</p>
                                    </div>
                                </div>
                            </section>

                            <section className="rounded-lg border border-slate-200 bg-white p-3">
                                <h4 className="text-sm font-semibold text-slate-900">Connectivity Tests</h4>
                                <p className="mt-1 text-xs text-slate-500">Run DNS, SSL, app, provider, and SMTP checks against the current configuration.</p>

                                <div className="mt-3 space-y-3">
                                    <div className="rounded-md border border-slate-200 bg-slate-50 p-3">
                                        <p className="text-xs font-semibold uppercase tracking-[0.12em] text-slate-500">Billing domain</p>
                                        <div className="mt-2 grid gap-2 md:grid-cols-[1fr_auto]">
                                            <select
                                                value={walletDomainTestForm.environment}
                                                onChange={(event) => setWalletDomainTestForm((current) => ({ ...current, environment: event.target.value }))}
                                                className="crm-select"
                                            >
                                                {walletEnvironmentOptions.map((environment) => (
                                                    <option key={`wallet-domain-${environment}`} value={environment}>{environment}</option>
                                                ))}
                                            </select>
                                            <button
                                                type="button"
                                                onClick={() => testWalletDomainMutation.mutate({
                                                    environment: walletDomainTestForm.environment,
                                                    reason: walletDomainTestForm.reason.trim(),
                                                })}
                                                disabled={testWalletDomainMutation.isPending || !walletDomainTestForm.reason.trim()}
                                                className="crm-btn-secondary disabled:cursor-not-allowed disabled:opacity-60"
                                            >
                                                {testWalletDomainMutation.isPending ? 'Testing...' : 'Run DNS test'}
                                            </button>
                                            <input
                                                value={walletDomainTestForm.reason}
                                                onChange={(event) => setWalletDomainTestForm((current) => ({ ...current, reason: event.target.value }))}
                                                className="crm-input md:col-span-2"
                                                placeholder="Reason for billing domain test"
                                            />
                                        </div>
                                    </div>

                                    <div className="rounded-md border border-slate-200 bg-slate-50 p-3">
                                        <p className="text-xs font-semibold uppercase tracking-[0.12em] text-slate-500">Billing SSL</p>
                                        <div className="mt-2 grid gap-2 md:grid-cols-[1fr_auto]">
                                            <select
                                                value={walletSslTestForm.environment}
                                                onChange={(event) => setWalletSslTestForm((current) => ({ ...current, environment: event.target.value }))}
                                                className="crm-select"
                                            >
                                                {walletEnvironmentOptions.map((environment) => (
                                                    <option key={`wallet-ssl-${environment}`} value={environment}>{environment}</option>
                                                ))}
                                            </select>
                                            <button
                                                type="button"
                                                onClick={() => testWalletSslMutation.mutate({
                                                    environment: walletSslTestForm.environment,
                                                    reason: walletSslTestForm.reason.trim(),
                                                })}
                                                disabled={testWalletSslMutation.isPending || !walletSslTestForm.reason.trim()}
                                                className="crm-btn-secondary disabled:cursor-not-allowed disabled:opacity-60"
                                            >
                                                {testWalletSslMutation.isPending ? 'Testing...' : 'Run SSL test'}
                                            </button>
                                            <input
                                                value={walletSslTestForm.reason}
                                                onChange={(event) => setWalletSslTestForm((current) => ({ ...current, reason: event.target.value }))}
                                                className="crm-input md:col-span-2"
                                                placeholder="Reason for billing SSL test"
                                            />
                                        </div>
                                    </div>

                                    <div className="rounded-md border border-slate-200 bg-slate-50 p-3">
                                        <p className="text-xs font-semibold uppercase tracking-[0.12em] text-slate-500">Billing app</p>
                                        <div className="mt-2 grid gap-2 md:grid-cols-[1fr_auto]">
                                            <select
                                                value={walletAppTestForm.environment}
                                                onChange={(event) => setWalletAppTestForm((current) => ({ ...current, environment: event.target.value }))}
                                                className="crm-select"
                                            >
                                                {walletEnvironmentOptions.map((environment) => (
                                                    <option key={`wallet-app-${environment}`} value={environment}>{environment}</option>
                                                ))}
                                            </select>
                                            <button
                                                type="button"
                                                onClick={() => testWalletAppMutation.mutate({
                                                    environment: walletAppTestForm.environment,
                                                    reason: walletAppTestForm.reason.trim(),
                                                })}
                                                disabled={testWalletAppMutation.isPending || !walletAppTestForm.reason.trim()}
                                                className="crm-btn-secondary disabled:cursor-not-allowed disabled:opacity-60"
                                            >
                                                {testWalletAppMutation.isPending ? 'Testing...' : 'Run app test'}
                                            </button>
                                            <input
                                                value={walletAppTestForm.reason}
                                                onChange={(event) => setWalletAppTestForm((current) => ({ ...current, reason: event.target.value }))}
                                                className="crm-input md:col-span-2"
                                                placeholder="Reason for billing app test"
                                            />
                                        </div>
                                    </div>

                                    <div className="rounded-md border border-slate-200 bg-slate-50 p-3">
                                        <p className="text-xs font-semibold uppercase tracking-[0.12em] text-slate-500">Provider connectivity</p>
                                        {!selectedPlatform ? (
                                            <p className="mt-2 text-xs text-amber-700">Select a market before running provider tests.</p>
                                        ) : (
                                            <div className="mt-2 grid gap-2">
                                                <div className="grid gap-2 md:grid-cols-2">
                                                    <select
                                                        value={walletProviderTestForm.provider}
                                                        onChange={(event) => setWalletProviderTestForm((current) => ({ ...current, provider: event.target.value }))}
                                                        className="crm-select"
                                                    >
                                                        {walletProviderKeys.map((providerKey) => (
                                                            <option key={`wallet-provider-test-${providerKey}`} value={providerKey}>{walletProviderLabel(providerKey)}</option>
                                                        ))}
                                                    </select>
                                                    <select
                                                        value={walletProviderTestForm.environment}
                                                        onChange={(event) => setWalletProviderTestForm((current) => ({ ...current, environment: event.target.value }))}
                                                        className="crm-select"
                                                    >
                                                        {walletEnvironmentOptions.map((environment) => (
                                                            <option key={`wallet-provider-environment-${environment}`} value={environment}>{environment}</option>
                                                        ))}
                                                    </select>
                                                </div>
                                                <input
                                                    value={walletProviderTestForm.reason}
                                                    onChange={(event) => setWalletProviderTestForm((current) => ({ ...current, reason: event.target.value }))}
                                                    className="crm-input"
                                                    placeholder="Reason for provider test"
                                                />
                                                <button
                                                    type="button"
                                                    onClick={() => testWalletProviderMutation.mutate({
                                                        platformId: selectedPlatform.platform_id,
                                                        payload: {
                                                            provider: walletProviderTestForm.provider,
                                                            environment: walletProviderTestForm.environment,
                                                            reason: walletProviderTestForm.reason.trim(),
                                                        },
                                                    })}
                                                    disabled={testWalletProviderMutation.isPending || !walletProviderTestForm.reason.trim()}
                                                    className="crm-btn-secondary self-end disabled:cursor-not-allowed disabled:opacity-60"
                                                >
                                                    {testWalletProviderMutation.isPending ? 'Testing...' : 'Run provider test'}
                                                </button>
                                            </div>
                                        )}
                                    </div>

                                    <div className="rounded-md border border-slate-200 bg-slate-50 p-3">
                                        <p className="text-xs font-semibold uppercase tracking-[0.12em] text-slate-500">SMTP test email</p>
                                        <div className="mt-2 grid gap-2 md:grid-cols-[1fr_auto]">
                                            <input
                                                value={walletEmailTestForm.to_email}
                                                onChange={(event) => setWalletEmailTestForm((current) => ({ ...current, to_email: event.target.value }))}
                                                className="crm-input"
                                                placeholder="recipient@example.com"
                                            />
                                            <button
                                                type="button"
                                                onClick={() => testWalletEmailMutation.mutate({
                                                    to_email: walletEmailTestForm.to_email.trim(),
                                                    reason: walletEmailTestForm.reason.trim(),
                                                })}
                                                disabled={testWalletEmailMutation.isPending || !walletEmailTestForm.to_email.trim() || !walletEmailTestForm.reason.trim()}
                                                className="crm-btn-secondary disabled:cursor-not-allowed disabled:opacity-60"
                                            >
                                                {testWalletEmailMutation.isPending ? 'Sending...' : 'Send test email'}
                                            </button>
                                            <input
                                                value={walletEmailTestForm.reason}
                                                onChange={(event) => setWalletEmailTestForm((current) => ({ ...current, reason: event.target.value }))}
                                                className="crm-input md:col-span-2"
                                                placeholder="Reason for SMTP test email"
                                            />
                                        </div>
                                    </div>
                                </div>
                            </section>

                            {selectedPlatform ? (
                                <section className="rounded-lg border border-slate-200 bg-slate-50 p-3">
                                    <h4 className="text-sm font-semibold text-slate-900">Selected Market</h4>
                                    <div className="mt-2 space-y-1 text-xs text-slate-600">
                                        <p><span className="font-semibold text-slate-800">Market:</span> {selectedPlatform.platform_name}</p>
                                        <p><span className="font-semibold text-slate-800">Country:</span> {selectedPlatform.country || '—'}</p>
                                        <p><span className="font-semibold text-slate-800">Effective mode:</span> {selectedWalletEffectiveMode.replaceAll('_', ' ')}</p>
                                        <p><span className="font-semibold text-slate-800">Currency:</span> {walletPlatformForm.currency_code || selectedPlatform.currency || 'KES'}</p>
                                        <p><span className="font-semibold text-slate-800">Refresh button:</span> {walletPlatformForm.show_refresh_button ? 'shown' : 'hidden'}</p>
                                    </div>
                                </section>
                            ) : null}

                            {latestWalletProviderTest ? (
                                <section className="rounded-lg border border-slate-200 bg-white p-3">
                                    <div className="flex flex-wrap items-center justify-between gap-2">
                                        <h4 className="text-sm font-semibold text-slate-900">Latest Provider Test</h4>
                                        <span className={`inline-flex items-center rounded-md px-2 py-0.5 text-xs font-medium ring-1 ring-inset ${statusChip(latestWalletProviderTest.ok ? 'success' : 'failed')}`}>
                                            {latestWalletProviderTest.ok ? 'success' : 'failed'}
                                        </span>
                                    </div>
                                    <div className="mt-2 space-y-1 text-xs text-slate-600">
                                        <p><span className="font-semibold text-slate-800">Provider:</span> {walletProviderLabel(latestWalletProviderTest.provider)}</p>
                                        <p><span className="font-semibold text-slate-800">Environment:</span> {latestWalletProviderTest.environment || '--'}</p>
                                        <p><span className="font-semibold text-slate-800">HTTP status:</span> {latestWalletProviderTest.http_status || '--'}</p>
                                        <p className="break-all"><span className="font-semibold text-slate-800">Message:</span> {latestWalletProviderTest.message || '--'}</p>
                                        {latestWalletProviderTest.provider_response ? (
                                            <p className="break-all"><span className="font-semibold text-slate-800">Response:</span> {JSON.stringify(latestWalletProviderTest.provider_response)}</p>
                                        ) : null}
                                    </div>
                                </section>
                            ) : null}

                            {latestWalletDomainTest ? (
                                <section className="rounded-lg border border-slate-200 bg-white p-3">
                                    <div className="flex flex-wrap items-center justify-between gap-2">
                                        <h4 className="text-sm font-semibold text-slate-900">Latest Domain Test</h4>
                                        <span className={`inline-flex items-center rounded-md px-2 py-0.5 text-xs font-medium ring-1 ring-inset ${statusChip(latestWalletDomainTest.status || 'failed')}`}>
                                            {(latestWalletDomainTest.status || 'failed').replaceAll('_', ' ')}
                                        </span>
                                    </div>
                                    <div className="mt-2 space-y-1 text-xs text-slate-600">
                                        <p><span className="font-semibold text-slate-800">Environment:</span> {latestWalletDomainTest.environment || '--'}</p>
                                        <p><span className="font-semibold text-slate-800">Host:</span> {latestWalletDomainTest.host || '--'}</p>
                                        <p className="break-all"><span className="font-semibold text-slate-800">Message:</span> {latestWalletDomainTest.message || '--'}</p>
                                    </div>
                                </section>
                            ) : null}

                            {latestWalletSslTest ? (
                                <section className="rounded-lg border border-slate-200 bg-white p-3">
                                    <div className="flex flex-wrap items-center justify-between gap-2">
                                        <h4 className="text-sm font-semibold text-slate-900">Latest SSL Test</h4>
                                        <span className={`inline-flex items-center rounded-md px-2 py-0.5 text-xs font-medium ring-1 ring-inset ${statusChip(latestWalletSslTest.ok ? 'success' : 'failed')}`}>
                                            {latestWalletSslTest.ok ? 'success' : 'failed'}
                                        </span>
                                    </div>
                                    <div className="mt-2 space-y-1 text-xs text-slate-600">
                                        <p><span className="font-semibold text-slate-800">Environment:</span> {latestWalletSslTest.environment || '--'}</p>
                                        <p><span className="font-semibold text-slate-800">HTTP status:</span> {latestWalletSslTest.http_status || '--'}</p>
                                        <p className="break-all"><span className="font-semibold text-slate-800">Message:</span> {latestWalletSslTest.message || '--'}</p>
                                    </div>
                                </section>
                            ) : null}

                            {latestWalletAppTest ? (
                                <section className="rounded-lg border border-slate-200 bg-white p-3">
                                    <div className="flex flex-wrap items-center justify-between gap-2">
                                        <h4 className="text-sm font-semibold text-slate-900">Latest App Test</h4>
                                        <span className={`inline-flex items-center rounded-md px-2 py-0.5 text-xs font-medium ring-1 ring-inset ${statusChip(latestWalletAppTest.ok ? 'success' : 'failed')}`}>
                                            {latestWalletAppTest.ok ? 'success' : 'failed'}
                                        </span>
                                    </div>
                                    <div className="mt-2 space-y-1 text-xs text-slate-600">
                                        <p><span className="font-semibold text-slate-800">Environment:</span> {latestWalletAppTest.environment || '--'}</p>
                                        <p className="break-all"><span className="font-semibold text-slate-800">URL:</span> {latestWalletAppTest.url || '--'}</p>
                                        <p><span className="font-semibold text-slate-800">HTTP status:</span> {latestWalletAppTest.http_status || '--'}</p>
                                        <p className="break-all"><span className="font-semibold text-slate-800">Message:</span> {latestWalletAppTest.message || '--'}</p>
                                        {latestWalletAppTest.provider_response ? (
                                            <p className="break-all"><span className="font-semibold text-slate-800">Response:</span> {JSON.stringify(latestWalletAppTest.provider_response)}</p>
                                        ) : null}
                                    </div>
                                </section>
                            ) : null}

                            {latestWalletEmailTest ? (
                                <section className="rounded-lg border border-slate-200 bg-white p-3">
                                    <div className="flex flex-wrap items-center justify-between gap-2">
                                        <h4 className="text-sm font-semibold text-slate-900">Latest Email Test</h4>
                                        <span className={`inline-flex items-center rounded-md px-2 py-0.5 text-xs font-medium ring-1 ring-inset ${statusChip(latestWalletEmailTest.status || 'success')}`}>
                                            {(latestWalletEmailTest.status || 'success').replaceAll('_', ' ')}
                                        </span>
                                    </div>
                                    <div className="mt-2 space-y-1 text-xs text-slate-600">
                                        <p><span className="font-semibold text-slate-800">Recipient:</span> {latestWalletEmailTest.to_email || '--'}</p>
                                        <p><span className="font-semibold text-slate-800">Mailer:</span> {latestWalletEmailTest.mailer || '--'}</p>
                                        <p className="break-all"><span className="font-semibold text-slate-800">Message:</span> {latestWalletEmailTest.message || '--'}</p>
                                    </div>
                                </section>
                            ) : null}
                        </div>
                    </div>
                </section>
            ) : null}

            {integrationArea === 'markets' ? (
                <section className="crm-surface overflow-hidden">
                <header className="crm-panel-header">
                    <div>
                        <h3 className="crm-panel-title">Market Integration Workspace</h3>
                        <p className="crm-panel-subtitle">Configure credentials, test connectivity, and run manual sync per market.</p>
                    </div>
                    <div className="flex flex-wrap items-center gap-2">
                        <button
                            type="button"
                            onClick={() => queryClient.invalidateQueries({ queryKey: ['settings-integrations'] })}
                            className="crm-btn-secondary px-3 py-2"
                        >
                            Refresh
                        </button>
                        {canCreateMarkets ? (
                            <button type="button" onClick={() => setCreateOpen(true)} className="crm-btn-primary px-3 py-2">
                                Add market
                            </button>
                        ) : null}
                    </div>
                </header>

                <div className="grid gap-4 p-4 xl:grid-cols-12">
                    <div className="xl:col-span-5">
                        {isLoading ? (
                            <p className="text-sm text-slate-500">Loading market profiles...</p>
                        ) : platformRows.length === 0 ? (
                            <p className="rounded-md border border-dashed border-slate-300 bg-slate-50 px-3 py-6 text-sm text-slate-500">No market profiles configured.</p>
                        ) : (
                            <div className="space-y-2">
                                {platformRows.map((platform) => {
                                    const isSelected = platform.platform_id === selectedPlatformId;
                                    const packageIncomplete = !platform.package_setup?.can_go_live;
                                    return (
                                        <button
                                            key={platform.platform_id}
                                            type="button"
                                            onClick={() => setSelectedPlatformId(platform.platform_id)}
                                            className={`w-full rounded-lg border px-3 py-3 text-left transition ${isSelected ? 'border-teal-300 bg-teal-50/40' : 'border-slate-200 bg-white hover:border-slate-300'}`}
                                        >
                                            <div className="flex items-start justify-between gap-2">
                                                <div>
                                                    <p className="text-sm font-semibold text-slate-900">{platform.platform_name}</p>
                                                    <p className="text-xs text-slate-500">{platform.country || '—'} • {platform.domain || 'No domain'}</p>
                                                </div>
                                                <span className={`inline-flex items-center rounded-md px-2 py-0.5 text-xs font-medium ring-1 ring-inset ${statusChip(platform.wp_sync?.status || 'pending')}`}>
                                                    {(platform.wp_sync?.status || 'pending').replaceAll('_', ' ')}
                                                </span>
                                            </div>
                                            <div className="mt-2 flex items-center justify-between text-xs text-slate-500">
                                                <span>Last sync: {formatDateTime(platform.sync?.last_synced_at)}</span>
                                                <span className="font-medium">{(platform.sync?.last_status || 'unknown').replaceAll('_', ' ')}</span>
                                            </div>
                                            <p className={`mt-1 text-xs ${packageIncomplete ? 'text-amber-700' : 'text-emerald-700'}`}>
                                                {packageIncomplete ? 'Package setup incomplete' : 'Package catalog ready'}
                                            </p>
                                        </button>
                                    );
                                })}
                            </div>
                        )}
                    </div>

                    <div className="xl:col-span-7">
                        {!selectedPlatform || !editor ? (
                            <p className="rounded-md border border-dashed border-slate-300 bg-slate-50 px-3 py-8 text-sm text-slate-500">
                                Select a market to edit integration details.
                            </p>
                        ) : (
                            <div className="space-y-4">
                                <section className="rounded-lg border border-slate-200 bg-slate-50 p-3">
                                    <h4 className="text-sm font-semibold text-slate-900">Market Profile</h4>
                                    <p className="mt-1 text-xs text-slate-500">Use this form to update credentials and runtime defaults.</p>
                                    <div className="mt-3 grid gap-3 md:grid-cols-2">
                                        <input
                                            value={editor.name}
                                            onChange={(event) => setEditor((current) => ({ ...current, name: event.target.value }))}
                                            className="crm-input"
                                            placeholder="Market name"
                                        />
                                        <input
                                            value={editor.domain}
                                            onChange={(event) => setEditor((current) => ({ ...current, domain: event.target.value }))}
                                            className="crm-input"
                                            placeholder="Domain"
                                        />
                                        <input
                                            value={editor.country}
                                            onChange={(event) => setEditor((current) => ({ ...current, country: event.target.value }))}
                                            className="crm-input"
                                            placeholder="Country"
                                        />
                                        <input
                                            value={editor.phone_prefix}
                                            onChange={(event) => setEditor((current) => ({ ...current, phone_prefix: event.target.value }))}
                                            className="crm-input"
                                            placeholder="Phone prefix"
                                        />
                                        <input
                                            value={editor.currency_code}
                                            onChange={(event) => setEditor((current) => ({ ...current, currency_code: event.target.value.toUpperCase() }))}
                                            className="crm-input"
                                            placeholder="Currency code"
                                        />
                                        <input
                                            value={editor.timezone}
                                            onChange={(event) => setEditor((current) => ({ ...current, timezone: event.target.value }))}
                                            className="crm-input"
                                            placeholder="Timezone"
                                        />
                                        <input
                                            value={editor.wp_api_url}
                                            onChange={(event) => setEditor((current) => ({ ...current, wp_api_url: event.target.value }))}
                                            className="crm-input md:col-span-2"
                                            placeholder="WordPress Sync API URL"
                                        />
                                        <input
                                            value={editor.support_chat_url}
                                            onChange={(event) => setEditor((current) => ({ ...current, support_chat_url: event.target.value }))}
                                            className="crm-input md:col-span-2"
                                            placeholder="Support board URL (e.g. https://chat.cloud.board.support/...)"
                                        />
                                        <input
                                            value={editor.wp_api_user}
                                            onChange={(event) => setEditor((current) => ({ ...current, wp_api_user: event.target.value }))}
                                            className="crm-input"
                                            placeholder="WordPress API user"
                                        />
                                        <input
                                            value={editor.wp_api_password}
                                            onChange={(event) => setEditor((current) => ({ ...current, wp_api_password: event.target.value }))}
                                            className="crm-input"
                                            placeholder="WordPress API password (leave blank to keep)"
                                            type="password"
                                        />
                                        <label className="md:col-span-2 flex items-center gap-2 text-sm text-slate-700">
                                            <input
                                                type="checkbox"
                                                checked={editor.is_active}
                                                onChange={(event) => setEditor((current) => ({ ...current, is_active: event.target.checked }))}
                                                disabled={!selectedPackagesReady && !editor.is_active}
                                                className="h-4 w-4 rounded border-slate-300 text-teal-700 focus:ring-teal-200"
                                            />
                                            Market is active
                                        </label>
                                        {!selectedPackagesReady ? (
                                            <p className="md:col-span-2 text-xs text-amber-700">
                                                Package setup is incomplete. Configure at least one active package with pricing before activating this market.
                                            </p>
                                        ) : null}
                                    </div>

                                    <div className="mt-3 flex justify-end">
                                        <button
                                            type="button"
                                            onClick={() => {
                                                const payload = {
                                                    ...editor,
                                                    reason: 'Integration profile update from settings workspace',
                                                };

                                                if (!payload.wp_api_password?.trim()) {
                                                    delete payload.wp_api_password;
                                                }

                                                updatePlatformMutation.mutate({
                                                    platformId: selectedPlatform.platform_id,
                                                    payload,
                                                });
                                            }}
                                            disabled={updatePlatformMutation.isPending || !editor.name.trim() || !editor.domain.trim() || !editor.country.trim()}
                                            className="crm-btn-primary disabled:cursor-not-allowed disabled:opacity-60"
                                        >
                                            {updatePlatformMutation.isPending ? 'Saving...' : 'Save profile'}
                                        </button>
                                    </div>
                                </section>

                                <section id="market-package-editor" className="rounded-lg border border-slate-200 bg-white p-3">
                                    <div className="flex flex-wrap items-center justify-between gap-2">
                                        <div>
                                            <h4 className="text-sm font-semibold text-slate-900">Market Packages</h4>
                                            <p className="text-xs text-slate-500">
                                                Configure packages and duration pricing for this market. Currency:
                                                {' '}
                                                <span className="font-semibold text-slate-700">{selectedPackageSetup?.currency || selectedPlatform.currency || 'KES'}</span>.
                                            </p>
                                        </div>
                                        <span className={`inline-flex items-center rounded-md px-2 py-0.5 text-xs font-medium ring-1 ring-inset ${
                                            selectedPackageSetup?.can_go_live
                                                ? 'bg-emerald-50 text-emerald-700 ring-emerald-200'
                                                : 'bg-amber-50 text-amber-700 ring-amber-200'
                                        }`}>
                                            {selectedPackageSetup?.can_go_live ? 'Package setup complete' : 'Package setup incomplete'}
                                        </span>
                                    </div>

                                    {!selectedPackageSetup?.can_go_live ? (
                                        <div className="mt-3 rounded-md border border-amber-200 bg-amber-50 px-3 py-2 text-xs text-amber-800">
                                            <p className="font-semibold">Configure at least one active package with a priced duration to activate this market.</p>
                                        </div>
                                    ) : null}

                                    <div className="mt-3 space-y-3">
                                        {(packageEditor?.rows || []).map((row, rowIndex) => (
                                            <div key={row.id || `new-${rowIndex}`} className={`rounded-md border p-3 ${row.is_active ? 'border-slate-200 bg-white' : 'border-slate-100 bg-slate-50'}`}>
                                                <div className="flex flex-wrap items-center gap-2">
                                                    <input
                                                        type="text"
                                                        value={row.name}
                                                        onChange={(e) => updatePackageRow(rowIndex, 'name', e.target.value.toUpperCase())}
                                                        placeholder="PACKAGE NAME"
                                                        className="crm-input w-40 font-semibold uppercase"
                                                    />
                                                    <input
                                                        type="text"
                                                        value={row.display_name}
                                                        onChange={(e) => updatePackageRow(rowIndex, 'display_name', e.target.value)}
                                                        placeholder="Display name"
                                                        className="crm-input w-40"
                                                    />
                                                    <select
                                                        value={row.tier}
                                                        onChange={(e) => updatePackageRow(rowIndex, 'tier', e.target.value)}
                                                        className="crm-select w-28"
                                                    >
                                                        <option value="basic">Basic</option>
                                                        <option value="premium">Premium</option>
                                                        <option value="vip">VIP</option>
                                                        <option value="vvip">VVIP</option>
                                                        <option value="custom">Custom</option>
                                                    </select>
                                                    <label className="inline-flex items-center gap-1.5 text-xs text-slate-600">
                                                        <input
                                                            type="checkbox"
                                                            checked={row.is_active}
                                                            onChange={(e) => updatePackageRow(rowIndex, 'is_active', e.target.checked)}
                                                            className="h-3.5 w-3.5 rounded border-slate-300 text-teal-700 focus:ring-teal-200"
                                                        />
                                                        Active
                                                    </label>
                                                    <button
                                                        type="button"
                                                        onClick={() => removePackageRow(rowIndex)}
                                                        className="ml-auto text-xs text-rose-500 hover:text-rose-700"
                                                        title="Remove package"
                                                    >
                                                        Remove
                                                    </button>
                                                </div>

                                                <div className="mt-2">
                                                    <table className="w-full text-xs">
                                                        <thead>
                                                            <tr className="text-left text-slate-400 uppercase tracking-wider">
                                                                <th className="px-1 py-1 font-medium">Duration</th>
                                                                <th className="px-1 py-1 font-medium">Days</th>
                                                                <th className="px-1 py-1 font-medium">Price ({selectedPackageSetup?.currency || 'KES'})</th>
                                                                <th className="px-1 py-1 font-medium">On</th>
                                                                <th className="px-1 py-1 font-medium"></th>
                                                            </tr>
                                                        </thead>
                                                        <tbody>
                                                            {row.prices.map((price, priceIndex) => (
                                                                <tr key={price.id || `price-${priceIndex}`}>
                                                                    <td className="px-1 py-1">
                                                                        <select
                                                                            value={price.duration_key}
                                                                            onChange={(e) => updatePriceRow(rowIndex, priceIndex, 'duration_key', e.target.value)}
                                                                            className="crm-select w-full text-xs"
                                                                        >
                                                                            <option value="">Select...</option>
                                                                            {defaultDurationOptions.map((d) => (
                                                                                <option key={d.key} value={d.key}>{d.label}</option>
                                                                            ))}
                                                                        </select>
                                                                    </td>
                                                                    <td className="px-1 py-1">
                                                                        <input
                                                                            type="number"
                                                                            min="1"
                                                                            max="365"
                                                                            value={price.duration_days}
                                                                            onChange={(e) => updatePriceRow(rowIndex, priceIndex, 'duration_days', Number(e.target.value || 30))}
                                                                            className="crm-input w-16 text-xs"
                                                                        />
                                                                    </td>
                                                                    <td className="px-1 py-1">
                                                                        <input
                                                                            type="number"
                                                                            min="0"
                                                                            step="1"
                                                                            value={price.price}
                                                                            onChange={(e) => updatePriceRow(rowIndex, priceIndex, 'price', e.target.value)}
                                                                            className="crm-input w-24 text-xs"
                                                                        />
                                                                    </td>
                                                                    <td className="px-1 py-1">
                                                                        <input
                                                                            type="checkbox"
                                                                            checked={price.is_active}
                                                                            onChange={(e) => updatePriceRow(rowIndex, priceIndex, 'is_active', e.target.checked)}
                                                                            className="h-3.5 w-3.5 rounded border-slate-300 text-teal-700 focus:ring-teal-200"
                                                                        />
                                                                    </td>
                                                                    <td className="px-1 py-1">
                                                                        <button
                                                                            type="button"
                                                                            onClick={() => removePriceRow(rowIndex, priceIndex)}
                                                                            className="text-rose-400 hover:text-rose-600"
                                                                            title="Remove duration"
                                                                        >
                                                                            x
                                                                        </button>
                                                                    </td>
                                                                </tr>
                                                            ))}
                                                        </tbody>
                                                    </table>
                                                    <button
                                                        type="button"
                                                        onClick={() => addPriceRow(rowIndex)}
                                                        className="mt-1 text-xs text-teal-600 hover:text-teal-800"
                                                    >
                                                        + Add duration
                                                    </button>
                                                </div>
                                            </div>
                                        ))}
                                    </div>

                                    <button
                                        type="button"
                                        onClick={addPackageRow}
                                        className="mt-3 w-full rounded-md border border-dashed border-slate-300 py-2 text-xs text-slate-500 hover:border-teal-300 hover:text-teal-700"
                                    >
                                        + Add package
                                    </button>

                                    <div className="mt-3 grid gap-3 md:grid-cols-[1fr_auto]">
                                        <input
                                            value={packageEditor?.reason || ''}
                                            onChange={(event) => setPackageEditor((current) => (current ? { ...current, reason: event.target.value } : current))}
                                            className="crm-input"
                                            placeholder="Reason for package catalog update"
                                        />
                                        <button
                                            type="button"
                                            onClick={savePackageCatalog}
                                            disabled={savePackageCatalogMutation.isPending || !packageEditor?.reason?.trim()}
                                            className="crm-btn-primary disabled:cursor-not-allowed disabled:opacity-60"
                                        >
                                            {savePackageCatalogMutation.isPending ? 'Saving...' : 'Save packages'}
                                        </button>
                                    </div>
                                </section>

                                <section className="rounded-lg border border-slate-200 bg-white p-3">
                                    <h4 className="text-sm font-semibold text-slate-900">Payment Link Providers</h4>
                                    <p className="mt-1 text-xs text-slate-500">Provider routing is managed in the dedicated Payment Links workspace for faster access from operations.</p>
                                    <div className="mt-3 flex flex-wrap items-center justify-between gap-2">
                                        <p className="text-xs text-slate-500">Selected market: <span className="font-semibold text-slate-700">{selectedPlatform.platform_name}</span></p>
                                        <button
                                            type="button"
                                            onClick={() => setIntegrationArea('payment_links')}
                                            className="crm-btn-secondary px-3 py-2"
                                        >
                                            Open payment links workspace
                                        </button>
                                    </div>
                                </section>

                                <section className="rounded-lg border border-slate-200 bg-white p-3">
                                    <div className="flex flex-wrap items-center justify-between gap-2">
                                        <div>
                                            <h4 className="text-sm font-semibold text-slate-900">Connection Health</h4>
                                            <p className="text-xs text-slate-500">Last checked: {formatDateTime(selectedPlatform.wp_sync?.last_checked_at)}</p>
                                        </div>
                                        <span className={`inline-flex items-center rounded-md px-2 py-0.5 text-xs font-medium ring-1 ring-inset ${statusChip(selectedPlatform.wp_sync?.status || 'pending')}`}>
                                            {(selectedPlatform.wp_sync?.status || 'pending').replaceAll('_', ' ')}
                                        </span>
                                    </div>
                                    {selectedPlatform.wp_sync?.last_error ? (
                                        <p className="mt-2 rounded-md border border-amber-200 bg-amber-50 px-2 py-1 text-xs text-amber-800">
                                            {selectedPlatform.wp_sync.last_error}
                                        </p>
                                    ) : null}
                                    <div className="mt-3 flex flex-wrap items-center gap-2">
                                        <input
                                            value={testReason}
                                            onChange={(event) => setTestReason(event.target.value)}
                                            className="crm-input min-w-[260px] flex-1"
                                            placeholder="Reason for connection test"
                                        />
                                        <button
                                            type="button"
                                            onClick={() => testConnectionMutation.mutate({
                                                platformId: selectedPlatform.platform_id,
                                                payload: { reason: testReason },
                                            })}
                                            disabled={!selectedHasCredentials || testConnectionMutation.isPending || !testReason.trim()}
                                            className="crm-btn-secondary disabled:cursor-not-allowed disabled:opacity-60"
                                        >
                                            {testConnectionMutation.isPending ? 'Testing...' : 'Test connection'}
                                        </button>
                                    </div>
                                    {!selectedHasCredentials ? (
                                        <p className="mt-2 text-xs text-amber-700">Add WordPress credentials to enable connection tests.</p>
                                    ) : null}
                                </section>

                                <section className="rounded-lg border border-slate-200 bg-white p-3">
                                    <h4 className="text-sm font-semibold text-slate-900">Manual Sync</h4>
                                    <p className="text-xs text-slate-500">Run scoped sync jobs without leaving settings.</p>
                                    {showInitialFullSyncCta ? (
                                        <div className="mt-3 rounded-md border border-teal-200 bg-teal-50/70 p-3">
                                            <p className="text-xs font-semibold text-teal-800">New market onboarding</p>
                                            <p className="mt-1 text-xs text-teal-700">Recommended first step: run a full clients sync to import all profiles before sales starts working this market.</p>
                                            <button
                                                type="button"
                                                onClick={openInitialFullSync}
                                                disabled={runSyncMutation.isPending}
                                                className="mt-2 rounded-md bg-teal-700 px-3 py-1.5 text-xs font-semibold text-white transition hover:bg-teal-800 disabled:cursor-not-allowed disabled:opacity-60"
                                            >
                                                Run initial full sync
                                            </button>
                                        </div>
                                    ) : null}
                                    <div className="mt-3 grid gap-3 md:grid-cols-2">
                                        <select
                                            value={syncForm.scope}
                                            onChange={(event) => setSyncForm((current) => ({ ...current, scope: event.target.value }))}
                                            className="crm-select"
                                        >
                                            <option value="leads">Leads only</option>
                                            <option value="clients">Clients only</option>
                                            <option value="all">Clients + leads</option>
                                        </select>
                                        <select
                                            value={syncForm.mode}
                                            onChange={(event) => setSyncForm((current) => ({ ...current, mode: event.target.value }))}
                                            className="crm-select"
                                            disabled={syncForm.scope === 'leads'}
                                        >
                                            <option value="delta">Delta</option>
                                            <option value="full">Full</option>
                                        </select>
                                        <label className="flex items-center gap-2 text-sm text-slate-700">
                                            <input
                                                type="checkbox"
                                                checked={syncForm.dry_run}
                                                onChange={(event) => setSyncForm((current) => ({ ...current, dry_run: event.target.checked }))}
                                                className="h-4 w-4 rounded border-slate-300 text-teal-700 focus:ring-teal-200"
                                            />
                                            Dry run
                                        </label>
                                        <input
                                            type="number"
                                            min="20"
                                            max="200"
                                            value={syncForm.per_page}
                                            onChange={(event) => setSyncForm((current) => ({ ...current, per_page: Number(event.target.value || 100) }))}
                                            className="crm-input"
                                            placeholder="Per page"
                                        />
                                        <textarea
                                            value={syncForm.reason}
                                            onChange={(event) => setSyncForm((current) => ({ ...current, reason: event.target.value }))}
                                            className="crm-input md:col-span-2"
                                            rows={2}
                                            placeholder="Reason for manual sync"
                                        />
                                    </div>
                                    <div className="mt-3 flex justify-end">
                                        <button
                                            type="button"
                                            onClick={() => setSyncConfirmOpen(true)}
                                            disabled={runSyncMutation.isPending || !selectedHasCredentials || !syncForm.reason.trim()}
                                            className="crm-btn-primary disabled:cursor-not-allowed disabled:opacity-60"
                                        >
                                            {runSyncMutation.isPending ? 'Running...' : 'Run sync'}
                                        </button>
                                    </div>
                                    {latestSyncResult ? (
                                        <div className="mt-3 rounded-md border border-slate-200 bg-slate-50 p-2 text-xs text-slate-700">
                                            <p className="font-semibold text-slate-800">Latest sync summary</p>
                                            <p className="mt-1">Scope: {latestSyncResult.scope || selectedPlatform.sync?.last_scope || 'unknown'} • Dry run: {latestSyncResult.dry_run ? 'yes' : 'no'}</p>
                                            {latestSyncResult.clients ? (
                                                <p>Clients: {latestSyncResult.clients.created || 0} created, {latestSyncResult.clients.updated || 0} updated</p>
                                            ) : null}
                                            {latestSyncResult.leads ? (
                                                <p>Leads: {latestSyncResult.leads.created || 0} created, {latestSyncResult.leads.updated || 0} updated, {latestSyncResult.leads.errors?.length || 0} errors</p>
                                            ) : null}
                                        </div>
                                    ) : null}
                                </section>
                            </div>
                        )}
                    </div>
                </div>
                </section>
            ) : null}

            {integrationArea === 'payment_links' ? (
                <section className="crm-surface overflow-hidden">
                <header className="crm-panel-header">
                    <div>
                        <h3 className="crm-panel-title">Payment Link Provider Routing</h3>
                        <p className="crm-panel-subtitle">Configure provider-level payment URLs used by the Payments queue "Send link" action.</p>
                    </div>
                    <button
                        type="button"
                        onClick={() => queryClient.invalidateQueries({ queryKey: ['settings-integrations'] })}
                        className="crm-btn-secondary px-3 py-2"
                    >
                        Refresh
                    </button>
                </header>

                <div className="space-y-4 p-4">
                    <section className="rounded-lg border border-slate-200 bg-slate-50 p-3">
                        <label className="mb-1 block text-sm font-medium text-slate-700">Market</label>
                        <select
                            value={selectedPlatformId || ''}
                            onChange={(event) => setSelectedPlatformId(Number(event.target.value) || null)}
                            className="crm-select max-w-xl"
                        >
                            {platformRows.map((platform) => (
                                <option key={platform.platform_id} value={platform.platform_id}>
                                    {platform.platform_name} ({platform.country || '—'})
                                </option>
                            ))}
                        </select>
                        <p className="mt-2 text-xs text-slate-500">Active provider is used by default when operators send payment links from the Payments workspace. Only enabled providers can be selected as active.</p>
                        {paymentLinkReadOnly ? (
                            <p className="mt-2 rounded-md border border-amber-200 bg-amber-50 px-2 py-1 text-xs text-amber-800">
                                Read-only access: only admin and sub-admin roles can update payment link provider settings.
                            </p>
                        ) : null}
                    </section>

                    {!selectedPlatform ? (
                        <p className="rounded-md border border-dashed border-slate-300 bg-slate-50 px-3 py-6 text-sm text-slate-500">
                            Select a market to edit payment link provider routing.
                        </p>
                    ) : (
                        <section className="rounded-lg border border-slate-200 bg-white p-3">
                            <h4 className="text-sm font-semibold text-slate-900">Providers</h4>
                            <p className="mt-1 text-xs text-slate-500">Add one or more providers, choose between direct URLs and CRM proxy checkout, and keep an audit reason for every change.</p>

                            <fieldset disabled={paymentLinkReadOnly || savePaymentLinkProvidersMutation.isPending} className={`${paymentLinkReadOnly ? 'opacity-70' : ''}`}>
                                <div className="mt-3 space-y-3">
                                    {paymentLinkForm.providers.map((provider, index) => (
                                        <div key={`provider-${index}`} className="rounded-md border border-slate-200 bg-slate-50 p-3">
                                            <div className="grid gap-2 md:grid-cols-2">
                                                <input
                                                    value={provider.key}
                                                    onChange={(event) => updatePaymentLinkProvider(index, 'key', event.target.value.toLowerCase().replace(/[^a-z0-9_-]/g, ''))}
                                                    className="crm-input"
                                                    placeholder="Provider key (e.g. pesapal)"
                                                />
                                                <input
                                                    value={provider.label}
                                                    onChange={(event) => updatePaymentLinkProvider(index, 'label', event.target.value)}
                                                    className="crm-input"
                                                    placeholder="Provider label"
                                                />
                                                <select
                                                    value={provider.mode || 'static_url'}
                                                    onChange={(event) => updatePaymentLinkProvider(index, 'mode', event.target.value)}
                                                    className="crm-select"
                                                >
                                                    {paymentLinkModeOptions.map((option) => (
                                                        <option key={option.value} value={option.value}>
                                                            {option.label}
                                                        </option>
                                                    ))}
                                                </select>
                                                <label className="flex items-center gap-2 rounded-md border border-slate-200 bg-white px-3 py-2 text-sm text-slate-700">
                                                    <input
                                                        type="checkbox"
                                                        checked={Boolean(provider.enabled)}
                                                        onChange={(event) => updatePaymentLinkProvider(index, 'enabled', event.target.checked)}
                                                        className="h-4 w-4 rounded border-slate-300 text-teal-700 focus:ring-teal-200"
                                                    />
                                                    Enabled for operator use
                                                </label>
                                                {provider.mode === 'proxy_hosted_checkout' ? (
                                                    <>
                                                        <select
                                                            value={provider.wallet_provider_key || 'paystack'}
                                                            onChange={(event) => updatePaymentLinkProvider(index, 'wallet_provider_key', event.target.value)}
                                                            className="crm-select"
                                                        >
                                                            {walletProviderKeys
                                                                .filter((providerKey) => paymentLinkProxyWalletProviders.includes(providerKey))
                                                                .map((providerKey) => (
                                                                    <option key={providerKey} value={providerKey}>
                                                                        {walletProviderLabel(providerKey)}
                                                                    </option>
                                                                ))}
                                                        </select>
                                                        <select
                                                            value={provider.environment || 'sandbox'}
                                                            onChange={(event) => updatePaymentLinkProvider(index, 'environment', event.target.value)}
                                                            className="crm-select"
                                                        >
                                                            {walletEnvironmentOptions.map((environment) => (
                                                                <option key={environment} value={environment}>
                                                                    {environment}
                                                                </option>
                                                            ))}
                                                        </select>
                                                        {(() => {
                                                            const readiness = paymentLinkReadinessState(provider, selectedPlatform, walletSystemConfig);

                                                            return readiness ? (
                                                                <div className={`md:col-span-2 rounded-md border px-3 py-2 text-xs ${paymentLinkReadinessClasses(readiness.tone)}`}>
                                                                    <p className="font-semibold">{readiness.label}</p>
                                                                    <p className="mt-1">{readiness.detail}</p>
                                                                </div>
                                                            ) : null;
                                                        })()}
                                                        <p className="md:col-span-2 text-xs text-slate-500">
                                                            CRM proxy checkout will generate a CRM-owned link and hand off to the configured wallet provider in the selected environment.
                                                        </p>
                                                    </>
                                                ) : (
                                                    <>
                                                        <input
                                                            value={provider.url}
                                                            onChange={(event) => updatePaymentLinkProvider(index, 'url', event.target.value)}
                                                            className="crm-input md:col-span-2"
                                                            placeholder="Direct URL (optional)"
                                                        />
                                                        <input
                                                            value={provider.base_url}
                                                            onChange={(event) => updatePaymentLinkProvider(index, 'base_url', event.target.value)}
                                                            className="crm-input"
                                                            placeholder="Base URL"
                                                        />
                                                        <input
                                                            value={provider.path}
                                                            onChange={(event) => updatePaymentLinkProvider(index, 'path', event.target.value)}
                                                            className="crm-input"
                                                            placeholder="Path (e.g. /pay)"
                                                        />
                                                        <p className="md:col-span-2 text-xs text-slate-500">
                                                            Static URL providers send operators directly to the configured market payment page.
                                                        </p>
                                                    </>
                                                )}
                                            </div>
                                            <div className="mt-2 flex justify-end">
                                                <button
                                                    type="button"
                                                    onClick={() => removePaymentLinkProvider(index)}
                                                    disabled={paymentLinkForm.providers.length <= 1 || paymentLinkReadOnly}
                                                    className="rounded-md border border-rose-200 bg-rose-50 px-2.5 py-1 text-xs font-semibold text-rose-700 transition hover:bg-rose-100 disabled:cursor-not-allowed disabled:opacity-50"
                                                >
                                                    Remove
                                                </button>
                                            </div>
                                        </div>
                                    ))}
                                </div>

                                <div className="mt-3 grid gap-2 md:grid-cols-2">
                                    <div>
                                        <label className="mb-1 block text-sm font-medium text-slate-700">Active provider</label>
                                        <select
                                            value={paymentLinkForm.active_provider}
                                            onChange={(event) => setPaymentLinkForm((current) => ({ ...current, active_provider: event.target.value }))}
                                            className="crm-select"
                                            disabled={enabledPaymentLinkProviders.length === 0}
                                        >
                                            {enabledPaymentLinkProviders.length === 0 ? (
                                                <option value="">No enabled providers</option>
                                            ) : enabledPaymentLinkProviders.map((provider) => (
                                                <option key={provider.key} value={provider.key}>
                                                    {paymentLinkProviderOptionLabel(provider)}
                                                </option>
                                            ))}
                                        </select>
                                        {enabledPaymentLinkProviders.length === 0 ? (
                                            <p className="mt-1 text-xs text-amber-700">Enable at least one provider to make payment-link routing available.</p>
                                        ) : null}
                                    </div>
                                    <div>
                                        <label className="mb-1 block text-sm font-medium text-slate-700">Audit reason</label>
                                        <input
                                            value={paymentLinkForm.reason}
                                            onChange={(event) => setPaymentLinkForm((current) => ({ ...current, reason: event.target.value }))}
                                            className="crm-input"
                                            placeholder="Reason for payment link config update"
                                        />
                                    </div>
                                </div>
                            </fieldset>

                            <div className="mt-3 flex flex-wrap items-center justify-between gap-2">
                                <button
                                    type="button"
                                    onClick={addPaymentLinkProvider}
                                    disabled={paymentLinkReadOnly}
                                    className="crm-btn-secondary px-3 py-2 disabled:cursor-not-allowed disabled:opacity-60"
                                >
                                    Add provider
                                </button>
                                <button
                                    type="button"
                                    onClick={savePaymentLinkProviders}
                                    disabled={paymentLinkReadOnly || savePaymentLinkProvidersMutation.isPending || !paymentLinkForm.reason.trim()}
                                    className="crm-btn-primary disabled:cursor-not-allowed disabled:opacity-60"
                                >
                                    {savePaymentLinkProvidersMutation.isPending ? 'Saving...' : 'Save payment link providers'}
                                </button>
                            </div>
                        </section>
                    )}
                </div>
                </section>
            ) : null}

            {integrationArea === 'scraper' ? (
                <section className="crm-surface overflow-hidden">
                <header className="crm-panel-header">
                    <div>
                        <h3 className="crm-panel-title">Scraper Configuration</h3>
                        <p className="crm-panel-subtitle">Configure compliant scrape sources, preview with dry-run, then import leads into the pipeline.</p>
                    </div>
                    <button
                        type="button"
                        onClick={() => setScraperCreateOpen(true)}
                        className="crm-btn-primary px-3 py-2"
                    >
                        Add scraper source
                    </button>
                </header>

                <div className="grid gap-4 p-4 xl:grid-cols-12">
                    <div className="space-y-3 xl:col-span-4">
                        <div className="grid gap-2 sm:grid-cols-3 xl:grid-cols-1">
                            <div className="rounded-md border border-slate-200 bg-slate-50 px-3 py-2">
                                <p className="text-[11px] font-semibold uppercase tracking-[0.1em] text-slate-500">Configured</p>
                                <p className="mt-1 text-lg font-semibold text-slate-900">{scraperSources.length}</p>
                            </div>
                            <div className="rounded-md border border-emerald-200 bg-emerald-50 px-3 py-2">
                                <p className="text-[11px] font-semibold uppercase tracking-[0.1em] text-emerald-700">Active</p>
                                <p className="mt-1 text-lg font-semibold text-emerald-800">{activeScraperSources}</p>
                            </div>
                            <div className="rounded-md border border-amber-200 bg-amber-50 px-3 py-2">
                                <p className="text-[11px] font-semibold uppercase tracking-[0.1em] text-amber-700">Needs review</p>
                                <p className="mt-1 text-lg font-semibold text-amber-800">{scraperBlockedOrFailed}</p>
                            </div>
                        </div>

                        <section className="rounded-lg border border-slate-200 bg-white">
                            <div className="border-b border-slate-100 px-3 py-2">
                                <p className="text-sm font-semibold text-slate-900">Sources</p>
                                <p className="text-xs text-slate-500">Select a source to edit parser and compliance settings.</p>
                            </div>
                            <div className="max-h-72 space-y-2 overflow-auto p-2">
                                {scraperSources.length === 0 ? (
                                    <p className="rounded-md border border-dashed border-slate-200 bg-slate-50 px-3 py-3 text-xs text-slate-500">
                                        No scraper sources yet. Add a source to start dry-run validation.
                                    </p>
                                ) : scraperSources.map((source) => (
                                    <button
                                        key={source.id}
                                        type="button"
                                        onClick={() => setSelectedScraperSourceId(source.id)}
                                        className={`w-full rounded-md border px-3 py-2 text-left transition ${
                                            selectedScraperSourceId === source.id
                                                ? 'border-teal-300 bg-teal-50'
                                                : 'border-slate-200 bg-white hover:border-slate-300'
                                        }`}
                                    >
                                        <div className="flex items-start justify-between gap-2">
                                            <div>
                                                <p className="text-sm font-semibold text-slate-900">{source.name}</p>
                                                <p className="text-[11px] text-slate-500">{source.platform_name}</p>
                                            </div>
                                            <span className={`inline-flex items-center rounded-md px-2 py-0.5 text-[11px] font-medium ring-1 ring-inset ${statusChip(source.last_run_status || 'unknown')}`}>
                                                {scraperStatusLabel(source.last_run_status)}
                                            </span>
                                        </div>
                                        <p className="mt-1 truncate text-[11px] text-slate-500">{source.source_url}</p>
                                    </button>
                                ))}
                            </div>
                        </section>

                        <section className="rounded-lg border border-slate-200 bg-white">
                            <div className="border-b border-slate-100 px-3 py-2">
                                <p className="text-sm font-semibold text-slate-900">Recent runs</p>
                            </div>
                            <div className="max-h-48 space-y-2 overflow-auto p-2">
                                {scraperRuns.length === 0 ? (
                                    <p className="text-xs text-slate-500">No scraper runs yet.</p>
                                ) : scraperRuns.map((run) => (
                                    <div key={run.id} className="rounded-md border border-slate-200 bg-slate-50 px-2 py-1.5">
                                        <p className="text-xs font-semibold text-slate-800">{run.source_name || `Source #${run.scraper_source_id}`}</p>
                                        <p className="text-[11px] text-slate-500">
                                            {run.mode.replace('_', ' ')} • {run.status} • {run.discovered_count} discovered • {run.created_count} created
                                        </p>
                                    </div>
                                ))}
                            </div>
                        </section>
                    </div>

                    <div className="space-y-3 xl:col-span-8">
                        {!selectedScraperSource || !scraperEditor ? (
                            <div className="rounded-lg border border-dashed border-slate-200 bg-slate-50 p-6 text-center text-sm text-slate-500">
                                Select a scraper source to manage parser, compliance, and run controls.
                            </div>
                        ) : (
                            <>
                                <section className="rounded-lg border border-slate-200 bg-white p-3">
                                    <h4 className="text-sm font-semibold text-slate-900">Source Profile</h4>
                                    <p className="mt-1 text-xs text-slate-500">Define extraction profile, schedule, dedupe strategy, and compliance guardrails.</p>
                                    <div className="mt-3 grid gap-3 md:grid-cols-2">
                                        <input
                                            value={scraperEditor.name}
                                            onChange={(event) => setScraperEditor((current) => ({ ...current, name: event.target.value }))}
                                            className="crm-input"
                                            placeholder="Source name"
                                        />
                                        <select
                                            value={scraperEditor.platform_id}
                                            onChange={(event) => setScraperEditor((current) => ({ ...current, platform_id: event.target.value }))}
                                            className="crm-select"
                                            disabled
                                        >
                                            {platformRows.map((platform) => (
                                                <option key={platform.platform_id} value={platform.platform_id}>{platform.platform_name}</option>
                                            ))}
                                        </select>
                                        <input
                                            value={scraperEditor.source_url}
                                            onChange={(event) => setScraperEditor((current) => ({ ...current, source_url: event.target.value }))}
                                            className="crm-input md:col-span-2"
                                            placeholder="https://example.com/listings"
                                        />
                                        <select
                                            value={scraperEditor.parser_profile}
                                            onChange={(event) => setScraperEditor((current) => ({ ...current, parser_profile: event.target.value }))}
                                            className="crm-select"
                                        >
                                            {scraperProfiles.map((profile) => (
                                                <option key={profile} value={profile}>{scraperProfileLabel(profile)}</option>
                                            ))}
                                        </select>
                                        <select
                                            value={scraperEditor.fetch_schedule}
                                            onChange={(event) => setScraperEditor((current) => ({ ...current, fetch_schedule: event.target.value }))}
                                            className="crm-select"
                                        >
                                            {scraperSchedules.map((schedule) => (
                                                <option key={schedule} value={schedule}>{scraperScheduleLabel(schedule)}</option>
                                            ))}
                                        </select>
                                        <select
                                            value={scraperEditor.dedupe_mode}
                                            onChange={(event) => setScraperEditor((current) => ({ ...current, dedupe_mode: event.target.value }))}
                                            className="crm-select md:col-span-2"
                                        >
                                            {scraperDedupeModes.map((mode) => (
                                                <option key={mode} value={mode}>{dedupeModeLabel(mode)}</option>
                                            ))}
                                        </select>
                                        <input
                                            value={selectedScraperRules.row_selector}
                                            onChange={(event) => setScraperEditor((current) => ({
                                                ...current,
                                                parser_rules: { ...current.parser_rules, row_selector: event.target.value },
                                            }))}
                                            className="crm-input"
                                            placeholder="Row selector (optional)"
                                        />
                                        <input
                                            value={selectedScraperRules.link_selector}
                                            onChange={(event) => setScraperEditor((current) => ({
                                                ...current,
                                                parser_rules: { ...current.parser_rules, link_selector: event.target.value },
                                            }))}
                                            className="crm-input"
                                            placeholder="Link selector (optional)"
                                        />
                                        <input
                                            value={selectedScraperRules.name_selector}
                                            onChange={(event) => setScraperEditor((current) => ({
                                                ...current,
                                                parser_rules: { ...current.parser_rules, name_selector: event.target.value },
                                            }))}
                                            className="crm-input"
                                            placeholder="Name selector (optional)"
                                        />
                                        <input
                                            value={selectedScraperRules.phone_selector}
                                            onChange={(event) => setScraperEditor((current) => ({
                                                ...current,
                                                parser_rules: { ...current.parser_rules, phone_selector: event.target.value },
                                            }))}
                                            className="crm-input"
                                            placeholder="Phone selector (optional)"
                                        />
                                        <input
                                            value={selectedScraperRules.email_selector}
                                            onChange={(event) => setScraperEditor((current) => ({
                                                ...current,
                                                parser_rules: { ...current.parser_rules, email_selector: event.target.value },
                                            }))}
                                            className="crm-input md:col-span-2"
                                            placeholder="Email selector (optional)"
                                        />

                                        <label className="flex items-center gap-2 text-sm text-slate-700">
                                            <input
                                                type="checkbox"
                                                checked={Boolean(scraperEditor.is_active)}
                                                onChange={(event) => setScraperEditor((current) => ({ ...current, is_active: event.target.checked }))}
                                                className="h-4 w-4 rounded border-slate-300 text-teal-700 focus:ring-teal-200"
                                            />
                                            Source is active
                                        </label>
                                        <label className="flex items-center gap-2 text-sm text-slate-700">
                                            <input
                                                type="checkbox"
                                                checked={Boolean(scraperEditor.compliance_ack_robots)}
                                                onChange={(event) => setScraperEditor((current) => ({ ...current, compliance_ack_robots: event.target.checked }))}
                                                className="h-4 w-4 rounded border-slate-300 text-teal-700 focus:ring-teal-200"
                                            />
                                            Robots policy reviewed
                                        </label>
                                        <label className="md:col-span-2 flex items-center gap-2 text-sm text-slate-700">
                                            <input
                                                type="checkbox"
                                                checked={Boolean(scraperEditor.compliance_ack_tos)}
                                                onChange={(event) => setScraperEditor((current) => ({ ...current, compliance_ack_tos: event.target.checked }))}
                                                className="h-4 w-4 rounded border-slate-300 text-teal-700 focus:ring-teal-200"
                                            />
                                            Terms and source usage reviewed
                                        </label>
                                        <textarea
                                            rows={2}
                                            value={scraperEditor.compliance_notes}
                                            onChange={(event) => setScraperEditor((current) => ({ ...current, compliance_notes: event.target.value }))}
                                            className="crm-input md:col-span-2"
                                            placeholder="Compliance notes (optional)"
                                        />
                                        <textarea
                                            rows={2}
                                            value={scraperEditor.reason}
                                            onChange={(event) => setScraperEditor((current) => ({ ...current, reason: event.target.value }))}
                                            className="crm-input md:col-span-2"
                                            placeholder="Reason for profile update"
                                        />
                                    </div>
                                    <div className="mt-3 flex justify-end">
                                        <button
                                            type="button"
                                            onClick={() => updateScraperSourceMutation.mutate({
                                                sourceId: selectedScraperSource.id,
                                                payload: {
                                                    name: scraperEditor.name,
                                                    source_url: scraperEditor.source_url,
                                                    parser_profile: scraperEditor.parser_profile,
                                                    fetch_schedule: scraperEditor.fetch_schedule,
                                                    dedupe_mode: scraperEditor.dedupe_mode,
                                                    parser_rules: scraperEditor.parser_rules,
                                                    is_active: scraperEditor.is_active,
                                                    compliance_ack_robots: scraperEditor.compliance_ack_robots,
                                                    compliance_ack_tos: scraperEditor.compliance_ack_tos,
                                                    compliance_notes: scraperEditor.compliance_notes,
                                                    reason: scraperEditor.reason,
                                                },
                                            })}
                                            disabled={
                                                updateScraperSourceMutation.isPending
                                                || !scraperEditor.name.trim()
                                                || !scraperEditor.source_url.trim()
                                                || !scraperEditor.reason.trim()
                                            }
                                            className="crm-btn-primary disabled:cursor-not-allowed disabled:opacity-60"
                                        >
                                            {updateScraperSourceMutation.isPending ? 'Saving...' : 'Save scraper source'}
                                        </button>
                                    </div>
                                </section>

                                <section className="rounded-lg border border-slate-200 bg-white p-3">
                                    <h4 className="text-sm font-semibold text-slate-900">Manual Run</h4>
                                    <p className="mt-1 text-xs text-slate-500">Run a controlled scrape now. Dry-run previews candidates without creating leads.</p>
                                    <div className="mt-3 grid gap-3 md:grid-cols-2">
                                        <label className="flex items-center gap-2 text-sm text-slate-700">
                                            <input
                                                type="checkbox"
                                                checked={Boolean(scraperRunForm.dry_run)}
                                                onChange={(event) => setScraperRunForm((current) => ({
                                                    ...current,
                                                    dry_run: event.target.checked,
                                                    reason: event.target.checked
                                                        ? 'Dry-run scraper execution from settings'
                                                        : 'Scraper import run from settings',
                                                }))}
                                                className="h-4 w-4 rounded border-slate-300 text-teal-700 focus:ring-teal-200"
                                            />
                                            Dry run preview
                                        </label>
                                        <input
                                            type="number"
                                            min="1"
                                            max="250"
                                            value={scraperRunForm.max_candidates}
                                            onChange={(event) => setScraperRunForm((current) => ({ ...current, max_candidates: Number(event.target.value || 50) }))}
                                            className="crm-input"
                                            placeholder="Max candidates"
                                        />
                                        <textarea
                                            rows={2}
                                            value={scraperRunForm.reason}
                                            onChange={(event) => setScraperRunForm((current) => ({ ...current, reason: event.target.value }))}
                                            className="crm-input md:col-span-2"
                                            placeholder="Reason for scraper run"
                                        />
                                    </div>

                                    {!selectedScraperCompliant ? (
                                        <p className="mt-2 rounded-md border border-amber-200 bg-amber-50 px-2 py-1 text-xs text-amber-700">
                                            Robots and terms acknowledgements are required before runs can proceed.
                                        </p>
                                    ) : null}

                                    <div className="mt-3 flex justify-end">
                                        <button
                                            type="button"
                                            onClick={() => setScraperRunConfirmOpen(true)}
                                            disabled={runScraperSourceMutation.isPending || !scraperRunForm.reason.trim() || !selectedScraperSource}
                                            className="crm-btn-secondary disabled:cursor-not-allowed disabled:opacity-60"
                                        >
                                            {runScraperSourceMutation.isPending ? 'Running...' : 'Run scraper now'}
                                        </button>
                                    </div>

                                    {latestScraperRunResult ? (
                                        <div className="mt-3 rounded-md border border-slate-200 bg-slate-50 p-2 text-xs text-slate-700">
                                            <p className="font-semibold text-slate-800">Latest run summary</p>
                                            <p className="mt-1">
                                                Status: <span className="font-semibold">{scraperStatusLabel(latestScraperRunResult.status)}</span> •
                                                Discovered: <span className="font-semibold">{latestScraperRunResult.discovered || 0}</span> •
                                                Created: <span className="font-semibold">{latestScraperRunResult.created || 0}</span> •
                                                Duplicates: <span className="font-semibold">{latestScraperRunResult.duplicates || 0}</span>
                                            </p>
                                            {latestScraperRunResult.message ? (
                                                <p className="mt-1 text-slate-600">{latestScraperRunResult.message}</p>
                                            ) : null}
                                            {Array.isArray(latestScraperRunResult.errors) && latestScraperRunResult.errors.length > 0 ? (
                                                <div className="mt-2 rounded-md border border-amber-200 bg-white p-2">
                                                    <p className="font-semibold text-amber-800">Errors</p>
                                                    <ul className="mt-1 space-y-1">
                                                        {latestScraperRunResult.errors.slice(0, 3).map((error) => (
                                                            <li key={error} className="text-slate-700">{error}</li>
                                                        ))}
                                                    </ul>
                                                </div>
                                            ) : null}
                                            {Array.isArray(latestScraperRunResult.preview) && latestScraperRunResult.preview.length > 0 ? (
                                                <div className="mt-2 rounded-md border border-slate-200 bg-white p-2">
                                                    <p className="font-semibold text-slate-800">Candidate preview</p>
                                                    <div className="mt-1 space-y-1">
                                                        {latestScraperRunResult.preview.slice(0, 4).map((row, index) => (
                                                            <p key={`${row.source_url || row.name || 'row'}-${index}`} className="text-slate-700">
                                                                <span className="font-semibold text-slate-900">{row.name || 'Unnamed'}</span>
                                                                {row.phone_normalized ? ` • ${row.phone_normalized}` : ''}
                                                                {row.email ? ` • ${row.email}` : ''}
                                                                {row.result ? ` • ${row.result}` : ''}
                                                            </p>
                                                        ))}
                                                    </div>
                                                </div>
                                            ) : null}
                                        </div>
                                    ) : null}
                                </section>
                            </>
                        )}
                    </div>
                </div>
                </section>
            ) : null}

            {createOpen ? (
                <div className="fixed inset-0 z-50 flex items-center justify-center bg-slate-900/45 p-4" onClick={() => setCreateOpen(false)}>
                    <div className="w-full max-w-2xl rounded-lg border border-slate-200 bg-white shadow-xl" onClick={(event) => event.stopPropagation()}>
                        <header className="crm-panel-header">
                            <div>
                                <h3 className="crm-panel-title">Add Market Integration</h3>
                                <p className="crm-panel-subtitle">Create a new market profile with WordPress sync credentials.</p>
                            </div>
                        </header>
                        <div className="grid gap-3 p-4 md:grid-cols-2">
                            <input value={createForm.name} onChange={(event) => setCreateForm((current) => ({ ...current, name: event.target.value }))} className="crm-input" placeholder="Market name" />
                            <input value={createForm.domain} onChange={(event) => setCreateForm((current) => ({ ...current, domain: event.target.value }))} className="crm-input" placeholder="Domain" />
                            <input value={createForm.country} onChange={(event) => setCreateForm((current) => ({ ...current, country: event.target.value }))} className="crm-input" placeholder="Country" />
                            <input value={createForm.phone_prefix} onChange={(event) => setCreateForm((current) => ({ ...current, phone_prefix: event.target.value }))} className="crm-input" placeholder="Phone prefix" />
                            <input value={createForm.currency_code} onChange={(event) => setCreateForm((current) => ({ ...current, currency_code: event.target.value.toUpperCase() }))} className="crm-input" placeholder="Currency code" />
                            <input value={createForm.timezone} onChange={(event) => setCreateForm((current) => ({ ...current, timezone: event.target.value }))} className="crm-input" placeholder="Timezone" />
                            <input value={createForm.wp_api_url} onChange={(event) => setCreateForm((current) => ({ ...current, wp_api_url: event.target.value }))} className="crm-input md:col-span-2" placeholder="WordPress Sync API URL" />
                            <input value={createForm.support_chat_url} onChange={(event) => setCreateForm((current) => ({ ...current, support_chat_url: event.target.value }))} className="crm-input md:col-span-2" placeholder="Support board URL" />
                            <input value={createForm.wp_api_user} onChange={(event) => setCreateForm((current) => ({ ...current, wp_api_user: event.target.value }))} className="crm-input" placeholder="WordPress API user" />
                            <input value={createForm.wp_api_password} onChange={(event) => setCreateForm((current) => ({ ...current, wp_api_password: event.target.value }))} className="crm-input" type="password" placeholder="WordPress API password" />
                            <label className="md:col-span-2 flex items-center gap-2 text-sm text-slate-700">
                                <input type="checkbox" checked={createForm.is_active} onChange={(event) => setCreateForm((current) => ({ ...current, is_active: event.target.checked }))} className="h-4 w-4 rounded border-slate-300 text-teal-700 focus:ring-teal-200" />
                                Market is active
                            </label>
                            <p className="md:col-span-2 rounded-md border border-teal-200 bg-teal-50/70 px-3 py-2 text-xs text-teal-700">
                                Onboarding flow: create market, configure package pricing, activate market, then run initial full sync.
                            </p>
                        </div>
                        <footer className="flex items-center justify-end gap-2 border-t border-slate-100 p-4">
                            <button type="button" onClick={() => setCreateOpen(false)} className="crm-btn-secondary">Cancel</button>
                            <button
                                type="button"
                                onClick={() => createPlatformMutation.mutate({
                                    ...createForm,
                                    reason: 'Created from integrations workspace',
                                })}
                                disabled={createPlatformMutation.isPending || !createForm.name.trim() || !createForm.domain.trim() || !createForm.country.trim()}
                                className="crm-btn-primary disabled:cursor-not-allowed disabled:opacity-60"
                            >
                                {createPlatformMutation.isPending ? 'Creating...' : 'Create market'}
                            </button>
                        </footer>
                    </div>
                </div>
            ) : null}

            {scraperCreateOpen ? (
                <div className="fixed inset-0 z-50 flex items-center justify-center bg-slate-900/45 p-4" onClick={() => setScraperCreateOpen(false)}>
                    <div className="w-full max-w-2xl rounded-lg border border-slate-200 bg-white shadow-xl" onClick={(event) => event.stopPropagation()}>
                        <header className="crm-panel-header">
                            <div>
                                <h3 className="crm-panel-title">Add Scraper Source</h3>
                                <p className="crm-panel-subtitle">Create a source profile, confirm compliance, then validate with dry-run.</p>
                            </div>
                        </header>
                        <div className="grid gap-3 p-4 md:grid-cols-2">
                            <select
                                value={scraperCreateForm.platform_id}
                                onChange={(event) => setScraperCreateForm((current) => ({ ...current, platform_id: event.target.value }))}
                                className="crm-select"
                            >
                                <option value="">Select market</option>
                                {platformRows.map((platform) => (
                                    <option key={platform.platform_id} value={platform.platform_id}>{platform.platform_name}</option>
                                ))}
                            </select>
                            <input
                                value={scraperCreateForm.name}
                                onChange={(event) => setScraperCreateForm((current) => ({ ...current, name: event.target.value }))}
                                className="crm-input"
                                placeholder="Source name"
                            />
                            <input
                                value={scraperCreateForm.source_url}
                                onChange={(event) => setScraperCreateForm((current) => ({ ...current, source_url: event.target.value }))}
                                className="crm-input md:col-span-2"
                                placeholder="https://example.com/listings"
                            />
                            <select
                                value={scraperCreateForm.parser_profile}
                                onChange={(event) => setScraperCreateForm((current) => ({ ...current, parser_profile: event.target.value }))}
                                className="crm-select"
                            >
                                {scraperProfiles.map((profile) => (
                                    <option key={profile} value={profile}>{scraperProfileLabel(profile)}</option>
                                ))}
                            </select>
                            <select
                                value={scraperCreateForm.fetch_schedule}
                                onChange={(event) => setScraperCreateForm((current) => ({ ...current, fetch_schedule: event.target.value }))}
                                className="crm-select"
                            >
                                {scraperSchedules.map((schedule) => (
                                    <option key={schedule} value={schedule}>{scraperScheduleLabel(schedule)}</option>
                                ))}
                            </select>
                            <select
                                value={scraperCreateForm.dedupe_mode}
                                onChange={(event) => setScraperCreateForm((current) => ({ ...current, dedupe_mode: event.target.value }))}
                                className="crm-select md:col-span-2"
                            >
                                {scraperDedupeModes.map((mode) => (
                                    <option key={mode} value={mode}>{dedupeModeLabel(mode)}</option>
                                ))}
                            </select>
                            <input
                                value={scraperCreateForm.parser_rules.row_selector}
                                onChange={(event) => setScraperCreateForm((current) => ({
                                    ...current,
                                    parser_rules: { ...current.parser_rules, row_selector: event.target.value },
                                }))}
                                className="crm-input"
                                placeholder="Row selector (optional)"
                            />
                            <input
                                value={scraperCreateForm.parser_rules.link_selector}
                                onChange={(event) => setScraperCreateForm((current) => ({
                                    ...current,
                                    parser_rules: { ...current.parser_rules, link_selector: event.target.value },
                                }))}
                                className="crm-input"
                                placeholder="Link selector (optional)"
                            />
                            <label className="flex items-center gap-2 text-sm text-slate-700">
                                <input
                                    type="checkbox"
                                    checked={Boolean(scraperCreateForm.is_active)}
                                    onChange={(event) => setScraperCreateForm((current) => ({ ...current, is_active: event.target.checked }))}
                                    className="h-4 w-4 rounded border-slate-300 text-teal-700 focus:ring-teal-200"
                                />
                                Source is active
                            </label>
                            <label className="flex items-center gap-2 text-sm text-slate-700">
                                <input
                                    type="checkbox"
                                    checked={Boolean(scraperCreateForm.compliance_ack_robots)}
                                    onChange={(event) => setScraperCreateForm((current) => ({ ...current, compliance_ack_robots: event.target.checked }))}
                                    className="h-4 w-4 rounded border-slate-300 text-teal-700 focus:ring-teal-200"
                                />
                                Robots policy reviewed
                            </label>
                            <label className="md:col-span-2 flex items-center gap-2 text-sm text-slate-700">
                                <input
                                    type="checkbox"
                                    checked={Boolean(scraperCreateForm.compliance_ack_tos)}
                                    onChange={(event) => setScraperCreateForm((current) => ({ ...current, compliance_ack_tos: event.target.checked }))}
                                    className="h-4 w-4 rounded border-slate-300 text-teal-700 focus:ring-teal-200"
                                />
                                Terms and source usage reviewed
                            </label>
                            <textarea
                                rows={2}
                                value={scraperCreateForm.compliance_notes}
                                onChange={(event) => setScraperCreateForm((current) => ({ ...current, compliance_notes: event.target.value }))}
                                className="crm-input md:col-span-2"
                                placeholder="Compliance notes (optional)"
                            />
                            <textarea
                                rows={2}
                                value={scraperCreateForm.reason}
                                onChange={(event) => setScraperCreateForm((current) => ({ ...current, reason: event.target.value }))}
                                className="crm-input md:col-span-2"
                                placeholder="Reason"
                            />
                        </div>
                        <footer className="flex items-center justify-end gap-2 border-t border-slate-100 p-4">
                            <button type="button" onClick={() => setScraperCreateOpen(false)} className="crm-btn-secondary">Cancel</button>
                            <button
                                type="button"
                                onClick={() => createScraperSourceMutation.mutate({
                                    platform_id: Number(scraperCreateForm.platform_id),
                                    name: scraperCreateForm.name,
                                    source_url: scraperCreateForm.source_url,
                                    parser_profile: scraperCreateForm.parser_profile,
                                    fetch_schedule: scraperCreateForm.fetch_schedule,
                                    dedupe_mode: scraperCreateForm.dedupe_mode,
                                    parser_rules: scraperCreateForm.parser_rules,
                                    is_active: scraperCreateForm.is_active,
                                    compliance_ack_robots: scraperCreateForm.compliance_ack_robots,
                                    compliance_ack_tos: scraperCreateForm.compliance_ack_tos,
                                    compliance_notes: scraperCreateForm.compliance_notes,
                                    reason: scraperCreateForm.reason,
                                })}
                                disabled={
                                    createScraperSourceMutation.isPending
                                    || !scraperCreateForm.platform_id
                                    || !scraperCreateForm.name.trim()
                                    || !scraperCreateForm.source_url.trim()
                                    || !scraperCreateForm.reason.trim()
                                }
                                className="crm-btn-primary disabled:cursor-not-allowed disabled:opacity-60"
                            >
                                {createScraperSourceMutation.isPending ? 'Creating...' : 'Create source'}
                            </button>
                        </footer>
                    </div>
                </div>
            ) : null}

            <ConfirmDialog
                open={smsTestConfirmOpen}
                title="Send Test SMS?"
                message="This sends a real SMS using the active provider and records the provider response for audit visibility."
                confirmLabel="Send test"
                cancelLabel="Cancel"
                tone="warning"
                onCancel={() => setSmsTestConfirmOpen(false)}
                onConfirm={() => {
                    testSmsProviderMutation.mutate({
                        phone: smsTestForm.phone.trim(),
                        message: smsTestForm.message.trim(),
                        reason: smsTestForm.reason.trim(),
                    });
                }}
                confirmDisabled={!smsTestForm.phone.trim() || !smsTestForm.message.trim() || !smsTestForm.reason.trim() || !smsProviderForm.enabled || !smsReady}
                isPending={testSmsProviderMutation.isPending}
            >
                <div className="space-y-1 text-sm text-slate-600">
                    <p><span className="font-semibold text-slate-800">Active provider:</span> {smsProviderLabel(smsProviderForm.active_provider)}</p>
                    <p><span className="font-semibold text-slate-800">Fallback:</span> {smsProviderLabel(smsProviderForm.fallback_provider)}</p>
                    <p><span className="font-semibold text-slate-800">Phone:</span> {smsTestForm.phone}</p>
                    <p className="line-clamp-2"><span className="font-semibold text-slate-800">Message:</span> {smsTestForm.message}</p>
                </div>
            </ConfirmDialog>

            <ConfirmDialog
                open={pushTestConfirmOpen}
                title="Send Test Push Notification?"
                message="This sends a real push notification to all subscribers for the selected market/provider."
                confirmLabel="Send test push"
                cancelLabel="Cancel"
                tone="warning"
                onCancel={() => setPushTestConfirmOpen(false)}
                onConfirm={() => {
                    testPushProviderMutation.mutate({
                        platform_id: Number(pushPlatformId),
                        title: pushTestForm.title.trim(),
                        message: pushTestForm.message.trim(),
                        target_url: pushTestForm.target_url.trim(),
                        icon_url: pushTestForm.icon_url.trim() || undefined,
                        reason: pushTestForm.reason.trim(),
                    });
                }}
                confirmDisabled={
                    !pushPlatformId
                    || !pushTestForm.title.trim()
                    || !pushTestForm.message.trim()
                    || !pushTestForm.target_url.trim()
                    || !pushTestForm.reason.trim()
                    || !pushProviderForm.enabled
                    || !selectedPushReady
                }
                isPending={testPushProviderMutation.isPending}
            >
                <div className="space-y-1 text-sm text-slate-600">
                    <p><span className="font-semibold text-slate-800">Market:</span> {selectedPushPlatform?.platform_name || '—'}</p>
                    <p><span className="font-semibold text-slate-800">Provider:</span> {pushProviderLabel(selectedPushProvider)}</p>
                    <p className="line-clamp-2"><span className="font-semibold text-slate-800">Title:</span> {pushTestForm.title}</p>
                    <p className="line-clamp-2"><span className="font-semibold text-slate-800">Message:</span> {pushTestForm.message}</p>
                </div>
            </ConfirmDialog>

            <ConfirmDialog
                open={scraperRunConfirmOpen}
                title="Run Scraper Now?"
                message="This will fetch the source URL, evaluate compliance/robots guardrails, and execute dedupe logic before optional lead creation."
                confirmLabel={scraperRunForm.dry_run ? 'Run dry-run' : 'Run import'}
                cancelLabel="Cancel"
                tone="warning"
                onCancel={() => setScraperRunConfirmOpen(false)}
                onConfirm={() => {
                    if (!selectedScraperSource) return;
                    runScraperSourceMutation.mutate({
                        sourceId: selectedScraperSource.id,
                        payload: {
                            dry_run: scraperRunForm.dry_run,
                            max_candidates: Number(scraperRunForm.max_candidates || 50),
                            reason: scraperRunForm.reason.trim(),
                        },
                    });
                }}
                confirmDisabled={!selectedScraperSource || !scraperRunForm.reason.trim()}
                isPending={runScraperSourceMutation.isPending}
            >
                <div className="space-y-1 text-sm text-slate-600">
                    <p><span className="font-semibold text-slate-800">Source:</span> {selectedScraperSource?.name}</p>
                    <p><span className="font-semibold text-slate-800">Profile:</span> {scraperProfileLabel(selectedScraperSource?.parser_profile)}</p>
                    <p><span className="font-semibold text-slate-800">Mode:</span> {scraperRunForm.dry_run ? 'Dry-run preview' : 'Import into leads'}</p>
                    <p><span className="font-semibold text-slate-800">Max candidates:</span> {scraperRunForm.max_candidates}</p>
                </div>
            </ConfirmDialog>

            <ConfirmDialog
                open={syncConfirmOpen}
                title="Run Manual Sync?"
                message="This operation pulls market records from WordPress using the selected scope and mode."
                confirmLabel="Run sync"
                cancelLabel="Cancel"
                tone="warning"
                onCancel={() => setSyncConfirmOpen(false)}
                onConfirm={() => {
                    if (!selectedPlatform) {
                        return;
                    }

                    runSyncMutation.mutate({
                        platformId: selectedPlatform.platform_id,
                        payload: {
                            ...syncForm,
                        },
                    });
                }}
                confirmDisabled={!syncForm.reason.trim()}
                isPending={runSyncMutation.isPending}
            >
                <div className="space-y-1 text-sm text-slate-600">
                    <p><span className="font-semibold text-slate-800">Market:</span> {selectedPlatform?.platform_name}</p>
                    <p><span className="font-semibold text-slate-800">Scope:</span> {syncForm.scope}</p>
                    <p><span className="font-semibold text-slate-800">Mode:</span> {syncForm.mode}</p>
                    <p><span className="font-semibold text-slate-800">Dry run:</span> {syncForm.dry_run ? 'yes' : 'no'}</p>
                </div>
            </ConfirmDialog>
        </div>
    );
}

function TemplatesWorkspace({ canManageTemplates }) {
    const queryClient = useQueryClient();
    const [page, setPage] = useState(1);
    const [searchInput, setSearchInput] = useState('');
    const [search, setSearch] = useState('');
    const [statusFilter, setStatusFilter] = useState('');
    const [categoryFilter, setCategoryFilter] = useState('');
    const [selectedTemplate, setSelectedTemplate] = useState(null);
    const [editorForm, setEditorForm] = useState(null);
    const [feedback, setFeedback] = useState(null);

    const { data, isLoading } = useQuery({
        queryKey: ['settings-templates', page, search, statusFilter, categoryFilter],
        queryFn: () => api.get('/crm/settings/templates', {
            params: {
                page,
                per_page: 20,
                ...(search ? { search } : {}),
                ...(statusFilter ? { status: statusFilter } : {}),
                ...(categoryFilter ? { category: categoryFilter } : {}),
            },
        }).then((response) => response.data),
    });

    const updateMutation = useMutation({
        mutationFn: ({ id, payload }) => api.patch(`/crm/settings/templates/${id}`, payload).then((response) => response.data),
        onSuccess: () => {
            queryClient.invalidateQueries({ queryKey: ['settings-templates'] });
            setFeedback({ tone: 'success', text: 'Template updated successfully.' });
            setSelectedTemplate(null);
            setEditorForm(null);
        },
        onError: () => {
            setFeedback({ tone: 'danger', text: 'Template update failed. Please try again.' });
        },
    });

    const rows = data?.data || [];

    const metrics = useMemo(() => {
        const active = rows.filter((row) => row.status === 'active').length;
        const draft = rows.filter((row) => row.status === 'draft').length;
        const renewal = rows.filter((row) => row.category === 'renewal').length;
        return { active, draft, renewal };
    }, [rows]);

    const columns = [
        {
            key: 'title',
            label: 'Template',
            render: (row) => (
                <div>
                    <p className="text-sm font-semibold text-slate-900">{row.title}</p>
                    <p className="truncate text-xs text-slate-500">{row.body}</p>
                </div>
            ),
        },
        {
            key: 'category',
            label: 'Category',
            render: (row) => <span className="text-xs capitalize text-slate-700">{({ credential_setup_link: 'Credential: Setup Link', credential_temp_password: 'Credential: Temp Password' }[row.category] || row.category.replace('_', ' '))}</span>,
        },
        {
            key: 'channel',
            label: 'Channel',
            render: (row) => (
                <span className="inline-flex items-center rounded-md bg-slate-100 px-2 py-0.5 text-xs font-medium uppercase text-slate-700 ring-1 ring-inset ring-slate-200">
                    {row.channel}
                </span>
            ),
        },
        {
            key: 'status',
            label: 'Status',
            render: (row) => <StatusBadge status={row.status} />,
        },
        {
            key: 'updated_at',
            label: 'Updated',
            render: (row) => <span className="text-xs text-slate-500">{new Date(row.updated_at).toLocaleDateString()}</span>,
        },
        {
            key: 'actions',
            label: 'Actions',
            render: (row) => (
                canManageTemplates ? (
                    <button
                        type="button"
                        onClick={(event) => {
                            event.stopPropagation();
                            setSelectedTemplate(row);
                            setEditorForm({
                                title: row.title || '',
                                category: row.category || 'follow_up',
                                channel: row.channel || 'sms',
                                subject: row.subject || '',
                                body: row.body || '',
                                status: row.status || 'draft',
                            });
                        }}
                        className="crm-btn-secondary px-3 py-1.5 text-xs"
                    >
                        Edit
                    </button>
                ) : (
                    <span className="text-xs text-slate-400">Read only</span>
                )
            ),
        },
    ];

    return (
        <div className="space-y-4">
            <section className="grid gap-4 md:grid-cols-3">
                <MetricCard label="Active Templates" value={metrics.active.toLocaleString()} meta="live automation copy" tone="success" />
                <MetricCard label="Draft Templates" value={metrics.draft.toLocaleString()} meta="pending approval" tone="warning" />
                <MetricCard label="Renewal Copy Sets" value={metrics.renewal.toLocaleString()} meta="expiry workflows" tone="accent" />
            </section>

            <section className="crm-filter-row">
                <div className="flex flex-wrap items-center gap-3">
                    <form
                        onSubmit={(event) => {
                            event.preventDefault();
                            setSearch(searchInput.trim());
                            setPage(1);
                        }}
                        className="min-w-[240px] flex-1"
                    >
                        <div className="relative">
                            <input
                                value={searchInput}
                                onChange={(event) => setSearchInput(event.target.value)}
                                placeholder="Search template body or title..."
                                className="crm-input pr-10"
                            />
                            <button type="submit" className="absolute right-2 top-1/2 -translate-y-1/2 rounded p-1 text-slate-400 transition hover:text-slate-600">
                                <svg className="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
                                    <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z" />
                                </svg>
                            </button>
                        </div>
                    </form>
                    <select value={categoryFilter} onChange={(event) => setCategoryFilter(event.target.value)} className="crm-select">
                        <option value="">All categories</option>
                        <option value="payment">Payment</option>
                        <option value="renewal">Renewal</option>
                        <option value="follow_up">Follow-up</option>
                        <option value="welcome">Welcome</option>
                        <option value="win_back">Win-back</option>
                        <option value="credential_setup_link">Credential: Setup Link</option>
                        <option value="credential_temp_password">Credential: Temp Password</option>
                    </select>
                    <select value={statusFilter} onChange={(event) => setStatusFilter(event.target.value)} className="crm-select">
                        <option value="">All statuses</option>
                        <option value="active">Active</option>
                        <option value="draft">Draft</option>
                    </select>
                    {(search || categoryFilter || statusFilter) ? (
                        <button
                            type="button"
                            onClick={() => {
                                setSearch('');
                                setSearchInput('');
                                setCategoryFilter('');
                                setStatusFilter('');
                                setPage(1);
                            }}
                            className="crm-btn-secondary px-3 py-2"
                        >
                            Reset
                        </button>
                    ) : null}
                </div>
                {feedback ? (
                    <p className={`mt-2 text-xs font-medium ${feedback.tone === 'success' ? 'text-emerald-700' : 'text-rose-700'}`}>
                        {feedback.text}
                    </p>
                ) : null}
            </section>

            <DataTable
                columns={columns}
                data={data?.data}
                pagination={data}
                onPageChange={setPage}
                onRowClick={(row) => {
                    if (!canManageTemplates) {
                        return;
                    }
                    setSelectedTemplate(row);
                    setEditorForm({
                        title: row.title || '',
                        category: row.category || 'follow_up',
                        channel: row.channel || 'sms',
                        subject: row.subject || '',
                        body: row.body || '',
                        status: row.status || 'draft',
                    });
                }}
                isLoading={isLoading}
                compact
                emptyMessage="No templates found."
            />

            {selectedTemplate && editorForm ? (
                <div className="fixed inset-0 z-50 flex bg-slate-900/45" onClick={() => {
                    setSelectedTemplate(null);
                    setEditorForm(null);
                }}>
                    <aside className="ml-auto h-full w-full max-w-xl border-l border-slate-200 bg-white shadow-xl" onClick={(event) => event.stopPropagation()}>
                        <header className="crm-panel-header sticky top-0 bg-white">
                            <div>
                                <h3 className="crm-panel-title">Edit Template</h3>
                                <p className="crm-panel-subtitle">{selectedTemplate.title}</p>
                            </div>
                        </header>

                        <div className="space-y-3 p-4">
                            <input
                                value={editorForm.title}
                                onChange={(event) => setEditorForm({ ...editorForm, title: event.target.value })}
                                className="crm-input"
                                placeholder="Template title"
                            />

                            <div className="grid gap-3 md:grid-cols-2">
                                <select value={editorForm.category} onChange={(event) => setEditorForm({ ...editorForm, category: event.target.value })} className="crm-select">
                                    <option value="payment">Payment</option>
                                    <option value="renewal">Renewal</option>
                                    <option value="follow_up">Follow-up</option>
                                    <option value="welcome">Welcome</option>
                                    <option value="win_back">Win-back</option>
                                    <option value="credential_setup_link">Credential: Setup Link</option>
                                    <option value="credential_temp_password">Credential: Temp Password</option>
                                </select>
                                <select value={editorForm.status} onChange={(event) => setEditorForm({ ...editorForm, status: event.target.value })} className="crm-select">
                                    <option value="active">Active</option>
                                    <option value="draft">Draft</option>
                                </select>
                            </div>

                            <input
                                value={editorForm.subject}
                                onChange={(event) => setEditorForm({ ...editorForm, subject: event.target.value })}
                                className="crm-input"
                                placeholder="Subject (optional)"
                            />

                            <textarea
                                value={editorForm.body}
                                onChange={(event) => setEditorForm({ ...editorForm, body: event.target.value })}
                                className="crm-input min-h-[240px] resize-y"
                                placeholder="Template body"
                            />

                            {editorForm.category.startsWith('credential_') ? (
                                <div className="rounded-md border border-slate-200 bg-slate-50 p-3">
                                    <p className="mb-1.5 text-xs font-semibold text-slate-700">Available placeholders</p>
                                    <div className="flex flex-wrap gap-1.5">
                                        {[
                                            '{clientName}',
                                            '{wpUsername}',
                                            '{loginUrl}',
                                            '{setupUrl}',
                                            '{profileUrl}',
                                            '{supportChatUrl}',
                                            ...(editorForm.category === 'credential_temp_password' ? ['{temporaryPassword}'] : []),
                                        ].map((tag) => (
                                            <code key={tag} className="rounded bg-white px-1.5 py-0.5 text-xs text-slate-600 ring-1 ring-slate-200">{tag}</code>
                                        ))}
                                    </div>
                                </div>
                            ) : null}
                        </div>

                        <footer className="flex items-center justify-end gap-2 border-t border-slate-100 p-4">
                            <button type="button" className="crm-btn-secondary" onClick={() => {
                                setSelectedTemplate(null);
                                setEditorForm(null);
                            }}>
                                Cancel
                            </button>
                            <button
                                type="button"
                                onClick={() => updateMutation.mutate({ id: selectedTemplate.id, payload: editorForm })}
                                disabled={!editorForm.title.trim() || !editorForm.body.trim() || updateMutation.isPending}
                                className="crm-btn-primary disabled:cursor-not-allowed disabled:opacity-50"
                            >
                                {updateMutation.isPending ? 'Saving...' : 'Save template'}
                            </button>
                        </footer>
                    </aside>
                </div>
            ) : null}
        </div>
    );
}

function incidentSeverityClasses(severity) {
    if (severity === 'high') return 'bg-rose-50 text-rose-700 ring-rose-200';
    if (severity === 'medium') return 'bg-amber-50 text-amber-700 ring-amber-200';
    return 'bg-emerald-50 text-emerald-700 ring-emerald-200';
}

function incidentSeverityLabel(severity) {
    if (severity === 'high') return 'Needs action';
    if (severity === 'medium') return 'Monitor';
    return 'Healthy';
}

function incidentCategoryLabel(category) {
    const normalized = String(category || 'operations');
    return normalized.replaceAll('_', ' ').replace(/\b\w/g, (char) => char.toUpperCase());
}

function WebhookLogsWorkspace() {
    const [page, setPage] = useState(1);
    const [searchInput, setSearchInput] = useState('');
    const [search, setSearch] = useState('');
    const [selectedLog, setSelectedLog] = useState(null);
    const [showRawPayload, setShowRawPayload] = useState(false);

    const { data, isLoading } = useQuery({
        queryKey: ['settings-webhook-logs', page, search],
        queryFn: () => api.get('/crm/settings/webhook-logs', {
            params: {
                page,
                per_page: 25,
                ...(search ? { search } : {}),
            },
        }).then((response) => response.data),
    });

    const logs = data?.data || [];
    const summary = useMemo(() => {
        return logs.reduce((accumulator, log) => {
            const severity = log?.incident?.severity || 'medium';
            if (severity === 'high') accumulator.high += 1;
            else if (severity === 'medium') accumulator.medium += 1;
            else accumulator.low += 1;

            const category = log?.incident?.category || 'operations';
            accumulator.categories[category] = (accumulator.categories[category] || 0) + 1;
            return accumulator;
        }, { high: 0, medium: 0, low: 0, categories: {} });
    }, [logs]);

    const topCategory = Object.entries(summary.categories)
        .sort((left, right) => right[1] - left[1])[0];

    const columns = [
        {
            key: 'incident',
            label: 'Incident',
            render: (row) => (
                <div className="max-w-[460px]">
                    <p className="text-sm font-semibold text-slate-900">{row.incident?.title || row.action.replaceAll('_', ' ')}</p>
                    <p className="truncate text-xs text-slate-500">{row.incident?.summary || row.summary || 'Operational event recorded.'}</p>
                </div>
            ),
        },
        {
            key: 'severity',
            label: 'Severity',
            render: (row) => (
                <span className={`inline-flex items-center rounded-md px-2 py-0.5 text-xs font-medium ring-1 ring-inset ${incidentSeverityClasses(row.incident?.severity || 'medium')}`}>
                    {incidentSeverityLabel(row.incident?.severity || 'medium')}
                </span>
            ),
        },
        {
            key: 'category',
            label: 'Category',
            render: (row) => <span className="text-xs text-slate-700">{incidentCategoryLabel(row.incident?.category || row.category)}</span>,
        },
        {
            key: 'actor',
            label: 'Owner',
            render: (row) => (
                <div>
                    <p className="text-xs font-medium text-slate-700">{row.actor?.name || 'System'}</p>
                    <p className="text-[11px] text-slate-500">{row.entity_type} #{row.entity_id}</p>
                </div>
            ),
        },
        {
            key: 'suggested_action',
            label: 'Recommended Action',
            render: (row) => <span className="truncate text-xs text-slate-600">{row.incident?.suggested_action || row.suggested_action || 'Inspect event details.'}</span>,
        },
        {
            key: 'created_at',
            label: 'Logged',
            render: (row) => <span className="text-xs text-slate-600">{row.created_at ? new Date(row.created_at).toLocaleString() : '--'}</span>,
        },
        {
            key: 'actions',
            label: 'Actions',
            render: (row) => (
                <button type="button" className="crm-btn-secondary px-3 py-1.5 text-xs" onClick={(event) => {
                    event.stopPropagation();
                    setSelectedLog(row);
                    setShowRawPayload(false);
                }}>
                    Inspect
                </button>
            ),
        },
    ];

    return (
        <div className="space-y-4">
            <section className="grid gap-4 md:grid-cols-4">
                <MetricCard
                    label="Needs Action"
                    value={(summary.high || 0).toLocaleString()}
                    meta="high-severity incidents"
                    tone={(summary.high || 0) > 0 ? 'danger' : 'success'}
                />
                <MetricCard
                    label="Monitor"
                    value={(summary.medium || 0).toLocaleString()}
                    meta="medium-severity incidents"
                    tone="warning"
                />
                <MetricCard
                    label="Healthy Events"
                    value={(summary.low || 0).toLocaleString()}
                    meta="completed without intervention"
                    tone="success"
                />
                <MetricCard
                    label="Top Category"
                    value={incidentCategoryLabel(topCategory?.[0] || 'operations')}
                    meta={`${(topCategory?.[1] || 0).toLocaleString()} incidents in current page`}
                    tone="default"
                />
            </section>

            <section className="crm-filter-row">
                <div className="flex flex-wrap items-center gap-3">
                    <form
                        onSubmit={(event) => {
                            event.preventDefault();
                            setSearch(searchInput.trim());
                            setPage(1);
                        }}
                        className="min-w-[240px] flex-1"
                    >
                        <div className="relative">
                            <input
                                value={searchInput}
                                onChange={(event) => setSearchInput(event.target.value)}
                                placeholder="Search incident, reason, action code, or entity..."
                                className="crm-input pr-10"
                            />
                            <button type="submit" className="absolute right-2 top-1/2 -translate-y-1/2 rounded p-1 text-slate-400 transition hover:text-slate-600">
                                <svg className="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
                                    <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z" />
                                </svg>
                            </button>
                        </div>
                    </form>
                    {search ? (
                        <button type="button" className="crm-btn-secondary px-3 py-2" onClick={() => {
                            setSearch('');
                            setSearchInput('');
                            setPage(1);
                        }}>
                            Reset
                        </button>
                    ) : null}
                </div>
                <p className="mt-2 text-xs text-slate-500">Incident summaries are human-readable. Open a row only when you need technical payload details.</p>
            </section>

            <DataTable
                columns={columns}
                data={logs}
                pagination={data}
                onPageChange={setPage}
                onRowClick={(row) => {
                    setSelectedLog(row);
                    setShowRawPayload(false);
                }}
                isLoading={isLoading}
                compact
                emptyMessage="No incident logs found."
            />

            {selectedLog ? (
                <div className="fixed inset-0 z-50 flex items-center justify-center bg-slate-900/45 p-4" onClick={() => setSelectedLog(null)}>
                    <div className="w-full max-w-3xl rounded-lg border border-slate-200 bg-white shadow-xl" onClick={(event) => event.stopPropagation()}>
                        <header className="crm-panel-header">
                            <div>
                                <h3 className="crm-panel-title">{selectedLog.incident?.title || selectedLog.action.replaceAll('_', ' ')}</h3>
                                <p className="crm-panel-subtitle">{selectedLog.created_at ? new Date(selectedLog.created_at).toLocaleString() : '--'} • Action code: {selectedLog.action}</p>
                            </div>
                        </header>

                        <div className="space-y-4 p-4">
                            <section className="grid gap-3 sm:grid-cols-2">
                                <div className="rounded-md border border-slate-200 bg-slate-50 p-3">
                                    <p className="text-[11px] font-semibold uppercase tracking-[0.1em] text-slate-500">Severity</p>
                                    <p className={`mt-1 inline-flex items-center rounded-md px-2 py-0.5 text-xs font-semibold ring-1 ring-inset ${incidentSeverityClasses(selectedLog.incident?.severity || 'medium')}`}>
                                        {incidentSeverityLabel(selectedLog.incident?.severity || 'medium')}
                                    </p>
                                </div>
                                <div className="rounded-md border border-slate-200 bg-slate-50 p-3">
                                    <p className="text-[11px] font-semibold uppercase tracking-[0.1em] text-slate-500">Category</p>
                                    <p className="mt-1 text-sm font-semibold text-slate-800">{incidentCategoryLabel(selectedLog.incident?.category || selectedLog.category)}</p>
                                </div>
                            </section>

                            <section className="rounded-md border border-slate-200 bg-slate-50 p-3">
                                <h4 className="text-xs font-semibold uppercase tracking-[0.1em] text-slate-500">Incident Summary</h4>
                                <p className="mt-2 text-sm text-slate-700">{selectedLog.incident?.summary || selectedLog.summary || 'Operational event recorded.'}</p>
                                <p className="mt-2 text-xs text-slate-600"><span className="font-semibold text-slate-700">Recommended action:</span> {selectedLog.incident?.suggested_action || selectedLog.suggested_action || 'Inspect this event if workflow appears blocked.'}</p>
                                {selectedLog.reason ? (
                                    <p className="mt-2 text-xs text-slate-600"><span className="font-semibold text-slate-700">Operator reason:</span> {selectedLog.reason}</p>
                                ) : null}
                            </section>

                            <section className="rounded-md border border-slate-200 bg-white p-3">
                                <div className="flex flex-wrap items-center justify-between gap-2">
                                    <h4 className="text-xs font-semibold uppercase tracking-[0.1em] text-slate-500">Technical Payload</h4>
                                    <button
                                        type="button"
                                        onClick={() => setShowRawPayload((current) => !current)}
                                        className="crm-btn-secondary px-3 py-1.5 text-xs"
                                    >
                                        {showRawPayload ? 'Hide raw payload' : 'Show raw payload'}
                                    </button>
                                </div>
                                {showRawPayload ? (
                                    <div className="mt-3 grid gap-3 lg:grid-cols-2">
                                        <section className="rounded-md border border-slate-200 bg-slate-50 p-2">
                                            <h5 className="text-[11px] font-semibold uppercase tracking-[0.1em] text-slate-500">Before State</h5>
                                            <pre className="crm-mono mt-2 max-h-64 overflow-auto text-xs text-slate-700">{JSON.stringify(selectedLog.before_state || {}, null, 2)}</pre>
                                        </section>
                                        <section className="rounded-md border border-slate-200 bg-slate-50 p-2">
                                            <h5 className="text-[11px] font-semibold uppercase tracking-[0.1em] text-slate-500">After State</h5>
                                            <pre className="crm-mono mt-2 max-h-64 overflow-auto text-xs text-slate-700">{JSON.stringify(selectedLog.after_state || {}, null, 2)}</pre>
                                        </section>
                                    </div>
                                ) : (
                                    <p className="mt-2 text-xs text-slate-500">Technical JSON payload is hidden by default to keep the incident view readable for operators.</p>
                                )}
                            </section>
                        </div>

                        <footer className="flex justify-end border-t border-slate-100 p-4">
                            <button type="button" className="crm-btn-secondary" onClick={() => setSelectedLog(null)}>
                                Close
                            </button>
                        </footer>
                    </div>
                </div>
            ) : null}
        </div>
    );
}

function roleClasses(role) {
    if (role === 'admin') return 'bg-indigo-50 text-indigo-700 ring-indigo-200';
    if (role === 'sub_admin') return 'bg-sky-50 text-sky-700 ring-sky-200';
    if (role === 'marketing') return 'bg-violet-50 text-violet-700 ring-violet-200';
    return 'bg-slate-100 text-slate-700 ring-slate-200';
}

function RolesWorkspace() {
    const queryClient = useQueryClient();
    const toast = useToast();
    const [selectedUser, setSelectedUser] = useState(null);
    const [editor, setEditor] = useState(null);
    const [createOpen, setCreateOpen] = useState(false);
    const [createForm, setCreateForm] = useState({
        name: '',
        email: '',
        password: '',
        role: 'sales',
        status: 'active',
        assigned_market_ids: [],
        reason: 'New team member onboarding',
    });

    const { data, isLoading } = useQuery({
        queryKey: ['settings-roles'],
        queryFn: () => api.get('/crm/settings/roles').then((response) => response.data),
    });

    const updateRoleMutation = useMutation({
        mutationFn: ({ userId, payload }) => api.patch(`/crm/settings/roles/${userId}`, payload).then((response) => response.data),
        onSuccess: () => {
            queryClient.invalidateQueries({ queryKey: ['settings-roles'] });
            toast.success('Role permissions updated.');
            setSelectedUser(null);
            setEditor(null);
        },
        onError: (error) => {
            toast.error(error?.response?.data?.message || 'Role update failed.');
        },
    });

    const createUserMutation = useMutation({
        mutationFn: (payload) => api.post('/crm/settings/roles/users', payload).then((response) => response.data),
        onSuccess: () => {
            queryClient.invalidateQueries({ queryKey: ['settings-roles'] });
            toast.success('User created and assigned successfully.');
            setCreateOpen(false);
            setCreateForm({
                name: '',
                email: '',
                password: '',
                role: 'sales',
                status: 'active',
                assigned_market_ids: [],
                reason: 'New team member onboarding',
            });
        },
        onError: (error) => {
            toast.error(error?.response?.data?.message || 'User creation failed.');
        },
    });

    const users = data?.users || [];
    const summary = data?.summary || {};
    const availableMarkets = data?.available_markets || [];

    const openEditor = (user) => {
        setSelectedUser(user);
        setEditor({
            role: user.role || 'sales',
            status: user.status || 'active',
            assigned_market_ids: Array.isArray(user.assigned_market_ids) ? user.assigned_market_ids.map((id) => Number(id)) : [],
            reason: 'Role update from settings',
        });
    };

    const toggleMarket = (marketId) => {
        setEditor((current) => {
            if (!current) return current;

            const exists = current.assigned_market_ids.includes(marketId);
            return {
                ...current,
                assigned_market_ids: exists
                    ? current.assigned_market_ids.filter((id) => id !== marketId)
                    : [...current.assigned_market_ids, marketId],
            };
        });
    };

    const toggleCreateMarket = (marketId) => {
        setCreateForm((current) => {
            const exists = current.assigned_market_ids.includes(marketId);
            return {
                ...current,
                assigned_market_ids: exists
                    ? current.assigned_market_ids.filter((id) => id !== marketId)
                    : [...current.assigned_market_ids, marketId],
            };
        });
    };

    return (
        <div className="space-y-4">
            <section className="grid gap-4 md:grid-cols-4">
                <MetricCard label="Admins" value={(summary.admins || 0).toLocaleString()} meta="full permissions" tone="accent" />
                <MetricCard label="Sub-admins" value={(summary.sub_admins || 0).toLocaleString()} meta="market-level controls" tone="default" />
                <MetricCard label="Sales Agents" value={(summary.sales || 0).toLocaleString()} meta="execution role" tone="success" />
                <MetricCard label="Inactive Users" value={(summary.inactive || 0).toLocaleString()} meta="access suspended" tone="warning" />
            </section>

            <section className="crm-surface overflow-hidden">
                <header className="crm-panel-header">
                    <div>
                        <h3 className="crm-panel-title">Access Matrix</h3>
                        <p className="crm-panel-subtitle">Role ownership and assigned market footprint.</p>
                    </div>
                    <button
                        type="button"
                        onClick={() => setCreateOpen(true)}
                        className="crm-btn-primary px-3 py-2"
                    >
                        Add user
                    </button>
                </header>

                <div className="max-h-[520px] overflow-auto">
                    {isLoading ? (
                        <p className="p-4 text-sm text-slate-500">Loading user access map...</p>
                    ) : users.length === 0 ? (
                        <p className="p-4 text-sm text-slate-500">No users found.</p>
                    ) : (
                        <table className="min-w-full divide-y divide-slate-200">
                            <thead className="bg-slate-50">
                                <tr>
                                    <th className="px-4 py-3 text-left text-[11px] font-semibold uppercase tracking-[0.12em] text-slate-500">User</th>
                                    <th className="px-4 py-3 text-left text-[11px] font-semibold uppercase tracking-[0.12em] text-slate-500">Role</th>
                                    <th className="px-4 py-3 text-left text-[11px] font-semibold uppercase tracking-[0.12em] text-slate-500">Status</th>
                                    <th className="px-4 py-3 text-left text-[11px] font-semibold uppercase tracking-[0.12em] text-slate-500">Assigned Markets</th>
                                    <th className="px-4 py-3 text-left text-[11px] font-semibold uppercase tracking-[0.12em] text-slate-500">Actions</th>
                                </tr>
                            </thead>
                            <tbody className="divide-y divide-slate-100">
                                {users.map((user) => {
                                    const assignedMarkets = Array.isArray(user.assigned_markets) ? user.assigned_markets : [];
                                    const marketCount = assignedMarkets.length;
                                    const marketLabel = marketCount > 0
                                        ? assignedMarkets.map((market) => market.name).join(', ')
                                        : 'None';

                                    return (
                                        <tr key={user.id}>
                                            <td className="px-4 py-2.5">
                                                <p className="text-sm font-semibold text-slate-900">{user.name}</p>
                                                <p className="text-xs text-slate-500">{user.email}</p>
                                            </td>
                                            <td className="px-4 py-2.5">
                                                <span className={`inline-flex items-center rounded-md px-2.5 py-0.5 text-xs font-medium ring-1 ring-inset ${roleClasses(user.role)}`}>
                                                    {user.role.replace('_', ' ')}
                                                </span>
                                            </td>
                                            <td className="px-4 py-2.5">
                                                <span className={`inline-flex items-center rounded-md px-2.5 py-0.5 text-xs font-medium ring-1 ring-inset ${
                                                    user.status === 'active'
                                                        ? 'bg-emerald-50 text-emerald-700 ring-emerald-200'
                                                        : 'bg-slate-200 text-slate-700 ring-slate-300'
                                                }`}>
                                                    {user.status}
                                                </span>
                                            </td>
                                            <td className="px-4 py-2.5">
                                                <p className="text-sm text-slate-700">{marketCount}</p>
                                                <p className="truncate text-xs text-slate-500">{marketLabel}</p>
                                            </td>
                                            <td className="px-4 py-2.5">
                                                <button
                                                    type="button"
                                                    onClick={() => openEditor(user)}
                                                    className="crm-btn-secondary px-3 py-1.5 text-xs"
                                                >
                                                    Edit
                                                </button>
                                            </td>
                                        </tr>
                                    );
                                })}
                            </tbody>
                        </table>
                    )}
                </div>
            </section>

            {selectedUser && editor ? (
                <div className="fixed inset-0 z-50 flex items-center justify-center bg-slate-900/45 p-4" onClick={() => {
                    setSelectedUser(null);
                    setEditor(null);
                }}>
                    <div className="w-full max-w-2xl rounded-lg border border-slate-200 bg-white shadow-xl" onClick={(event) => event.stopPropagation()}>
                        <header className="crm-panel-header">
                            <div>
                                <h3 className="crm-panel-title">Edit Role & Permissions</h3>
                                <p className="crm-panel-subtitle">{selectedUser.name} • {selectedUser.email}</p>
                            </div>
                        </header>

                        <div className="grid gap-3 p-4 md:grid-cols-2">
                            <div>
                                <label htmlFor="role-select" className="mb-1 block text-sm font-medium text-slate-700">Role</label>
                                <select
                                    id="role-select"
                                    value={editor.role}
                                    onChange={(event) => setEditor((current) => ({ ...current, role: event.target.value }))}
                                    className="crm-select w-full"
                                >
                                    <option value="admin">Admin</option>
                                    <option value="sub_admin">Sub-admin</option>
                                    <option value="sales">Sales</option>
                                    <option value="marketing">Marketing</option>
                                </select>
                            </div>

                            <div>
                                <label htmlFor="status-select" className="mb-1 block text-sm font-medium text-slate-700">Status</label>
                                <select
                                    id="status-select"
                                    value={editor.status}
                                    onChange={(event) => setEditor((current) => ({ ...current, status: event.target.value }))}
                                    className="crm-select w-full"
                                >
                                    <option value="active">Active</option>
                                    <option value="inactive">Inactive</option>
                                </select>
                            </div>

                            <div className="md:col-span-2">
                                <p className="mb-1 text-sm font-medium text-slate-700">Assigned markets</p>
                                {availableMarkets.length === 0 ? (
                                    <p className="text-sm text-slate-500">No markets available.</p>
                                ) : (
                                    <div className="grid max-h-56 gap-2 overflow-auto rounded-md border border-slate-200 p-2 sm:grid-cols-2">
                                        {availableMarkets.map((market) => (
                                            <label key={market.id} className="flex items-center gap-2 rounded-md px-2 py-1 text-sm text-slate-700 hover:bg-slate-50">
                                                <input
                                                    type="checkbox"
                                                    checked={editor.assigned_market_ids.includes(market.id)}
                                                    onChange={() => toggleMarket(market.id)}
                                                    className="h-4 w-4 rounded border-slate-300 text-teal-700 focus:ring-teal-200"
                                                />
                                                <span>{market.name} {market.country ? `(${market.country})` : ''}</span>
                                            </label>
                                        ))}
                                    </div>
                                )}
                            </div>

                            <div className="md:col-span-2">
                                <label htmlFor="role-reason" className="mb-1 block text-sm font-medium text-slate-700">Reason</label>
                                <textarea
                                    id="role-reason"
                                    rows={3}
                                    value={editor.reason}
                                    onChange={(event) => setEditor((current) => ({ ...current, reason: event.target.value }))}
                                    className="crm-input"
                                />
                            </div>
                        </div>

                        <footer className="flex items-center justify-end gap-2 border-t border-slate-100 p-4">
                            <button
                                type="button"
                                className="crm-btn-secondary"
                                onClick={() => {
                                    setSelectedUser(null);
                                    setEditor(null);
                                }}
                            >
                                Cancel
                            </button>
                            <button
                                type="button"
                                onClick={() => updateRoleMutation.mutate({
                                    userId: selectedUser.id,
                                    payload: {
                                        role: editor.role,
                                        status: editor.status,
                                        assigned_market_ids: editor.assigned_market_ids,
                                        reason: editor.reason,
                                    },
                                })}
                                disabled={!editor.reason.trim() || updateRoleMutation.isPending}
                                className="crm-btn-primary disabled:cursor-not-allowed disabled:opacity-50"
                            >
                                {updateRoleMutation.isPending ? 'Saving...' : 'Save changes'}
                            </button>
                        </footer>
                    </div>
                </div>
            ) : null}

            {createOpen ? (
                <div className="fixed inset-0 z-50 flex items-center justify-center bg-slate-900/45 p-4" onClick={() => setCreateOpen(false)}>
                    <div className="w-full max-w-2xl rounded-lg border border-slate-200 bg-white shadow-xl" onClick={(event) => event.stopPropagation()}>
                        <header className="crm-panel-header">
                            <div>
                                <h3 className="crm-panel-title">Create User</h3>
                                <p className="crm-panel-subtitle">Add a new team member and assign initial market access.</p>
                            </div>
                        </header>

                        <div className="grid gap-3 p-4 md:grid-cols-2">
                            <input
                                value={createForm.name}
                                onChange={(event) => setCreateForm((current) => ({ ...current, name: event.target.value }))}
                                className="crm-input"
                                placeholder="Full name"
                            />
                            <input
                                type="email"
                                value={createForm.email}
                                onChange={(event) => setCreateForm((current) => ({ ...current, email: event.target.value }))}
                                className="crm-input"
                                placeholder="Email address"
                            />
                            <input
                                type="password"
                                value={createForm.password}
                                onChange={(event) => setCreateForm((current) => ({ ...current, password: event.target.value }))}
                                className="crm-input"
                                placeholder="Temporary password (optional)"
                            />
                            <select
                                value={createForm.role}
                                onChange={(event) => setCreateForm((current) => ({ ...current, role: event.target.value }))}
                                className="crm-select"
                            >
                                <option value="admin">Admin</option>
                                <option value="sub_admin">Sub-admin</option>
                                <option value="sales">Sales</option>
                                <option value="marketing">Marketing</option>
                            </select>
                            <select
                                value={createForm.status}
                                onChange={(event) => setCreateForm((current) => ({ ...current, status: event.target.value }))}
                                className="crm-select"
                            >
                                <option value="active">Active</option>
                                <option value="inactive">Inactive</option>
                            </select>
                            <textarea
                                rows={2}
                                value={createForm.reason}
                                onChange={(event) => setCreateForm((current) => ({ ...current, reason: event.target.value }))}
                                className="crm-input md:col-span-2"
                                placeholder="Reason"
                            />

                            <div className="md:col-span-2">
                                <p className="mb-1 text-sm font-medium text-slate-700">Assigned markets</p>
                                {availableMarkets.length === 0 ? (
                                    <p className="text-sm text-slate-500">No markets available.</p>
                                ) : (
                                    <div className="grid max-h-56 gap-2 overflow-auto rounded-md border border-slate-200 p-2 sm:grid-cols-2">
                                        {availableMarkets.map((market) => (
                                            <label key={market.id} className="flex items-center gap-2 rounded-md px-2 py-1 text-sm text-slate-700 hover:bg-slate-50">
                                                <input
                                                    type="checkbox"
                                                    checked={createForm.assigned_market_ids.includes(market.id)}
                                                    onChange={() => toggleCreateMarket(market.id)}
                                                    className="h-4 w-4 rounded border-slate-300 text-teal-700 focus:ring-teal-200"
                                                />
                                                <span>{market.name} {market.country ? `(${market.country})` : ''}</span>
                                            </label>
                                        ))}
                                    </div>
                                )}
                            </div>
                        </div>

                        <footer className="flex items-center justify-end gap-2 border-t border-slate-100 p-4">
                            <button type="button" className="crm-btn-secondary" onClick={() => setCreateOpen(false)}>
                                Cancel
                            </button>
                            <button
                                type="button"
                                onClick={() => createUserMutation.mutate({
                                    name: createForm.name,
                                    email: createForm.email,
                                    role: createForm.role,
                                    status: createForm.status,
                                    assigned_market_ids: createForm.assigned_market_ids,
                                    reason: createForm.reason,
                                    ...(createForm.password.trim() ? { password: createForm.password } : {}),
                                })}
                                disabled={createUserMutation.isPending || !createForm.name.trim() || !createForm.email.trim() || !createForm.reason.trim()}
                                className="crm-btn-primary disabled:cursor-not-allowed disabled:opacity-50"
                            >
                                {createUserMutation.isPending ? 'Creating...' : 'Create user'}
                            </button>
                        </footer>
                    </div>
                </div>
            ) : null}
        </div>
    );
}

function DashboardSettingsPanel() {
    const { config, toggle, reset, labels } = useDashboardWidgets();

    return (
        <section className="rounded-lg border border-slate-200 bg-white shadow-sm">
            <header className="flex items-start justify-between gap-3 border-b border-slate-100 px-5 py-4">
                <div>
                    <h3 className="text-lg font-semibold text-slate-900">Dashboard Widgets</h3>
                    <p className="mt-1 text-sm text-slate-500">Toggle which widgets appear on your dashboard.</p>
                </div>
                <button
                    type="button"
                    onClick={reset}
                    className="rounded-md border border-slate-300 bg-white px-3 py-1.5 text-sm font-semibold text-slate-700 transition hover:bg-slate-50 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-slate-400"
                >
                    Reset defaults
                </button>
            </header>
            <div className="divide-y divide-slate-100">
                {Object.entries(labels).map(([key, meta]) => (
                    <label key={key} className="flex cursor-pointer items-center justify-between gap-4 px-5 py-3.5 transition hover:bg-slate-50">
                        <div>
                            <p className="text-sm font-medium text-slate-900">{meta.name}</p>
                            <p className="text-xs text-slate-500">{meta.description}</p>
                        </div>
                        <button
                            type="button"
                            role="switch"
                            aria-checked={config[key]}
                            onClick={() => toggle(key)}
                            className={`relative inline-flex h-6 w-11 shrink-0 cursor-pointer items-center rounded-full transition-colors focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-teal-600 focus-visible:ring-offset-2 ${config[key] ? 'bg-teal-600' : 'bg-slate-200'}`}
                        >
                            <span
                                className={`inline-block h-4 w-4 rounded-full bg-white shadow-sm transition-transform ${config[key] ? 'translate-x-6' : 'translate-x-1'}`}
                            />
                        </button>
                    </label>
                ))}
            </div>
        </section>
    );
}

export default function Settings() {
    const { user } = useAuth();
    const [activeTab, setActiveTab] = useState('integrations');
    const canManageTemplates = ['admin', 'sub_admin'].includes(user?.role || '');
    const canViewRoles = (user?.role || '') === 'admin';
    const canCreateMarkets = (user?.role || '') === 'admin';
    const canManageMarkets = ['admin', 'sub_admin'].includes(user?.role || '');
    const canEditPaymentLinks = ['admin', 'sub_admin'].includes(user?.role || '');
    const canManagePushProviders = ['admin', 'sub_admin'].includes(user?.role || '');
    const canManageWalletSystem = (user?.role || '') === 'admin';
    const canManageWalletPlatforms = ['admin', 'sub_admin'].includes(user?.role || '');
    const canManageSms = (user?.role || '') === 'admin';

    const tabs = useMemo(() => {
        return baseTabs.filter((tab) => (tab.id === 'roles' ? canViewRoles : true));
    }, [canViewRoles]);

    useEffect(() => {
        if (!tabs.find((tab) => tab.id === activeTab)) {
            setActiveTab(tabs[0]?.id || 'integrations');
        }
    }, [activeTab, tabs]);

    return (
        <div className="space-y-4">
            <PageHeader title="Settings" subtitle="Configure integrations, templates, and operational controls." />

            <section className="crm-surface p-2">
                <div className="flex flex-wrap gap-1">
                    {tabs.map((tab) => (
                        <button
                            key={tab.id}
                            type="button"
                            onClick={() => setActiveTab(tab.id)}
                            className={`rounded-md px-3 py-2 text-sm font-medium transition ${activeTab === tab.id ? 'bg-white text-slate-900 ring-1 ring-slate-200' : 'text-slate-500 hover:bg-slate-100 hover:text-slate-700'}`}
                        >
                            {tab.label}
                        </button>
                    ))}
                </div>
            </section>

            {activeTab === 'integrations' ? (
                <IntegrationsWorkspace
                    canCreateMarkets={canCreateMarkets}
                    canEditPaymentLinks={canEditPaymentLinks}
                    canManagePushProviders={canManagePushProviders}
                    canManageWalletSystem={canManageWalletSystem}
                    canManageWalletPlatforms={canManageWalletPlatforms}
                    currentUserEmail={user?.email || ''}
                />
            ) : null}

            {activeTab === 'templates' ? <TemplatesWorkspace canManageTemplates={canManageTemplates} /> : null}
            {activeTab === 'logs' ? <WebhookLogsWorkspace /> : null}
            {activeTab === 'roles' && canViewRoles ? <RolesWorkspace /> : null}
            {activeTab === 'dashboard' ? <DashboardSettingsPanel /> : null}
            {activeTab === 'health' ? (
                <SystemHealthWorkspace
                    canCreateMarkets={canCreateMarkets}
                    canManageMarkets={canManageMarkets}
                    canManageSms={canManageSms}
                    onOpenMarketSetup={() => {
                        const params = new URLSearchParams(window.location.search);
                        params.set('integrationArea', 'markets');
                        params.set('createMarket', '1');
                        window.history.replaceState({}, '', `${window.location.pathname}?${params.toString()}`);
                        setActiveTab('integrations');
                    }}
                />
            ) : null}
        </div>
    );
}
