import React, { useEffect, useMemo, useState } from 'react';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import api from '../services/api';
import DataTable from '../components/DataTable';
import ConfirmDialog from '../components/ConfirmDialog';
import MetricCard from '../components/MetricCard';
import PageHeader from '../components/PageHeader';
import StatusBadge from '../components/StatusBadge';
import { useAuth } from '../hooks/useAuth';
import { useToast } from '../components/ToastProvider';

const baseTabs = [
    { id: 'integrations', label: 'Integrations' },
    { id: 'templates', label: 'Templates' },
    { id: 'logs', label: 'Webhook Logs' },
    { id: 'roles', label: 'Roles & Permissions' },
];

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
    };
}

function defaultPlatformForm() {
    return {
        name: '',
        domain: '',
        country: '',
        is_active: true,
        wp_api_url: '',
        wp_api_user: '',
        wp_api_password: '',
        currency_code: 'KES',
        timezone: 'Africa/Nairobi',
        phone_prefix: '254',
    };
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

function IntegrationsWorkspace({ canCreateMarkets }) {
    const queryClient = useQueryClient();
    const toast = useToast();
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

    const { data, isLoading } = useQuery({
        queryKey: ['settings-integrations'],
        queryFn: () => api.get('/crm/settings/integrations').then((response) => response.data),
    });

    const services = data?.services || {};
    const smsProviderConfig = services.sms_provider || null;
    const activeProviderLabel = smsProviderLabel(smsProviderConfig?.active_provider || 'legacy_gateway');
    const serviceRows = [
        {
            key: 'sms',
            label: 'SMS Routing',
            status: services.sms_gateway?.status || 'pending',
            detail: `Active: ${activeProviderLabel} • ${services.sms_gateway?.enabled ? 'Dispatch enabled' : 'Dispatch disabled'}`,
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
    const scraperSources = data?.scraper?.sources || [];
    const scraperRuns = data?.scraper?.recent_runs || [];
    const scraperProfiles = data?.scraper?.parser_profiles || ['contact_cards', 'profile_links'];
    const scraperSchedules = data?.scraper?.fetch_schedules || ['manual_only', 'daily', 'weekly'];
    const scraperDedupeModes = data?.scraper?.dedupe_modes || ['phone_or_email', 'phone_only', 'email_only', 'source_url'];
    const selectedScraperSource = scraperSources.find((source) => source.id === selectedScraperSourceId) || null;

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
        if (!selectedPlatform) {
            return;
        }

        setEditor(buildPlatformEditor(selectedPlatform));
        setLatestSyncResult(selectedPlatform.sync?.last_result || null);
    }, [selectedPlatformId]);

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

    const createPlatformMutation = useMutation({
        mutationFn: (payload) => api.post('/crm/settings/integrations/platforms', payload).then((response) => response.data),
        onSuccess: (response) => {
            queryClient.invalidateQueries({ queryKey: ['settings-integrations'] });
            setCreateOpen(false);
            setCreateForm(defaultPlatformForm());
            setSelectedPlatformId(response?.platform?.platform_id || null);
            toast.success('Market integration profile created.');
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

    const connectedServices = serviceRows.filter((item) => ['connected', 'healthy', 'success'].includes(item.status)).length;
    const wpReadyMarkets = platformRows.filter((item) => item.wp_sync?.credentials_ready).length;
    const syncErrors = platformRows.filter((item) => item.sync?.last_status === 'error').length;
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

    const selectedHasCredentials = Boolean(selectedPlatform?.wp_sync?.credentials_ready);
    const activeScraperSources = scraperSources.filter((source) => source.is_active).length;
    const scraperBlockedOrFailed = scraperSources.filter((source) => ['blocked', 'error'].includes(source.last_run_status)).length;
    const selectedScraperRules = scraperEditor?.parser_rules || defaultScraperRules();
    const selectedScraperCompliant = Boolean(scraperEditor?.compliance_ack_robots) && Boolean(scraperEditor?.compliance_ack_tos);

    return (
        <div className="space-y-4">
            <section className="grid gap-4 md:grid-cols-4">
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
            </section>

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
                                                className="h-4 w-4 rounded border-slate-300 text-teal-700 focus:ring-teal-200"
                                            />
                                            Market is active
                                        </label>
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
                            <input value={createForm.wp_api_user} onChange={(event) => setCreateForm((current) => ({ ...current, wp_api_user: event.target.value }))} className="crm-input" placeholder="WordPress API user" />
                            <input value={createForm.wp_api_password} onChange={(event) => setCreateForm((current) => ({ ...current, wp_api_password: event.target.value }))} className="crm-input" type="password" placeholder="WordPress API password" />
                            <label className="md:col-span-2 flex items-center gap-2 text-sm text-slate-700">
                                <input type="checkbox" checked={createForm.is_active} onChange={(event) => setCreateForm((current) => ({ ...current, is_active: event.target.checked }))} className="h-4 w-4 rounded border-slate-300 text-teal-700 focus:ring-teal-200" />
                                Market is active
                            </label>
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
            render: (row) => <span className="text-xs capitalize text-slate-700">{row.category.replace('_', ' ')}</span>,
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

export default function Settings() {
    const { user } = useAuth();
    const [activeTab, setActiveTab] = useState('integrations');
    const canManageTemplates = ['admin', 'sub_admin'].includes(user?.role || '');
    const canViewRoles = (user?.role || '') === 'admin';
    const canCreateMarkets = (user?.role || '') === 'admin';

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

            {activeTab === 'integrations' ? <IntegrationsWorkspace canCreateMarkets={canCreateMarkets} /> : null}

            {activeTab === 'templates' ? <TemplatesWorkspace canManageTemplates={canManageTemplates} /> : null}
            {activeTab === 'logs' ? <WebhookLogsWorkspace /> : null}
            {activeTab === 'roles' && canViewRoles ? <RolesWorkspace /> : null}
        </div>
    );
}
