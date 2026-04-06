import React, { useEffect, useMemo, useRef, useState } from 'react';
import { useMutation, useQueryClient } from '@tanstack/react-query';
import api from '../../services/api';
import { useToast } from '../ToastProvider';
import BillingStateNotice from './BillingStateNotice';
import { isForbiddenQueryError } from './queryState';

const environments = ['sandbox', 'production'];
const modeOptions = ['disabled', 'sandbox', 'production'];
const BILLING_SURFACES = [
    'wallet_funding',
    'subscription_link',
    'self_checkout',
    'proxy_hosted_checkout',
    'wallet_auto_renew',
];

function buildDraft(system = {}) {
    const timing = system?.timing || {};
    const smtp = system?.smtp || {};

    return {
        mode: system?.mode || 'disabled',
        default_currency: system?.default_currency || 'KES',
        max_single_topup_default: system?.max_single_topup_default || '50000.00',
        max_wallet_balance_default: system?.max_wallet_balance_default || '200000.00',
        billing_domains: {
            sandbox: system?.billing_domains?.sandbox || '',
            production: system?.billing_domains?.production || '',
        },
        billing_branding: {
            sandbox: {
                business_name: system?.billing_branding?.sandbox?.business_name || '',
                description: system?.billing_branding?.sandbox?.description || '',
            },
            production: {
                business_name: system?.billing_branding?.production?.business_name || '',
                description: system?.billing_branding?.production?.description || '',
            },
        },
        redirect_delay_seconds: String(timing?.redirect_delay_seconds ?? 3),
        wallet_refresh_rate_limit_seconds: String(timing?.wallet_refresh_rate_limit_seconds ?? 15),
        wallet_refresh_timeout_seconds: String(timing?.wallet_refresh_timeout_seconds ?? 15),
        topup_poll_interval_seconds: String(timing?.topup_poll_interval_seconds ?? 10),
        smtp: {
            enabled: Boolean(smtp?.enabled),
            host: smtp?.host || '',
            port: String(smtp?.port ?? 587),
            username: smtp?.username || '',
            password: '',
            encryption: smtp?.encryption || 'tls',
            from_address: smtp?.from_address || '',
            from_name: smtp?.from_name || '',
            password_configured: Boolean(smtp?.password_configured),
        },
        reason: 'Updated wallet system settings',
    };
}

function normalizeDraft(draft) {
    return {
        mode: String(draft.mode || 'disabled'),
        default_currency: String(draft.default_currency || '').trim().toUpperCase(),
        max_single_topup_default: String(draft.max_single_topup_default || '').trim(),
        max_wallet_balance_default: String(draft.max_wallet_balance_default || '').trim(),
        billing_domains: {
            sandbox: String(draft.billing_domains?.sandbox || '').trim(),
            production: String(draft.billing_domains?.production || '').trim(),
        },
        billing_branding: {
            sandbox: {
                business_name: String(draft.billing_branding?.sandbox?.business_name || '').trim(),
                description: String(draft.billing_branding?.sandbox?.description || '').trim(),
            },
            production: {
                business_name: String(draft.billing_branding?.production?.business_name || '').trim(),
                description: String(draft.billing_branding?.production?.description || '').trim(),
            },
        },
        redirect_delay_seconds: String(draft.redirect_delay_seconds || '').trim(),
        wallet_refresh_rate_limit_seconds: String(draft.wallet_refresh_rate_limit_seconds || '').trim(),
        wallet_refresh_timeout_seconds: String(draft.wallet_refresh_timeout_seconds || '').trim(),
        topup_poll_interval_seconds: String(draft.topup_poll_interval_seconds || '').trim(),
        smtp: {
            enabled: Boolean(draft.smtp?.enabled),
            host: String(draft.smtp?.host || '').trim(),
            port: String(draft.smtp?.port || '').trim(),
            username: String(draft.smtp?.username || '').trim(),
            password: String(draft.smtp?.password || ''),
            encryption: String(draft.smtp?.encryption || '').trim(),
            from_address: String(draft.smtp?.from_address || '').trim(),
            from_name: String(draft.smtp?.from_name || '').trim(),
        },
        reason: String(draft.reason || '').trim(),
    };
}

export default function BillingSystemTab({
    system,
    source = {},
    features = {},
    markets = [],
    isLoading = false,
    isError = false,
    error = null,
}) {
    const toast = useToast();
    const queryClient = useQueryClient();
    const [draft, setDraft] = useState(() => buildDraft(system));
    const [initialDraft, setInitialDraft] = useState(() => buildDraft(system));
    const [killSwitchesDraft, setKillSwitchesDraft] = useState({ market_ids: [], surfaces: [] });
    const [marketIdInput, setMarketIdInput] = useState('');

    useEffect(() => {
        const nextDraft = buildDraft(system);
        setDraft(nextDraft);
        setInitialDraft(nextDraft);
    }, [system]);

    useEffect(() => {
        const nextKillSwitches = {
            market_ids: Array.isArray(source?.rollout?.kill_switches?.market_ids)
                ? source.rollout.kill_switches.market_ids.map((value) => Number(value)).filter((value) => Number.isInteger(value) && value > 0)
                : [],
            surfaces: Array.isArray(source?.rollout?.kill_switches?.surfaces)
                ? source.rollout.kill_switches.surfaces
                : [],
        };

        setKillSwitchesDraft(nextKillSwitches);
    }, [source]);

    const editable = Boolean(source?.editable);
    const rollout = source?.rollout || {};
    const liveReadEnabled = Boolean(source?.live_read_enabled);
    const availableMarketIds = useMemo(
        () => markets
            .map((market) => Number(market?.id))
            .filter((value) => Number.isInteger(value) && value > 0),
        [markets]
    );
    const visibleMarketIds = useMemo(() => {
        const saved = Array.isArray(killSwitchesDraft.market_ids) ? killSwitchesDraft.market_ids : [];

        return Array.from(new Set([...availableMarketIds, ...saved])).sort((left, right) => left - right);
    }, [availableMarketIds, killSwitchesDraft.market_ids]);

    const dirty = useMemo(() => {
        return JSON.stringify(normalizeDraft(draft)) !== JSON.stringify(normalizeDraft(initialDraft));
    }, [draft, initialDraft]);

    const killSwitchesDirty = useMemo(() => {
        const current = {
            market_ids: [...(killSwitchesDraft.market_ids || [])].sort((left, right) => left - right),
            surfaces: [...(killSwitchesDraft.surfaces || [])].sort(),
        };
        const persisted = {
            market_ids: [...(rollout?.kill_switches?.market_ids || [])].map((value) => Number(value)).sort((left, right) => left - right),
            surfaces: [...(rollout?.kill_switches?.surfaces || [])].sort(),
        };

        return JSON.stringify(current) !== JSON.stringify(persisted);
    }, [killSwitchesDraft, rollout]);

    const saveMutation = useMutation({
        mutationFn: (payload) => api.patch('/crm/settings/wallet', payload).then((response) => response.data),
        onSuccess: (response) => {
            queryClient.invalidateQueries({ queryKey: ['billing-system-settings'] });
            queryClient.invalidateQueries({ queryKey: ['billing-workspace-overview'] });
            queryClient.invalidateQueries({ queryKey: ['settings-integrations'] });
            const nextDraft = buildDraft(response?.system || null);
            setDraft(nextDraft);
            setInitialDraft(nextDraft);
            toast.success('Billing system settings saved.', {
                title: 'Global billing configuration updated',
            });
        },
        onError: (mutationError) => {
            const validation = mutationError?.response?.data?.errors;
            const firstValidationError = validation && typeof validation === 'object'
                ? Object.values(validation).flat()[0]
                : null;

            toast.error(
                firstValidationError
                    || mutationError?.response?.data?.message
                    || 'CRM could not save the billing system settings.',
                { title: 'Billing system save failed' }
            );
        },
    });

    const killSwitchMutation = useMutation({
        mutationFn: (payload) => api.patch('/crm/settings/billing/system/kill-switches', payload).then((response) => response.data),
        onMutate: async (payload) => {
            await queryClient.cancelQueries({ queryKey: ['billing-system-settings'] });
            const previous = queryClient.getQueryData(['billing-system-settings']);

            queryClient.setQueryData(['billing-system-settings'], (current) => {
                if (!current || typeof current !== 'object') {
                    return current;
                }

                return {
                    ...current,
                    source: {
                        ...(current.source || {}),
                        rollout: {
                            ...((current.source || {}).rollout || {}),
                            kill_switches: payload,
                        },
                    },
                };
            });

            return { previous };
        },
        onSuccess: (response) => {
            const nextKillSwitches = response?.kill_switches || { market_ids: [], surfaces: [] };
            setKillSwitchesDraft({
                market_ids: Array.isArray(nextKillSwitches.market_ids) ? nextKillSwitches.market_ids : [],
                surfaces: Array.isArray(nextKillSwitches.surfaces) ? nextKillSwitches.surfaces : [],
            });
            queryClient.invalidateQueries({ queryKey: ['billing-system-settings'] });
            queryClient.invalidateQueries({ queryKey: ['billing-workspace-overview'] });
            toast.success('Billing kill switches saved.', {
                title: 'Rollback posture updated',
            });
        },
        onError: (mutationError, _payload, context) => {
            if (context?.previous) {
                queryClient.setQueryData(['billing-system-settings'], context.previous);
            }

            toast.error(
                mutationError?.response?.data?.message || 'CRM could not save the billing kill switches.',
                { title: 'Kill switch update failed' }
            );
        },
        onSettled: () => {
            queryClient.invalidateQueries({ queryKey: ['billing-system-settings'] });
        },
    });

    if (isLoading) {
        return (
            <div className="space-y-4 p-5 animate-pulse">
                <div className="h-24 rounded-xl border border-slate-200 bg-white" />
                <div className="grid gap-4 xl:grid-cols-2">
                    <div className="h-64 rounded-xl border border-slate-200 bg-white" />
                    <div className="h-64 rounded-xl border border-slate-200 bg-white" />
                </div>
                <div className="h-56 rounded-xl border border-slate-200 bg-white" />
            </div>
        );
    }

    if (isError) {
        if (isForbiddenQueryError(error)) {
            return (
                <div className="space-y-4 p-5">
                    <BillingStateNotice
                        state="forbidden"
                        eyebrow="Billing System"
                        title="Billing system posture is restricted"
                        message="This role cannot inspect billing domains, branding, and system-level wallet funding posture in the new Billing workspace."
                    />
                </div>
            );
        }

        return (
            <div className="space-y-4 p-5">
                <BillingStateNotice
                    state="degraded"
                    eyebrow="Billing System"
                    title="Billing system metadata unavailable"
                    message="CRM could not load the billing system configuration right now. Retry later."
                />
            </div>
        );
    }

    const summaryCards = [
        { label: 'Source of truth', value: source?.source_of_truth || 'wallet_system_config' },
        { label: 'Precedence', value: liveReadEnabled ? 'New model primary' : 'Legacy primary' },
        { label: 'Default currency', value: draft.default_currency || 'KES' },
        { label: 'Global mode', value: formatMode(draft.mode) },
    ];

    const rolloutCards = [
        {
            label: 'Billing system live-read',
            value: liveReadEnabled ? 'Enabled' : 'Disabled',
            tone: liveReadEnabled ? 'online' : 'neutral',
            detail: liveReadEnabled
                ? 'Runtime reads billing_system_settings first, with fallback posture still available.'
                : 'Runtime still reads wallet_system_config first.',
        },
        {
            label: 'Shadow read',
            value: rollout.shadow_read_enabled ? 'Enabled' : 'Pending',
            tone: rollout.shadow_read_enabled ? 'online' : 'attention',
            detail: rollout.shadow_read_enabled
                ? 'Comparison mode is available for Phase 8 validation.'
                : 'Diff evidence and cutover validation still need Phase 8 hardening.',
        },
        {
            label: 'Rollback scope',
            value: formatFlagLabel(rollout.rollback_scope || 'legacy_primary_still_active'),
            tone: rollout.rollback_scope === 'legacy_fallback_available' ? 'online' : 'neutral',
            detail: 'Phase 8 should make rollback posture explicit and reversible without hiding the authority model.',
        },
        {
            label: 'Surface cutovers',
            value: String(rollout.market_surface_cutover_count ?? 0),
            tone: Number(rollout.market_surface_cutover_count || 0) > 0 ? 'online' : 'neutral',
            detail: 'Configured market/surface flags currently tracked for cutover gating.',
        },
    ];

    const handleSave = () => {
        saveMutation.mutate({
            mode: draft.mode,
            default_currency: String(draft.default_currency || '').trim().toUpperCase(),
            max_single_topup_default: String(draft.max_single_topup_default || '').trim(),
            max_wallet_balance_default: String(draft.max_wallet_balance_default || '').trim(),
            billing_domains: {
                sandbox: String(draft.billing_domains?.sandbox || '').trim() || null,
                production: String(draft.billing_domains?.production || '').trim() || null,
            },
            billing_branding: {
                sandbox: {
                    business_name: String(draft.billing_branding?.sandbox?.business_name || '').trim(),
                    description: String(draft.billing_branding?.sandbox?.description || '').trim(),
                },
                production: {
                    business_name: String(draft.billing_branding?.production?.business_name || '').trim(),
                    description: String(draft.billing_branding?.production?.description || '').trim(),
                },
            },
            redirect_delay_seconds: Number(draft.redirect_delay_seconds || 0),
            wallet_refresh_rate_limit_seconds: Number(draft.wallet_refresh_rate_limit_seconds || 0),
            wallet_refresh_timeout_seconds: Number(draft.wallet_refresh_timeout_seconds || 0),
            topup_poll_interval_seconds: Number(draft.topup_poll_interval_seconds || 0),
            smtp: {
                enabled: Boolean(draft.smtp?.enabled),
                host: String(draft.smtp?.host || '').trim() || null,
                port: draft.smtp?.port ? Number(draft.smtp.port) : null,
                username: String(draft.smtp?.username || '').trim() || null,
                password: String(draft.smtp?.password || '').trim() || null,
                encryption: String(draft.smtp?.encryption || '').trim() || null,
                from_address: String(draft.smtp?.from_address || '').trim() || null,
                from_name: String(draft.smtp?.from_name || '').trim() || null,
            },
            reason: String(draft.reason || '').trim() || 'Updated wallet system settings',
        });
    };

    const addMarketKillSwitch = () => {
        const parsed = Number.parseInt(String(marketIdInput || '').trim(), 10);
        if (!Number.isInteger(parsed) || parsed <= 0) {
            toast.error('Enter a valid market ID before adding it to the kill switch list.', {
                title: 'Invalid market ID',
            });
            return;
        }

        setKillSwitchesDraft((current) => ({
            ...current,
            market_ids: Array.from(new Set([...(current.market_ids || []), parsed])).sort((left, right) => left - right),
        }));
        setMarketIdInput('');
    };

    const removeMarketKillSwitch = (marketId) => {
        setKillSwitchesDraft((current) => ({
            ...current,
            market_ids: (current.market_ids || []).filter((value) => value !== marketId),
        }));
    };

    const toggleSurfaceKillSwitch = (surface) => {
        setKillSwitchesDraft((current) => {
            const existing = new Set(current.surfaces || []);
            if (existing.has(surface)) {
                existing.delete(surface);
            } else {
                existing.add(surface);
            }

            return {
                ...current,
                surfaces: Array.from(existing).sort(),
            };
        });
    };

    const saveKillSwitches = () => {
        killSwitchMutation.mutate({
            market_ids: [...(killSwitchesDraft.market_ids || [])].sort((left, right) => left - right),
            surfaces: [...(killSwitchesDraft.surfaces || [])].sort(),
        });
    };

    return (
        <div className="space-y-5 p-5">
            {!editable ? (
                <BillingStateNotice
                    state="forbidden"
                    eyebrow="Billing System"
                    title="Billing system settings are view-only for this role"
                    message="Sub-admins can inspect global billing posture here, but only admin users can save authority-level billing domains, branding, timing, and delivery configuration."
                />
            ) : null}

            <section className="rounded-lg border border-slate-200 bg-white p-5 shadow-sm shadow-slate-950/[0.02]">
                <div className="grid gap-5 xl:grid-cols-[minmax(0,1fr)_minmax(360px,420px)] xl:items-end">
                    <div>
                        <p className="text-[11px] font-semibold uppercase tracking-[0.1em] text-slate-500">Billing system authority</p>
                        <h4 className="mt-2 text-2xl font-semibold tracking-tight text-slate-950">
                            Global billing settings and rollout posture
                        </h4>
                        <p className="mt-3 max-w-3xl text-sm leading-6 text-slate-600">
                            Manage the CRM-global billing defaults that still control domains, branding, timing, wallet
                            limits, and outbound delivery. Cutover evidence and rollback posture stay visible here,
                            but separate from the editable authority surface.
                        </p>
                    </div>
                    <div className="grid gap-3 sm:grid-cols-2 xl:ml-auto xl:w-full">
                        {summaryCards.map((card) => (
                            <MetricCell key={card.label} label={card.label} value={card.value} />
                        ))}
                    </div>
                </div>
                <div className="mt-5 flex flex-wrap items-center gap-3">
                    <StatusTag label={editable ? 'Admin edit enabled' : 'View only'} tone={editable ? 'online' : 'neutral'} />
                    <StatusTag
                        label={features.shadow_read ? 'Shadow read active' : 'Shadow read pending'}
                        tone={features.shadow_read ? 'online' : 'attention'}
                    />
                    <StatusTag
                        label={features.billing_system_live_read ? 'New-model read path active' : 'Legacy read path active'}
                        tone={features.billing_system_live_read ? 'online' : 'neutral'}
                    />
                </div>
            </section>

            <section className="rounded-lg border border-slate-200 bg-white p-5 shadow-sm shadow-slate-950/[0.02]">
                <div className="flex flex-col gap-3 xl:flex-row xl:items-start xl:justify-between">
                    <div>
                        <p className="text-[11px] font-semibold uppercase tracking-[0.1em] text-slate-500">Global controls</p>
                        <h5 className="mt-2 text-lg font-semibold text-slate-950">Authority-level billing defaults</h5>
                        <p className="mt-2 max-w-3xl text-sm leading-6 text-slate-600">
                            These settings back the global billing system contract and should stay explicit while Phase 8
                            cutover validation hardens the precedence model.
                        </p>
                    </div>
                    <div className="flex flex-wrap items-center gap-3">
                        <button
                            type="button"
                            onClick={() => setDraft(initialDraft)}
                            disabled={!dirty || saveMutation.isPending}
                            className="inline-flex items-center justify-center rounded-md border border-slate-300 px-4 py-2 text-sm font-medium text-slate-700 transition hover:bg-slate-50 disabled:cursor-not-allowed disabled:opacity-50"
                        >
                            Reset
                        </button>
                        <button
                            type="button"
                            onClick={handleSave}
                            disabled={!editable || !dirty || saveMutation.isPending}
                            className="inline-flex items-center justify-center rounded-md bg-slate-950 px-4 py-2 text-sm font-semibold text-white transition hover:bg-slate-800 disabled:cursor-not-allowed disabled:opacity-50"
                        >
                            {saveMutation.isPending ? 'Saving…' : 'Save billing system'}
                        </button>
                    </div>
                </div>

                <div className="mt-5 grid gap-4 xl:grid-cols-2">
                    <FieldCard title="Core posture" description="Set the global operating mode, default currency, and wallet caps.">
                        <div className="grid gap-4 md:grid-cols-2">
                            <SelectField
                                label="Global mode"
                                value={draft.mode}
                                options={modeOptions}
                                disabled={!editable}
                                onChange={(value) => setDraft((current) => ({ ...current, mode: value }))}
                            />
                            <TextField
                                label="Default currency"
                                value={draft.default_currency}
                                disabled={!editable}
                                onChange={(value) => setDraft((current) => ({ ...current, default_currency: value.toUpperCase() }))}
                            />
                            <TextField
                                label="Single wallet cap"
                                value={draft.max_single_topup_default}
                                disabled={!editable}
                                onChange={(value) => setDraft((current) => ({ ...current, max_single_topup_default: value }))}
                            />
                            <TextField
                                label="Wallet balance cap"
                                value={draft.max_wallet_balance_default}
                                disabled={!editable}
                                onChange={(value) => setDraft((current) => ({ ...current, max_wallet_balance_default: value }))}
                            />
                        </div>
                    </FieldCard>

                    <FieldCard title="Runtime timing" description="Control redirects, refresh cadence, and polling defaults.">
                        <div className="grid gap-4 md:grid-cols-2">
                            <NumberField
                                label="Redirect delay (s)"
                                value={draft.redirect_delay_seconds}
                                disabled={!editable}
                                onChange={(value) => setDraft((current) => ({ ...current, redirect_delay_seconds: value }))}
                            />
                            <NumberField
                                label="Funding poll interval (s)"
                                value={draft.topup_poll_interval_seconds}
                                disabled={!editable}
                                onChange={(value) => setDraft((current) => ({ ...current, topup_poll_interval_seconds: value }))}
                            />
                            <NumberField
                                label="Wallet refresh rate limit (s)"
                                value={draft.wallet_refresh_rate_limit_seconds}
                                disabled={!editable}
                                onChange={(value) => setDraft((current) => ({ ...current, wallet_refresh_rate_limit_seconds: value }))}
                            />
                            <NumberField
                                label="Wallet refresh timeout (s)"
                                value={draft.wallet_refresh_timeout_seconds}
                                disabled={!editable}
                                onChange={(value) => setDraft((current) => ({ ...current, wallet_refresh_timeout_seconds: value }))}
                            />
                        </div>
                    </FieldCard>
                </div>

                <div className="mt-4 grid gap-4 xl:grid-cols-2">
                    {environments.map((environment) => (
                        <FieldCard
                            key={environment}
                            title={environment === 'sandbox' ? 'Sandbox presentation' : 'Production presentation'}
                            description={environment === 'sandbox'
                                ? 'Configure preview-facing domains and brand copy for QA and sandbox billing journeys.'
                                : 'Configure live-facing billing domain and brand copy used when production billing is active.'}
                        >
                            <div className="grid gap-4">
                                <TextField
                                    label="Billing domain"
                                    value={draft.billing_domains[environment]}
                                    disabled={!editable}
                                    onChange={(value) => setDraft((current) => ({
                                        ...current,
                                        billing_domains: {
                                            ...current.billing_domains,
                                            [environment]: value,
                                        },
                                    }))}
                                />
                                <TextField
                                    label="Business name"
                                    value={draft.billing_branding[environment].business_name}
                                    disabled={!editable}
                                    onChange={(value) => setDraft((current) => ({
                                        ...current,
                                        billing_branding: {
                                            ...current.billing_branding,
                                            [environment]: {
                                                ...current.billing_branding[environment],
                                                business_name: value,
                                            },
                                        },
                                    }))}
                                />
                                <TextAreaField
                                    label="Description"
                                    value={draft.billing_branding[environment].description}
                                    disabled={!editable}
                                    onChange={(value) => setDraft((current) => ({
                                        ...current,
                                        billing_branding: {
                                            ...current.billing_branding,
                                            [environment]: {
                                                ...current.billing_branding[environment],
                                                description: value,
                                            },
                                        },
                                    }))}
                                />
                            </div>
                        </FieldCard>
                    ))}
                </div>

                <div className="mt-4 grid gap-4 xl:grid-cols-[minmax(0,1.15fr)_minmax(0,0.85fr)]">
                    <FieldCard title="Outbound delivery" description="Control SMTP and billing-email posture without mixing it into cutover state.">
                        <div className="grid gap-4 md:grid-cols-2">
                            <ToggleField
                                label="SMTP enabled"
                                checked={draft.smtp.enabled}
                                disabled={!editable}
                                onChange={(checked) => setDraft((current) => ({
                                    ...current,
                                    smtp: {
                                        ...current.smtp,
                                        enabled: checked,
                                    },
                                }))}
                            />
                            <SelectField
                                label="Encryption"
                                value={draft.smtp.encryption}
                                options={['tls', 'ssl', 'none']}
                                disabled={!editable}
                                onChange={(value) => setDraft((current) => ({
                                    ...current,
                                    smtp: {
                                        ...current.smtp,
                                        encryption: value,
                                    },
                                }))}
                            />
                            <TextField
                                label="SMTP host"
                                value={draft.smtp.host}
                                disabled={!editable}
                                onChange={(value) => setDraft((current) => ({
                                    ...current,
                                    smtp: {
                                        ...current.smtp,
                                        host: value,
                                    },
                                }))}
                            />
                            <NumberField
                                label="SMTP port"
                                value={draft.smtp.port}
                                disabled={!editable}
                                onChange={(value) => setDraft((current) => ({
                                    ...current,
                                    smtp: {
                                        ...current.smtp,
                                        port: value,
                                    },
                                }))}
                            />
                            <TextField
                                label="SMTP username"
                                value={draft.smtp.username}
                                disabled={!editable}
                                onChange={(value) => setDraft((current) => ({
                                    ...current,
                                    smtp: {
                                        ...current.smtp,
                                        username: value,
                                    },
                                }))}
                            />
                            <TextField
                                label="SMTP password"
                                type="password"
                                value={draft.smtp.password}
                                placeholder={draft.smtp.password_configured ? 'Leave blank to keep current password' : 'Enter password'}
                                disabled={!editable}
                                onChange={(value) => setDraft((current) => ({
                                    ...current,
                                    smtp: {
                                        ...current.smtp,
                                        password: value,
                                    },
                                }))}
                            />
                            <TextField
                                label="SMTP from address"
                                value={draft.smtp.from_address}
                                disabled={!editable}
                                onChange={(value) => setDraft((current) => ({
                                    ...current,
                                    smtp: {
                                        ...current.smtp,
                                        from_address: value,
                                    },
                                }))}
                            />
                            <TextField
                                label="SMTP from name"
                                value={draft.smtp.from_name}
                                disabled={!editable}
                                onChange={(value) => setDraft((current) => ({
                                    ...current,
                                    smtp: {
                                        ...current.smtp,
                                        from_name: value,
                                    },
                                }))}
                            />
                        </div>
                    </FieldCard>

                    <FieldCard title="Change log note" description="Add an audit reason so Phase 8 rollout changes stay attributable.">
                        <div className="grid gap-4">
                            <TextAreaField
                                label="Reason"
                                value={draft.reason}
                                disabled={!editable}
                                onChange={(value) => setDraft((current) => ({ ...current, reason: value }))}
                            />
                            <div className="rounded-lg border border-slate-200 bg-slate-50 px-4 py-3">
                                <p className="text-[10px] font-semibold uppercase tracking-[0.08em] text-slate-500">Password state</p>
                                <p className="mt-2 text-sm font-medium text-slate-900">
                                    {draft.smtp.password_configured ? 'Configured in current live settings' : 'Not configured'}
                                </p>
                            </div>
                            <div className="rounded-lg border border-slate-200 bg-slate-50 px-4 py-3">
                                <p className="text-[10px] font-semibold uppercase tracking-[0.08em] text-slate-500">Unsaved changes</p>
                                <p className="mt-2 text-sm font-medium text-slate-900">
                                    {dirty ? 'You have unsaved billing-system edits.' : 'No pending edits.'}
                                </p>
                            </div>
                        </div>
                    </FieldCard>
                </div>
            </section>

            <section className="rounded-lg border border-slate-200 bg-white p-5 shadow-sm shadow-slate-950/[0.02]">
                <div>
                    <p className="text-[11px] font-semibold uppercase tracking-[0.1em] text-slate-500">Security gates</p>
                    <h5 className="mt-2 text-lg font-semibold text-slate-950">PIN controls</h5>
                    <p className="mt-2 max-w-3xl text-sm leading-6 text-slate-600">
                        These PINs gate high-risk actions: operator wallet transactions, free-trial approvals, and
                        discount overrides. Set a PIN before operators can perform the protected action.
                    </p>
                </div>
                <div className="mt-5 grid gap-4 xl:grid-cols-3">
                    <PinCard
                        label="Operator PIN"
                        description="Required for operator-initiated wallet transactions and manual adjustments."
                        endpoint="/crm/settings/wallet/pin"
                        pinSet={Boolean(system?.pin_policy?.operator_pin_set)}
                        lastUpdatedAt={system?.pin_policy?.operator_pin_last_updated_at || null}
                        editable={editable}
                    />
                    <PinCard
                        label="Free-trial PIN"
                        description="Required when granting free-trial access to a customer profile."
                        endpoint="/crm/settings/free-trial/pin"
                        pinSet={Boolean(system?.pin_policy?.free_trial_pin_set)}
                        lastUpdatedAt={system?.pin_policy?.free_trial_pin_last_updated_at || null}
                        editable={editable}
                    />
                    <PinCard
                        label="Discount PIN"
                        description="Required to authorize discounted subscription pricing for any market."
                        endpoint="/crm/settings/discounts/pin"
                        pinSet={Boolean(system?.pin_policy?.discount_pin_set)}
                        lastUpdatedAt={system?.pin_policy?.discount_pin_last_updated_at || null}
                        editable={editable}
                    />
                </div>
            </section>

            <section className="rounded-lg border border-slate-200 bg-slate-50 px-5 py-5">
                <div className="flex flex-col gap-3 xl:flex-row xl:items-start xl:justify-between">
                    <div>
                        <p className="text-[11px] font-semibold uppercase tracking-[0.1em] text-slate-500">Rollout and cutover</p>
                        <h5 className="mt-2 text-lg font-semibold text-slate-950">Phase 8 rollout posture</h5>
                        <p className="mt-2 max-w-3xl text-sm leading-6 text-slate-600">
                            Keep rollout evidence and rollback posture visible without burying the editable billing
                            authority surface. This panel is where Phase 8 should eventually surface shadow-read
                            validation, cutover readiness, and scoped rollback controls.
                        </p>
                    </div>
                    <div className="flex flex-wrap items-center gap-3">
                        <StatusTag
                            label={rollout.dual_write_enabled ? 'Dual write active' : 'Dual write pending'}
                            tone={rollout.dual_write_enabled ? 'online' : 'attention'}
                        />
                        <StatusTag
                            label={rollout.diagnostics_v2_enabled ? 'Diagnostics v2 online' : 'Diagnostics v2 pending'}
                            tone={rollout.diagnostics_v2_enabled ? 'online' : 'neutral'}
                        />
                    </div>
                </div>
                <div className="mt-5 grid gap-4 xl:grid-cols-4">
                    {rolloutCards.map((card) => (
                        <RolloutCard key={card.label} {...card} />
                    ))}
                </div>
                {editable ? (
                    <div className="mt-5 rounded-lg border border-amber-200 bg-amber-50/70 p-4">
                        <div className="flex flex-col gap-3 xl:flex-row xl:items-start xl:justify-between">
                            <div>
                                <p className="text-[11px] font-semibold uppercase tracking-[0.1em] text-amber-700">Kill switches</p>
                                <h6 className="mt-2 text-base font-semibold text-slate-950">Scoped legacy fallback controls</h6>
                                <p className="mt-2 max-w-3xl text-sm leading-6 text-slate-700">
                                    Instantly revert specific markets or surfaces to legacy config without a deploy.
                                </p>
                            </div>
                            <button
                                type="button"
                                onClick={saveKillSwitches}
                                disabled={!killSwitchesDirty || killSwitchMutation.isPending}
                                className="inline-flex items-center justify-center rounded-md bg-amber-600 px-4 py-2 text-sm font-semibold text-white transition hover:bg-amber-500 disabled:cursor-not-allowed disabled:opacity-50"
                            >
                                {killSwitchMutation.isPending ? 'Saving…' : 'Save kill switches'}
                            </button>
                        </div>
                        <div className="mt-4 grid gap-4 xl:grid-cols-2">
                            <section className="rounded-lg border border-amber-200 bg-white px-4 py-4">
                                <div className="flex flex-col gap-3 sm:flex-row sm:items-end">
                                    <TextField
                                        label="Market ID"
                                        value={marketIdInput}
                                        disabled={killSwitchMutation.isPending}
                                        onChange={setMarketIdInput}
                                        type="number"
                                        placeholder="Add market ID"
                                    />
                                    <button
                                        type="button"
                                        onClick={addMarketKillSwitch}
                                        disabled={killSwitchMutation.isPending}
                                        className="inline-flex h-10 items-center justify-center rounded-md border border-amber-300 px-4 text-sm font-medium text-amber-800 transition hover:bg-amber-50 disabled:cursor-not-allowed disabled:opacity-50"
                                    >
                                        Add market
                                    </button>
                                </div>
                                <div className="mt-4 flex flex-wrap gap-2">
                                    {killSwitchesDraft.market_ids.length > 0 ? killSwitchesDraft.market_ids.map((marketId) => {
                                        const market = markets.find((candidate) => Number(candidate?.id) === marketId);
                                        const label = market
                                            ? `${market.name} (#${marketId})`
                                            : `Market #${marketId}`;

                                        return (
                                            <button
                                                key={marketId}
                                                type="button"
                                                onClick={() => removeMarketKillSwitch(marketId)}
                                                disabled={killSwitchMutation.isPending}
                                                className="inline-flex items-center gap-2 rounded-full border border-amber-200 bg-amber-50 px-3 py-1 text-xs font-medium text-amber-900 transition hover:bg-amber-100 disabled:cursor-not-allowed disabled:opacity-50"
                                            >
                                                <span>{label}</span>
                                                <span aria-hidden="true">&times;</span>
                                            </button>
                                        );
                                    }) : (
                                        <p className="text-sm text-slate-600">No market-specific kill switches saved.</p>
                                    )}
                                </div>
                                {visibleMarketIds.length > 0 ? (
                                    <p className="mt-3 text-xs text-slate-500">
                                        Known markets: {visibleMarketIds.join(', ')}
                                    </p>
                                ) : null}
                            </section>

                            <section className="rounded-lg border border-amber-200 bg-white px-4 py-4">
                                <p className="text-[11px] font-semibold uppercase tracking-[0.1em] text-slate-500">Surfaces</p>
                                <div className="mt-3 grid gap-2">
                                    {BILLING_SURFACES.map((surface) => (
                                        <label key={surface} className="flex items-center justify-between gap-3 rounded-md border border-slate-200 px-3 py-2 text-sm text-slate-800">
                                            <span>{formatSurfaceLabel(surface)}</span>
                                            <input
                                                type="checkbox"
                                                checked={(killSwitchesDraft.surfaces || []).includes(surface)}
                                                disabled={killSwitchMutation.isPending}
                                                onChange={() => toggleSurfaceKillSwitch(surface)}
                                                className="h-4 w-4 rounded border-slate-300 text-amber-600 focus:ring-amber-500"
                                            />
                                        </label>
                                    ))}
                                </div>
                            </section>
                        </div>
                    </div>
                ) : null}
            </section>
        </div>
    );
}

function formatMode(mode) {
    return String(mode || 'disabled')
        .replace(/_/g, ' ')
        .replace(/\b\w/g, (char) => char.toUpperCase());
}

function formatFlagLabel(value) {
    return String(value || '')
        .replace(/_/g, ' ')
        .replace(/\b\w/g, (char) => char.toUpperCase());
}

function formatSurfaceLabel(value) {
    return formatFlagLabel(value);
}

function MetricCell({ label, value }) {
    return (
        <div className="rounded-lg border border-slate-200 bg-slate-50 px-4 py-4">
            <p className="text-[8px] font-semibold uppercase tracking-[0.16em] text-slate-400">{label}</p>
            <p className="mt-2 break-words text-sm font-semibold leading-6 text-slate-950">{value}</p>
        </div>
    );
}

function StatusTag({ label, tone = 'neutral' }) {
    const tones = {
        online: { dot: 'bg-emerald-500', border: 'border-emerald-200', text: 'text-emerald-700' },
        attention: { dot: 'bg-amber-500', border: 'border-amber-200', text: 'text-amber-700' },
        neutral: { dot: 'bg-slate-300', border: 'border-slate-200', text: 'text-slate-600' },
    };

    const resolved = tones[tone] || tones.neutral;

    return (
        <span className={`inline-flex items-center gap-2 rounded-full border bg-white px-3 py-1 text-[10px] font-semibold uppercase tracking-[0.08em] ${resolved.border} ${resolved.text}`}>
            <span className={`h-2 w-2 rounded-full ${resolved.dot}`} />
            {label}
        </span>
    );
}

function FieldCard({ title, description, children }) {
    return (
        <section className="rounded-lg border border-slate-200 bg-slate-50/70 p-4">
            <div>
                <h6 className="text-sm font-semibold text-slate-950">{title}</h6>
                <p className="mt-1 text-sm leading-6 text-slate-600">{description}</p>
            </div>
            <div className="mt-4">{children}</div>
        </section>
    );
}

function TextField({ label, value, onChange, disabled = false, type = 'text', placeholder = '' }) {
    return (
        <label className="grid gap-2">
            <span className="text-[10px] font-semibold uppercase tracking-[0.08em] text-slate-500">{label}</span>
            <input
                type={type}
                value={value}
                placeholder={placeholder}
                disabled={disabled}
                onChange={(event) => onChange(event.target.value)}
                className="rounded-md border border-slate-300 bg-white px-3 py-2 text-sm text-slate-900 shadow-sm outline-none transition focus:border-slate-500 focus:ring-2 focus:ring-slate-200 disabled:cursor-not-allowed disabled:bg-slate-100"
            />
        </label>
    );
}

function NumberField({ label, value, onChange, disabled = false }) {
    return (
        <TextField
            label={label}
            type="number"
            value={value}
            disabled={disabled}
            onChange={onChange}
        />
    );
}

function TextAreaField({ label, value, onChange, disabled = false }) {
    return (
        <label className="grid gap-2">
            <span className="text-[10px] font-semibold uppercase tracking-[0.08em] text-slate-500">{label}</span>
            <textarea
                value={value}
                rows={3}
                disabled={disabled}
                onChange={(event) => onChange(event.target.value)}
                className="rounded-md border border-slate-300 bg-white px-3 py-2 text-sm text-slate-900 shadow-sm outline-none transition focus:border-slate-500 focus:ring-2 focus:ring-slate-200 disabled:cursor-not-allowed disabled:bg-slate-100"
            />
        </label>
    );
}

function SelectField({ label, value, onChange, options = [], disabled = false }) {
    return (
        <label className="grid gap-2">
            <span className="text-[10px] font-semibold uppercase tracking-[0.08em] text-slate-500">{label}</span>
            <select
                value={value}
                disabled={disabled}
                onChange={(event) => onChange(event.target.value)}
                className="rounded-md border border-slate-300 bg-white px-3 py-2 text-sm text-slate-900 shadow-sm outline-none transition focus:border-slate-500 focus:ring-2 focus:ring-slate-200 disabled:cursor-not-allowed disabled:bg-slate-100"
            >
                {options.map((option) => (
                    <option key={option} value={option}>
                        {formatMode(option)}
                    </option>
                ))}
            </select>
        </label>
    );
}

function ToggleField({ label, checked, onChange, disabled = false }) {
    return (
        <label className="flex items-center justify-between gap-4 rounded-md border border-slate-300 bg-white px-3 py-2">
            <span className="text-sm font-medium text-slate-800">{label}</span>
            <button
                type="button"
                role="switch"
                aria-checked={checked}
                disabled={disabled}
                onClick={() => onChange(!checked)}
                className={`inline-flex h-6 w-11 items-center rounded-full transition ${
                    checked ? 'bg-emerald-500' : 'bg-slate-300'
                } disabled:cursor-not-allowed disabled:opacity-60`}
            >
                <span
                    className={`ml-0.5 h-5 w-5 rounded-full bg-white shadow-sm transition ${
                        checked ? 'translate-x-5' : 'translate-x-0'
                    }`}
                />
            </button>
        </label>
    );
}

function PinCard({ label, description, endpoint, pinSet, lastUpdatedAt, editable }) {
    const toast = useToast();
    const [open, setOpen] = useState(false);
    const [form, setForm] = useState({ pin: '', pin_confirmation: '', reason: '' });
    const pinRef = useRef(null);

    const mutation = useMutation({
        mutationFn: (payload) => api.patch(endpoint, payload).then((response) => response.data),
        onSuccess: () => {
            setOpen(false);
            setForm({ pin: '', pin_confirmation: '', reason: '' });
            toast.success(`${label} updated.`, { title: 'PIN changed' });
        },
        onError: (error) => {
            const validation = error?.response?.data?.errors;
            const first = validation && typeof validation === 'object'
                ? Object.values(validation).flat()[0]
                : null;
            toast.error(
                first || error?.response?.data?.message || 'CRM could not save the PIN.',
                { title: 'PIN update failed' }
            );
        },
    });

    useEffect(() => {
        if (open && pinRef.current) {
            pinRef.current.focus();
        }
    }, [open]);

    const handleSubmit = (event) => {
        event.preventDefault();
        mutation.mutate({
            pin: form.pin,
            pin_confirmation: form.pin_confirmation,
            reason: form.reason || undefined,
        });
    };

    const formattedDate = lastUpdatedAt
        ? new Date(lastUpdatedAt).toLocaleDateString('en-GB', { day: 'numeric', month: 'short', year: 'numeric' })
        : null;

    return (
        <div className="rounded-lg border border-slate-200 bg-slate-50/70 p-4">
            <div className="flex items-start justify-between gap-3">
                <div>
                    <p className="text-sm font-semibold text-slate-950">{label}</p>
                    <p className="mt-1 text-sm leading-5 text-slate-600">{description}</p>
                </div>
                <span className={`mt-0.5 shrink-0 rounded-full px-2.5 py-1 text-[10px] font-semibold uppercase tracking-[0.06em] ${
                    pinSet
                        ? 'bg-emerald-100 text-emerald-700'
                        : 'bg-amber-100 text-amber-700'
                }`}>
                    {pinSet ? 'Set' : 'Not set'}
                </span>
            </div>

            {formattedDate ? (
                <p className="mt-3 text-[11px] text-slate-400">Last changed {formattedDate}</p>
            ) : null}

            {editable && !open ? (
                <button
                    type="button"
                    onClick={() => setOpen(true)}
                    className="mt-3 inline-flex items-center rounded-md border border-slate-300 bg-white px-3 py-1.5 text-[11px] font-semibold uppercase tracking-[0.06em] text-slate-700 transition hover:bg-slate-50"
                >
                    {pinSet ? 'Change PIN' : 'Set PIN'}
                </button>
            ) : null}

            {editable && open ? (
                <form onSubmit={handleSubmit} className="mt-4 space-y-3">
                    <label className="grid gap-1.5">
                        <span className="text-[10px] font-semibold uppercase tracking-[0.08em] text-slate-500">New PIN (4–6 digits)</span>
                        <input
                            ref={pinRef}
                            type="password"
                            inputMode="numeric"
                            pattern="\d{4,6}"
                            value={form.pin}
                            onChange={(event) => setForm((current) => ({ ...current, pin: event.target.value }))}
                            disabled={mutation.isPending}
                            className="rounded-md border border-slate-300 bg-white px-3 py-2 text-sm text-slate-900 outline-none transition focus:border-slate-500 focus:ring-2 focus:ring-slate-200 disabled:cursor-not-allowed disabled:bg-slate-100"
                        />
                    </label>
                    <label className="grid gap-1.5">
                        <span className="text-[10px] font-semibold uppercase tracking-[0.08em] text-slate-500">Confirm PIN</span>
                        <input
                            type="password"
                            inputMode="numeric"
                            pattern="\d{4,6}"
                            value={form.pin_confirmation}
                            onChange={(event) => setForm((current) => ({ ...current, pin_confirmation: event.target.value }))}
                            disabled={mutation.isPending}
                            className="rounded-md border border-slate-300 bg-white px-3 py-2 text-sm text-slate-900 outline-none transition focus:border-slate-500 focus:ring-2 focus:ring-slate-200 disabled:cursor-not-allowed disabled:bg-slate-100"
                        />
                    </label>
                    <label className="grid gap-1.5">
                        <span className="text-[10px] font-semibold uppercase tracking-[0.08em] text-slate-500">Reason (optional)</span>
                        <input
                            type="text"
                            value={form.reason}
                            onChange={(event) => setForm((current) => ({ ...current, reason: event.target.value }))}
                            disabled={mutation.isPending}
                            className="rounded-md border border-slate-300 bg-white px-3 py-2 text-sm text-slate-900 outline-none transition focus:border-slate-500 focus:ring-2 focus:ring-slate-200 disabled:cursor-not-allowed disabled:bg-slate-100"
                        />
                    </label>
                    <div className="flex items-center gap-2">
                        <button
                            type="submit"
                            disabled={!form.pin || !form.pin_confirmation || mutation.isPending}
                            className="inline-flex items-center rounded-md bg-slate-950 px-3 py-1.5 text-[11px] font-semibold uppercase tracking-[0.06em] text-white transition hover:bg-slate-800 disabled:cursor-not-allowed disabled:opacity-50"
                        >
                            {mutation.isPending ? 'Saving…' : 'Save PIN'}
                        </button>
                        <button
                            type="button"
                            onClick={() => { setOpen(false); setForm({ pin: '', pin_confirmation: '', reason: '' }); }}
                            disabled={mutation.isPending}
                            className="inline-flex items-center rounded-md border border-slate-300 bg-white px-3 py-1.5 text-[11px] font-semibold uppercase tracking-[0.06em] text-slate-700 transition hover:bg-slate-50 disabled:cursor-not-allowed disabled:opacity-50"
                        >
                            Cancel
                        </button>
                    </div>
                </form>
            ) : null}
        </div>
    );
}

function RolloutCard({ label, value, detail, tone = 'neutral' }) {
    return (
        <div className="rounded-lg border border-slate-200 bg-white p-4 shadow-sm shadow-slate-950/[0.02]">
            <div className="flex items-start justify-between gap-3">
                <p className="text-[10px] font-semibold uppercase tracking-[0.08em] text-slate-500">{label}</p>
                <StatusTag label={value} tone={tone} />
            </div>
            <p className="mt-3 text-sm leading-6 text-slate-600">{detail}</p>
        </div>
    );
}
