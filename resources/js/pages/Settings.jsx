import React, { useEffect, useMemo, useRef, useState } from 'react';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { Navigate } from 'react-router-dom';
import api from '../services/api';
import DataTable from '../components/DataTable';
import ConfirmDialog from '../components/ConfirmDialog';
import MetricCard from '../components/MetricCard';
import PageHeader from '../components/PageHeader';
import StatusBadge from '../components/StatusBadge';
import BillingWorkspace from '../components/billing/BillingWorkspace';
import IntegrationsAreaNav from '../components/settings/IntegrationsAreaNav';
import IntegrationsMetricsRow from '../components/settings/IntegrationsMetricsRow';
import IntegrationsOverviewPanel from '../components/settings/IntegrationsOverviewPanel';
import MarketListPanel from '../components/settings/MarketListPanel';
import MarketCreateModal from '../components/settings/MarketCreateModal';
import MessagingArea from '../components/settings/messaging/MessagingArea';
import PaymentLinkRoutingPanel from '../components/settings/PaymentLinkRoutingPanel';
import PushRoutingPanel from '../components/settings/PushRoutingPanel';
import ScraperConfigPanel from '../components/settings/ScraperConfigPanel';
import ScraperCreateModal from '../components/settings/ScraperCreateModal';
import SalesDashboardSettingsPanel from '../components/settings/SalesDashboardSettingsPanel';
import FieldSalesSettingsPanel from '../components/settings/FieldSalesSettingsPanel';
import SeoEnginePanel from '../components/settings/SeoEnginePanel';
import AutoOptimizePanel from '../components/settings/AutoOptimizePanel';
import AiWorkspacePanel from '../components/settings/AiWorkspacePanel';
import SmsRoutingPanel from '../components/settings/SmsRoutingPanel';
import WordPressSyncKeyCard from '../components/settings/WordPressSyncKeyCard';
import SystemHealthWorkspace from '../components/SystemHealthWorkspace';
import FaqWorkspace from '../components/settings/FaqPanel/Workspace';
import KycSetupWizard from '../components/kyc/KycSetupWizard';
import { useAuth } from '../hooks/useAuth';
import useDashboardWidgets from '../hooks/useDashboardWidgets';
import { useToast } from '../components/ToastProvider';
import kyc from '../services/kyc';

const baseTabs = [
    { id: 'integrations', label: 'Integrations' },
    { id: 'kyc', label: 'KYC' },
    { id: 'billing', label: 'Billing' },
    { id: 'seo-engine', label: 'SEO Engine' },
    { id: 'auto-optimize', label: 'Auto Optimize' },
    { id: 'ai', label: 'AI Briefings' },
    { id: 'faq', label: 'FAQ & Feedback' },
    { id: 'templates', label: 'Templates' },
    { id: 'logs', label: 'Webhook Logs' },
    { id: 'error-logs', label: 'Error Logs' },
    { id: 'roles', label: 'Roles & Permissions' },
    { id: 'field-sales', label: 'Field Sales' },
    { id: 'security', label: 'Security' },
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
const customDurationOptionKey = '__custom';
const paymentLinkModeOptions = [
    { value: 'static_url', label: 'Static URL' },
    { value: 'proxy_hosted_checkout', label: 'CRM Proxy Checkout' },
];
const paymentLinkProxyWalletProviders = ['paystack', 'pesapal', 'pawapay'];

function statusChip(status) {
    if (['connected', 'healthy', 'success'].includes(status)) return 'bg-emerald-50 text-emerald-700 ring-emerald-200';
    if (['configured_disabled', 'partial', 'degraded', 'pending', 'queued', 'running'].includes(status)) return 'bg-amber-50 text-amber-700 ring-amber-200';
    if (['completed'].includes(status)) return 'bg-emerald-50 text-emerald-700 ring-emerald-200';
    if (['deferred', 'unknown'].includes(status)) return 'bg-slate-100 text-slate-700 ring-slate-300';
    return 'bg-rose-50 text-rose-700 ring-rose-200';
}

function formatDateTime(value) {
    if (!value) return 'Never';
    const date = new Date(value);
    if (Number.isNaN(date.getTime())) return 'Never';
    return date.toLocaleString();
}

function walletSyncStatusText(value) {
    return String(value || 'unknown').replaceAll('_', ' ');
}

function describeWalletSyncResult(result, label) {
    if (!result || typeof result !== 'object') {
        return null;
    }

    const status = String(result.status || 'unknown');
    if (status === 'synced') {
        const action = result.credential_action ? ` (${walletSyncStatusText(result.credential_action)})` : '';
        return `${label} synced${action}.`;
    }

    if (status === 'skipped') {
        const reason = result.reason ? ` (${walletSyncStatusText(result.reason)})` : '';
        return `${label} skipped${reason}.`;
    }

    const detail = String(result.error || result.reason || 'unknown error');
    return `${label} failed: ${detail}.`;
}

function summarizeWalletSyncResponse(response) {
    const walletSync = response?.wallet_sync;

    if (walletSync && typeof walletSync === 'object' && ('config' in walletSync || 'credentials' in walletSync)) {
        const message = [
            describeWalletSyncResult(walletSync.config, 'Wallet config'),
            describeWalletSyncResult(walletSync.credentials, 'Active wallet auth'),
        ].filter(Boolean).join(' ');
        const tone = walletSync.config?.status === 'failed' || walletSync.credentials?.status === 'failed'
            ? 'warning'
            : 'success';

        return message ? { tone, message } : null;
    }

    if (walletSync && typeof walletSync === 'object') {
        const entries = Object.values(walletSync).filter((entry) => entry && typeof entry === 'object');
        if (entries.length) {
            const counters = {
                config: { synced: 0, skipped: 0, failed: 0 },
                credentials: { synced: 0, skipped: 0, failed: 0 },
            };

            entries.forEach((entry) => {
                ['config', 'credentials'].forEach((key) => {
                    const status = String(entry?.[key]?.status || '');
                    if (status && counters[key][status] !== undefined) {
                        counters[key][status] += 1;
                    }
                });
            });

            const message = [
                `Wallet config: ${counters.config.synced} synced, ${counters.config.skipped} skipped, ${counters.config.failed} failed.`,
                `Active wallet auth: ${counters.credentials.synced} synced, ${counters.credentials.skipped} skipped, ${counters.credentials.failed} failed.`,
            ].join(' ');
            const tone = counters.config.failed > 0 || counters.credentials.failed > 0 ? 'warning' : 'success';

            return { tone, message };
        }
    }

    const singleMessage = [
        describeWalletSyncResult(response?.wallet_config_sync, 'Wallet config'),
        describeWalletSyncResult(response?.wallet_credentials_sync, 'Active wallet auth'),
    ].filter(Boolean).join(' ');

    if (!singleMessage) {
        return null;
    }

    const tone = response?.wallet_config_sync?.status === 'failed' || response?.wallet_credentials_sync?.status === 'failed'
        ? 'warning'
        : 'success';

    return { tone, message: singleMessage };
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
        db_host: platform.wp_provisioning?.db_host || '',
        db_name: platform.wp_provisioning?.db_name || '',
        db_user: platform.wp_provisioning?.db_user || '',
        db_pass: '',
        db_prefix: platform.wp_provisioning?.db_prefix || 'wp_',
        db_pass_configured: Boolean(platform.wp_provisioning?.db_pass_configured),
        currency_code: platform.currency || 'KES',
        timezone: platform.timezone || '',
        phone_prefix: platform.phone_prefix || '254',
        support_chat_url: platform.support_chat_url || '',
        support_board_api_url: platform.support_board_api_url || '',
        support_board_token: '',
        support_board_token_configured: Boolean(platform.support_board_token_configured),
        support_board_sender_id: platform.support_board_sender_id ?? '',
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
        db_host: '',
        db_name: '',
        db_user: '',
        db_pass: '',
        db_prefix: 'wp_',
        currency_code: 'KES',
        timezone: '',
        phone_prefix: '254',
        support_chat_url: '',
    };
}

function buildPackageEditor(platform) {
    const currency = platform?.currency || 'KES';
    const supportedCurrencies = Array.isArray(platform?.supported_currencies) && platform.supported_currencies.length > 0
        ? platform.supported_currencies.map((value) => String(value).toUpperCase())
        : [currency];
    const serverRows = Array.isArray(platform?.packages) ? platform.packages : [];

    const rows = serverRows.map((row) => ({
        id: row.id || null,
        name: row.name || '',
        display_name: row.display_name || '',
        tier: row.tier || 'custom',
        sort_order: Number(row.sort_order || 0),
        is_active: Boolean(row.is_active),
        is_public: row.is_public !== false,
        is_archived: Boolean(row.is_archived),
        origin: row.origin || 'admin',
        creator: row.creator || null,
        created_by_user_id: row.created_by_user_id || null,
        prices: Array.isArray(row.prices) && row.prices.length > 0
            ? row.prices.map((p) => ({
                id: p.id || null,
                duration_key: p.duration_key,
                duration_label: p.duration_label,
                duration_days: p.duration_days,
                price: Number(p.price || 0),
                currency: String(p.currency || row.currency || currency).toUpperCase(),
                is_active: Boolean(p.is_active),
                sort_order: Number(p.sort_order || 0),
            }))
            : [],
    }));

    return {
        reason: 'Updated market package catalog from settings workspace',
        rows,
        currency,
        supported_currencies: supportedCurrencies,
        effective_currencies: Array.isArray(platform?.effective_currencies) ? platform.effective_currencies : [currency],
        multi_currency_wallet_enabled: Boolean(platform?.multi_currency_wallet_enabled),
    };
}

function newPackageRow(sortOrder = 0, currency = 'KES') {
    return {
        id: null,
        name: '',
        display_name: '',
        tier: 'custom',
        sort_order: sortOrder,
        is_active: true,
        is_public: true,
        is_archived: false,
        prices: [{ id: null, duration_key: '1_month', duration_label: '1 Month', duration_days: 30, price: 0, currency, is_active: true, sort_order: 10 }],
    };
}

function newPriceRow(sortOrder = 0, currency = '') {
    return { id: null, duration_key: '', duration_label: '', duration_days: 30, price: 0, currency, is_active: true, sort_order: sortOrder };
}

function isPresetDurationKey(durationKey) {
    return defaultDurationOptions.some((option) => option.key === durationKey);
}

function packageDurationSelectValue(durationKey) {
    if (!durationKey) return '';
    return isPresetDurationKey(durationKey) ? durationKey : customDurationOptionKey;
}

function slugDurationKey(label, days) {
    const parsedDays = Number(days || 0);
    const dayKey = parsedDays > 0 ? `${parsedDays}_days` : '';
    const labelKey = String(label || '')
        .trim()
        .toLowerCase()
        .replace(/[^a-z0-9]+/g, '_')
        .replace(/^_+|_+$/g, '');

    return labelKey || dayKey || 'custom_duration';
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
            api_key_configured: false,
            sender_id: '',
        },
        markets: {},
    };
}

function buildSmsProviderForm(smsProvider) {
    const fallback = defaultSmsProviderForm();
    if (!smsProvider) {
        return fallback;
    }

    const rawMarkets = smsProvider.markets && typeof smsProvider.markets === 'object'
        ? smsProvider.markets
        : {};

    const markets = Object.fromEntries(
        Object.entries(rawMarkets).map(([platformId, entry]) => [
            platformId,
            {
                active_provider: entry?.active_provider ?? null,
                fallback_provider: entry?.fallback_provider ?? null,
                africastalking: {
                    username: entry?.africastalking?.username ?? '',
                    api_key: '',
                    api_key_configured: Boolean(entry?.africastalking?.api_key_configured),
                    sender_id: entry?.africastalking?.sender_id ?? '',
                },
                legacy_gateway: {
                    gateway_url: entry?.legacy_gateway?.gateway_url ?? '',
                    org_code: entry?.legacy_gateway?.org_code ?? '',
                },
            },
        ])
    );

    return {
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
            api_key_configured: Boolean(smsProvider.africastalking?.api_key_configured),
            sender_id: smsProvider.africastalking?.sender_id || '',
        },
        markets,
    };
}

function smsConfigReady(globalForm, marketEntry = null) {
    const provider = marketEntry?.active_provider || globalForm.active_provider;

    if (provider === 'africastalking') {
        const username = marketEntry?.africastalking?.username?.trim() || globalForm.africastalking.username.trim();
        const keyConfigured = marketEntry?.africastalking?.api_key_configured
            || marketEntry?.africastalking?.api_key?.trim()
            || globalForm.africastalking.api_key_configured
            || globalForm.africastalking.api_key?.trim();

        return Boolean(username) && Boolean(keyConfigured);
    }

    const gatewayUrl = marketEntry?.legacy_gateway?.gateway_url?.trim() || globalForm.legacy_gateway.gateway_url.trim();
    const orgCode = marketEntry?.legacy_gateway?.org_code?.trim() || globalForm.legacy_gateway.org_code.trim();

    return Boolean(gatewayUrl) && Boolean(orgCode);
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
    if (providerId === 'pawapay') return 'PawaPay';
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

const DEFAULT_WALLET_PROVIDER_SCHEMAS = {
    pesapal: {
        provider_key: 'pesapal',
        label: 'Pesapal',
        supported_environments: ['sandbox', 'production'],
        fields: [
            { key: 'consumer_key', label: 'Consumer key', type: 'text', placeholder: 'Consumer key', configured_flag: 'consumer_key_configured', serialize: 'trim' },
            { key: 'consumer_secret', label: 'Consumer secret', type: 'secret', placeholder: 'Consumer secret', configured_flag: 'consumer_secret_configured', serialize: 'trim' },
            { key: 'ipn_id', label: 'IPN ID', type: 'text', placeholder: 'IPN ID', serialize: 'trim' },
        ],
    },
    paystack: {
        provider_key: 'paystack',
        label: 'Paystack',
        supported_environments: ['sandbox', 'production'],
        fields: [
            { key: 'public_key', label: 'Public key', type: 'text', placeholder: 'Public key', configured_flag: 'public_key_configured', serialize: 'trim' },
            { key: 'secret_key', label: 'Secret key', type: 'secret', placeholder: 'Secret key', configured_flag: 'secret_key_configured', serialize: 'trim' },
        ],
    },
    mpesa_stk: {
        provider_key: 'mpesa_stk',
        label: 'M-Pesa STK',
        supported_environments: ['sandbox', 'production'],
        fields: [
            { key: 'transport', label: 'Transport', type: 'select', default: 'django_proxy', serialize: 'raw', options: ['django_proxy', 'direct_provider'] },
            { key: 'payment_service_base_url', label: 'Payment service base URL', type: 'url', placeholder: 'Payment service base URL', serialize: 'trim_or_null' },
            { key: 'organization_code', label: 'Organization code', type: 'text', placeholder: 'Organization code', default: '76', serialize: 'trim' },
            { key: 'callback_base_url', label: 'Callback base URL', type: 'url', placeholder: 'Callback base URL', serialize: 'trim_or_null' },
        ],
    },
};

function walletProviderSchemaLabel(providerKey, schema) {
    return schema?.label || walletProviderLabel(providerKey);
}

function walletProviderFieldOptions(field) {
    return Array.isArray(field?.options) ? field.options : [];
}

function walletProviderSelectOptionLabel(option) {
    if (option && typeof option === 'object') {
        return option.label || option.value || 'Option';
    }

    if (option === 'django_proxy') return 'Django proxy';
    if (option === 'direct_provider') return 'Direct provider';

    return String(option || '').replaceAll('_', ' ') || 'Option';
}

function walletProviderSelectOptionValue(option) {
    if (option && typeof option === 'object') {
        return option.value || '';
    }

    return String(option || '');
}

function walletProviderCredentialStatus(schema, providerConfig) {
    const fields = Array.isArray(schema?.fields) ? schema.fields : [];
    const configuredFields = fields.filter((field) => field.configured_flag);

    if (configuredFields.length) {
        const configuredCount = configuredFields.filter((field) => Boolean(providerConfig?.[field.configured_flag])).length;

        return {
            tone: configuredCount === configuredFields.length ? 'text-emerald-700' : 'text-amber-700',
            text: configuredCount === configuredFields.length
                ? 'Secrets stored'
                : `${configuredCount}/${configuredFields.length} secrets stored`,
        };
    }

    const selectField = fields.find((field) => field.type === 'select');
    if (selectField) {
        const selectedValue = providerConfig?.[selectField.key] ?? selectField.default ?? '';
        const selectedOption = walletProviderFieldOptions(selectField).find((option) => (
            walletProviderSelectOptionValue(option) === selectedValue
        ));

        return {
            tone: 'text-slate-500',
            text: walletProviderSelectOptionLabel(selectedOption || selectedValue || selectField.default || ''),
        };
    }

    return {
        tone: 'text-slate-500',
        text: 'Configured per environment',
    };
}

function serializeWalletProviderField(field, value) {
    if (field?.serialize === 'raw') {
        return value ?? field?.default ?? '';
    }

    const normalized = typeof value === 'string' ? value.trim() : value;

    if (field?.serialize === 'trim_or_null') {
        return normalized ? normalized : null;
    }

    if (normalized === null || normalized === undefined) {
        return field?.default ?? '';
    }

    return normalized;
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
            : walletProviderKey === 'pawapay'
                ? true  // PawaPay credentials are managed via Billing Profiles, not legacy wallet config
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
                self_checkout_fx_enabled: false,
                self_checkout_fx_currency: 'KES',
                self_checkout_fx_rate: '',
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
            self_checkout_fx_enabled: provider?.self_checkout_fx_enabled === true,
            self_checkout_fx_currency: provider?.self_checkout_fx_currency || 'KES',
            self_checkout_fx_rate: provider?.self_checkout_fx_rate != null ? String(provider.self_checkout_fx_rate) : '',
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
        free_trial_pin_set: false,
        free_trial_pin_last_updated_at: null,
        discount_pin_set: false,
        discount_pin_last_updated_at: null,
        discount_config: {
            max_percentage_by_platform: {},
        },
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
        free_trial_pin_set: Boolean(systemConfig.free_trial_pin_set),
        free_trial_pin_last_updated_at: systemConfig.free_trial_pin_last_updated_at || null,
        discount_pin_set: Boolean(systemConfig.discount_pin_set),
        discount_pin_last_updated_at: systemConfig.discount_pin_last_updated_at || null,
        discount_config: {
            max_percentage_by_platform: { ...(systemConfig.discount_config?.max_percentage_by_platform || {}) },
        },
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

function defaultFreeTrialPinForm() {
    return {
        pin: '',
        pin_confirmation: '',
        reason: 'Updated free-trial redemption PIN',
    };
}

function defaultDiscountPinForm() {
    return {
        pin: '',
        pin_confirmation: '',
        reason: 'Updated discount approval PIN',
    };
}

function defaultWalletPlatformForm(currency = 'KES') {
    const presets = ['500.00', '1000.00', '2000.00', '5000.00'];
    return {
        enabled: false,
        mode_override: 'inherit',
        currency_code: currency,
        supported_currencies: [currency],
        effective_currencies: [currency],
        multi_currency_wallet_enabled: false,
        min_single_topup: '100.00',
        max_single_topup: '50000.00',
        max_wallet_balance: '200000.00',
        topup_presets: presets,
        topup_presets_by_currency: {
            [currency]: presets,
        },
        limits_by_currency: {
            [currency]: {
                min_single_topup: '100.00',
                max_single_topup: '50000.00',
                max_wallet_balance: '200000.00',
            },
        },
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

    const primaryCurrency = wallet.currency_code || fallback.currency_code;
    const supportedCurrencies = Array.isArray(platform?.supported_currencies) && platform.supported_currencies.length > 0
        ? platform.supported_currencies.map((value) => String(value).toUpperCase())
        : (Array.isArray(wallet.supported_currencies) && wallet.supported_currencies.length > 0
            ? wallet.supported_currencies.map((value) => String(value).toUpperCase())
            : [primaryCurrency]);
    const topupPresetsByCurrency = supportedCurrencies.reduce((carry, currency) => {
        const values = Array.isArray(wallet.topup_presets_by_currency?.[currency]) && wallet.topup_presets_by_currency[currency].length > 0
            ? wallet.topup_presets_by_currency[currency].map((value) => String(value))
            : (currency === primaryCurrency ? fallback.topup_presets : []);
        carry[currency] = values;
        return carry;
    }, {});
    const limitsByCurrency = supportedCurrencies.reduce((carry, currency) => {
        carry[currency] = {
            min_single_topup: wallet.limits_by_currency?.[currency]?.min_single_topup
                || (currency === primaryCurrency ? wallet.min_single_topup : '')
                || fallback.min_single_topup,
            max_single_topup: wallet.limits_by_currency?.[currency]?.max_single_topup
                || (currency === primaryCurrency ? wallet.max_single_topup : '')
                || fallback.max_single_topup,
            max_wallet_balance: wallet.limits_by_currency?.[currency]?.max_wallet_balance
                || (currency === primaryCurrency ? wallet.max_wallet_balance : '')
                || fallback.max_wallet_balance,
        };
        return carry;
    }, {});

    return {
        ...fallback,
        enabled: Boolean(wallet.enabled),
        mode_override: wallet.mode_override || 'inherit',
        currency_code: primaryCurrency,
        supported_currencies: supportedCurrencies,
        effective_currencies: Array.isArray(platform?.effective_currencies) && platform.effective_currencies.length > 0
            ? platform.effective_currencies.map((value) => String(value).toUpperCase())
            : [primaryCurrency],
        multi_currency_wallet_enabled: Boolean(platform?.multi_currency_wallet_enabled),
        min_single_topup: wallet.min_single_topup || fallback.min_single_topup,
        max_single_topup: wallet.max_single_topup || fallback.max_single_topup,
        max_wallet_balance: wallet.max_wallet_balance || fallback.max_wallet_balance,
        topup_presets: Array.isArray(wallet.topup_presets) && wallet.topup_presets.length > 0
            ? wallet.topup_presets.map((value) => String(value))
            : fallback.topup_presets,
        topup_presets_by_currency: topupPresetsByCurrency,
        limits_by_currency: limitsByCurrency,
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

function buildWalletProvidersForm(platform, providerSchemas = DEFAULT_WALLET_PROVIDER_SCHEMAS, environmentOptions = ['sandbox', 'production']) {
    const credentials = platform?.wallet?.credentials || {};
    const schemas = providerSchemas && Object.keys(providerSchemas).length ? providerSchemas : DEFAULT_WALLET_PROVIDER_SCHEMAS;
    const form = {};

    Object.entries(schemas).forEach(([providerKey, schema]) => {
        const schemaEnvironments = schema?.supported_environments?.length ? schema.supported_environments : environmentOptions;
        const schemaFields = Array.isArray(schema?.fields) ? schema.fields : [];

        form[providerKey] = {};

        schemaEnvironments.forEach((environment) => {
            const maskedCredentials = credentials?.[providerKey]?.[environment] || {};
            const environmentForm = {};

            schemaFields.forEach((field) => {
                environmentForm[field.key] = maskedCredentials[field.key] ?? field.default ?? '';

                if (field.configured_flag) {
                    environmentForm[field.configured_flag] = Boolean(maskedCredentials[field.configured_flag]);
                }
            });

            form[providerKey][environment] = {
                ...maskedCredentials,
                ...environmentForm,
            };
        });
    });

    return {
        ...form,
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
    canManageMarkets,
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
    const [latestClientSyncRun, setLatestClientSyncRun] = useState(null);
    const [supportBoardSyncForm, setSupportBoardSyncForm] = useState({
        refresh: false,
        reason: 'Manual Support Board link sync from integrations workspace',
    });
    const [supportBoardSyncConfirmOpen, setSupportBoardSyncConfirmOpen] = useState(false);
    const [latestSupportBoardSyncResult, setLatestSupportBoardSyncResult] = useState(null);
    const [sbLeadImportForm, setSbLeadImportForm] = useState({
        mode: 'bootstrap',
        reason: 'Manual Support Board lead import from integrations workspace',
    });
    const [sbLeadImportConfirmOpen, setSbLeadImportConfirmOpen] = useState(false);
    const [latestSbLeadImportResult, setLatestSbLeadImportResult] = useState(null);
    const [smsProviderForm, setSmsProviderForm] = useState(defaultSmsProviderForm());
    const [smsTestForm, setSmsTestForm] = useState({
        market_id: null,
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
    const [discountPinForm, setDiscountPinForm] = useState(defaultDiscountPinForm());
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
    const [freeTrialPinForm, setFreeTrialPinForm] = useState(defaultFreeTrialPinForm());
    const [discountConfigReason, setDiscountConfigReason] = useState('Updated market discount guardrails');

    const { data, isLoading, error: integrationsError } = useQuery({
        queryKey: ['settings-integrations'],
        queryFn: () => api.get('/crm/settings/integrations').then((response) => response.data),
    });

    const services = data?.services || {};
    const walletConfig = data?.wallet || {};
    const walletSystemConfig = walletConfig.system || null;
    const walletModeOptions = walletConfig.mode_options || ['disabled', 'sandbox', 'production'];
    const walletEnvironmentOptions = walletConfig.environment_options || ['sandbox', 'production'];
    const walletProviderKeys = walletConfig.provider_keys || ['pesapal', 'paystack', 'mpesa_stk'];
    const walletProviderSchemas = walletConfig.provider_schemas || DEFAULT_WALLET_PROVIDER_SCHEMAS;
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
    const clientSyncStatusQuery = useQuery({
        queryKey: ['settings-client-sync', selectedPlatformId],
        queryFn: () => api.get(`/crm/settings/integrations/platforms/${selectedPlatformId}/sync/latest`).then((response) => response.data),
        enabled: Boolean(selectedPlatformId) && canManageMarkets,
        refetchInterval: (query) => query.state.data?.run?.in_progress ? 4000 : false,
    });
    const supportBoardSyncStatusQuery = useQuery({
        queryKey: ['settings-support-board-sync', selectedPlatformId],
        queryFn: () => api.get(`/crm/settings/integrations/platforms/${selectedPlatformId}/support-board/sync/latest`).then((response) => response.data),
        enabled: Boolean(selectedPlatformId) && canManageMarkets,
        refetchInterval: (query) => query.state.data?.run?.in_progress ? 4000 : false,
    });
    const sbLeadImportStatusQuery = useQuery({
        queryKey: ['settings-sb-lead-import', selectedPlatformId],
        queryFn: () => api.get(`/crm/settings/integrations/platforms/${selectedPlatformId}/support-board/lead-import/latest`).then((response) => response.data),
        enabled: Boolean(selectedPlatformId) && canManageMarkets,
        refetchInterval: (query) => query.state.data?.run?.in_progress ? 4000 : false,
    });
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
        const allowedAreas = new Set(['overview', 'wallet', 'markets', 'payment_links', 'messaging', 'sms', 'push', 'scraper']);
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
        setLatestClientSyncRun(selectedPlatform.client_sync?.latest_run || null);
        setLatestSupportBoardSyncResult(selectedPlatform.support_board_sync?.latest_run || null);
        setPaymentLinkForm(buildPaymentLinkProviderForm(selectedPlatform));
        setPackageEditor(buildPackageEditor(selectedPlatform));
        setWalletPlatformForm(buildWalletPlatformForm(selectedPlatform));
        setWalletProvidersForm(buildWalletProvidersForm(selectedPlatform, walletProviderSchemas, walletEnvironmentOptions));
        setWalletCredentialReveal(null);
    }, [selectedPlatformId, selectedPlatform]);

    useEffect(() => {
        if (!clientSyncStatusQuery.data) {
            return;
        }

        setLatestClientSyncRun(clientSyncStatusQuery.data.run || null);
        if (clientSyncStatusQuery.data.platform?.sync?.last_result) {
            setLatestSyncResult(clientSyncStatusQuery.data.platform.sync.last_result);
        }
    }, [clientSyncStatusQuery.data]);

    useEffect(() => {
        if (!supportBoardSyncStatusQuery.data) {
            return;
        }

        setLatestSupportBoardSyncResult(supportBoardSyncStatusQuery.data.run || null);
    }, [supportBoardSyncStatusQuery.data]);

    useEffect(() => {
        if (!sbLeadImportStatusQuery.data) {
            return;
        }

        setLatestSbLeadImportResult(sbLeadImportStatusQuery.data.run || null);
    }, [sbLeadImportStatusQuery.data]);

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
        setSmsProviderForm(buildSmsProviderForm(smsProviderConfig));
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
            if (response?.run) {
                queryClient.setQueryData(['settings-client-sync', response?.platform?.platform_id || selectedPlatformId], response);
                setLatestClientSyncRun(response.run);
                toast[response?.reused_run ? 'warning' : 'success'](
                    response?.message || (response?.reused_run ? 'A client sync is already running for this market.' : 'Client sync has been queued.')
                );
            } else {
                setLatestSyncResult(response?.result || null);
                toast.success(response?.status === 'partial' ? 'Sync completed with warnings.' : 'Sync completed successfully.');
            }
            setSyncConfirmOpen(false);
        },
        onError: (error) => {
            toast.error(error?.response?.data?.message || 'Manual sync failed.');
        },
    });

    const runSupportBoardSyncMutation = useMutation({
        mutationFn: ({ platformId, payload }) => api.post(`/crm/settings/integrations/platforms/${platformId}/support-board/sync`, payload).then((response) => response.data),
        onSuccess: (response) => {
            queryClient.invalidateQueries({ queryKey: ['settings-integrations'] });
            queryClient.setQueryData(['settings-support-board-sync', response?.platform?.platform_id || selectedPlatformId], response);
            setLatestSupportBoardSyncResult(response?.run || null);
            setSupportBoardSyncConfirmOpen(false);
            toast[response?.reused_run ? 'warning' : 'success'](
                response?.reused_run
                    ? (response?.message || 'A Support Board link sync is already running for this market.')
                    : (response?.message || 'Support Board link sync has been queued.')
            );
        },
        onError: (error) => {
            const run = error?.response?.data?.run || null;
            if (run) {
                setLatestSupportBoardSyncResult(run);
            }
            toast.error(error?.response?.data?.message || 'Support Board link sync failed.');
        },
    });

    const runSbLeadImportMutation = useMutation({
        mutationFn: ({ platformId, payload }) => api.post(`/crm/settings/integrations/platforms/${platformId}/support-board/lead-import`, payload).then((response) => response.data),
        onSuccess: (response) => {
            queryClient.invalidateQueries({ queryKey: ['settings-integrations'] });
            setLatestSbLeadImportResult(response?.run || null);
            setSbLeadImportConfirmOpen(false);
            toast[response?.reused_run ? 'warning' : 'success'](
                response?.reused_run
                    ? (response?.message || 'A Support Board lead import is already running for this market.')
                    : (response?.message || 'Support Board lead import has been queued.')
            );
        },
        onError: (error) => {
            const run = error?.response?.data?.run || null;
            if (run) {
                setLatestSbLeadImportResult(run);
            }
            toast.error(error?.response?.data?.message || 'Support Board lead import failed.');
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
            setSmsProviderForm(buildSmsProviderForm(response?.sms_provider || null));
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
            const syncSummary = summarizeWalletSyncResponse(response);
            if (syncSummary) {
                toast[syncSummary.tone](syncSummary.message, { title: 'Wallet system settings saved' });
                return;
            }

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

    const updateFreeTrialPinMutation = useMutation({
        mutationFn: (payload) => api.patch('/crm/settings/free-trial/pin', payload).then((response) => response.data),
        onSuccess: (response) => {
            queryClient.invalidateQueries({ queryKey: ['settings-integrations'] });
            const system = buildWalletSystemForm(response?.system || null);
            setWalletSystemForm((current) => ({
                ...current,
                free_trial_pin_set: system.free_trial_pin_set,
                free_trial_pin_last_updated_at: system.free_trial_pin_last_updated_at,
            }));
            setFreeTrialPinForm(defaultFreeTrialPinForm());
            toast.success('Free-trial PIN updated.');
        },
        onError: (error) => {
            toast.error(error?.response?.data?.message || 'Failed to update free-trial PIN.');
        },
    });

    const updateDiscountPinMutation = useMutation({
        mutationFn: (payload) => api.patch('/crm/settings/discounts/pin', payload).then((response) => response.data),
        onSuccess: (response) => {
            queryClient.invalidateQueries({ queryKey: ['settings-integrations'] });
            const system = buildWalletSystemForm(response?.system || null);
            setWalletSystemForm((current) => ({
                ...current,
                discount_pin_set: system.discount_pin_set,
                discount_pin_last_updated_at: system.discount_pin_last_updated_at,
            }));
            setDiscountPinForm(defaultDiscountPinForm());
            toast.success('Discount PIN updated.');
        },
        onError: (error) => {
            toast.error(error?.response?.data?.message || 'Failed to update discount PIN.');
        },
    });

    const updateDiscountConfigMutation = useMutation({
        mutationFn: (payload) => api.patch('/crm/settings/discounts/config', payload).then((response) => response.data),
        onSuccess: (response) => {
            queryClient.invalidateQueries({ queryKey: ['settings-integrations'] });
            const system = buildWalletSystemForm(response?.system || null);
            setWalletSystemForm((current) => ({
                ...current,
                discount_config: system.discount_config,
            }));
            setDiscountConfigReason('Updated market discount guardrails');
            toast.success('Discount configuration saved.');
        },
        onError: (error) => {
            toast.error(error?.response?.data?.message || 'Failed to save discount configuration.');
        },
    });

    const saveWalletPlatformMutation = useMutation({
        mutationFn: ({ platformId, payload }) => api.patch(`/crm/settings/integrations/platforms/${platformId}/wallet`, payload).then((response) => response.data),
        onSuccess: (response) => {
            queryClient.invalidateQueries({ queryKey: ['settings-integrations'] });
            if (response?.platform) {
                setWalletPlatformForm(buildWalletPlatformForm(response.platform));
                setWalletProvidersForm(buildWalletProvidersForm(response.platform, walletProviderSchemas, walletEnvironmentOptions));
            }
            const syncSummary = summarizeWalletSyncResponse(response);
            if (syncSummary) {
                toast[syncSummary.tone](syncSummary.message, { title: 'Platform wallet settings saved' });
                return;
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
                setWalletProvidersForm(buildWalletProvidersForm(response.platform, walletProviderSchemas, walletEnvironmentOptions));
            }
            const syncSummary = summarizeWalletSyncResponse(response);
            if (syncSummary) {
                toast[syncSummary.tone](syncSummary.message, { title: 'Wallet provider credentials saved' });
                return;
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
            const revealed = response?.revealed && typeof response.revealed === 'object' ? response.revealed : {};
            if (Object.keys(revealed).length > 0) {
                setWalletCredentialReveal({
                    environment: response?.environment,
                    credential: response?.credential,
                    revealed,
                });
            } else {
                setWalletCredentialReveal(null);
            }
            const syncSummary = summarizeWalletSyncResponse(response);
            if (syncSummary) {
                toast[syncSummary.tone](syncSummary.message, { title: 'Wallet credentials rotated' });
                return;
            }

            toast.success('Wallet credentials rotated.');
        },
        onError: (error) => {
            toast.error(error?.response?.data?.message || 'Failed to rotate wallet credential.');
        },
    });

    const pushWalletCredentialsMutation = useMutation({
        mutationFn: ({ platformId, payload }) => api.post(`/crm/settings/integrations/platforms/${platformId}/wallet/wp-credentials/push`, payload).then((response) => response.data),
        onSuccess: (response) => {
            queryClient.invalidateQueries({ queryKey: ['settings-integrations'] });
            if (response?.platform) {
                setWalletProvidersForm(buildWalletProvidersForm(response.platform, walletProviderSchemas, walletEnvironmentOptions));
            }

            const syncSummary = summarizeWalletSyncResponse(response);
            if (syncSummary) {
                toast[syncSummary.tone](syncSummary.message, { title: 'Active wallet auth push finished' });
                return;
            }

            toast.success('Active wallet auth pushed to WordPress.');
        },
        onError: (error) => {
            toast.error(error?.response?.data?.message || 'Failed to push wallet credentials to WordPress.');
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

    const saveFreeTrialPin = () => {
        updateFreeTrialPinMutation.mutate({
            pin: freeTrialPinForm.pin.trim(),
            pin_confirmation: freeTrialPinForm.pin_confirmation.trim(),
            reason: freeTrialPinForm.reason.trim(),
        });
    };

    const updateDiscountPlatformMax = (platformId, value) => {
        const key = String(platformId);
        setWalletSystemForm((current) => ({
            ...current,
            discount_config: {
                ...current.discount_config,
                max_percentage_by_platform: {
                    ...(current.discount_config?.max_percentage_by_platform || {}),
                    [key]: value,
                },
            },
        }));
    };

    const saveDiscountPin = () => {
        updateDiscountPinMutation.mutate({
            pin: discountPinForm.pin.trim(),
            pin_confirmation: discountPinForm.pin_confirmation.trim(),
            reason: discountPinForm.reason.trim(),
        });
    };

    const saveDiscountConfig = () => {
        const maxPercentageByPlatform = {};

        platformRows.forEach((platform) => {
            const key = String(platform.platform_id);
            const rawValue = String(walletSystemForm.discount_config?.max_percentage_by_platform?.[key] ?? '').trim();
            if (rawValue === '') {
                return;
            }

            const numericValue = Number(rawValue);
            if (!Number.isNaN(numericValue)) {
                maxPercentageByPlatform[key] = numericValue;
            }
        });

        updateDiscountConfigMutation.mutate({
            discount_config: {
                max_percentage_by_platform: maxPercentageByPlatform,
            },
            reason: discountConfigReason.trim(),
        });
    };

    const updateWalletPlatformField = (field, value) => {
        setWalletPlatformForm((current) => ({
            ...current,
            [field]: value,
        }));
    };

    const updateWalletSupportedCurrencies = (value) => {
        setWalletPlatformForm((current) => {
            const parsed = value
                .split(',')
                .map((entry) => entry.trim().toUpperCase())
                .filter(Boolean);
            const supportedCurrencies = Array.from(new Set([current.currency_code, ...parsed]));
            const topupPresetsByCurrency = { ...(current.topup_presets_by_currency || {}) };
            const limitsByCurrency = { ...(current.limits_by_currency || {}) };

            supportedCurrencies.forEach((currency) => {
                if (!Array.isArray(topupPresetsByCurrency[currency])) {
                    topupPresetsByCurrency[currency] = currency === current.currency_code
                        ? [...(current.topup_presets || [])]
                        : [];
                }
                if (!limitsByCurrency[currency]) {
                    limitsByCurrency[currency] = {
                        min_single_topup: currency === current.currency_code ? current.min_single_topup : '',
                        max_single_topup: currency === current.currency_code ? current.max_single_topup : '',
                        max_wallet_balance: currency === current.currency_code ? current.max_wallet_balance : '',
                    };
                }
            });

            Object.keys(topupPresetsByCurrency).forEach((currency) => {
                if (!supportedCurrencies.includes(currency)) {
                    delete topupPresetsByCurrency[currency];
                }
            });
            Object.keys(limitsByCurrency).forEach((currency) => {
                if (!supportedCurrencies.includes(currency)) {
                    delete limitsByCurrency[currency];
                }
            });

            return {
                ...current,
                supported_currencies: supportedCurrencies,
                topup_presets_by_currency: topupPresetsByCurrency,
                limits_by_currency: limitsByCurrency,
            };
        });
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

    const updateWalletLimitByCurrency = (currency, field, value) => {
        setWalletPlatformForm((current) => ({
            ...current,
            limits_by_currency: {
                ...(current.limits_by_currency || {}),
                [currency]: {
                    ...((current.limits_by_currency || {})[currency] || {}),
                    [field]: value,
                },
            },
            ...(currency === current.currency_code
                ? ({
                    min_single_topup: field === 'min_single_topup' ? value : current.min_single_topup,
                    max_single_topup: field === 'max_single_topup' ? value : current.max_single_topup,
                    max_wallet_balance: field === 'max_wallet_balance' ? value : current.max_wallet_balance,
                })
                : {}),
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

    const updateWalletTopupPresetByCurrency = (currency, index, value) => {
        setWalletPlatformForm((current) => {
            const existing = Array.isArray(current.topup_presets_by_currency?.[currency])
                ? current.topup_presets_by_currency[currency]
                : [];
            const updated = existing.map((preset, presetIndex) => (presetIndex === index ? value : preset));

            return {
                ...current,
                topup_presets_by_currency: {
                    ...(current.topup_presets_by_currency || {}),
                    [currency]: updated,
                },
                ...(currency === current.currency_code ? { topup_presets: updated } : {}),
            };
        });
    };

    const addWalletTopupPreset = () => {
        setWalletPlatformForm((current) => ({
            ...current,
            topup_presets: [...current.topup_presets, ''],
        }));
    };

    const addWalletTopupPresetByCurrency = (currency) => {
        setWalletPlatformForm((current) => {
            const existing = Array.isArray(current.topup_presets_by_currency?.[currency])
                ? current.topup_presets_by_currency[currency]
                : [];
            const updated = [...existing, ''];

            return {
                ...current,
                topup_presets_by_currency: {
                    ...(current.topup_presets_by_currency || {}),
                    [currency]: updated,
                },
                ...(currency === current.currency_code ? { topup_presets: updated } : {}),
            };
        });
    };

    const removeWalletTopupPreset = (index) => {
        setWalletPlatformForm((current) => ({
            ...current,
            topup_presets: current.topup_presets.filter((_, presetIndex) => presetIndex !== index),
        }));
    };

    const removeWalletTopupPresetByCurrency = (currency, index) => {
        setWalletPlatformForm((current) => {
            const existing = Array.isArray(current.topup_presets_by_currency?.[currency])
                ? current.topup_presets_by_currency[currency]
                : [];
            const updated = existing.filter((_, presetIndex) => presetIndex !== index);

            return {
                ...current,
                topup_presets_by_currency: {
                    ...(current.topup_presets_by_currency || {}),
                    [currency]: updated,
                },
                ...(currency === current.currency_code ? { topup_presets: updated } : {}),
            };
        });
    };

    const saveWalletPlatformConfig = () => {
        if (!selectedPlatform) {
            return;
        }

        const primaryCurrency = walletPlatformForm.currency_code.trim().toUpperCase();
        const supportedCurrencies = Array.from(new Set([primaryCurrency, ...(walletPlatformForm.supported_currencies || [])]));
        const topupPresetsByCurrency = {};
        const limitsByCurrency = {};

        for (const currency of supportedCurrencies) {
            const presets = (walletPlatformForm.topup_presets_by_currency?.[currency] || [])
                .map((value) => String(value).trim())
                .filter(Boolean);

            if (presets.length === 0) {
                toast.error(`${currency} needs at least one wallet top-up preset before saving.`);
                return;
            }

            const minSingleTopup = String(walletPlatformForm.limits_by_currency?.[currency]?.min_single_topup || '').trim();
            const maxSingleTopup = String(walletPlatformForm.limits_by_currency?.[currency]?.max_single_topup || '').trim();
            const maxWalletBalance = String(walletPlatformForm.limits_by_currency?.[currency]?.max_wallet_balance || '').trim();

            if (!minSingleTopup || !maxSingleTopup || !maxWalletBalance) {
                toast.error(`${currency} needs min top-up, max single top-up, and max wallet balance.`);
                return;
            }

            if (Number(minSingleTopup) > Number(maxSingleTopup)) {
                toast.error(`${currency} minimum top-up cannot exceed the max single top-up.`);
                return;
            }

            topupPresetsByCurrency[currency] = presets;
            limitsByCurrency[currency] = {
                min_single_topup: minSingleTopup,
                max_single_topup: maxSingleTopup,
                max_wallet_balance: maxWalletBalance,
            };
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
                currency_code: primaryCurrency,
                supported_currencies: supportedCurrencies,
                multi_currency_wallet_enabled: Boolean(walletPlatformForm.multi_currency_wallet_enabled),
                min_single_topup: limitsByCurrency[primaryCurrency]?.min_single_topup || walletPlatformForm.min_single_topup.trim(),
                max_single_topup: limitsByCurrency[primaryCurrency]?.max_single_topup || walletPlatformForm.max_single_topup.trim(),
                max_wallet_balance: limitsByCurrency[primaryCurrency]?.max_wallet_balance || walletPlatformForm.max_wallet_balance.trim(),
                topup_presets: topupPresetsByCurrency[primaryCurrency] || [],
                topup_presets_by_currency: topupPresetsByCurrency,
                limits_by_currency: limitsByCurrency,
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

        const providerPayload = walletProviderKeys.reduce((carry, providerKey) => {
            const schema = walletProviderSchemas[providerKey] || DEFAULT_WALLET_PROVIDER_SCHEMAS[providerKey];
            const schemaFields = Array.isArray(schema?.fields) ? schema.fields : [];
            const schemaEnvironments = schema?.supported_environments?.length ? schema.supported_environments : walletEnvironmentOptions;

            carry[providerKey] = schemaEnvironments.reduce((environmentCarry, environment) => {
                environmentCarry[environment] = schemaFields.reduce((fieldCarry, field) => {
                    fieldCarry[field.key] = serializeWalletProviderField(
                        field,
                        walletProvidersForm?.[providerKey]?.[environment]?.[field.key]
                    );

                    return fieldCarry;
                }, {});

                return environmentCarry;
            }, {});

            return carry;
        }, {});

        saveWalletProvidersMutation.mutate({
            platformId: selectedPlatform.platform_id,
            payload: {
                ...providerPayload,
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
            markets: Object.fromEntries(
                Object.entries(smsProviderForm.markets || {}).map(([platformId, entry]) => {
                    const marketAt = entry.africastalking ?? {};
                    const marketLegacy = entry.legacy_gateway ?? {};
                    const atPayload = {
                        username: (marketAt.username ?? '').trim(),
                        sender_id: (marketAt.sender_id ?? '').trim(),
                    };
                    const rotatedApiKey = (marketAt.api_key ?? '').trim();
                    if (rotatedApiKey) {
                        atPayload.api_key = rotatedApiKey;
                    }

                    return [platformId, {
                        active_provider: entry.active_provider ?? null,
                        fallback_provider: entry.fallback_provider ?? null,
                        africastalking: atPayload,
                        legacy_gateway: {
                            gateway_url: (marketLegacy.gateway_url ?? '').trim(),
                            org_code: (marketLegacy.org_code ?? '').trim(),
                        },
                    }];
                })
            ),
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
                    self_checkout_fx_enabled: false,
                    self_checkout_fx_currency: 'KES',
                    self_checkout_fx_rate: '',
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
                self_checkout_fx_enabled: Boolean(provider.self_checkout_fx_enabled),
                self_checkout_fx_currency: String(provider.self_checkout_fx_currency || '').trim().toUpperCase(),
                self_checkout_fx_rate: String(provider.self_checkout_fx_rate || '').trim(),
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

        const invalidProxyFxProvider = normalizedProviders.find((provider) => (
            provider.mode === 'proxy_hosted_checkout'
            && provider.self_checkout_fx_enabled
            && (
                !provider.self_checkout_fx_currency
                || !provider.self_checkout_fx_rate
                || Number.parseFloat(provider.self_checkout_fx_rate) <= 0
            )
        ));
        if (invalidProxyFxProvider) {
            toast.error(`${invalidProxyFxProvider.label || invalidProxyFxProvider.key} needs a target charge currency and exchange rate for the self-checkout FX test.`);
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
                self_checkout_fx_enabled: provider.mode === 'proxy_hosted_checkout'
                    ? provider.self_checkout_fx_enabled
                    : false,
                self_checkout_fx_currency: provider.mode === 'proxy_hosted_checkout' && provider.self_checkout_fx_enabled
                    ? (provider.self_checkout_fx_currency || null)
                    : null,
                self_checkout_fx_rate: provider.mode === 'proxy_hosted_checkout' && provider.self_checkout_fx_enabled
                    ? Number.parseFloat(provider.self_checkout_fx_rate)
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
                    if (field === 'is_active' || field === 'is_public') return { ...row, [field]: Boolean(value) };
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
                                if (value === customDurationOptionKey) {
                                    const customLabel = isPresetDurationKey(price.duration_key) ? '' : price.duration_label;
                                    const customDays = Number(price.duration_days || 2);
                                    return {
                                        ...price,
                                        duration_key: slugDurationKey(customLabel || `${customDays} Days`, customDays),
                                        duration_label: customLabel || `${customDays} Days`,
                                        duration_days: customDays,
                                    };
                                }
                                const preset = defaultDurationOptions.find((d) => d.key === value);
                                return {
                                    ...price,
                                    duration_key: value,
                                    duration_label: preset ? preset.label : price.duration_label,
                                    duration_days: preset ? preset.days : price.duration_days,
                                };
                            }
                            if (field === 'duration_label') {
                                return {
                                    ...price,
                                    duration_label: value,
                                    duration_key: isPresetDurationKey(price.duration_key)
                                        ? price.duration_key
                                        : slugDurationKey(value, price.duration_days),
                                };
                            }
                            if (field === 'duration_days') {
                                const days = Number(value || 1);
                                return {
                                    ...price,
                                    duration_days: days,
                                    duration_key: isPresetDurationKey(price.duration_key)
                                        ? price.duration_key
                                        : slugDurationKey(price.duration_label, days),
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
            return {
                ...current,
                rows: [...current.rows, newPackageRow(maxSort + 10, current.supported_currencies?.[0] || current.currency || 'KES')],
            };
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
                    return {
                        ...row,
                        prices: [...row.prices, newPriceRow(maxSort + 10, current.supported_currencies?.[0] || current.currency || 'KES')],
                    };
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
                const durationKey = isPresetDurationKey(price.duration_key)
                    ? price.duration_key
                    : slugDurationKey(price.duration_label, price.duration_days);
                if (!durationKey || !Number(price.duration_days || 0)) {
                    toast.error(`${row.name || 'Unnamed package'} has an incomplete duration row. Select a preset or enter a custom label and days.`);
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
                    is_public: row.is_public !== false,
                    is_archived: Boolean(row.is_archived),
                    prices: row.prices.map((p) => ({
                        id: p.id || undefined,
                        duration_key: isPresetDurationKey(p.duration_key) ? p.duration_key : slugDurationKey(p.duration_label, p.duration_days),
                        duration_label: p.duration_label || p.duration_key.replace(/_/g, ' '),
                        duration_days: p.duration_days || 30,
                        price: Number(p.price || 0),
                        currency: String(p.currency || packageEditor.currency || 'KES').toUpperCase(),
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
    const smsReady = smsConfigReady(smsProviderForm);
    const selectedSmsTestMarket = smsTestForm.market_id
        ? (smsProviderForm.markets?.[String(smsTestForm.market_id)] || null)
        : null;
    const selectedSmsTestPlatform = smsTestForm.market_id
        ? (platformRows.find((platform) => String(platform.platform_id) === String(smsTestForm.market_id)) || null)
        : null;
    const smsTestReady = smsConfigReady(smsProviderForm, selectedSmsTestMarket);
    const smsTestProvider = selectedSmsTestMarket?.active_provider || smsProviderForm.active_provider;
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
    const activeWalletEnvironment = selectedWalletEffectiveMode === 'production' ? 'production' : 'sandbox';
    const selectedWalletProvidersEnabled = Object.values(walletPlatformForm.providers || {}).filter((provider) => provider?.enabled).length;
    const selectedWalletWpCredentials = walletProvidersForm.wp_to_crm || {};
    const walletGuideDomain = walletSystemForm.billing_domains?.[walletGuideEnvironment]?.trim() || '';
    const walletGuideBaseUrl = walletGuideDomain || 'https://billing.example.com';
    const walletGuideProxyTarget = (typeof window !== 'undefined' ? window.location.origin : '') || 'https://crm.example.com';
    const walletGuideHealthUrl = `${walletGuideBaseUrl.replace(/\/$/, '')}/api/billing/health`;
    const walletGuideCompleteUrl = `${walletGuideBaseUrl.replace(/\/$/, '')}/billing/complete?payment={transaction_uuid}`;
    const walletGuidePaystackWebhookUrl = `${walletGuideBaseUrl.replace(/\/$/, '')}/api/billing/paystack/webhook`;
    const walletGuidePesapalIpnUrl = `${walletGuideBaseUrl.replace(/\/$/, '')}/api/billing/pesapal/ipn`;
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
    const clientSyncQueue = clientSyncStatusQuery.data?.platform?.client_sync?.queue
        || selectedPlatform?.client_sync?.queue
        || latestClientSyncRun?.queue
        || null;
    const clientSyncActive = Boolean(latestClientSyncRun?.in_progress);
    const clientSyncQueueReady = Boolean(clientSyncQueue?.available ?? true);
    const clientSyncStatusLabel = latestClientSyncRun?.status
        ? latestClientSyncRun.status.replaceAll('_', ' ')
        : 'idle';
    const latestClientPruned = Number(latestSyncResult?.clients?.pruned || 0);
    const canPushActiveWalletCredentials = selectedHasCredentials && selectedWalletEffectiveMode !== 'disabled';
    const selectedSupportBoardConfigured = Boolean(
        selectedPlatform?.support_board_api_url
        && selectedPlatform?.support_board_token_configured
    );
    const supportBoardSyncQueue = supportBoardSyncStatusQuery.data?.platform?.support_board_sync?.queue
        || selectedPlatform?.support_board_sync?.queue
        || latestSupportBoardSyncResult?.queue
        || null;
    const supportBoardSyncActive = Boolean(latestSupportBoardSyncResult?.in_progress);
    const supportBoardSyncQueueReady = Boolean(supportBoardSyncQueue?.available ?? true);
    const supportBoardSyncStatusLabel = latestSupportBoardSyncResult?.status
        ? latestSupportBoardSyncResult.status.replaceAll('_', ' ')
        : 'idle';
    const sbLeadImportActive = Boolean(latestSbLeadImportResult?.in_progress);
    const sbLeadImportQueue = latestSbLeadImportResult?.queue || null;
    const sbLeadImportQueueReady = Boolean(sbLeadImportQueue?.available ?? true);
    const sbLeadImportStatusLabel = latestSbLeadImportResult?.status
        ? latestSbLeadImportResult.status.replaceAll('_', ' ')
        : 'idle';
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
        { id: 'messaging', label: 'Messaging', hint: 'Meta Cloud API' },
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
            <IntegrationsMetricsRow
                connectedServices={connectedServices}
                marketCount={platformRows.length}
                packageReadyMarkets={packageReadyMarkets}
                syncErrors={syncErrors}
                wpReadyMarkets={wpReadyMarkets}
            />

            <IntegrationsAreaNav
                integrationArea={integrationArea}
                integrationAreas={integrationAreas}
                onSelect={setIntegrationArea}
            />

            {integrationArea === 'overview' ? (
                <div className="space-y-4">
                    <IntegrationsOverviewPanel
                        isLoading={isLoading}
                        serviceRows={serviceRows}
                    />
                    <WordPressSyncKeyCard />
                </div>
            ) : null}

            {integrationArea === 'sms' ? (
                <SmsRoutingPanel
                    fallbackInvalid={fallbackInvalid}
                    fallbackOptions={fallbackOptions}
                    latestSmsTestResult={latestSmsTestResult}
                    markets={smsProviderForm.markets ?? {}}
                    onMarketsChange={(updated) => setSmsProviderForm((current) => ({ ...current, markets: updated }))}
                    platforms={platformRows.map((platform) => ({
                        id: platform.platform_id,
                        name: platform.platform_name,
                        country: platform.country,
                    }))}
                    saveSmsProviderConfig={saveSmsProviderConfig}
                    saveSmsProviderMutation={saveSmsProviderMutation}
                    setSmsProviderForm={setSmsProviderForm}
                    setSmsTestConfirmOpen={setSmsTestConfirmOpen}
                    setSmsTestForm={setSmsTestForm}
                    smsProviderForm={smsProviderForm}
                    smsProviderLabel={smsProviderLabel}
                    smsReady={smsReady}
                    smsTestForm={smsTestForm}
                    smsTestReady={smsTestReady}
                    statusChip={statusChip}
                    testSmsProviderMutation={testSmsProviderMutation}
                    updateSmsProviderField={updateSmsProviderField}
                />
            ) : null}

            {integrationArea === 'push' ? (
                <PushRoutingPanel
                    canManagePushProviders={canManagePushProviders}
                    latestPushTestResult={latestPushTestResult}
                    platformRows={platformRows}
                    pushFallbackInvalid={pushFallbackInvalid}
                    pushFallbackOptions={pushFallbackOptions}
                    pushPlatformId={pushPlatformId}
                    pushProviderForm={pushProviderForm}
                    pushProviderLabel={pushProviderLabel}
                    pushProviderOptions={pushProviderOptions}
                    pushTestForm={pushTestForm}
                    savePushProviderConfig={savePushProviderConfig}
                    savePushProviderMutation={savePushProviderMutation}
                    selectedPushConfig={selectedPushConfig}
                    selectedPushPlatform={selectedPushPlatform}
                    selectedPushProvider={selectedPushProvider}
                    selectedPushReady={selectedPushReady}
                    setPushPlatformId={setPushPlatformId}
                    setPushTestConfirmOpen={setPushTestConfirmOpen}
                    setPushTestForm={setPushTestForm}
                    statusChip={statusChip}
                    testPushProviderMutation={testPushProviderMutation}
                    updatePushPlatformField={updatePushPlatformField}
                    updatePushProviderCredentialField={updatePushProviderCredentialField}
                    updatePushProviderField={updatePushProviderField}
                />
            ) : null}

            {integrationArea === 'messaging' ? (
                <MessagingArea
                    platformRows={platformRows}
                    statusChip={statusChip}
                    toast={toast}
                />
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
                                                <p className="mt-1 break-all text-[11px] text-slate-600">Pesapal IPN: {walletGuidePesapalIpnUrl}</p>
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

                            <section className="rounded-lg border border-slate-200 bg-white p-3">
                                <div className="flex flex-wrap items-center justify-between gap-2">
                                    <div>
                                        <h4 className="text-sm font-semibold text-slate-900">Free-trial PIN</h4>
                                        <p className="mt-1 text-xs text-slate-500">Required when sales or sub-admin users redeem a free-trial activation, renewal, or extension from CRM.</p>
                                    </div>
                                    <span className={`inline-flex items-center rounded-md px-2 py-0.5 text-xs font-medium ring-1 ring-inset ${statusChip(walletSystemForm.free_trial_pin_set ? 'success' : 'pending')}`}>
                                        {walletSystemForm.free_trial_pin_set ? 'configured' : 'not set'}
                                    </span>
                                </div>

                                {!canManageWalletSystem ? (
                                    <p className="mt-3 rounded-md border border-amber-200 bg-amber-50 px-3 py-2 text-xs text-amber-800">
                                        Only admin can rotate the free-trial PIN. Sales and sub-admin users will redeem the configured PIN during free-trial subscription actions.
                                    </p>
                                ) : null}

                                <fieldset disabled={walletSystemReadOnly || updateFreeTrialPinMutation.isPending} className={`mt-3 ${walletSystemReadOnly ? 'opacity-70' : ''}`}>
                                    <div className="grid gap-3 md:grid-cols-2">
                                        <div className="rounded-md border border-slate-200 bg-slate-50 px-3 py-2 md:col-span-2">
                                            <p className="text-[11px] font-semibold uppercase tracking-[0.1em] text-slate-500">Last rotated</p>
                                            <p className="mt-1 text-sm font-semibold text-slate-900">{formatDateTime(walletSystemForm.free_trial_pin_last_updated_at)}</p>
                                        </div>

                                        <input
                                            type="password"
                                            inputMode="numeric"
                                            maxLength={6}
                                            value={freeTrialPinForm.pin}
                                            onChange={(event) => setFreeTrialPinForm((current) => ({ ...current, pin: event.target.value.replace(/\D/g, '').slice(0, 6) }))}
                                            className="crm-input"
                                            placeholder="New free-trial PIN"
                                        />
                                        <input
                                            type="password"
                                            inputMode="numeric"
                                            maxLength={6}
                                            value={freeTrialPinForm.pin_confirmation}
                                            onChange={(event) => setFreeTrialPinForm((current) => ({ ...current, pin_confirmation: event.target.value.replace(/\D/g, '').slice(0, 6) }))}
                                            className="crm-input"
                                            placeholder="Confirm free-trial PIN"
                                        />

                                        <textarea
                                            rows={2}
                                            value={freeTrialPinForm.reason}
                                            onChange={(event) => setFreeTrialPinForm((current) => ({ ...current, reason: event.target.value }))}
                                            className="crm-input md:col-span-2"
                                            placeholder="Reason for free-trial PIN rotation"
                                        />
                                    </div>
                                </fieldset>

                                <div className="mt-3 flex justify-end">
                                    <button
                                        type="button"
                                        onClick={saveFreeTrialPin}
                                        disabled={
                                            walletSystemReadOnly
                                            || updateFreeTrialPinMutation.isPending
                                            || freeTrialPinForm.pin.length < 4
                                            || freeTrialPinForm.pin_confirmation.length < 4
                                            || !freeTrialPinForm.reason.trim()
                                        }
                                        className="crm-btn-primary disabled:cursor-not-allowed disabled:opacity-60"
                                    >
                                        {updateFreeTrialPinMutation.isPending ? 'Saving...' : (walletSystemForm.free_trial_pin_set ? 'Rotate free-trial PIN' : 'Set free-trial PIN')}
                                    </button>
                                </div>
                            </section>

                            <section className="rounded-lg border border-slate-200 bg-white p-3">
                                <div className="flex flex-wrap items-center justify-between gap-2">
                                    <div>
                                        <h4 className="text-sm font-semibold text-slate-900">Discount Controls</h4>
                                        <p className="mt-1 text-xs text-slate-500">Approve subscription discounts with a PIN and set per-market maximum percentages.</p>
                                    </div>
                                    <span className={`inline-flex items-center rounded-md px-2 py-0.5 text-xs font-medium ring-1 ring-inset ${statusChip(walletSystemForm.discount_pin_set ? 'success' : 'pending')}`}>
                                        {walletSystemForm.discount_pin_set ? 'PIN configured' : 'PIN not set'}
                                    </span>
                                </div>

                                {!canManageWalletSystem ? (
                                    <p className="mt-3 rounded-md border border-amber-200 bg-amber-50 px-3 py-2 text-xs text-amber-800">
                                        Only admin can rotate the discount PIN or change market discount limits.
                                    </p>
                                ) : null}

                                <div className="mt-3 grid gap-4 xl:grid-cols-[minmax(0,0.9fr)_minmax(0,1.1fr)]">
                                    <div className="rounded-lg border border-slate-200 bg-slate-50 p-3">
                                        <div className="rounded-md border border-slate-200 bg-white px-3 py-2">
                                            <p className="text-[11px] font-semibold uppercase tracking-[0.1em] text-slate-500">Last rotated</p>
                                            <p className="mt-1 text-sm font-semibold text-slate-900">{formatDateTime(walletSystemForm.discount_pin_last_updated_at)}</p>
                                        </div>

                                        <fieldset disabled={walletSystemReadOnly || updateDiscountPinMutation.isPending} className={`mt-3 ${walletSystemReadOnly ? 'opacity-70' : ''}`}>
                                            <div className="grid gap-3 md:grid-cols-2">
                                                <input
                                                    type="password"
                                                    inputMode="numeric"
                                                    maxLength={6}
                                                    value={discountPinForm.pin}
                                                    onChange={(event) => setDiscountPinForm((current) => ({ ...current, pin: event.target.value.replace(/\D/g, '').slice(0, 6) }))}
                                                    className="crm-input"
                                                    placeholder="New discount PIN"
                                                />
                                                <input
                                                    type="password"
                                                    inputMode="numeric"
                                                    maxLength={6}
                                                    value={discountPinForm.pin_confirmation}
                                                    onChange={(event) => setDiscountPinForm((current) => ({ ...current, pin_confirmation: event.target.value.replace(/\D/g, '').slice(0, 6) }))}
                                                    className="crm-input"
                                                    placeholder="Confirm discount PIN"
                                                />
                                                <textarea
                                                    rows={2}
                                                    value={discountPinForm.reason}
                                                    onChange={(event) => setDiscountPinForm((current) => ({ ...current, reason: event.target.value }))}
                                                    className="crm-input md:col-span-2"
                                                    placeholder="Reason for discount PIN rotation"
                                                />
                                            </div>
                                        </fieldset>

                                        <div className="mt-3 flex justify-end">
                                            <button
                                                type="button"
                                                onClick={saveDiscountPin}
                                                disabled={
                                                    walletSystemReadOnly
                                                    || updateDiscountPinMutation.isPending
                                                    || discountPinForm.pin.length < 4
                                                    || discountPinForm.pin_confirmation.length < 4
                                                    || !discountPinForm.reason.trim()
                                                }
                                                className="crm-btn-primary disabled:cursor-not-allowed disabled:opacity-60"
                                            >
                                                {updateDiscountPinMutation.isPending ? 'Saving...' : (walletSystemForm.discount_pin_set ? 'Rotate discount PIN' : 'Set discount PIN')}
                                            </button>
                                        </div>
                                    </div>

                                    <div className="rounded-lg border border-slate-200 bg-slate-50 p-3">
                                        <div className="flex items-center justify-between gap-3">
                                            <div>
                                                <h5 className="text-sm font-semibold text-slate-900">Per-market max discount %</h5>
                                                <p className="mt-1 text-xs text-slate-500">Leave blank to block discounts in that market.</p>
                                            </div>
                                            <span className="rounded-md border border-slate-200 bg-white px-2 py-1 text-[11px] font-medium text-slate-600">
                                                Max 99%
                                            </span>
                                        </div>

                                        <fieldset disabled={walletSystemReadOnly || updateDiscountConfigMutation.isPending} className={`mt-3 space-y-3 ${walletSystemReadOnly ? 'opacity-70' : ''}`}>
                                            <div className="space-y-2">
                                                {platformRows.map((platform) => {
                                                    const key = String(platform.platform_id);
                                                    const rawValue = walletSystemForm.discount_config?.max_percentage_by_platform?.[key] ?? '';

                                                    return (
                                                        <div key={`discount-platform-${platform.platform_id}`} className="grid gap-2 rounded-md border border-slate-200 bg-white px-3 py-2 md:grid-cols-[minmax(0,1fr)_140px] md:items-center">
                                                            <div>
                                                                <p className="text-sm font-semibold text-slate-900">{platform.platform_name}</p>
                                                                <p className="text-xs text-slate-500">{platform.country || 'Market'} • {platform.currency || 'KES'}</p>
                                                            </div>
                                                            <label className="flex items-center gap-2">
                                                                <input
                                                                    type="number"
                                                                    min="0"
                                                                    max="99"
                                                                    step="0.01"
                                                                    value={rawValue}
                                                                    onChange={(event) => updateDiscountPlatformMax(platform.platform_id, event.target.value)}
                                                                    className="crm-input text-right"
                                                                    placeholder="e.g. 25"
                                                                />
                                                                <span className="text-sm font-medium text-slate-500">%</span>
                                                            </label>
                                                        </div>
                                                    );
                                                })}
                                            </div>

                                            <textarea
                                                rows={2}
                                                value={discountConfigReason}
                                                onChange={(event) => setDiscountConfigReason(event.target.value)}
                                                className="crm-input"
                                                placeholder="Reason for discount policy change"
                                            />
                                        </fieldset>

                                        <div className="mt-3 flex justify-end">
                                            <button
                                                type="button"
                                                onClick={saveDiscountConfig}
                                                disabled={walletSystemReadOnly || updateDiscountConfigMutation.isPending || !discountConfigReason.trim()}
                                                className="crm-btn-primary disabled:cursor-not-allowed disabled:opacity-60"
                                            >
                                                {updateDiscountConfigMutation.isPending ? 'Saving...' : 'Save discount limits'}
                                            </button>
                                        </div>
                                    </div>
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
                                                    <label className="mb-1 block text-sm font-medium text-slate-700">Primary currency</label>
                                                    <input
                                                        value={walletPlatformForm.currency_code}
                                                        onChange={(event) => updateWalletPlatformField('currency_code', event.target.value.toUpperCase())}
                                                        className="crm-input"
                                                        placeholder="KES"
                                                    />
                                                </div>

                                                <div>
                                                    <label className="mb-1 block text-sm font-medium text-slate-700">Supported currencies</label>
                                                    <input
                                                        value={(walletPlatformForm.supported_currencies || []).join(', ')}
                                                        onChange={(event) => updateWalletSupportedCurrencies(event.target.value)}
                                                        className="crm-input"
                                                        placeholder="CDF, USD"
                                                    />
                                                    <p className="mt-1 text-xs text-slate-500">
                                                        Admin config may stage extra currencies here. Runtime surfaces still use effective currencies only.
                                                    </p>
                                                </div>

                                                <label className="md:col-span-2 flex items-center gap-2 text-sm text-slate-700">
                                                    <input
                                                        type="checkbox"
                                                        checked={Boolean(walletPlatformForm.multi_currency_wallet_enabled)}
                                                        onChange={(event) => updateWalletPlatformField('multi_currency_wallet_enabled', event.target.checked)}
                                                        className="h-4 w-4 rounded border-slate-300 text-teal-700 focus:ring-teal-200"
                                                    />
                                                    Enable multi-currency wallet runtime for this market
                                                </label>

                                                <div className="md:col-span-2 rounded-md border border-slate-200 bg-white px-3 py-2 text-xs text-slate-600">
                                                    Effective checkout/wallet currencies: {(walletPlatformForm.effective_currencies || [walletPlatformForm.currency_code]).join(', ')}
                                                </div>

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
                                                    <label className="mb-1 block text-sm font-medium text-slate-700">Per-currency limits and top-up presets</label>
                                                    <div className="space-y-3">
                                                        {(walletPlatformForm.supported_currencies || [walletPlatformForm.currency_code]).map((currency) => (
                                                            <div key={`wallet-currency-${currency}`} className="rounded-md border border-slate-200 bg-slate-50 p-3">
                                                                <div className="grid gap-3 md:grid-cols-3">
                                                                    <div>
                                                                        <label className="mb-1 block text-xs font-semibold uppercase tracking-[0.08em] text-slate-500">{currency} min top-up</label>
                                                                        <input
                                                                            value={walletPlatformForm.limits_by_currency?.[currency]?.min_single_topup || ''}
                                                                            onChange={(event) => updateWalletLimitByCurrency(currency, 'min_single_topup', event.target.value)}
                                                                            className="crm-input"
                                                                            placeholder="Min top-up"
                                                                        />
                                                                    </div>
                                                                    <div>
                                                                        <label className="mb-1 block text-xs font-semibold uppercase tracking-[0.08em] text-slate-500">{currency} max single top-up</label>
                                                                        <input
                                                                            value={walletPlatformForm.limits_by_currency?.[currency]?.max_single_topup || ''}
                                                                            onChange={(event) => updateWalletLimitByCurrency(currency, 'max_single_topup', event.target.value)}
                                                                            className="crm-input"
                                                                            placeholder="Max single top-up"
                                                                        />
                                                                    </div>
                                                                    <div>
                                                                        <label className="mb-1 block text-xs font-semibold uppercase tracking-[0.08em] text-slate-500">{currency} max wallet balance</label>
                                                                        <input
                                                                            value={walletPlatformForm.limits_by_currency?.[currency]?.max_wallet_balance || ''}
                                                                            onChange={(event) => updateWalletLimitByCurrency(currency, 'max_wallet_balance', event.target.value)}
                                                                            className="crm-input"
                                                                            placeholder="Max wallet balance"
                                                                        />
                                                                    </div>
                                                                </div>

                                                                <div className="mt-3 space-y-2">
                                                                    {(walletPlatformForm.topup_presets_by_currency?.[currency] || []).map((preset, index) => (
                                                                        <div key={`wallet-preset-${currency}-${index}`} className="flex gap-2">
                                                                            <input
                                                                                value={preset}
                                                                                onChange={(event) => updateWalletTopupPresetByCurrency(currency, index, event.target.value)}
                                                                                className="crm-input flex-1"
                                                                                placeholder={`${currency} preset amount`}
                                                                            />
                                                                            <button
                                                                                type="button"
                                                                                onClick={() => removeWalletTopupPresetByCurrency(currency, index)}
                                                                                disabled={(walletPlatformForm.topup_presets_by_currency?.[currency] || []).length <= 1}
                                                                                className="rounded-md border border-rose-200 bg-rose-50 px-3 py-2 text-xs font-semibold text-rose-700 transition hover:bg-rose-100 disabled:cursor-not-allowed disabled:opacity-50"
                                                                            >
                                                                                Remove
                                                                            </button>
                                                                        </div>
                                                                    ))}
                                                                </div>
                                                                <button
                                                                    type="button"
                                                                    onClick={() => addWalletTopupPresetByCurrency(currency)}
                                                                    className="mt-2 text-xs font-semibold text-teal-700 hover:text-teal-900"
                                                                >
                                                                    + Add {currency} preset
                                                                </button>
                                                            </div>
                                                        ))}
                                                    </div>
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
                                                        {(() => {
                                                            const schema = walletProviderSchemas[providerKey] || DEFAULT_WALLET_PROVIDER_SCHEMAS[providerKey];
                                                            const schemaFields = Array.isArray(schema?.fields) ? schema.fields : [];

                                                            return (
                                                                <>
                                                                    <div className="flex flex-wrap items-center justify-between gap-2">
                                                                        <h5 className="text-sm font-semibold text-slate-900">{walletProviderSchemaLabel(providerKey, schema)}</h5>
                                                                        <span className="text-xs text-slate-500">{selectedPlatform.platform_name}</span>
                                                                    </div>

                                                                    <div className="mt-3 grid gap-3 xl:grid-cols-2">
                                                                        {walletEnvironmentOptions.map((environment) => {
                                                                            const providerConfig = walletProvidersForm[providerKey]?.[environment] || {};
                                                                            const status = walletProviderCredentialStatus(schema, providerConfig);

                                                                            return (
                                                                                <div key={`${providerKey}-${environment}`} className="rounded-md border border-slate-200 bg-white p-3">
                                                                                    <div className="flex items-center justify-between gap-2">
                                                                                        <p className="text-xs font-semibold uppercase tracking-[0.12em] text-slate-500">{environment}</p>
                                                                                        <span className={`text-[11px] ${status.tone}`}>{status.text}</span>
                                                                                    </div>

                                                                                    <div className="mt-3 grid gap-2">
                                                                                        {schemaFields.map((field) => (
                                                                                            field.type === 'select' ? (
                                                                                                <select
                                                                                                    key={`${providerKey}-${environment}-${field.key}`}
                                                                                                    value={providerConfig[field.key] || field.default || ''}
                                                                                                    onChange={(event) => updateWalletProviderCredentialField(providerKey, environment, field.key, event.target.value)}
                                                                                                    className="crm-select"
                                                                                                >
                                                                                                    {walletProviderFieldOptions(field).map((option) => (
                                                                                                        <option key={`${providerKey}-${environment}-${field.key}-${walletProviderSelectOptionValue(option)}`} value={walletProviderSelectOptionValue(option)}>
                                                                                                            {walletProviderSelectOptionLabel(option)}
                                                                                                        </option>
                                                                                                    ))}
                                                                                                </select>
                                                                                            ) : (
                                                                                                <input
                                                                                                    key={`${providerKey}-${environment}-${field.key}`}
                                                                                                    type={field.type === 'secret' ? 'password' : 'text'}
                                                                                                    value={providerConfig[field.key] || ''}
                                                                                                    onChange={(event) => updateWalletProviderCredentialField(providerKey, environment, field.key, event.target.value)}
                                                                                                    className="crm-input"
                                                                                                    placeholder={field.placeholder || field.label}
                                                                                                />
                                                                                            )
                                                                                        ))}
                                                                                    </div>
                                                                                </div>
                                                                            );
                                                                        })}
                                                                    </div>
                                                                </>
                                                            );
                                                        })()}
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
                                        <p className="mt-1 text-xs text-slate-500">Active-environment wallet auth is pushed to WordPress automatically. Rotating an inactive environment stores the secret for later without changing the live site.</p>
                                        <p className="mt-2 text-xs text-slate-600">
                                            Active WordPress auth environment:
                                            <span className="ml-1 inline-flex items-center rounded-full bg-slate-100 px-2 py-0.5 font-semibold text-slate-700">
                                                {selectedWalletEffectiveMode === 'disabled' ? 'wallet disabled' : activeWalletEnvironment}
                                            </span>
                                        </p>

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
                                            <div className="mt-3 flex flex-wrap items-center gap-2">
                                                <button
                                                    type="button"
                                                    onClick={() => pushWalletCredentialsMutation.mutate({
                                                        platformId: selectedPlatform.platform_id,
                                                        payload: {
                                                            reason: `Push active wallet auth for ${selectedPlatform.platform_name}`,
                                                        },
                                                    })}
                                                    disabled={!canPushActiveWalletCredentials || pushWalletCredentialsMutation.isPending}
                                                    className="crm-btn-secondary disabled:cursor-not-allowed disabled:opacity-60"
                                                >
                                                    {pushWalletCredentialsMutation.isPending ? 'Pushing...' : 'Push active auth to WordPress'}
                                                </button>
                                                {!canPushActiveWalletCredentials ? (
                                                    <p className="text-xs text-amber-700">
                                                        {selectedWalletEffectiveMode === 'disabled'
                                                            ? 'Enable the wallet for this market before pushing active auth.'
                                                            : 'Add WordPress sync credentials before pushing active auth.'}
                                                    </p>
                                                ) : null}
                                                {walletCredentialRotationForm.environment !== activeWalletEnvironment && selectedWalletEffectiveMode !== 'disabled' ? (
                                                    <p className="text-xs text-slate-500">
                                                        Rotating {walletCredentialRotationForm.environment} will not touch the live WordPress auth until this market switches to that environment.
                                                    </p>
                                                ) : null}
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
                                                <p className="mt-2 text-xs text-teal-800">
                                                    Revealed once for audit and recovery. If this environment is active, WordPress was updated automatically when the push succeeded.
                                                </p>
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
                                    <div className="rounded-md border border-slate-200 bg-slate-50 px-3 py-2">
                                        <p className="text-[11px] font-semibold uppercase tracking-[0.1em] text-slate-500">Discount PIN</p>
                                        <p className="mt-1 text-lg font-semibold text-slate-900">{walletSystemForm.discount_pin_set ? 'Configured' : 'Missing'}</p>
                                        <p className="mt-1 text-xs text-slate-500">Updated {formatDateTime(walletSystemForm.discount_pin_last_updated_at)}</p>
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
                                        <p><span className="font-semibold text-slate-800">Primary currency:</span> {walletPlatformForm.currency_code || selectedPlatform.currency || 'KES'}</p>
                                        <p><span className="font-semibold text-slate-800">Supported currencies:</span> {(walletPlatformForm.supported_currencies || [walletPlatformForm.currency_code || selectedPlatform.currency || 'KES']).join(', ')}</p>
                                        <p><span className="font-semibold text-slate-800">Effective currencies:</span> {(walletPlatformForm.effective_currencies || [walletPlatformForm.currency_code || selectedPlatform.currency || 'KES']).join(', ')}</p>
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
                        <MarketListPanel
                            formatDateTime={formatDateTime}
                            isLoading={isLoading}
                            platformRows={platformRows}
                            selectedPlatformId={selectedPlatformId}
                            setSelectedPlatformId={setSelectedPlatformId}
                            statusChip={statusChip}
                        />
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
                                            value={editor.support_board_api_url}
                                            onChange={(event) => setEditor((current) => ({ ...current, support_board_api_url: event.target.value }))}
                                            className="crm-input md:col-span-2"
                                            placeholder="https://cloud.board.support/script/include/api.php"
                                        />
                                        <div className="space-y-1 md:col-span-2">
                                            <input
                                                value={editor.support_board_token}
                                                onChange={(event) => setEditor((current) => ({ ...current, support_board_token: event.target.value }))}
                                                className="crm-input"
                                                placeholder={editor.support_board_token_configured ? '••••••••' : 'Support Board API token'}
                                                type="password"
                                            />
                                            <p className="text-xs text-slate-500">Leave blank to keep the current Support Board token.</p>
                                        </div>
                                        <div className="space-y-1 md:col-span-2">
                                            <input
                                                value={editor.support_board_sender_id}
                                                onChange={(event) => setEditor((current) => ({ ...current, support_board_sender_id: event.target.value }))}
                                                className="crm-input"
                                                placeholder="Default Sender ID"
                                                type="number"
                                                min="1"
                                                inputMode="numeric"
                                            />
                                            <p className="text-xs text-slate-500">Fallback SB agent ID for replies. Find in SB admin: Users → agent → ID in URL.</p>
                                        </div>
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
                                        <input
                                            value={editor.db_host}
                                            onChange={(event) => setEditor((current) => ({ ...current, db_host: event.target.value }))}
                                            className="crm-input"
                                            placeholder="WordPress DB host"
                                        />
                                        <input
                                            value={editor.db_name}
                                            onChange={(event) => setEditor((current) => ({ ...current, db_name: event.target.value }))}
                                            className="crm-input"
                                            placeholder="WordPress DB name"
                                        />
                                        <input
                                            value={editor.db_user}
                                            onChange={(event) => setEditor((current) => ({ ...current, db_user: event.target.value }))}
                                            className="crm-input"
                                            placeholder="WordPress DB user"
                                        />
                                        <div className="space-y-1">
                                            <input
                                                value={editor.db_pass}
                                                onChange={(event) => setEditor((current) => ({ ...current, db_pass: event.target.value }))}
                                                className="crm-input"
                                                placeholder={editor.db_pass_configured ? '••••••••' : 'WordPress DB password'}
                                                type="password"
                                            />
                                            <p className="text-xs text-slate-500">Leave blank to keep the current WordPress DB password.</p>
                                        </div>
                                        <input
                                            value={editor.db_prefix}
                                            onChange={(event) => setEditor((current) => ({ ...current, db_prefix: event.target.value }))}
                                            className="crm-input md:col-span-2"
                                            placeholder="WordPress table prefix (for example wp_)"
                                        />
                                        <p className="md:col-span-2 rounded-md border border-sky-200 bg-sky-50/80 px-3 py-2 text-xs text-sky-800">
                                            CRM “Provision in WordPress” uses the database connection from the market site’s `wp-config.php`: `DB_HOST`, `DB_NAME`, `DB_USER`, `DB_PASSWORD`, and `$table_prefix`.
                                        </p>
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
                                                const {
                                                    support_board_token_configured,
                                                    db_pass_configured,
                                                    ...editorPayload
                                                } = editor;
                                                const payload = {
                                                    ...editorPayload,
                                                    reason: 'Integration profile update from settings workspace',
                                                };

                                                if (!payload.wp_api_password?.trim()) {
                                                    delete payload.wp_api_password;
                                                }

                                                if (!payload.db_pass?.trim()) {
                                                    delete payload.db_pass;
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
                                                Configure packages and duration pricing for this market. Supported currencies:
                                                {' '}
                                                <span className="font-semibold text-slate-700">
                                                    {(packageEditor?.supported_currencies || [selectedPackageSetup?.currency || selectedPlatform.currency || 'KES']).join(', ')}
                                                </span>.
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
                                                    <label className="inline-flex items-center gap-1.5 text-xs text-slate-600">
                                                        <input
                                                            type="checkbox"
                                                            checked={row.is_public !== false}
                                                            onChange={(e) => updatePackageRow(rowIndex, 'is_public', e.target.checked)}
                                                            className="h-3.5 w-3.5 rounded border-slate-300 text-teal-700 focus:ring-teal-200"
                                                        />
                                                        Website
                                                    </label>
                                                    {row.is_public === false ? (
                                                        <span className="rounded-md bg-slate-100 px-2 py-0.5 text-[11px] font-semibold uppercase tracking-[0.08em] text-slate-600 ring-1 ring-inset ring-slate-200">
                                                            CRM only
                                                        </span>
                                                    ) : null}
                                                    {row.origin === 'sales' ? (
                                                        <span className="rounded-md bg-teal-50 px-2 py-0.5 text-[11px] font-semibold uppercase tracking-[0.08em] text-teal-700 ring-1 ring-inset ring-teal-100">
                                                            Sales-created{row.creator?.name ? ` - ${row.creator.name}` : ''}
                                                        </span>
                                                    ) : null}
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
                                                                <th className="px-1 py-1 font-medium">Currency</th>
                                                                <th className="px-1 py-1 font-medium">Days</th>
                                                                <th className="px-1 py-1 font-medium">Price</th>
                                                                <th className="px-1 py-1 font-medium">On</th>
                                                                <th className="px-1 py-1 font-medium"></th>
                                                            </tr>
                                                        </thead>
                                                        <tbody>
                                                            {row.prices.map((price, priceIndex) => (
                                                                <tr key={price.id || `price-${priceIndex}`}>
                                                                    <td className="px-1 py-1">
                                                                        <select
                                                                            value={packageDurationSelectValue(price.duration_key)}
                                                                            onChange={(e) => updatePriceRow(rowIndex, priceIndex, 'duration_key', e.target.value)}
                                                                            className="crm-select w-full text-xs"
                                                                        >
                                                                            <option value="">Select...</option>
                                                                            {defaultDurationOptions.map((d) => (
                                                                                <option key={d.key} value={d.key}>{d.label}</option>
                                                                            ))}
                                                                            <option value={customDurationOptionKey}>Custom</option>
                                                                        </select>
                                                                        {packageDurationSelectValue(price.duration_key) === customDurationOptionKey ? (
                                                                            <input
                                                                                type="text"
                                                                                value={price.duration_label || ''}
                                                                                onChange={(e) => updatePriceRow(rowIndex, priceIndex, 'duration_label', e.target.value)}
                                                                                placeholder="e.g. 2 Days"
                                                                                className="crm-input mt-1 w-full text-xs"
                                                                            />
                                                                        ) : null}
                                                                    </td>
                                                                    <td className="px-1 py-1">
                                                                        <select
                                                                            value={price.currency || packageEditor?.currency || 'KES'}
                                                                            onChange={(e) => updatePriceRow(rowIndex, priceIndex, 'currency', e.target.value.toUpperCase())}
                                                                            className="crm-select w-full text-xs"
                                                                        >
                                                                            {(packageEditor?.supported_currencies || [packageEditor?.currency || 'KES']).map((currency) => (
                                                                                <option key={`${rowIndex}-${priceIndex}-${currency}`} value={currency}>{currency}</option>
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
                                    <p className="text-xs text-slate-500">Run scoped sync jobs without leaving settings. Full client syncs reconcile the CRM table to WordPress and remove stale source records.</p>
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
                                            disabled={
                                                runSyncMutation.isPending
                                                || !selectedHasCredentials
                                                || !syncForm.reason.trim()
                                                || (syncForm.scope === 'clients' && !clientSyncQueueReady)
                                            }
                                            className="crm-btn-primary disabled:cursor-not-allowed disabled:opacity-60"
                                        >
                                            {runSyncMutation.isPending ? 'Running...' : (syncForm.scope === 'clients' ? 'Queue sync' : 'Run sync')}
                                        </button>
                                    </div>
                                    {syncForm.scope === 'clients' && !clientSyncQueueReady ? (
                                        <div className="mt-3 rounded-md border border-rose-200 bg-rose-50 px-3 py-2 text-xs text-rose-800">
                                            <p className="font-semibold">Background queue not ready</p>
                                            <p className="mt-1">{clientSyncQueue?.issues?.[0] || 'Background client sync is currently unavailable.'}</p>
                                        </div>
                                    ) : null}
                                    {latestSyncResult ? (
                                        <div className="mt-3 rounded-md border border-slate-200 bg-slate-50 p-2 text-xs text-slate-700">
                                            <p className="font-semibold text-slate-800">Latest sync summary</p>
                                            <p className="mt-1">Scope: {latestSyncResult.scope || selectedPlatform.sync?.last_scope || 'unknown'} • Dry run: {latestSyncResult.dry_run ? 'yes' : 'no'}</p>
                                            {latestSyncResult.clients ? (
                                                <p>
                                                    Clients: {latestSyncResult.clients.created || 0} created, {latestSyncResult.clients.updated || 0} updated
                                                    {Number(latestSyncResult.clients.pruned || 0) > 0 ? `, ${Number(latestSyncResult.clients.pruned || 0).toLocaleString()} stale deleted` : ''}
                                                </p>
                                            ) : null}
                                            {latestSyncResult.leads ? (
                                                <p>Leads: {latestSyncResult.leads.created || 0} created, {latestSyncResult.leads.updated || 0} updated, {latestSyncResult.leads.errors?.length || 0} errors</p>
                                            ) : null}
                                        </div>
                                    ) : null}
                                    {latestClientSyncRun ? (
                                        <div className="mt-3 rounded-md border border-slate-200 bg-white p-3 text-xs text-slate-700">
                                            <div className="flex items-center justify-between gap-2">
                                                <p className="font-semibold text-slate-800">Client sync run</p>
                                                <span className={`inline-flex items-center rounded-md px-2 py-1 text-xs font-medium capitalize ring-1 ring-inset ${statusChip(latestClientSyncRun.status)}`}>
                                                    {clientSyncStatusLabel}
                                                </span>
                                            </div>
                                            <p className="mt-2">
                                                Protocol: <span className="font-medium text-slate-900">{latestClientSyncRun.protocol || selectedPlatform?.client_sync?.protocol || 'pending'}</span>
                                                {' • '}
                                                Processed: <span className="font-medium text-slate-900">{latestClientSyncRun.processed || 0}</span>
                                                {' • '}
                                                Created/updated: <span className="font-medium text-slate-900">{latestClientSyncRun.created || 0}/{latestClientSyncRun.updated || 0}</span>
                                                {latestClientPruned > 0 ? (
                                                    <>
                                                        {' • '}
                                                        Deleted stale: <span className="font-medium text-slate-900">{latestClientPruned.toLocaleString()}</span>
                                                    </>
                                                ) : null}
                                            </p>
                                            <p className="mt-1">
                                                Started: <span className="font-medium text-slate-900">{formatDateTime(latestClientSyncRun.started_at || latestClientSyncRun.created_at)}</span>
                                                {latestClientSyncRun.in_progress ? ' • Sync continues in the background.' : ''}
                                            </p>
                                            {selectedPlatform?.client_sync?.legacy_correctness_risk ? (
                                                <p className="mt-1 text-amber-700">This market is still using the legacy plugin contract, so reconciliation remains best-effort until the v2 plugin is deployed.</p>
                                            ) : null}
                                            {latestClientSyncRun.queue?.message ? (
                                                <p className="mt-1 text-amber-700">{latestClientSyncRun.queue.message}</p>
                                            ) : null}
                                            {latestClientSyncRun.error_details?.[0]?.message ? (
                                                <p className="mt-1 text-rose-700">{latestClientSyncRun.error_details[0].message}</p>
                                            ) : null}
                                        </div>
                                    ) : null}
                                </section>

                                <section className="rounded-lg border border-slate-200 bg-white p-3">
                                    <h4 className="text-sm font-semibold text-slate-900">Support Board Link Sync</h4>
                                    <p className="text-xs text-slate-500">Backfill the local chat-match cache so the Clients filter can find matched profiles without opening each chat tab. Syncs now run in the background and continue even if you leave this page.</p>
                                    <div className="mt-3 rounded-md border border-sky-200 bg-sky-50/70 p-3 text-xs text-sky-900">
                                        <p className="font-semibold">How it runs</p>
                                        <p className="mt-1">
                                            Incremental mode checks only clients without an existing Support Board link.
                                            Revalidation mode checks all clients in this market and refreshes stale matches.
                                        </p>
                                    </div>
                                    {!supportBoardSyncQueueReady ? (
                                        <div className="mt-3 rounded-md border border-rose-200 bg-rose-50 px-3 py-2 text-xs text-rose-800">
                                            <p className="font-semibold">Background queue not ready</p>
                                            <p className="mt-1">{supportBoardSyncQueue?.issues?.[0] || 'Support Board background sync is currently unavailable.'}</p>
                                        </div>
                                    ) : null}
                                    <div className="mt-3 grid gap-3 md:grid-cols-2">
                                        <label className="flex items-center gap-2 text-sm text-slate-700">
                                            <input
                                                type="checkbox"
                                                checked={supportBoardSyncForm.refresh}
                                                onChange={(event) => setSupportBoardSyncForm((current) => ({ ...current, refresh: event.target.checked }))}
                                                className="h-4 w-4 rounded border-slate-300 text-teal-700 focus:ring-teal-200"
                                            />
                                            Revalidate existing matches
                                        </label>
                                        <div className="rounded-md border border-slate-200 bg-slate-50 px-3 py-2 text-xs text-slate-600">
                                            Scope:
                                            {' '}
                                            <span className="font-semibold text-slate-800">{selectedPlatform.platform_name}</span>
                                        </div>
                                        <textarea
                                            value={supportBoardSyncForm.reason}
                                            onChange={(event) => setSupportBoardSyncForm((current) => ({ ...current, reason: event.target.value }))}
                                            className="crm-input md:col-span-2"
                                            rows={2}
                                            placeholder="Reason for Support Board link sync"
                                        />
                                    </div>
                                    <div className="mt-3 flex justify-end">
                                        <button
                                            type="button"
                                            onClick={() => setSupportBoardSyncConfirmOpen(true)}
                                            disabled={
                                                !canManageMarkets
                                                || runSupportBoardSyncMutation.isPending
                                                || supportBoardSyncActive
                                                || !supportBoardSyncQueueReady
                                                || !selectedSupportBoardConfigured
                                                || !supportBoardSyncForm.reason.trim()
                                            }
                                            className="crm-btn-primary disabled:cursor-not-allowed disabled:opacity-60"
                                        >
                                            {runSupportBoardSyncMutation.isPending
                                                ? 'Starting...'
                                                : supportBoardSyncActive
                                                    ? 'Sync in progress'
                                                    : 'Start Support Board sync'}
                                        </button>
                                    </div>
                                    {!canManageMarkets ? (
                                        <p className="mt-2 text-xs text-slate-500">Only admin and sub-admin users can run Support Board link sync.</p>
                                    ) : null}
                                    {!selectedSupportBoardConfigured ? (
                                        <p className="mt-2 text-xs text-amber-700">Save a Support Board API URL and token for this market before running the link sync.</p>
                                    ) : null}
                                    {latestSupportBoardSyncResult ? (
                                        <div className="mt-3 rounded-md border border-slate-200 bg-slate-50 p-3 text-xs text-slate-700">
                                            <div className="flex flex-wrap items-center justify-between gap-2">
                                                <div>
                                                    <p className="font-semibold text-slate-800">Latest Support Board sync</p>
                                                    <p className="mt-1 text-slate-500">
                                                        Mode: {latestSupportBoardSyncResult.refresh ? 'revalidate all matches' : 'incremental unmatched-only'}
                                                        {' • '}
                                                        Started: {formatDateTime(latestSupportBoardSyncResult.started_at || latestSupportBoardSyncResult.created_at)}
                                                    </p>
                                                </div>
                                                <span className={`inline-flex items-center rounded-md px-2 py-1 text-xs font-medium capitalize ring-1 ring-inset ${statusChip(latestSupportBoardSyncResult.status)}`}>
                                                    {supportBoardSyncStatusLabel}
                                                </span>
                                            </div>

                                            <div className="mt-3">
                                                <div className="flex items-center justify-between text-[11px] uppercase tracking-[0.24em] text-slate-500">
                                                    <span>Progress</span>
                                                    <span>{latestSupportBoardSyncResult.progress_percent || 0}%</span>
                                                </div>
                                                <div className="mt-1 h-2 overflow-hidden rounded-full bg-slate-200">
                                                    <div
                                                        className="h-full rounded-full bg-teal-600 transition-all"
                                                        style={{ width: `${Math.max(0, Math.min(100, latestSupportBoardSyncResult.progress_percent || 0))}%` }}
                                                    />
                                                </div>
                                                <p className="mt-2 text-slate-600">
                                                    Processed {latestSupportBoardSyncResult.processed || 0} of {latestSupportBoardSyncResult.candidates || 0} candidates.
                                                    {latestSupportBoardSyncResult.in_progress ? ' You can leave this page while the sync continues.' : ''}
                                                </p>
                                            </div>

                                            <div className="mt-3 grid gap-2 md:grid-cols-3">
                                                <div className="rounded-md border border-slate-200 bg-white px-3 py-2">
                                                    <p className="text-[11px] uppercase tracking-[0.22em] text-slate-500">Matched</p>
                                                    <p className="mt-1 text-sm font-semibold text-slate-900">{latestSupportBoardSyncResult.matched || 0}</p>
                                                </div>
                                                <div className="rounded-md border border-slate-200 bg-white px-3 py-2">
                                                    <p className="text-[11px] uppercase tracking-[0.22em] text-slate-500">Updated / Cleared</p>
                                                    <p className="mt-1 text-sm font-semibold text-slate-900">{latestSupportBoardSyncResult.updated || 0} / {latestSupportBoardSyncResult.cleared || 0}</p>
                                                </div>
                                                <div className="rounded-md border border-slate-200 bg-white px-3 py-2">
                                                    <p className="text-[11px] uppercase tracking-[0.22em] text-slate-500">Errors</p>
                                                    <p className="mt-1 text-sm font-semibold text-slate-900">{latestSupportBoardSyncResult.errors || 0}</p>
                                                </div>
                                            </div>

                                            <div className="mt-3 space-y-1 text-slate-600">
                                                <p>Last heartbeat: <span className="font-medium text-slate-900">{formatDateTime(latestSupportBoardSyncResult.last_heartbeat_at)}</span></p>
                                                {latestSupportBoardSyncResult.last_processed_client_name ? (
                                                    <p>
                                                        Last processed: <span className="font-medium text-slate-900">{latestSupportBoardSyncResult.last_processed_client_name}</span>
                                                        {latestSupportBoardSyncResult.last_processed_client_id ? ` (#${latestSupportBoardSyncResult.last_processed_client_id})` : ''}
                                                    </p>
                                                ) : null}
                                                {latestSupportBoardSyncResult.finished_at ? (
                                                    <p>Finished: <span className="font-medium text-slate-900">{formatDateTime(latestSupportBoardSyncResult.finished_at)}</span></p>
                                                ) : null}
                                            </div>

                                            {latestSupportBoardSyncResult.queue?.message ? (
                                                <div className="mt-3 rounded-md border border-amber-200 bg-amber-50 px-3 py-2 text-amber-800">
                                                    <p className="font-semibold">Queue attention needed</p>
                                                    <p className="mt-1">{latestSupportBoardSyncResult.queue.message}</p>
                                                </div>
                                            ) : null}

                                            {latestSupportBoardSyncResult.errors_detail?.length ? (
                                                <div className="mt-3 rounded-md border border-amber-200 bg-amber-50 px-3 py-2 text-amber-800">
                                                    <p className="font-semibold">Recent error</p>
                                                    <p className="mt-1">{latestSupportBoardSyncResult.errors_detail[0]?.message || 'Unknown error'}</p>
                                                </div>
                                            ) : null}
                                        </div>
                                    ) : (
                                        <div className="mt-3 rounded-md border border-dashed border-slate-300 bg-slate-50 px-3 py-4 text-xs text-slate-500">
                                            No Support Board sync has run for this market yet.
                                        </div>
                                    )}
                                </section>

                                <section className="rounded-lg border border-slate-200 bg-white p-3">
                                    <h4 className="text-sm font-semibold text-slate-900">Support Board Lead Import</h4>
                                    <p className="text-xs text-slate-500">Import chat-origin contacts from Support Board as CRM leads. Runs in the background and continues even if you leave this page.</p>
                                    <div className="mt-3 rounded-md border border-sky-200 bg-sky-50/70 p-3 text-xs text-sky-900">
                                        <p className="font-semibold">How it runs</p>
                                        <p className="mt-1">
                                            Bootstrap mode fetches all conversations and imports every unique user.
                                            Incremental mode imports only users from new conversations since the last run.
                                        </p>
                                    </div>
                                    {!sbLeadImportQueueReady ? (
                                        <div className="mt-3 rounded-md border border-rose-200 bg-rose-50 px-3 py-2 text-xs text-rose-800">
                                            <p className="font-semibold">Background queue not ready</p>
                                            <p className="mt-1">{sbLeadImportQueue?.issues?.[0] || 'Background lead import is currently unavailable.'}</p>
                                        </div>
                                    ) : null}
                                    <div className="mt-3 grid gap-3 md:grid-cols-2">
                                        <div className="flex items-center gap-4">
                                            <label className="flex items-center gap-2 text-sm text-slate-700">
                                                <input
                                                    type="radio"
                                                    name="sb_lead_import_mode"
                                                    value="bootstrap"
                                                    checked={sbLeadImportForm.mode === 'bootstrap'}
                                                    onChange={() => setSbLeadImportForm((current) => ({ ...current, mode: 'bootstrap' }))}
                                                    className="h-4 w-4 border-slate-300 text-teal-700 focus:ring-teal-200"
                                                />
                                                Bootstrap (all)
                                            </label>
                                            <label className="flex items-center gap-2 text-sm text-slate-700">
                                                <input
                                                    type="radio"
                                                    name="sb_lead_import_mode"
                                                    value="incremental"
                                                    checked={sbLeadImportForm.mode === 'incremental'}
                                                    onChange={() => setSbLeadImportForm((current) => ({ ...current, mode: 'incremental' }))}
                                                    className="h-4 w-4 border-slate-300 text-teal-700 focus:ring-teal-200"
                                                />
                                                Incremental (new only)
                                            </label>
                                        </div>
                                        <div className="rounded-md border border-slate-200 bg-slate-50 px-3 py-2 text-xs text-slate-600">
                                            Scope:
                                            {' '}
                                            <span className="font-semibold text-slate-800">{selectedPlatform.platform_name}</span>
                                        </div>
                                        <textarea
                                            value={sbLeadImportForm.reason}
                                            onChange={(event) => setSbLeadImportForm((current) => ({ ...current, reason: event.target.value }))}
                                            className="crm-input md:col-span-2"
                                            rows={2}
                                            placeholder="Reason for Support Board lead import"
                                        />
                                    </div>
                                    <div className="mt-3 flex justify-end">
                                        <button
                                            type="button"
                                            onClick={() => setSbLeadImportConfirmOpen(true)}
                                            disabled={
                                                !canManageMarkets
                                                || runSbLeadImportMutation.isPending
                                                || sbLeadImportActive
                                                || !sbLeadImportQueueReady
                                                || !selectedSupportBoardConfigured
                                                || !sbLeadImportForm.reason.trim()
                                            }
                                            className="crm-btn-primary disabled:cursor-not-allowed disabled:opacity-60"
                                        >
                                            {runSbLeadImportMutation.isPending
                                                ? 'Starting...'
                                                : sbLeadImportActive
                                                    ? 'Import in progress'
                                                    : 'Start Lead Import'}
                                        </button>
                                    </div>
                                    {!canManageMarkets ? (
                                        <p className="mt-2 text-xs text-slate-500">Only admin and sub-admin users can run Support Board lead import.</p>
                                    ) : null}
                                    {!selectedSupportBoardConfigured ? (
                                        <p className="mt-2 text-xs text-amber-700">Save a Support Board API URL and token for this market before running the lead import.</p>
                                    ) : null}
                                    {latestSbLeadImportResult ? (
                                        <div className="mt-3 rounded-md border border-slate-200 bg-slate-50 p-3 text-xs text-slate-700">
                                            <div className="flex flex-wrap items-center justify-between gap-2">
                                                <div>
                                                    <p className="font-semibold text-slate-800">Latest lead import</p>
                                                    <p className="mt-1 text-slate-500">
                                                        Mode: {latestSbLeadImportResult.mode === 'bootstrap' ? 'bootstrap (all conversations)' : 'incremental (new only)'}
                                                        {' \u2022 '}
                                                        Started: {formatDateTime(latestSbLeadImportResult.started_at || latestSbLeadImportResult.created_at)}
                                                    </p>
                                                </div>
                                                <span className={`inline-flex items-center rounded-md px-2 py-1 text-xs font-medium capitalize ring-1 ring-inset ${statusChip(latestSbLeadImportResult.status)}`}>
                                                    {sbLeadImportStatusLabel}
                                                </span>
                                            </div>

                                            <div className="mt-3">
                                                <div className="flex items-center justify-between text-[11px] uppercase tracking-[0.24em] text-slate-500">
                                                    <span>Progress</span>
                                                    <span>{latestSbLeadImportResult.progress_percent || 0}%</span>
                                                </div>
                                                <div className="mt-1 h-2 overflow-hidden rounded-full bg-slate-200">
                                                    <div
                                                        className="h-full rounded-full bg-teal-600 transition-all"
                                                        style={{ width: `${Math.max(0, Math.min(100, latestSbLeadImportResult.progress_percent || 0))}%` }}
                                                    />
                                                </div>
                                                <p className="mt-2 text-slate-600">
                                                    Processed {latestSbLeadImportResult.processed || 0} of {latestSbLeadImportResult.candidates || 0} candidates.
                                                    {latestSbLeadImportResult.in_progress ? ' You can leave this page while the import continues.' : ''}
                                                </p>
                                            </div>

                                            <div className="mt-3 grid gap-2 md:grid-cols-4">
                                                <div className="rounded-md border border-slate-200 bg-white px-3 py-2">
                                                    <p className="text-[11px] uppercase tracking-[0.22em] text-slate-500">Created</p>
                                                    <p className="mt-1 text-sm font-semibold text-slate-900">{latestSbLeadImportResult.created_leads || 0}</p>
                                                </div>
                                                <div className="rounded-md border border-slate-200 bg-white px-3 py-2">
                                                    <p className="text-[11px] uppercase tracking-[0.22em] text-slate-500">Updated</p>
                                                    <p className="mt-1 text-sm font-semibold text-slate-900">{latestSbLeadImportResult.updated_leads || 0}</p>
                                                </div>
                                                <div className="rounded-md border border-slate-200 bg-white px-3 py-2">
                                                    <p className="text-[11px] uppercase tracking-[0.22em] text-slate-500">Skipped</p>
                                                    <p className="mt-1 text-sm font-semibold text-slate-900">{(latestSbLeadImportResult.skipped_existing_client || 0) + (latestSbLeadImportResult.skipped_existing_lead || 0)}</p>
                                                </div>
                                                <div className="rounded-md border border-slate-200 bg-white px-3 py-2">
                                                    <p className="text-[11px] uppercase tracking-[0.22em] text-slate-500">Errors</p>
                                                    <p className="mt-1 text-sm font-semibold text-slate-900">{latestSbLeadImportResult.errors || 0}</p>
                                                </div>
                                            </div>

                                            <div className="mt-3 space-y-1 text-slate-600">
                                                <p>Last heartbeat: <span className="font-medium text-slate-900">{formatDateTime(latestSbLeadImportResult.last_heartbeat_at)}</span></p>
                                                {latestSbLeadImportResult.last_processed_name ? (
                                                    <p>
                                                        Last processed: <span className="font-medium text-slate-900">{latestSbLeadImportResult.last_processed_name}</span>
                                                        {latestSbLeadImportResult.last_processed_sb_user_id ? ` (SB #${latestSbLeadImportResult.last_processed_sb_user_id})` : ''}
                                                    </p>
                                                ) : null}
                                                {latestSbLeadImportResult.finished_at ? (
                                                    <p>Finished: <span className="font-medium text-slate-900">{formatDateTime(latestSbLeadImportResult.finished_at)}</span></p>
                                                ) : null}
                                            </div>

                                            {latestSbLeadImportResult.queue?.message ? (
                                                <div className="mt-3 rounded-md border border-amber-200 bg-amber-50 px-3 py-2 text-amber-800">
                                                    <p className="font-semibold">Queue attention needed</p>
                                                    <p className="mt-1">{latestSbLeadImportResult.queue.message}</p>
                                                </div>
                                            ) : null}

                                            {latestSbLeadImportResult.error_details?.length ? (
                                                <div className="mt-3 rounded-md border border-amber-200 bg-amber-50 px-3 py-2 text-amber-800">
                                                    <p className="font-semibold">Recent error</p>
                                                    <p className="mt-1">{latestSbLeadImportResult.error_details[0]?.message || 'Unknown error'}</p>
                                                </div>
                                            ) : null}
                                        </div>
                                    ) : (
                                        <div className="mt-3 rounded-md border border-dashed border-slate-300 bg-slate-50 px-3 py-4 text-xs text-slate-500">
                                            No Support Board lead import has run for this market yet.
                                        </div>
                                    )}
                                </section>
                            </div>
                        )}
                    </div>
                </div>
                </section>
            ) : null}

            {integrationArea === 'payment_links' ? (
                <PaymentLinkRoutingPanel
                    addPaymentLinkProvider={addPaymentLinkProvider}
                    enabledPaymentLinkProviders={enabledPaymentLinkProviders}
                    onRefresh={() => queryClient.invalidateQueries({ queryKey: ['settings-integrations'] })}
                    paymentLinkForm={paymentLinkForm}
                    paymentLinkModeOptions={paymentLinkModeOptions}
                    paymentLinkProviderOptionLabel={paymentLinkProviderOptionLabel}
                    paymentLinkProxyWalletProviders={paymentLinkProxyWalletProviders}
                    paymentLinkReadOnly={paymentLinkReadOnly}
                    paymentLinkReadinessClasses={paymentLinkReadinessClasses}
                    paymentLinkReadinessState={paymentLinkReadinessState}
                    platformRows={platformRows}
                    removePaymentLinkProvider={removePaymentLinkProvider}
                    savePaymentLinkProviders={savePaymentLinkProviders}
                    savePaymentLinkProvidersMutation={savePaymentLinkProvidersMutation}
                    selectedPlatform={selectedPlatform}
                    selectedPlatformId={selectedPlatformId}
                    setPaymentLinkForm={setPaymentLinkForm}
                    setSelectedPlatformId={setSelectedPlatformId}
                    updatePaymentLinkProvider={updatePaymentLinkProvider}
                    walletEnvironmentOptions={walletEnvironmentOptions}
                    walletProviderKeys={walletProviderKeys}
                    walletProviderLabel={walletProviderLabel}
                    walletSystemConfig={walletSystemConfig}
                />
            ) : null}

            {integrationArea === 'scraper' ? (
                <ScraperConfigPanel
                    activeScraperSources={activeScraperSources}
                    dedupeModeLabel={dedupeModeLabel}
                    latestScraperRunResult={latestScraperRunResult}
                    onOpenCreateModal={() => setScraperCreateOpen(true)}
                    onOpenRunConfirm={() => setScraperRunConfirmOpen(true)}
                    onSelectSource={setSelectedScraperSourceId}
                    onUpdateScraperEditor={setScraperEditor}
                    onUpdateScraperRunForm={setScraperRunForm}
                    platformRows={platformRows}
                    runScraperSourceMutation={runScraperSourceMutation}
                    scraperBlockedOrFailed={scraperBlockedOrFailed}
                    scraperDedupeModes={scraperDedupeModes}
                    scraperEditor={scraperEditor}
                    scraperProfileLabel={scraperProfileLabel}
                    scraperProfiles={scraperProfiles}
                    scraperRunForm={scraperRunForm}
                    scraperRuns={scraperRuns}
                    scraperScheduleLabel={scraperScheduleLabel}
                    scraperSchedules={scraperSchedules}
                    scraperSources={scraperSources}
                    scraperStatusLabel={scraperStatusLabel}
                    selectedScraperCompliant={selectedScraperCompliant}
                    selectedScraperRules={selectedScraperRules}
                    selectedScraperSource={selectedScraperSource}
                    selectedScraperSourceId={selectedScraperSourceId}
                    statusChip={statusChip}
                    updateScraperSourceMutation={updateScraperSourceMutation}
                />
            ) : null}

            {createOpen ? (
                <MarketCreateModal
                    createForm={createForm}
                    createPlatformMutation={createPlatformMutation}
                    onClose={() => setCreateOpen(false)}
                    onUpdateForm={setCreateForm}
                />
            ) : null}

            {scraperCreateOpen ? (
                <ScraperCreateModal
                    createScraperSourceMutation={createScraperSourceMutation}
                    dedupeModeLabel={dedupeModeLabel}
                    onClose={() => setScraperCreateOpen(false)}
                    onUpdateForm={setScraperCreateForm}
                    platformRows={platformRows}
                    scraperCreateForm={scraperCreateForm}
                    scraperDedupeModes={scraperDedupeModes}
                    scraperProfileLabel={scraperProfileLabel}
                    scraperProfiles={scraperProfiles}
                    scraperScheduleLabel={scraperScheduleLabel}
                    scraperSchedules={scraperSchedules}
                />
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
                        market_id: smsTestForm.market_id || null,
                        phone: smsTestForm.phone.trim(),
                        message: smsTestForm.message.trim(),
                        reason: smsTestForm.reason.trim(),
                    });
                }}
                confirmDisabled={!smsTestForm.phone.trim() || !smsTestForm.message.trim() || !smsTestForm.reason.trim() || !smsProviderForm.enabled || !smsTestReady}
                isPending={testSmsProviderMutation.isPending}
            >
                <div className="space-y-1 text-sm text-slate-600">
                    <p><span className="font-semibold text-slate-800">Active provider:</span> {smsProviderLabel(smsTestProvider)}</p>
                    <p><span className="font-semibold text-slate-800">Market:</span> {selectedSmsTestPlatform?.platform_name || 'Global routing'}</p>
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
                    {syncForm.scope === 'clients' && syncForm.mode === 'full' ? (
                        <p className="rounded-md border border-amber-200 bg-amber-50 px-2 py-1 text-amber-800">
                            Full client sync will delete CRM clients in this market whose WordPress post IDs are no longer returned by the source.
                        </p>
                    ) : null}
                </div>
            </ConfirmDialog>

            <ConfirmDialog
                open={supportBoardSyncConfirmOpen}
                title="Start Support Board Link Sync?"
                message="This will queue a background sync for the selected market so chat-match filtering can use the locally cached mapping."
                confirmLabel="Start sync"
                cancelLabel="Cancel"
                tone="warning"
                onCancel={() => setSupportBoardSyncConfirmOpen(false)}
                onConfirm={() => {
                    if (!selectedPlatform) {
                        return;
                    }

                    runSupportBoardSyncMutation.mutate({
                        platformId: selectedPlatform.platform_id,
                        payload: {
                            refresh: supportBoardSyncForm.refresh,
                            reason: supportBoardSyncForm.reason.trim(),
                        },
                    });
                }}
                confirmDisabled={!supportBoardSyncForm.reason.trim()}
                isPending={runSupportBoardSyncMutation.isPending}
            >
                <div className="space-y-1 text-sm text-slate-600">
                    <p><span className="font-semibold text-slate-800">Market:</span> {selectedPlatform?.platform_name}</p>
                    <p><span className="font-semibold text-slate-800">Mode:</span> {supportBoardSyncForm.refresh ? 'Revalidate all clients' : 'Incremental unmatched-only'}</p>
                    <p className="text-xs text-slate-500">The sync will continue in the background. You can safely leave this page after it starts.</p>
                </div>
            </ConfirmDialog>

            <ConfirmDialog
                open={sbLeadImportConfirmOpen}
                title="Start Support Board Lead Import?"
                message="This will queue a background import of Support Board chat users as CRM leads for the selected market."
                confirmLabel="Start import"
                cancelLabel="Cancel"
                tone="warning"
                onCancel={() => setSbLeadImportConfirmOpen(false)}
                onConfirm={() => {
                    if (!selectedPlatform) {
                        return;
                    }

                    runSbLeadImportMutation.mutate({
                        platformId: selectedPlatform.platform_id,
                        payload: {
                            mode: sbLeadImportForm.mode,
                            reason: sbLeadImportForm.reason.trim(),
                        },
                    });
                }}
                confirmDisabled={!sbLeadImportForm.reason.trim()}
                isPending={runSbLeadImportMutation.isPending}
            >
                <div className="space-y-1 text-sm text-slate-600">
                    <p><span className="font-semibold text-slate-800">Market:</span> {selectedPlatform?.platform_name}</p>
                    <p><span className="font-semibold text-slate-800">Mode:</span> {sbLeadImportForm.mode === 'bootstrap' ? 'Bootstrap (all conversations)' : 'Incremental (new only)'}</p>
                    <p className="text-xs text-slate-500">The import will continue in the background. You can safely leave this page after it starts.</p>
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
                    <div className="flex flex-wrap items-center gap-2">
                        <p className="text-sm font-semibold text-slate-900">{row.title}</p>
                        {row.is_quick_reply ? (
                            <span className="inline-flex items-center rounded-md bg-teal-50 px-2 py-0.5 text-[11px] font-semibold text-teal-700 ring-1 ring-inset ring-teal-200">
                                Quick reply
                            </span>
                        ) : null}
                    </div>
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
                                is_quick_reply: Boolean(row.is_quick_reply),
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
                        is_quick_reply: Boolean(row.is_quick_reply),
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

                            <label className="flex items-center gap-2 rounded-md border border-slate-200 bg-slate-50 px-3 py-2 text-sm text-slate-700">
                                <input
                                    type="checkbox"
                                    checked={Boolean(editorForm.is_quick_reply)}
                                    onChange={(event) => setEditorForm({ ...editorForm, is_quick_reply: event.target.checked })}
                                    className="h-4 w-4 rounded border-slate-300 text-teal-700 focus:ring-2 focus:ring-teal-200"
                                />
                                <span className="font-medium">Quick reply</span>
                            </label>

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

function errorLogLevelClasses(level) {
    switch ((level || '').toLowerCase()) {
        case 'emergency':
        case 'alert':
        case 'critical':
            return 'bg-rose-50 text-rose-700 ring-rose-200';
        case 'error':
            return 'bg-amber-50 text-amber-700 ring-amber-200';
        default:
            return 'bg-slate-50 text-slate-700 ring-slate-200';
    }
}

function errorLogSourceLabel(source) {
    switch (source) {
        case 'queue_job': return 'Queue job';
        case 'log': return 'Log call';
        case 'exception': return 'Exception';
        default: return source || '—';
    }
}

function ErrorLogsWorkspace() {
    const queryClient = useQueryClient();
    const [page, setPage] = useState(1);
    const [searchInput, setSearchInput] = useState('');
    const [search, setSearch] = useState('');
    const [levelFilter, setLevelFilter] = useState('');
    const [sourceFilter, setSourceFilter] = useState('');
    const [statusFilter, setStatusFilter] = useState('unresolved');
    const [selectedGroupId, setSelectedGroupId] = useState(null);

    const listQuery = useQuery({
        queryKey: ['settings-error-logs', page, search, levelFilter, sourceFilter, statusFilter],
        queryFn: () => api.get('/crm/settings/error-logs', {
            params: {
                page,
                per_page: 25,
                ...(search ? { search } : {}),
                ...(levelFilter ? { level: levelFilter } : {}),
                ...(sourceFilter ? { source: sourceFilter } : {}),
                ...(statusFilter ? { status: statusFilter } : {}),
            },
        }).then((response) => response.data),
        refetchInterval: 30_000,
    });

    const detailQuery = useQuery({
        queryKey: ['settings-error-logs', 'detail', selectedGroupId],
        queryFn: () => api.get(`/crm/settings/error-logs/${selectedGroupId}`).then((response) => response.data?.data),
        enabled: Boolean(selectedGroupId),
    });

    const resolveMutation = useMutation({
        mutationFn: (groupId) => api.post(`/crm/settings/error-logs/${groupId}/resolve`).then((response) => response.data?.data),
        onSuccess: () => {
            queryClient.invalidateQueries({ queryKey: ['settings-error-logs'] });
            setSelectedGroupId(null);
        },
    });

    const reopenMutation = useMutation({
        mutationFn: (groupId) => api.post(`/crm/settings/error-logs/${groupId}/reopen`).then((response) => response.data?.data),
        onSuccess: () => {
            queryClient.invalidateQueries({ queryKey: ['settings-error-logs'] });
        },
    });

    const groups = listQuery.data?.data || [];
    const summary = listQuery.data?.summary || {};

    const columns = [
        {
            key: 'error',
            label: 'Error',
            render: (row) => (
                <div className="max-w-[460px]">
                    <p className="text-sm font-semibold text-slate-900">{row.exception_class ? row.exception_class.split('\\').pop() : 'Log entry'}</p>
                    <p className="truncate text-xs text-slate-600">{row.message}</p>
                    {row.file ? <p className="truncate text-[11px] text-slate-400">{row.file}{row.line ? `:${row.line}` : ''}</p> : null}
                </div>
            ),
        },
        {
            key: 'occurrence_count',
            label: 'Count',
            render: (row) => (
                <span className="inline-flex items-center rounded-md bg-slate-100 px-2 py-0.5 text-xs font-semibold text-slate-700 ring-1 ring-inset ring-slate-200">
                    {Number(row.occurrence_count || 0).toLocaleString()}
                </span>
            ),
        },
        {
            key: 'level',
            label: 'Level',
            render: (row) => (
                <span className={`inline-flex items-center rounded-md px-2 py-0.5 text-xs font-medium ring-1 ring-inset ${errorLogLevelClasses(row.level)}`}>
                    {row.level || 'error'}
                </span>
            ),
        },
        {
            key: 'source',
            label: 'Source',
            render: (row) => <span className="text-xs text-slate-700">{errorLogSourceLabel(row.source)}</span>,
        },
        {
            key: 'first_seen',
            label: 'First Seen',
            render: (row) => <span className="text-xs text-slate-600">{row.first_seen_at ? new Date(row.first_seen_at).toLocaleString() : '—'}</span>,
        },
        {
            key: 'last_seen',
            label: 'Last Seen',
            render: (row) => <span className="text-xs text-slate-600">{row.last_seen_at ? new Date(row.last_seen_at).toLocaleString() : '—'}</span>,
        },
        {
            key: 'actions',
            label: 'Actions',
            render: (row) => (
                <div className="flex gap-2">
                    <button type="button" className="crm-btn-secondary px-3 py-1.5 text-xs" onClick={(event) => {
                        event.stopPropagation();
                        setSelectedGroupId(row.id);
                    }}>
                        Inspect
                    </button>
                    {row.resolved_at ? (
                        <button type="button" className="crm-btn-secondary px-3 py-1.5 text-xs" onClick={(event) => {
                            event.stopPropagation();
                            reopenMutation.mutate(row.id);
                        }}>
                            Reopen
                        </button>
                    ) : (
                        <button type="button" className="crm-btn-secondary px-3 py-1.5 text-xs" onClick={(event) => {
                            event.stopPropagation();
                            resolveMutation.mutate(row.id);
                        }}>
                            Resolve
                        </button>
                    )}
                </div>
            ),
        },
    ];

    const detail = detailQuery.data;

    return (
        <div className="space-y-4">
            <section className="grid gap-4 md:grid-cols-5">
                <MetricCard
                    label="Unresolved Critical"
                    value={Number(summary.unresolved_critical || 0).toLocaleString()}
                    meta="critical / alert / emergency"
                    tone={(summary.unresolved_critical || 0) > 0 ? 'danger' : 'success'}
                />
                <MetricCard
                    label="Top Offender"
                    value={summary.top_offender?.label || '—'}
                    meta={summary.top_offender ? `${Number(summary.top_offender.count || 0).toLocaleString()} occurrences` : 'no unresolved issues'}
                    tone={summary.top_offender ? 'warning' : 'success'}
                />
                <MetricCard
                    label="Errors Today"
                    value={Number(summary.occurrences_today || 0).toLocaleString()}
                    meta="occurrence count sum"
                    tone="default"
                />
                <MetricCard
                    label="Resolved This Week"
                    value={Number(summary.resolved_last_7_days || 0).toLocaleString()}
                    meta="last 7 days"
                    tone="success"
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
                                placeholder="Search exception class, message, or file..."
                                className="crm-input pr-10"
                            />
                            <button type="submit" className="absolute right-2 top-1/2 -translate-y-1/2 rounded p-1 text-slate-400 transition hover:text-slate-600">
                                <svg className="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
                                    <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z" />
                                </svg>
                            </button>
                        </div>
                    </form>
                    <select
                        value={levelFilter}
                        onChange={(event) => { setLevelFilter(event.target.value); setPage(1); }}
                        className="crm-input min-w-[140px]"
                    >
                        <option value="">All levels</option>
                        <option value="error">Error</option>
                        <option value="critical">Critical</option>
                        <option value="alert">Alert</option>
                        <option value="emergency">Emergency</option>
                    </select>
                    <select
                        value={sourceFilter}
                        onChange={(event) => { setSourceFilter(event.target.value); setPage(1); }}
                        className="crm-input min-w-[140px]"
                    >
                        <option value="">All sources</option>
                        <option value="exception">Exception</option>
                        <option value="log">Log call</option>
                        <option value="queue_job">Queue job</option>
                    </select>
                    <select
                        value={statusFilter}
                        onChange={(event) => { setStatusFilter(event.target.value); setPage(1); }}
                        className="crm-input min-w-[140px]"
                    >
                        <option value="unresolved">Unresolved</option>
                        <option value="resolved">Resolved</option>
                        <option value="">All</option>
                    </select>
                    {(search || levelFilter || sourceFilter || statusFilter !== 'unresolved') ? (
                        <button type="button" className="crm-btn-secondary px-3 py-2" onClick={() => {
                            setSearch('');
                            setSearchInput('');
                            setLevelFilter('');
                            setSourceFilter('');
                            setStatusFilter('unresolved');
                            setPage(1);
                        }}>
                            Reset
                        </button>
                    ) : null}
                </div>
                <p className="mt-2 text-xs text-slate-500">Errors are deduplicated by signature. Click Inspect for the full stack trace and last 20 occurrences.</p>
            </section>

            <DataTable
                columns={columns}
                data={groups}
                pagination={listQuery.data}
                onPageChange={setPage}
                onRowClick={(row) => setSelectedGroupId(row.id)}
                isLoading={listQuery.isLoading}
                compact
                emptyMessage="No errors match the current filters."
            />

            {selectedGroupId ? (
                <div className="fixed inset-0 z-50 flex items-center justify-center bg-slate-900/45 p-4" onClick={() => setSelectedGroupId(null)}>
                    <div className="w-full max-w-4xl rounded-lg border border-slate-200 bg-white shadow-xl" onClick={(event) => event.stopPropagation()}>
                        <header className="crm-panel-header">
                            <div>
                                <h3 className="crm-panel-title">{detail?.exception_class || 'Log entry'}</h3>
                                <p className="crm-panel-subtitle">
                                    {detail?.file ? `${detail.file}${detail.line ? `:${detail.line}` : ''}` : 'No file/line'} • {Number(detail?.occurrence_count || 0).toLocaleString()} occurrences
                                </p>
                            </div>
                        </header>

                        <div className="max-h-[70vh] space-y-4 overflow-auto p-4">
                            {detailQuery.isLoading ? (
                                <p className="text-sm text-slate-500">Loading…</p>
                            ) : detail ? (
                                <>
                                    <section className="grid gap-3 sm:grid-cols-3">
                                        <div className="rounded-md border border-slate-200 bg-slate-50 p-3">
                                            <p className="text-[11px] font-semibold uppercase tracking-[0.1em] text-slate-500">Level</p>
                                            <p className={`mt-1 inline-flex items-center rounded-md px-2 py-0.5 text-xs font-semibold ring-1 ring-inset ${errorLogLevelClasses(detail.level)}`}>
                                                {detail.level || 'error'}
                                            </p>
                                        </div>
                                        <div className="rounded-md border border-slate-200 bg-slate-50 p-3">
                                            <p className="text-[11px] font-semibold uppercase tracking-[0.1em] text-slate-500">Source</p>
                                            <p className="mt-1 text-sm font-semibold text-slate-800">{errorLogSourceLabel(detail.source)}</p>
                                        </div>
                                        <div className="rounded-md border border-slate-200 bg-slate-50 p-3">
                                            <p className="text-[11px] font-semibold uppercase tracking-[0.1em] text-slate-500">Status</p>
                                            <p className="mt-1 text-sm font-semibold text-slate-800">
                                                {detail.resolved_at ? `Resolved ${new Date(detail.resolved_at).toLocaleString()}` : 'Unresolved'}
                                            </p>
                                        </div>
                                    </section>

                                    <section className="rounded-md border border-slate-200 bg-slate-50 p-3">
                                        <h4 className="text-xs font-semibold uppercase tracking-[0.1em] text-slate-500">Message</h4>
                                        <p className="mt-2 break-words text-sm text-slate-700">{detail.message}</p>
                                    </section>

                                    {detail.occurrences?.[0]?.trace ? (
                                        <section className="rounded-md border border-slate-200 bg-white p-3">
                                            <h4 className="text-xs font-semibold uppercase tracking-[0.1em] text-slate-500">Latest Stack Trace</h4>
                                            <pre className="crm-mono mt-2 max-h-72 overflow-auto text-[11px] leading-relaxed text-slate-700">{detail.occurrences[0].trace}</pre>
                                        </section>
                                    ) : null}

                                    <section className="rounded-md border border-slate-200 bg-white p-3">
                                        <h4 className="text-xs font-semibold uppercase tracking-[0.1em] text-slate-500">Recent Occurrences (last {detail.occurrences?.length || 0})</h4>
                                        <ul className="mt-2 divide-y divide-slate-100">
                                            {(detail.occurrences || []).map((occurrence) => (
                                                <li key={occurrence.id} className="py-2 text-xs">
                                                    <p className="font-semibold text-slate-700">{occurrence.occurred_at ? new Date(occurrence.occurred_at).toLocaleString() : '—'}</p>
                                                    <p className="text-slate-600">
                                                        {occurrence.method ? `${occurrence.method} ` : ''}{occurrence.url || (occurrence.context?.job ? `Job: ${occurrence.context.job}` : 'Console')}
                                                    </p>
                                                    {occurrence.user ? (
                                                        <p className="text-slate-500">User: {occurrence.user.name} ({occurrence.user.email})</p>
                                                    ) : null}
                                                </li>
                                            ))}
                                            {(!detail.occurrences || detail.occurrences.length === 0) ? (
                                                <li className="py-2 text-xs text-slate-500">No occurrences recorded.</li>
                                            ) : null}
                                        </ul>
                                    </section>
                                </>
                            ) : (
                                <p className="text-sm text-slate-500">Unable to load detail.</p>
                            )}
                        </div>

                        <footer className="flex flex-wrap justify-end gap-2 border-t border-slate-100 p-4">
                            {detail?.resolved_at ? (
                                <button type="button" className="crm-btn-secondary" onClick={() => reopenMutation.mutate(detail.id)} disabled={reopenMutation.isPending}>
                                    Reopen
                                </button>
                            ) : (
                                <button type="button" className="crm-btn-primary" onClick={() => detail && resolveMutation.mutate(detail.id)} disabled={resolveMutation.isPending || !detail}>
                                    {resolveMutation.isPending ? 'Resolving…' : 'Mark resolved'}
                                </button>
                            )}
                            <button type="button" className="crm-btn-secondary" onClick={() => setSelectedGroupId(null)}>
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
    if (role === 'field_sales') return 'bg-teal-50 text-teal-700 ring-teal-200';
    if (role === 'marketing') return 'bg-violet-50 text-violet-700 ring-violet-200';
    return 'bg-slate-100 text-slate-700 ring-slate-200';
}

function paymentFailureSmsStateClasses(state) {
    if (state === 'enabled') return 'bg-emerald-50 text-emerald-700 ring-emerald-200';
    if (state === 'not_eligible') return 'bg-slate-100 text-slate-700 ring-slate-300';
    return 'bg-amber-50 text-amber-700 ring-amber-200';
}

function paymentFailureSmsStateLabel(state) {
    if (state === 'enabled') return 'On';
    if (state === 'not_eligible') return 'Not eligible';
    return 'Off';
}

function RolesWorkspace() {
    const queryClient = useQueryClient();
    const toast = useToast();
    const launchWindowRef = useRef(null);
    const { user: currentUser } = useAuth();
    const [selectedUser, setSelectedUser] = useState(null);
    const [editor, setEditor] = useState(null);
    const [createOpen, setCreateOpen] = useState(false);
    const [createForm, setCreateForm] = useState({
        name: '',
        email: '',
        password: '',
        phone: '',
        role: 'sales',
        is_ceo: false,
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
                phone: '',
                role: 'sales',
                is_ceo: false,
                status: 'active',
                assigned_market_ids: [],
                reason: 'New team member onboarding',
            });
        },
        onError: (error) => {
            toast.error(error?.response?.data?.message || 'User creation failed.');
        },
    });

    const impersonationLinkMutation = useMutation({
        mutationFn: (userId) => api.post(`/crm/settings/roles/${userId}/impersonation-link`).then((response) => response.data),
        onSuccess: (result) => {
            const popup = launchWindowRef.current;
            if (popup && !popup.closed) {
                popup.location.href = result.url;
                popup.focus();
            } else {
                window.open(result.url, '_blank', 'noopener,noreferrer');
            }
            launchWindowRef.current = null;
            toast.success('CRM user session opened in a new tab.');
        },
        onError: (error) => {
            const popup = launchWindowRef.current;
            if (popup && !popup.closed) {
                popup.close();
            }
            launchWindowRef.current = null;
            toast.error(error?.response?.data?.message || 'Unable to open CRM user session.');
        },
    });

    const testAlertSmsMutation = useMutation({
        mutationFn: (payload) => api.post('/crm/settings/integrations/sms-provider/test', payload).then((response) => response.data),
    });

    const users = data?.users || [];
    const summary = data?.summary || {};
    const availableMarkets = data?.available_markets || [];

    const openEditor = (user) => {
        testAlertSmsMutation.reset();
        setSelectedUser(user);
        setEditor({
            role: user.role || 'sales',
            is_ceo: Boolean(user.is_ceo),
            status: user.status || 'active',
            sb_agent_id: user.sb_agent_id ?? '',
            assigned_market_ids: Array.isArray(user.assigned_market_ids) ? user.assigned_market_ids.map((id) => Number(id)) : [],
            password: '',
            reason: 'Role update from settings',
            phone: user.phone || '',
            notification_prefs: user.notification_prefs ?? null,
        });
    };

    const canImpersonateUser = (user) => (
        user?.status === 'active'
        && user?.role !== 'admin'
        && Number(user?.id) !== Number(currentUser?.id)
    );

    const handleImpersonationLaunch = (user) => {
        launchWindowRef.current = window.open('', '_blank');
        if (launchWindowRef.current && !launchWindowRef.current.closed) {
            launchWindowRef.current.document.write('<p style="font-family: sans-serif; padding: 16px;">Opening CRM user session...</p>');
        }

        impersonationLinkMutation.mutate(user.id);
    };

    const toggleMarket = (marketId) => {
        setEditor((current) => {
            if (!current) return current;

            const exists = current.assigned_market_ids.includes(marketId);
            const assignedMarketIds = exists
                ? current.assigned_market_ids.filter((id) => id !== marketId)
                : [...current.assigned_market_ids, marketId];
            const selectableMarketIds = assignedMarketIds.length > 0
                ? assignedMarketIds
                : (current.role === 'admin' ? availableMarkets.map((market) => Number(market.id)) : []);
            const scopedMarketIds = current.notification_prefs?.payment_failure_sms?.market_ids;

            return {
                ...current,
                assigned_market_ids: assignedMarketIds,
                notification_prefs: Array.isArray(scopedMarketIds)
                    ? {
                        ...current.notification_prefs,
                        payment_failure_sms: {
                            enabled: current.notification_prefs?.payment_failure_sms?.enabled ?? false,
                            market_ids: scopedMarketIds.filter((id) => selectableMarketIds.includes(id)),
                        },
                    }
                    : current.notification_prefs,
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

    const getSmsEnabled = () => {
        if (!editor) return false;
        const prefs = editor.notification_prefs;
        if (!prefs?.payment_failure_sms) return ['sales', 'field_sales'].includes(editor.role);
        return !!prefs.payment_failure_sms.enabled;
    };

    const getSmsMarketScope = () => {
        const ids = editor?.notification_prefs?.payment_failure_sms?.market_ids;
        return ids === null || ids === undefined ? 'all' : 'specific';
    };

    const getSmsMarketIds = () => editor?.notification_prefs?.payment_failure_sms?.market_ids ?? [];

    const getSmsSelectableMarkets = () => {
        if (!editor) return [];

        const selectableIds = editor.assigned_market_ids.length > 0
            ? editor.assigned_market_ids
            : (editor.role === 'admin' ? availableMarkets.map((market) => Number(market.id)) : []);

        return availableMarkets.filter((market) => selectableIds.includes(Number(market.id)));
    };

    const getSmsTestMarketId = () => {
        if (!editor) return null;

        const scopedIds = getSmsMarketIds();
        const selectableMarkets = getSmsSelectableMarkets();
        const selectableIds = selectableMarkets.map((market) => Number(market.id));

        return scopedIds.find((id) => selectableIds.includes(Number(id)))
            ?? editor.assigned_market_ids[0]
            ?? selectableIds[0]
            ?? null;
    };

    const setSmsEnabled = (enabled) => {
        setEditor((current) => ({
            ...current,
            notification_prefs: {
                ...current.notification_prefs,
                payment_failure_sms: {
                    enabled,
                    market_ids: current.notification_prefs?.payment_failure_sms?.market_ids ?? null,
                },
            },
        }));
    };

    const setSmsMarketScope = (scope) => {
        setEditor((current) => ({
            ...current,
            notification_prefs: {
                ...current.notification_prefs,
                payment_failure_sms: {
                    enabled: current.notification_prefs?.payment_failure_sms?.enabled ?? false,
                    market_ids: scope === 'all' ? null : [],
                },
            },
        }));
    };

    const toggleSmsMarket = (marketId) => {
        setEditor((current) => {
            const existing = current.notification_prefs?.payment_failure_sms?.market_ids ?? [];
            const next = existing.includes(marketId)
                ? existing.filter((id) => id !== marketId)
                : [...existing, marketId];

            return {
                ...current,
                notification_prefs: {
                    ...current.notification_prefs,
                    payment_failure_sms: {
                        enabled: current.notification_prefs?.payment_failure_sms?.enabled ?? false,
                        market_ids: next,
                    },
                },
            };
        });
    };

    const getLiveAlertState = () => {
        if (!editor) return 'disabled';
        if (!['admin', 'sub_admin', 'sales', 'field_sales'].includes(editor.role)) return 'not_eligible';
        return getSmsEnabled() ? 'enabled' : 'disabled';
    };

    return (
        <div className="space-y-4">
            <section className="grid gap-4 md:grid-cols-4">
                <MetricCard label="Admins" value={(summary.admins || 0).toLocaleString()} meta="full permissions" tone="accent" />
                <MetricCard label="Sub-admins" value={(summary.sub_admins || 0).toLocaleString()} meta="market-level controls" tone="default" />
                <MetricCard label="Sales Agents" value={(summary.sales || 0).toLocaleString()} meta="execution role" tone="success" />
                <MetricCard label="Field Sales" value={(summary.field_sales || 0).toLocaleString()} meta="field execution" tone="accent" />
                <MetricCard label="CEO Access" value={(summary.ceos || 0).toLocaleString()} meta="executive dashboard tag" tone="slate" />
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
                                    <th className="px-4 py-3 text-left text-[11px] font-semibold uppercase tracking-[0.12em] text-slate-500">CEO</th>
                                    <th className="px-4 py-3 text-left text-[11px] font-semibold uppercase tracking-[0.12em] text-slate-500">Status</th>
                                    <th className="px-4 py-3 text-left text-[11px] font-semibold uppercase tracking-[0.12em] text-slate-500">Assigned Markets</th>
                                    <th className="px-4 py-3 text-left text-[11px] font-semibold uppercase tracking-[0.12em] text-slate-500">Phone</th>
                                    <th className="px-4 py-3 text-left text-[11px] font-semibold uppercase tracking-[0.12em] text-slate-500">Live Alerts</th>
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
                                                {user.is_ceo ? (
                                                    <span className="inline-flex items-center rounded-md bg-slate-900 px-2.5 py-0.5 text-xs font-semibold text-white ring-1 ring-inset ring-slate-900">
                                                        CEO
                                                    </span>
                                                ) : (
                                                    <span className="text-xs text-slate-400">No</span>
                                                )}
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
                                                <p className="font-mono text-xs text-slate-500">{user.phone || '—'}</p>
                                            </td>
                                            <td className="px-4 py-2.5">
                                                <span className={`inline-flex items-center rounded-md px-2.5 py-0.5 text-xs font-medium ring-1 ring-inset ${paymentFailureSmsStateClasses(user.payment_failure_sms_state)}`}>
                                                    {paymentFailureSmsStateLabel(user.payment_failure_sms_state)}
                                                </span>
                                            </td>
                                            <td className="px-4 py-2.5">
                                                <div className="flex flex-wrap items-center gap-2">
                                                    <button
                                                        type="button"
                                                        onClick={() => openEditor(user)}
                                                        className="crm-btn-secondary px-3 py-1.5 text-xs"
                                                    >
                                                        Edit
                                                    </button>
                                                    {canImpersonateUser(user) ? (
                                                        <button
                                                            type="button"
                                                            onClick={() => handleImpersonationLaunch(user)}
                                                            disabled={impersonationLinkMutation.isPending}
                                                            className="crm-btn-secondary px-3 py-1.5 text-xs disabled:cursor-not-allowed disabled:opacity-50"
                                                        >
                                                            {impersonationLinkMutation.isPending ? 'Opening...' : 'Log in as user'}
                                                        </button>
                                                    ) : null}
                                                </div>
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

                        <div className="max-h-[65vh] overflow-y-auto">
                            <div className="grid gap-3 p-4 md:grid-cols-2">
                                <div>
                                    <label htmlFor="role-select" className="mb-1 block text-sm font-medium text-slate-700">Role</label>
                                    <select
                                        id="role-select"
                                        value={editor.role}
                                        onChange={(event) => setEditor((current) => ({
                                            ...current,
                                            role: event.target.value,
                                            is_ceo: event.target.value === 'admin' ? current.is_ceo : false,
                                        }))}
                                        className="crm-select w-full"
                                    >
                                        <option value="admin">Admin</option>
                                        <option value="sub_admin">Sub-admin</option>
                                        <option value="sales">Sales</option>
                                        <option value="field_sales">Field Sales</option>
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

                                <div className="md:col-span-2 rounded-md border border-slate-200 bg-slate-50 px-3 py-3">
                                    <div className="flex items-center justify-between gap-3">
                                        <div>
                                            <p className="text-sm font-semibold text-slate-800">Mark as CEO</p>
                                            <p className="mt-1 text-xs text-slate-500">Grants the executive dashboard when the user is an active admin.</p>
                                        </div>
                                        <button
                                            type="button"
                                            role="switch"
                                            aria-checked={Boolean(editor.is_ceo)}
                                            disabled={editor.role !== 'admin'}
                                            onClick={() => setEditor((current) => ({ ...current, is_ceo: !current.is_ceo }))}
                                            className={`relative inline-flex h-6 w-11 shrink-0 rounded-full transition-colors focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-teal-600 focus-visible:ring-offset-2 ${editor.role !== 'admin' ? 'cursor-not-allowed opacity-50' : 'cursor-pointer'} ${editor.is_ceo ? 'bg-slate-900' : 'bg-slate-200'}`}
                                        >
                                            <span className={`mt-1 inline-block h-4 w-4 rounded-full bg-white shadow-sm transition-transform ${editor.is_ceo ? 'translate-x-6' : 'translate-x-1'}`} />
                                        </button>
                                    </div>
                                    {editor.role !== 'admin' ? (
                                        <p className="mt-2 text-xs text-amber-700">CEO access is only valid for admin users.</p>
                                    ) : null}
                                </div>

                                <div className="md:col-span-2">
                                    <label htmlFor="sb-agent-id" className="mb-1 block text-sm font-medium text-slate-700">SB Agent ID</label>
                                    <input
                                        id="sb-agent-id"
                                        type="number"
                                        min="1"
                                        inputMode="numeric"
                                        value={editor.sb_agent_id}
                                        onChange={(event) => setEditor((current) => ({ ...current, sb_agent_id: event.target.value }))}
                                        className="crm-input"
                                        placeholder="Support Board user ID"
                                    />
                                    <p className="mt-1 text-xs text-slate-500">
                                        Maps this CRM user to their Support Board agent identity for personalized replies.
                                    </p>
                                </div>

                                <div className="md:col-span-2">
                                    <label htmlFor="edit-phone" className="mb-1 block text-sm font-medium text-slate-700">
                                        Phone number <span className="font-normal text-slate-400">(for SMS alerts)</span>
                                    </label>
                                    <div className="flex gap-2">
                                        <input
                                            id="edit-phone"
                                            type="tel"
                                            value={editor.phone}
                                            onChange={(event) => setEditor((current) => ({ ...current, phone: event.target.value }))}
                                            className="crm-input flex-1"
                                            placeholder="e.g. 0712345678"
                                        />
                                        <button
                                            type="button"
                                            onClick={() => testAlertSmsMutation.mutate({
                                                phone: editor.phone.trim(),
                                                message: 'Test: This is a payment alert verification from ExoticCRM. Your alerts are configured correctly.',
                                                market_id: getSmsTestMarketId(),
                                                reason: 'Agent phone verification test',
                                            })}
                                            disabled={!editor.phone.trim() || testAlertSmsMutation.isPending}
                                            className="crm-btn-secondary shrink-0 px-3 disabled:cursor-not-allowed disabled:opacity-50"
                                        >
                                            {testAlertSmsMutation.isPending ? 'Sending…' : 'Test'}
                                        </button>
                                    </div>
                                    {testAlertSmsMutation.isSuccess ? (
                                        <p className="mt-1 text-xs text-emerald-600">✓ Test message sent — check the phone.</p>
                                    ) : null}
                                    {testAlertSmsMutation.isError ? (
                                        <p className="mt-1 text-xs text-red-600">✗ Send failed — check the number and SMS settings.</p>
                                    ) : null}
                                    <p className="mt-1 text-xs text-slate-500">
                                        Test sends a provider check only. Live payment-failure alerts still depend on the alert setting below.
                                    </p>
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

                                <div className="md:col-span-2 space-y-3 rounded-md border border-slate-200 p-3">
                                    <div className="flex items-center justify-between gap-3">
                                        <div>
                                            <p className="text-sm font-medium text-slate-700">Payment failure SMS alerts</p>
                                            <p className="text-xs text-slate-500">
                                                {editor.role === 'marketing'
                                                    ? 'This alert is available for sales, field sales, admin, and sub-admin roles only.'
                                                    : ['admin', 'sub_admin'].includes(editor.role)
                                                    ? 'Opt in to receive an SMS when a payment fails in your accessible markets.'
                                                    : 'Receive an SMS when a payment fails in your assigned markets.'}
                                            </p>
                                        </div>
                                        <span className={`inline-flex items-center rounded-md px-2.5 py-0.5 text-xs font-medium ring-1 ring-inset ${paymentFailureSmsStateClasses(getLiveAlertState())}`}>
                                            {paymentFailureSmsStateLabel(getLiveAlertState())}
                                        </span>
                                        <button
                                            type="button"
                                            role="switch"
                                            aria-checked={getSmsEnabled()}
                                            disabled={editor.role === 'marketing'}
                                            onClick={() => setSmsEnabled(!getSmsEnabled())}
                                            className={`relative inline-flex h-6 w-11 shrink-0 rounded-full transition-colors focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-teal-600 focus-visible:ring-offset-2 ${editor.role === 'marketing' ? 'cursor-not-allowed opacity-50' : 'cursor-pointer'} ${getSmsEnabled() ? 'bg-teal-600' : 'bg-slate-200'}`}
                                        >
                                            <span className={`mt-1 inline-block h-4 w-4 rounded-full bg-white shadow-sm transition-transform ${getSmsEnabled() ? 'translate-x-6' : 'translate-x-1'}`} />
                                        </button>
                                    </div>

                                    {['admin', 'sub_admin'].includes(editor.role) && editor.phone.trim() && !getSmsEnabled() ? (
                                        <div className="rounded-md border border-amber-200 bg-amber-50 px-3 py-2">
                                            <p className="text-xs text-amber-800">
                                                Live payment-failure alerts are off for this user. Test SMS can succeed even while live alerts remain disabled.
                                            </p>
                                        </div>
                                    ) : null}

                                    {getSmsEnabled() && ['admin', 'sub_admin'].includes(editor.role) ? (
                                        <div className="space-y-2 border-t border-slate-100 pt-3">
                                            <p className="text-xs font-medium text-slate-600">Alert scope</p>
                                            {['all', 'specific'].map((scope) => (
                                                <label key={scope} className="flex cursor-pointer items-center gap-2 text-sm text-slate-700">
                                                    <input
                                                        type="radio"
                                                        name="sms-scope"
                                                        checked={getSmsMarketScope() === scope}
                                                        onChange={() => setSmsMarketScope(scope)}
                                                        className="h-4 w-4 border-slate-300 text-teal-700 focus:ring-teal-200"
                                                    />
                                                    {scope === 'all' ? 'All accessible markets' : 'Specific markets only'}
                                                </label>
                                            ))}
                                            {getSmsMarketScope() === 'specific' ? (
                                                <div className="grid gap-1 pl-6 pt-1 sm:grid-cols-2">
                                                    {getSmsSelectableMarkets().length === 0 ? (
                                                        <p className="text-xs text-slate-500">Assign markets above first.</p>
                                                    ) : (
                                                        getSmsSelectableMarkets().map((market) => (
                                                            <label key={market.id} className="flex cursor-pointer items-center gap-2 text-xs text-slate-700">
                                                                <input
                                                                    type="checkbox"
                                                                    checked={getSmsMarketIds().includes(market.id)}
                                                                    onChange={() => toggleSmsMarket(market.id)}
                                                                    className="h-3.5 w-3.5 rounded border-slate-300 text-teal-700 focus:ring-teal-200"
                                                                />
                                                                {market.name}
                                                            </label>
                                                        ))
                                                    )}
                                                </div>
                                            ) : null}
                                        </div>
                                    ) : null}
                                </div>

                                <div className="md:col-span-2">
                                    <label htmlFor="edit-password" className="mb-1 block text-sm font-medium text-slate-700">New Password <span className="font-normal text-slate-400">(leave blank to keep current)</span></label>
                                    <input
                                        id="edit-password"
                                        type="password"
                                        value={editor.password || ''}
                                        onChange={(event) => setEditor((current) => ({ ...current, password: event.target.value }))}
                                        className="crm-input"
                                        placeholder="Min 8 characters"
                                        autoComplete="new-password"
                                    />
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
                                        is_ceo: editor.role === 'admin' && Boolean(editor.is_ceo),
                                        status: editor.status,
                                        sb_agent_id: editor.sb_agent_id === '' ? null : Number(editor.sb_agent_id),
                                        phone: editor.phone.trim() || null,
                                        assigned_market_ids: editor.assigned_market_ids,
                                        notification_prefs: editor.notification_prefs,
                                        ...(editor.password ? { password: editor.password } : {}),
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
                            <input
                                type="tel"
                                value={createForm.phone}
                                onChange={(event) => setCreateForm((current) => ({ ...current, phone: event.target.value }))}
                                className="crm-input md:col-span-2"
                                placeholder="Phone number (for SMS alerts, e.g. 0712345678)"
                            />
                            <select
                                value={createForm.role}
                                onChange={(event) => setCreateForm((current) => ({
                                    ...current,
                                    role: event.target.value,
                                    is_ceo: event.target.value === 'admin' ? current.is_ceo : false,
                                }))}
                                className="crm-select"
                            >
                                <option value="admin">Admin</option>
                                <option value="sub_admin">Sub-admin</option>
                                <option value="sales">Sales</option>
                                <option value="field_sales">Field Sales</option>
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
                            <div className="md:col-span-2 rounded-md border border-slate-200 bg-slate-50 px-3 py-3">
                                <div className="flex items-center justify-between gap-3">
                                    <div>
                                        <p className="text-sm font-semibold text-slate-800">Mark as CEO</p>
                                        <p className="mt-1 text-xs text-slate-500">Available for active admin users only.</p>
                                    </div>
                                    <button
                                        type="button"
                                        role="switch"
                                        aria-checked={Boolean(createForm.is_ceo)}
                                        disabled={createForm.role !== 'admin'}
                                        onClick={() => setCreateForm((current) => ({ ...current, is_ceo: !current.is_ceo }))}
                                        className={`relative inline-flex h-6 w-11 shrink-0 rounded-full transition-colors focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-teal-600 focus-visible:ring-offset-2 ${createForm.role !== 'admin' ? 'cursor-not-allowed opacity-50' : 'cursor-pointer'} ${createForm.is_ceo ? 'bg-slate-900' : 'bg-slate-200'}`}
                                    >
                                        <span className={`mt-1 inline-block h-4 w-4 rounded-full bg-white shadow-sm transition-transform ${createForm.is_ceo ? 'translate-x-6' : 'translate-x-1'}`} />
                                    </button>
                                </div>
                            </div>
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
                                    phone: createForm.phone.trim() || null,
                                    role: createForm.role,
                                    is_ceo: createForm.role === 'admin' && Boolean(createForm.is_ceo),
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

function ReportingFxRateCard({ rate, onSave, onDelete }) {
    const [editing, setEditing] = useState(false);
    const [rateValue, setRateValue] = useState(String(rate.rate ?? ''));
    const [notes, setNotes] = useState(rate.notes ?? '');
    const [confirmDelete, setConfirmDelete] = useState(false);

    const handleSave = () => {
        onSave({ rate: rateValue, notes });
        setEditing(false);
    };

    return (
        <div className="rounded-xl border border-slate-200 bg-white px-4 py-3">
            <div className="flex flex-wrap items-center justify-between gap-3">
                <div className="min-w-0">
                    <p className="text-sm font-semibold text-slate-900">
                        {rate.source_currency} → {rate.target_currency}
                    </p>
                    <p className="mt-0.5 text-xs text-slate-500">
                        {rate.rate_date} · Rate: {rate.rate}
                        {rate.notes ? ` · ${rate.notes}` : ''}
                    </p>
                </div>
                <div className="flex items-center gap-2">
                    <button
                        type="button"
                        onClick={() => { setEditing((v) => !v); setConfirmDelete(false); }}
                        className="crm-btn-secondary px-2.5 py-1 text-xs"
                    >
                        {editing ? 'Cancel' : 'Edit'}
                    </button>
                    {confirmDelete ? (
                        <>
                            <span className="text-xs text-rose-700">Remove?</span>
                            <button type="button" onClick={onDelete} className="crm-btn-secondary px-2.5 py-1 text-xs text-rose-700 hover:border-rose-200 hover:bg-rose-50">Yes</button>
                            <button type="button" onClick={() => setConfirmDelete(false)} className="crm-btn-secondary px-2.5 py-1 text-xs">No</button>
                        </>
                    ) : (
                        <button
                            type="button"
                            onClick={() => { setConfirmDelete(true); setEditing(false); }}
                            className="crm-btn-secondary px-2.5 py-1 text-xs text-rose-700 hover:border-rose-200 hover:bg-rose-50"
                        >
                            Remove
                        </button>
                    )}
                </div>
            </div>
            {editing ? (
                <div className="mt-3 flex flex-wrap items-end gap-2">
                    <label className="space-y-1">
                        <span className="text-xs font-medium text-slate-500">Rate</span>
                        <input
                            type="number"
                            step="any"
                            className="crm-input w-32"
                            value={rateValue}
                            onChange={(e) => setRateValue(e.target.value)}
                        />
                    </label>
                    <label className="flex-1 space-y-1">
                        <span className="text-xs font-medium text-slate-500">Notes</span>
                        <input type="text" className="crm-input w-full" value={notes} onChange={(e) => setNotes(e.target.value)} />
                    </label>
                    <button type="button" onClick={handleSave} className="crm-btn-primary px-3 py-2 text-xs">Save</button>
                </div>
            ) : null}
        </div>
    );
}

function ReportingCurrencySettingsPanel() {
    const toast = useToast();
    const queryClient = useQueryClient();
    const [draft, setDraft] = useState({
        enabled: false,
        target_currency: 'USD',
        provider: 'currencyapi',
        allow_user_override: true,
        stale_days: 7,
        api_key: '',
    });
    const [testResult, setTestResult] = useState(null);
    const [newRate, setNewRate] = useState({ source_currency: '', target_currency: '', rate_date: '', rate: '', notes: '' });
    const [showAddRate, setShowAddRate] = useState(false);

    const settingsQuery = useQuery({
        queryKey: ['reporting-currency-settings'],
        queryFn: () => api.get('/crm/settings/reporting-currency').then((response) => response.data),
    });
    const settings = settingsQuery.data?.settings || {};

    const fxRatesQuery = useQuery({
        queryKey: ['reporting-fx-rates'],
        queryFn: () => api.get('/crm/settings/reporting-fx-rates').then((response) => response.data),
    });
    const fxRates = fxRatesQuery.data?.data || [];

    useEffect(() => {
        if (!settingsQuery.data?.settings) {
            return;
        }

        setDraft((current) => ({
            ...current,
            enabled: Boolean(settings.enabled),
            target_currency: String(settings.target_currency || 'USD').toUpperCase(),
            provider: settings.provider || 'currencyapi',
            allow_user_override: settings.allow_user_override !== false,
            stale_days: Number(settings.stale_days ?? 7),
        }));
    }, [settingsQuery.data, settings]);

    const saveMutation = useMutation({
        mutationFn: () => api.patch('/crm/settings/reporting-currency', {
            enabled: draft.enabled,
            target_currency: draft.target_currency.trim().toUpperCase(),
            provider: draft.provider.trim() || 'currencyapi',
            allow_user_override: draft.allow_user_override,
            stale_days: Number(draft.stale_days || 0),
            ...(draft.api_key.trim() ? { api_key: draft.api_key.trim() } : {}),
        }).then((response) => response.data?.settings || {}),
        onSuccess: (updatedSettings) => {
            queryClient.setQueryData(['reporting-currency-settings'], { settings: updatedSettings });
            queryClient.invalidateQueries({ queryKey: ['reporting-currency-settings'] });
            queryClient.invalidateQueries({ queryKey: ['dashboard'] });
            queryClient.invalidateQueries({ queryKey: ['reports-summary'] });
            queryClient.invalidateQueries({ queryKey: ['payments'] });
            queryClient.invalidateQueries({ queryKey: ['team'] });
            setDraft((current) => ({ ...current, api_key: '' }));
            toast.success('Reporting currency settings saved.');
        },
        onError: (error) => {
            toast.error(error?.response?.data?.message || 'Reporting currency settings could not be saved.');
        },
    });

    const testMutation = useMutation({
        mutationFn: () => api.get('/crm/settings/reporting-currency/test').then((response) => response.data),
        onSuccess: (result) => {
            setTestResult(result);
            queryClient.invalidateQueries({ queryKey: ['reporting-currency-settings'] });
        },
        onError: (error) => {
            setTestResult({ ok: false, error: error?.response?.data?.error || 'Connection test failed.' });
        },
    });

    const createFxRateMutation = useMutation({
        mutationFn: (payload) => api.post('/crm/settings/reporting-fx-rates', payload).then((r) => r.data),
        onSuccess: () => {
            queryClient.invalidateQueries({ queryKey: ['reporting-fx-rates'] });
            setNewRate({ source_currency: '', target_currency: '', rate_date: '', rate: '', notes: '' });
            setShowAddRate(false);
            toast.success('Manual rate saved.');
        },
        onError: (error) => toast.error(error?.response?.data?.message || 'Could not save rate.'),
    });

    const updateFxRateMutation = useMutation({
        mutationFn: ({ id, ...payload }) => api.patch(`/crm/settings/reporting-fx-rates/${id}`, payload).then((r) => r.data),
        onSuccess: () => {
            queryClient.invalidateQueries({ queryKey: ['reporting-fx-rates'] });
            toast.success('Rate updated.');
        },
        onError: (error) => toast.error(error?.response?.data?.message || 'Could not update rate.'),
    });

    const deleteFxRateMutation = useMutation({
        mutationFn: (id) => api.delete(`/crm/settings/reporting-fx-rates/${id}`),
        onSuccess: () => {
            queryClient.invalidateQueries({ queryKey: ['reporting-fx-rates'] });
            toast.success('Rate removed.');
        },
        onError: (error) => toast.error(error?.response?.data?.message || 'Could not remove rate.'),
    });

    const health = settings.health || {};

    return (
        <section className="rounded-lg border border-slate-200 bg-white shadow-sm">
            <header className="flex flex-wrap items-start justify-between gap-3 border-b border-slate-100 px-5 py-4">
                <div>
                    <h3 className="text-lg font-semibold text-slate-900">Reporting Currency</h3>
                    <p className="mt-1 text-sm text-slate-500">Controls normalized revenue totals on Dashboard, Reports, Team, and Payments.</p>
                </div>
                <span className={`rounded-full px-3 py-1 text-xs font-semibold ${health.status === 'configured' ? 'bg-emerald-50 text-emerald-700 ring-1 ring-emerald-200' : 'bg-amber-50 text-amber-700 ring-1 ring-amber-200'}`}>
                    {health.status || 'not checked'}
                </span>
            </header>

            <div className="grid gap-4 px-5 py-4 lg:grid-cols-4">
                <label className="space-y-1">
                    <span className="text-xs font-semibold uppercase tracking-[0.1em] text-slate-500">Target</span>
                    <input
                        value={draft.target_currency}
                        onChange={(event) => setDraft((current) => ({ ...current, target_currency: event.target.value.toUpperCase().slice(0, 8) }))}
                        className="crm-input w-full"
                        placeholder="USD"
                    />
                </label>
                <label className="space-y-1">
                    <span className="text-xs font-semibold uppercase tracking-[0.1em] text-slate-500">Provider</span>
                    <input
                        value={draft.provider}
                        onChange={(event) => setDraft((current) => ({ ...current, provider: event.target.value }))}
                        className="crm-input w-full"
                        placeholder="currencyapi"
                    />
                </label>
                <label className="space-y-1">
                    <span className="text-xs font-semibold uppercase tracking-[0.1em] text-slate-500">Stale days</span>
                    <input
                        type="number"
                        min="0"
                        value={draft.stale_days}
                        onChange={(event) => setDraft((current) => ({ ...current, stale_days: event.target.value }))}
                        className="crm-input w-full"
                    />
                </label>
                <div className="flex flex-col justify-end gap-2">
                    <label className="inline-flex items-center gap-2 text-sm font-medium text-slate-700">
                        <input
                            type="checkbox"
                            checked={draft.enabled}
                            onChange={(event) => setDraft((current) => ({ ...current, enabled: event.target.checked }))}
                            className="h-4 w-4 rounded border-slate-300 text-teal-600 focus:ring-teal-500"
                        />
                        Enabled
                    </label>
                    <label className="inline-flex items-center gap-2 text-sm font-medium text-slate-700">
                        <input
                            type="checkbox"
                            checked={draft.allow_user_override}
                            onChange={(event) => setDraft((current) => ({ ...current, allow_user_override: event.target.checked }))}
                            className="h-4 w-4 rounded border-slate-300 text-teal-600 focus:ring-teal-500"
                        />
                        Page override
                    </label>
                </div>
            </div>

            <div className="border-t border-slate-100 px-5 py-4">
                <p className="mb-2 text-xs font-semibold uppercase tracking-[0.1em] text-slate-500">API Key</p>
                <input
                    type="password"
                    className="crm-input w-full max-w-md"
                    placeholder="API key (leave blank to keep current)"
                    value={draft.api_key}
                    onChange={(event) => setDraft((current) => ({ ...current, api_key: event.target.value }))}
                    autoComplete="new-password"
                />
                <p className={`mt-1.5 text-xs ${settings.api_key_configured ? 'text-emerald-700' : 'text-amber-700'}`}>
                    {settings.api_key_configured
                        ? 'API key stored. Enter a new value only when rotating credentials.'
                        : 'No API key configured — rates will not auto-refresh from CurrencyAPI.'}
                </p>
                <div className="mt-3 flex flex-wrap items-center gap-3">
                    <button
                        type="button"
                        onClick={() => testMutation.mutate()}
                        disabled={testMutation.isPending}
                        className="crm-btn-secondary px-3 py-1.5 text-xs disabled:cursor-not-allowed disabled:opacity-60"
                    >
                        {testMutation.isPending ? 'Testing…' : 'Test connection'}
                    </button>
                    {testResult ? (
                        <span className={`text-xs font-medium ${testResult.ok ? 'text-emerald-700' : 'text-rose-700'}`}>
                            {testResult.ok
                                ? `Connected — ${testResult.quotas_used ?? 0}/${testResult.quotas_total ?? '?'} requests used`
                                : testResult.error}
                        </span>
                    ) : null}
                </div>
            </div>

            <div className="border-t border-slate-100 px-5 py-4">
                <div className="mb-3 flex items-center justify-between gap-3">
                    <p className="text-xs font-semibold uppercase tracking-[0.1em] text-slate-500">Manual Override Rates</p>
                    <button
                        type="button"
                        onClick={() => setShowAddRate((v) => !v)}
                        className="crm-btn-secondary px-3 py-1.5 text-xs"
                    >
                        {showAddRate ? 'Cancel' : '+ Add rate'}
                    </button>
                </div>

                {showAddRate ? (
                    <div className="mb-3 rounded-xl border border-teal-200 bg-teal-50/50 p-4">
                        <div className="flex flex-wrap items-end gap-2">
                            <label className="space-y-1">
                                <span className="text-xs font-medium text-slate-500">From</span>
                                <input type="text" className="crm-input w-20" placeholder="EUR" maxLength={8}
                                    value={newRate.source_currency}
                                    onChange={(e) => setNewRate((r) => ({ ...r, source_currency: e.target.value.toUpperCase() }))} />
                            </label>
                            <label className="space-y-1">
                                <span className="text-xs font-medium text-slate-500">To</span>
                                <input type="text" className="crm-input w-20" placeholder="USD" maxLength={8}
                                    value={newRate.target_currency}
                                    onChange={(e) => setNewRate((r) => ({ ...r, target_currency: e.target.value.toUpperCase() }))} />
                            </label>
                            <label className="space-y-1">
                                <span className="text-xs font-medium text-slate-500">Date</span>
                                <input type="date" className="crm-input"
                                    value={newRate.rate_date}
                                    onChange={(e) => setNewRate((r) => ({ ...r, rate_date: e.target.value }))} />
                            </label>
                            <label className="space-y-1">
                                <span className="text-xs font-medium text-slate-500">Rate</span>
                                <input type="number" step="any" className="crm-input w-28"
                                    value={newRate.rate}
                                    onChange={(e) => setNewRate((r) => ({ ...r, rate: e.target.value }))} />
                            </label>
                            <label className="flex-1 space-y-1">
                                <span className="text-xs font-medium text-slate-500">Notes</span>
                                <input type="text" className="crm-input w-full"
                                    value={newRate.notes}
                                    onChange={(e) => setNewRate((r) => ({ ...r, notes: e.target.value }))} />
                            </label>
                            <button
                                type="button"
                                onClick={() => createFxRateMutation.mutate(newRate)}
                                disabled={createFxRateMutation.isPending || !newRate.source_currency || !newRate.target_currency || !newRate.rate_date || !newRate.rate}
                                className="crm-btn-primary px-3 py-2 text-xs disabled:cursor-not-allowed disabled:opacity-60"
                            >
                                {createFxRateMutation.isPending ? 'Saving…' : 'Save rate'}
                            </button>
                        </div>
                    </div>
                ) : null}

                {fxRates.length === 0 && !showAddRate ? (
                    <p className="text-xs text-slate-400">No manual override rates. These take precedence over live and cached provider rates for the exact date and currency pair.</p>
                ) : (
                    <div className="space-y-2">
                        {fxRates.map((rate) => (
                            <ReportingFxRateCard
                                key={rate.id}
                                rate={rate}
                                onSave={(payload) => updateFxRateMutation.mutate({ id: rate.id, ...payload })}
                                onDelete={() => deleteFxRateMutation.mutate(rate.id)}
                            />
                        ))}
                    </div>
                )}
            </div>

            <footer className="flex flex-wrap items-center justify-between gap-3 border-t border-slate-100 px-5 py-4">
                <p className="text-xs text-slate-500">{health.message || 'Cached historical rates are used for normalized reporting.'}</p>
                <button
                    type="button"
                    onClick={() => saveMutation.mutate()}
                    disabled={saveMutation.isPending || !draft.target_currency.trim()}
                    className="crm-btn-primary disabled:cursor-not-allowed disabled:opacity-60"
                >
                    {saveMutation.isPending ? 'Saving...' : 'Save reporting currency'}
                </button>
            </footer>
        </section>
    );
}

function authStatusTone(settings) {
    if (settings?.google?.enabled && settings?.google?.ready) return 'connected';
    if (settings?.google?.configured) return 'pending';
    return 'unknown';
}

function buildAuthSettingsForm(settings) {
    const google = settings?.google || {};

    return {
        password_login_policy: settings?.password_login_policy || 'enabled',
        require_google_for_non_admin: Boolean(settings?.require_google_for_non_admin),
        google: {
            enabled: Boolean(google.enabled),
            primary: Boolean(google.primary),
            client_id: google.client_id || '',
            client_secret: '',
            redirect_uri: google.redirect_uri || `${window.location.origin}/auth/google/callback`,
            allowed_domains: Array.isArray(google.allowed_domains) ? google.allowed_domains.join('\n') : '',
            allowed_emails: Array.isArray(google.allowed_emails) ? google.allowed_emails.join('\n') : '',
            auto_link_existing_users: google.auto_link_existing_users !== false,
        },
    };
}

function parseAuthList(value) {
    return String(value || '')
        .split(/[\n,]+/)
        .map((item) => item.trim())
        .filter(Boolean);
}

function SecuritySettingsWorkspace() {
    const queryClient = useQueryClient();
    const toast = useToast();
    const [form, setForm] = useState(() => buildAuthSettingsForm(null));

    const authQuery = useQuery({
        queryKey: ['settings-auth'],
        queryFn: () => api.get('/crm/settings/auth').then((response) => response.data),
    });

    const settings = authQuery.data?.settings || null;
    const policyOptions = authQuery.data?.password_policy_options || [
        { value: 'enabled', label: 'Enabled for everyone' },
        { value: 'admin_only', label: 'Admins only' },
        { value: 'disabled', label: 'Disabled' },
    ];

    useEffect(() => {
        if (settings) {
            setForm(buildAuthSettingsForm(settings));
        }
    }, [settings]);

    useEffect(() => {
        const params = new URLSearchParams(window.location.search);
        const googleTest = params.get('googleTest');
        if (!googleTest) return;

        if (googleTest === 'success') {
            toast.success('Google SSO test passed.');
        } else {
            toast.warning('Google SSO test failed. Review the status panel.');
        }
        params.delete('googleTest');
        const nextSearch = params.toString();
        window.history.replaceState({}, '', `${window.location.pathname}${nextSearch ? `?${nextSearch}` : ''}`);
        queryClient.invalidateQueries({ queryKey: ['settings-auth'] });
    }, [queryClient, toast]);

    const saveMutation = useMutation({
        mutationFn: (payload) => api.patch('/crm/settings/auth', payload).then((response) => response.data),
        onSuccess: (data) => {
            queryClient.setQueryData(['settings-auth'], (current) => ({
                ...(current || {}),
                settings: data.settings,
            }));
            toast.success('Authentication settings saved.');
        },
        onError: (error) => {
            toast.error(error.response?.data?.message || 'Unable to save authentication settings.');
        },
    });

    const activateMutation = useMutation({
        mutationFn: () => api.post('/crm/settings/auth/google/activate').then((response) => response.data),
        onSuccess: (data) => {
            queryClient.setQueryData(['settings-auth'], (current) => ({
                ...(current || {}),
                settings: data.settings,
            }));
            toast.success('Google SSO is active.');
        },
        onError: (error) => {
            toast.error(error.response?.data?.message || 'Google SSO is not ready to activate.');
        },
    });

    const rollbackMutation = useMutation({
        mutationFn: () => api.post('/crm/settings/auth/rollback').then((response) => response.data),
        onSuccess: (data) => {
            queryClient.setQueryData(['settings-auth'], (current) => ({
                ...(current || {}),
                settings: data.settings,
            }));
            toast.success('Password login restored and Google SSO disabled.');
        },
    });

    const testMutation = useMutation({
        mutationFn: () => api.post('/crm/settings/auth/google/test/start').then((response) => response.data),
        onSuccess: (data) => {
            if (data?.url) {
                window.location.href = data.url;
            }
        },
        onError: (error) => {
            toast.error(error.response?.data?.message || 'Save Google settings before testing.');
        },
    });

    const save = () => {
        saveMutation.mutate({
            password_login_policy: form.password_login_policy,
            require_google_for_non_admin: form.require_google_for_non_admin,
            google: {
                enabled: false,
                primary: form.google.primary,
                client_id: form.google.client_id,
                client_secret: form.google.client_secret,
                redirect_uri: form.google.redirect_uri,
                allowed_domains: parseAuthList(form.google.allowed_domains),
                allowed_emails: parseAuthList(form.google.allowed_emails),
                auto_link_existing_users: form.google.auto_link_existing_users,
            },
        });
    };

    const passwordLabel = {
        enabled: 'Enabled',
        admin_only: 'Admins only',
        disabled: 'Disabled',
    }[settings?.password_login_policy || 'enabled'];
    const googleTone = authStatusTone(settings);
    const googleStatus = settings?.google?.enabled
        ? 'Active'
        : settings?.google?.ready
            ? 'Ready to activate'
            : settings?.google?.configured
                ? 'Test required'
                : 'Not configured';

    return (
        <div className="space-y-4">
            <section className="grid gap-4 lg:grid-cols-4">
                <MetricCard label="Email Login" value={passwordLabel} hint={settings?.emergency_password_login ? 'Emergency fallback on' : 'No emergency fallback'} tone={settings?.password_login_policy === 'disabled' ? 'warning' : 'success'} />
                <MetricCard label="Google SSO" value={googleStatus} hint={settings?.google?.last_test?.tested_at ? `Last tested ${formatDateTime(settings.google.last_test.tested_at)}` : 'No live test yet'} tone={googleTone === 'connected' ? 'success' : googleTone === 'pending' ? 'warning' : 'neutral'} />
                <MetricCard label="Workspace Domains" value={(settings?.google?.allowed_domains || []).length || 0} hint={(settings?.google?.allowed_domains || []).join(', ') || 'No domain restriction'} tone="neutral" />
                <MetricCard label="Login Default" value={settings?.google?.primary ? 'Google' : 'Email'} hint={settings?.require_google_for_non_admin ? 'Google required for non-admins' : 'Flexible during rollout'} tone="neutral" />
            </section>

            <section className="crm-surface overflow-hidden">
                <div className="border-b border-slate-200 px-5 py-4">
                    <h3 className="crm-panel-title">Authentication Methods</h3>
                    <p className="mt-1 text-sm text-slate-500">Change login policy without removing the emergency recovery path.</p>
                </div>
                <div className="grid gap-5 p-5 lg:grid-cols-[minmax(0,0.9fr)_minmax(0,1.1fr)]">
                    <div className="space-y-4">
                        <label className="block">
                            <span className="text-sm font-medium text-slate-700">Password login policy</span>
                            <select
                                value={form.password_login_policy}
                                onChange={(event) => setForm((current) => ({ ...current, password_login_policy: event.target.value }))}
                                className="mt-2 h-11 w-full rounded-lg border border-slate-300 bg-white px-3 text-sm text-slate-800 focus:border-teal-600 focus:outline-none focus:ring-2 focus:ring-teal-100"
                            >
                                {policyOptions.map((option) => (
                                    <option key={option.value} value={option.value}>{option.label}</option>
                                ))}
                            </select>
                        </label>

                        <label className="flex items-start gap-3 rounded-lg border border-slate-200 bg-slate-50 px-3 py-3">
                            <input
                                type="checkbox"
                                checked={form.require_google_for_non_admin}
                                onChange={(event) => setForm((current) => ({ ...current, require_google_for_non_admin: event.target.checked }))}
                                className="mt-1 h-4 w-4 rounded border-slate-300 text-teal-700 focus:ring-teal-500"
                            />
                            <span>
                                <span className="block text-sm font-semibold text-slate-800">Require Google for non-admin users</span>
                                <span className="mt-1 block text-xs leading-5 text-slate-500">Admins keep password recovery while the team moves to Workspace SSO.</span>
                            </span>
                        </label>
                    </div>

                    <div className="rounded-lg border border-amber-200 bg-amber-50 px-4 py-3 text-sm leading-6 text-amber-800">
                        Settings will not allow Google-only or restricted password login until Google has completed a live OAuth test. The server-side emergency fallback remains controlled by environment config.
                    </div>
                </div>
            </section>

            <section className="crm-surface overflow-hidden">
                <div className="flex flex-wrap items-center justify-between gap-3 border-b border-slate-200 px-5 py-4">
                    <div>
                        <h3 className="crm-panel-title">Google SSO Configuration</h3>
                        <p className="mt-1 text-sm text-slate-500">Use Google Workspace to verify identity, then keep CRM permissions local.</p>
                    </div>
                    <span className={`rounded-full px-3 py-1 text-xs font-semibold ring-1 ${statusChip(settings?.google?.enabled ? 'connected' : settings?.google?.configured ? 'pending' : 'unknown')}`}>
                        {googleStatus}
                    </span>
                </div>

                <div className="grid gap-5 p-5 lg:grid-cols-2">
                    <label className="block">
                        <span className="text-sm font-medium text-slate-700">Google client ID</span>
                        <input
                            value={form.google.client_id}
                            onChange={(event) => setForm((current) => ({ ...current, google: { ...current.google, client_id: event.target.value } }))}
                            className="mt-2 h-11 w-full rounded-lg border border-slate-300 bg-white px-3 text-sm text-slate-800 focus:border-teal-600 focus:outline-none focus:ring-2 focus:ring-teal-100"
                            placeholder="1234567890-abc.apps.googleusercontent.com"
                        />
                    </label>

                    <label className="block">
                        <span className="text-sm font-medium text-slate-700">Google client secret</span>
                        <input
                            type="password"
                            value={form.google.client_secret}
                            onChange={(event) => setForm((current) => ({ ...current, google: { ...current.google, client_secret: event.target.value } }))}
                            className="mt-2 h-11 w-full rounded-lg border border-slate-300 bg-white px-3 text-sm text-slate-800 focus:border-teal-600 focus:outline-none focus:ring-2 focus:ring-teal-100"
                            placeholder={settings?.google?.client_secret_configured ? 'Secret saved. Enter a new value to replace it.' : 'Paste client secret'}
                        />
                    </label>

                    <label className="block lg:col-span-2">
                        <span className="text-sm font-medium text-slate-700">Redirect URI</span>
                        <input
                            value={form.google.redirect_uri}
                            onChange={(event) => setForm((current) => ({ ...current, google: { ...current.google, redirect_uri: event.target.value } }))}
                            className="mt-2 h-11 w-full rounded-lg border border-slate-300 bg-white px-3 text-sm text-slate-800 focus:border-teal-600 focus:outline-none focus:ring-2 focus:ring-teal-100"
                        />
                    </label>

                    <label className="block">
                        <span className="text-sm font-medium text-slate-700">Allowed Workspace domains</span>
                        <textarea
                            value={form.google.allowed_domains}
                            onChange={(event) => setForm((current) => ({ ...current, google: { ...current.google, allowed_domains: event.target.value } }))}
                            className="mt-2 min-h-24 w-full rounded-lg border border-slate-300 bg-white px-3 py-2 text-sm text-slate-800 focus:border-teal-600 focus:outline-none focus:ring-2 focus:ring-teal-100"
                            placeholder="exotic-online.com"
                        />
                    </label>

                    <label className="block">
                        <span className="text-sm font-medium text-slate-700">Allowed email overrides</span>
                        <textarea
                            value={form.google.allowed_emails}
                            onChange={(event) => setForm((current) => ({ ...current, google: { ...current.google, allowed_emails: event.target.value } }))}
                            className="mt-2 min-h-24 w-full rounded-lg border border-slate-300 bg-white px-3 py-2 text-sm text-slate-800 focus:border-teal-600 focus:outline-none focus:ring-2 focus:ring-teal-100"
                            placeholder="admin@example.com"
                        />
                    </label>

                    <label className="flex items-start gap-3 rounded-lg border border-slate-200 bg-slate-50 px-3 py-3 lg:col-span-2">
                        <input
                            type="checkbox"
                            checked={form.google.auto_link_existing_users}
                            onChange={(event) => setForm((current) => ({ ...current, google: { ...current.google, auto_link_existing_users: event.target.checked } }))}
                            className="mt-1 h-4 w-4 rounded border-slate-300 text-teal-700 focus:ring-teal-500"
                        />
                        <span>
                            <span className="block text-sm font-semibold text-slate-800">Auto-link existing CRM users by verified Google email</span>
                            <span className="mt-1 block text-xs leading-5 text-slate-500">No Google account can create a CRM user. This only links users already present in Roles & Permissions.</span>
                        </span>
                    </label>
                </div>

                <div className="flex flex-wrap items-center justify-between gap-3 border-t border-slate-200 px-5 py-4">
                    <div className="text-sm text-slate-500">
                        {settings?.google?.last_test?.status === 'passed'
                            ? `Last test passed for ${settings.google.last_test.email || 'Google account'}.`
                            : settings?.google?.last_test?.message || 'Save the draft, then run a live Google OAuth test.'}
                    </div>
                    <div className="flex flex-wrap gap-2">
                        <button type="button" onClick={save} disabled={saveMutation.isPending} className="rounded-lg border border-slate-300 bg-white px-3 py-2 text-sm font-semibold text-slate-700 transition hover:bg-slate-50 disabled:opacity-60">
                            {saveMutation.isPending ? 'Saving...' : 'Save draft'}
                        </button>
                        <button type="button" onClick={() => testMutation.mutate()} disabled={testMutation.isPending || !settings?.google?.configured} className="rounded-lg border border-teal-200 bg-teal-50 px-3 py-2 text-sm font-semibold text-teal-800 transition hover:bg-teal-100 disabled:opacity-60">
                            {testMutation.isPending ? 'Opening...' : 'Test Google login'}
                        </button>
                        <button type="button" onClick={() => activateMutation.mutate()} disabled={activateMutation.isPending || !settings?.google?.ready} className="rounded-lg bg-teal-700 px-3 py-2 text-sm font-semibold text-white transition hover:bg-teal-800 disabled:opacity-60">
                            Activate Google SSO
                        </button>
                        <button type="button" onClick={() => rollbackMutation.mutate()} disabled={rollbackMutation.isPending} className="rounded-lg border border-rose-200 bg-white px-3 py-2 text-sm font-semibold text-rose-700 transition hover:bg-rose-50 disabled:opacity-60">
                            Restore password login
                        </button>
                    </div>
                </div>
            </section>
        </div>
    );
}

export default function Settings() {
    const { user } = useAuth();
    const toast = useToast();
    const queryClient = useQueryClient();
    const isSales = (user?.role || '') === 'sales';
    const [activeTab, setActiveTab] = useState('integrations');
    const canManageTemplates = ['admin', 'sub_admin'].includes(user?.role || '');
    const canViewRoles = (user?.role || '') === 'admin';
    const canManageSecurity = (user?.role || '') === 'admin';
    const canCreateMarkets = (user?.role || '') === 'admin';
    const canManageMarkets = ['admin', 'sub_admin'].includes(user?.role || '');
    const canEditPaymentLinks = ['admin', 'sub_admin'].includes(user?.role || '');
    const canManagePushProviders = ['admin', 'sub_admin'].includes(user?.role || '');
    const canManageWalletSystem = (user?.role || '') === 'admin';
    const canManageWalletPlatforms = ['admin', 'sub_admin'].includes(user?.role || '');
    const canAccessBillingWorkspace = ['admin', 'sub_admin'].includes(user?.role || '');
    const canManageSms = (user?.role || '') === 'admin';
    const canViewUpdates = ['admin', 'sub_admin'].includes(user?.role || '');
    const canDeployUpdates = (user?.role || '') === 'admin';
    const billingAvailabilityQuery = useQuery({
        queryKey: ['billing-workspace-availability'],
        queryFn: () => api.get('/crm/settings/billing/overview').then((response) => response.data?.billing || {}),
        enabled: !isSales,
        staleTime: 60_000,
    });
    const billingWorkspaceEnabled = Boolean(
        billingAvailabilityQuery.data?.features?.workspace ?? billingAvailabilityQuery.data?.enabled
    );
    const kycSettingsQuery = useQuery({
        queryKey: ['settings-kyc'],
        queryFn: () => kyc.getSettings(),
        enabled: activeTab === 'kyc',
        staleTime: 60_000,
    });
    const kycPlatformsQuery = useQuery({
        queryKey: ['settings-kyc-platforms'],
        queryFn: () => api.get('/platforms').then((response) => response.data?.platforms || []),
        enabled: activeTab === 'kyc',
        staleTime: 60_000,
    });
    const saveKycSettingsMutation = useMutation({
        mutationFn: (payload) => kyc.updateSettings(payload),
        onSuccess: () => {
            toast.success('KYC settings saved.');
            queryClient.invalidateQueries({ queryKey: ['settings-kyc'] });
            queryClient.invalidateQueries({ queryKey: ['kyc-settings-summary'] });
            queryClient.invalidateQueries({ queryKey: ['kyc-queue'] });
            queryClient.invalidateQueries({ queryKey: ['kyc-queue-count'] });
        },
        onError: (error) => {
            toast.error(error?.response?.data?.message || 'Could not save KYC settings.');
        },
    });
    const testS3Mutation = useMutation({
        mutationFn: (payload) => kyc.testS3Connectivity(payload),
        onSuccess: () => {
            toast.success('S3 connectivity probe passed.');
            queryClient.invalidateQueries({ queryKey: ['settings-kyc'] });
        },
        onError: (error) => {
            toast.error(error?.response?.data?.message || 'S3 connectivity test failed.');
        },
    });

    const tabs = useMemo(() => {
        return baseTabs.filter((tab) => {
            if (tab.id === 'roles') {
                return canViewRoles;
            }

            if (tab.id === 'field-sales') {
                return ['admin', 'sub_admin'].includes(user?.role || '');
            }

            if (tab.id === 'security') {
                return canManageSecurity;
            }

            if (tab.id === 'error-logs') {
                return (user?.role || '') === 'admin';
            }

            if (tab.id === 'billing') {
                return canAccessBillingWorkspace && billingWorkspaceEnabled;
            }

            if (tab.id === 'seo-engine') {
                return ['admin', 'sub_admin'].includes(user?.role || '');
            }

            if (tab.id === 'auto-optimize') {
                return ['admin', 'sub_admin', 'marketing'].includes(user?.role || '');
            }

            if (tab.id === 'ai') {
                return ['admin', 'sub_admin'].includes(user?.role || '');
            }

            return true;
        });
    }, [billingWorkspaceEnabled, canAccessBillingWorkspace, canManageSecurity, canViewRoles, user?.role]);

    useEffect(() => {
        const params = new URLSearchParams(window.location.search);
        const requestedTab = params.get('tab');
        if (requestedTab && tabs.some((tab) => tab.id === requestedTab)) {
            setActiveTab(requestedTab);
        }
    }, [tabs]);

    useEffect(() => {
        if (!tabs.find((tab) => tab.id === activeTab)) {
            setActiveTab(tabs[0]?.id || 'integrations');
        }
    }, [activeTab, tabs]);

    if (isSales) {
        return <Navigate to="/" replace />;
    }

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
                    canManageMarkets={canManageMarkets}
                    canManagePushProviders={canManagePushProviders}
                    canManageWalletSystem={canManageWalletSystem}
                    canManageWalletPlatforms={canManageWalletPlatforms}
                    currentUserEmail={user?.email || ''}
                />
            ) : null}
            {activeTab === 'kyc' ? (
                <KycSetupWizard
                    settings={kycSettingsQuery.data?.settings}
                    platforms={kycPlatformsQuery.data || []}
                    totalBlobBytes={kycSettingsQuery.data?.total_blob_bytes || 0}
                    s3Health={testS3Mutation.data || kycSettingsQuery.data?.s3_health || null}
                    isSaving={saveKycSettingsMutation.isPending}
                    isTestingS3={testS3Mutation.isPending}
                    onSave={(payload) => saveKycSettingsMutation.mutate(payload)}
                    onTestS3={(payload) => testS3Mutation.mutate(payload)}
                />
            ) : null}

            {activeTab === 'billing' && canAccessBillingWorkspace && billingWorkspaceEnabled ? <BillingWorkspace /> : null}
            {activeTab === 'seo-engine' ? <SeoEnginePanel /> : null}
            {activeTab === 'auto-optimize' ? <AutoOptimizePanel /> : null}
            {activeTab === 'ai' ? <AiWorkspacePanel /> : null}
            {activeTab === 'faq' ? <FaqWorkspace /> : null}
            {activeTab === 'templates' ? <TemplatesWorkspace canManageTemplates={canManageTemplates} /> : null}
            {activeTab === 'logs' ? <WebhookLogsWorkspace /> : null}
            {activeTab === 'error-logs' && (user?.role || '') === 'admin' ? <ErrorLogsWorkspace /> : null}
            {activeTab === 'roles' && canViewRoles ? <RolesWorkspace /> : null}
            {activeTab === 'field-sales' && ['admin', 'sub_admin'].includes(user?.role || '') ? <FieldSalesSettingsPanel /> : null}
            {activeTab === 'security' && canManageSecurity ? <SecuritySettingsWorkspace /> : null}
            {activeTab === 'dashboard' ? (
                <div className="space-y-4">
                    <ReportingCurrencySettingsPanel />
                    <DashboardSettingsPanel />
                    <SalesDashboardSettingsPanel />
                </div>
            ) : null}
            {activeTab === 'health' ? (
                <SystemHealthWorkspace
                    canCreateMarkets={canCreateMarkets}
                    canManageMarkets={canManageMarkets}
                    canManageSms={canManageSms}
                    canViewUpdates={canViewUpdates}
                    canDeployUpdates={canDeployUpdates}
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
