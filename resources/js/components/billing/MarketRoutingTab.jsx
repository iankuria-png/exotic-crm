import React, { useEffect, useMemo, useState } from 'react';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import api from '../../services/api';
import { useToast } from '../ToastProvider';
import BillingStateNotice from './BillingStateNotice';
import { isForbiddenQueryError } from './queryState';

const EXECUTION_MODES = [
    {
        key: 'direct',
        label: 'Direct',
        description: 'Send traffic straight to the selected provider profile.',
    },
    {
        key: 'proxy',
        label: 'Proxy',
        description: 'Pin the flow to the CRM-controlled proxy before handoff.',
    },
];

const SURFACE_COPY = {
    subscription_link: {
        title: 'Subscription link',
        description: 'Operator-sent or self-service hosted checkout links for plan activation.',
    },
    subscription_push: {
        title: 'Subscription push',
        description: 'Push-based mobile-money initiation for guided subscription activation.',
    },
    subscription_invoice: {
        title: 'Subscription invoice',
        description: 'Invoice-style billing flows where the provider owns the payable reference.',
    },
    wallet_funding: {
        title: 'Wallet funding',
        description: 'Top-up flows that move customer funds into their CRM wallet balance.',
    },
    wallet_auto_renew: {
        title: 'Wallet auto-renew',
        description: 'Fallback collections triggered when an expiring subscription should charge wallet policy.',
    },
    manual_confirmation: {
        title: 'Manual confirmation',
        description: 'Operator-assisted confirmation flows used when payments cannot be verified automatically.',
    },
    proxy_hosted_checkout: {
        title: 'Proxy hosted checkout',
        description: 'High-risk hosted checkout flows routed through the CRM-owned proxy.',
    },
    self_checkout: {
        title: 'Self checkout',
        description: 'Customer-driven checkout initiated directly from the web experience.',
    },
};

function formatKey(value) {
    return String(value || '')
        .replace(/_/g, ' ')
        .replace(/\b\w/g, (char) => char.toUpperCase());
}

function cloneDraftMap(value) {
    return JSON.parse(JSON.stringify(value || {}));
}

function normalizeDraftMap(value) {
    return Object.keys(value || {})
        .sort()
        .reduce((carry, key) => {
            const entry = value[key] || {};
            carry[key] = {
                billing_surface: key,
                active: Boolean(entry.active),
                primary_profile_id: entry.primary_profile_id ? Number(entry.primary_profile_id) : null,
                fallback_profile_ids: Array.isArray(entry.fallback_profile_ids)
                    ? entry.fallback_profile_ids.map((id) => Number(id)).filter(Boolean).sort((a, b) => a - b)
                    : [],
                execution_mode: entry.execution_mode || 'direct',
                operator_enabled: entry.operator_enabled !== false,
                self_service_enabled: Boolean(entry.self_service_enabled),
                notes: String(entry.notes || '').trim(),
            };

            return carry;
        }, {});
}

function buildDraftMap(payload) {
    const rules = Array.isArray(payload?.routing_rules) ? payload.routing_rules : [];
    const bindings = Array.isArray(payload?.bindings) ? payload.bindings : [];
    const surfaces = Array.isArray(payload?.surfaces) ? payload.surfaces : [];

    return surfaces.reduce((carry, surface) => {
        const surfaceKey = surface.key;
        const rule = rules.find((entry) => entry.billing_surface === surfaceKey) || null;
        const primaryBinding = rule?.primary_binding || null;
        const surfaceBindings = bindings
            .filter((entry) => entry.billing_surface === surfaceKey)
            .sort((left, right) => Number(left.priority || 999) - Number(right.priority || 999));
        const fallbackIds = Array.isArray(rule?.fallback_strategy_json?.provider_profile_ids)
            ? rule.fallback_strategy_json.provider_profile_ids
            : surfaceBindings
                  .filter((entry) => entry.id !== primaryBinding?.id)
                  .map((entry) => entry.provider_profile_id)
                  .filter(Boolean);

        carry[surfaceKey] = {
            billing_surface: surfaceKey,
            active: Boolean(rule?.active),
            primary_profile_id: primaryBinding?.provider_profile?.id || primaryBinding?.provider_profile_id || null,
            fallback_profile_ids: Array.from(new Set(fallbackIds.map((id) => Number(id)).filter(Boolean))),
            execution_mode:
                primaryBinding?.execution_mode || rule?.risk_policy_json?.execution_mode || 'direct',
            operator_enabled:
                primaryBinding?.operator_enabled ?? rule?.risk_policy_json?.operator_enabled ?? true,
            self_service_enabled:
                primaryBinding?.self_service_enabled ?? rule?.risk_policy_json?.self_service_enabled ?? false,
            notes: primaryBinding?.notes || '',
        };

        return carry;
    }, {});
}

function statusTone(active) {
    return active
        ? 'border-emerald-200 bg-emerald-50 text-emerald-700'
        : 'border-slate-200 bg-slate-100 text-slate-600';
}

export default function MarketRoutingTab({ platforms = [] }) {
    const toast = useToast();
    const queryClient = useQueryClient();
    const [selectedMarket, setSelectedMarket] = useState(null);
    const [draftMap, setDraftMap] = useState({});
    const [initialDraftMap, setInitialDraftMap] = useState({});

    const marketId = selectedMarket?.id || null;

    const routingRulesQuery = useQuery({
        queryKey: ['billing-routing-rules', marketId],
        queryFn: () => api.get(`/crm/settings/billing/routing-rules/${marketId}`).then((response) => response.data),
        enabled: Boolean(marketId),
        staleTime: 60_000,
    });

    const saveMutation = useMutation({
        mutationFn: (payload) =>
            api.put(`/crm/settings/billing/routing-rules/${marketId}`, payload).then((response) => response.data),
        onSuccess: (payload) => {
            queryClient.setQueryData(['billing-routing-rules', marketId], payload);
            const nextDraft = buildDraftMap(payload);
            setInitialDraftMap(nextDraft);
            setDraftMap(nextDraft);
            toast.success('Market routing saved.', {
                title: 'Billing routing updated',
            });
        },
        onError: (error) => {
            const message =
                error?.response?.data?.message
                || Object.values(error?.response?.data?.errors || {}).flat()[0]
                || 'CRM could not save the market routing rules.';

            toast.error(String(message), {
                title: 'Billing routing save failed',
            });
        },
    });

    useEffect(() => {
        if (!routingRulesQuery.data) {
            return;
        }

        const nextDraft = buildDraftMap(routingRulesQuery.data);
        setInitialDraftMap(nextDraft);
        setDraftMap(nextDraft);
    }, [routingRulesQuery.data]);

    const routingData = routingRulesQuery.data || {};
    const surfaces = Array.isArray(routingData.surfaces) ? routingData.surfaces : [];
    const profiles = Array.isArray(routingData.profiles) ? routingData.profiles : [];
    const editable = Boolean(routingData.editable);

    const dirty = useMemo(() => {
        return JSON.stringify(normalizeDraftMap(draftMap)) !== JSON.stringify(normalizeDraftMap(initialDraftMap));
    }, [draftMap, initialDraftMap]);

    const profileMap = useMemo(() => {
        return profiles.reduce((carry, profile) => {
            carry[profile.id] = profile;
            return carry;
        }, {});
    }, [profiles]);

    if (!selectedMarket && platforms.length === 0) {
        return (
            <div className="space-y-4 p-5">
                <BillingStateNotice
                    state="empty"
                    eyebrow="Market Routing"
                    title="No markets available"
                    message="Create or enable markets in Platform settings before configuring routing rules."
                />
            </div>
        );
    }

    if (!selectedMarket) {
        return (
            <div className="space-y-5 p-5">
                <section className="rounded-3xl border border-slate-200 bg-white p-5 shadow-sm shadow-slate-950/[0.03]">
                    <div className="grid gap-5 xl:grid-cols-[minmax(0,1.35fr)_minmax(300px,0.65fr)]">
                        <div>
                            <p className="text-[11px] font-semibold uppercase tracking-[0.1em] text-slate-500">
                                Market Routing
                            </p>
                            <h4 className="mt-2 text-2xl font-semibold tracking-tight text-slate-950">
                                Assign primary and fallback payment paths by market
                            </h4>
                            <p className="mt-3 max-w-3xl text-sm leading-6 text-slate-600">
                                Select a market to configure the provider profile that owns each billing surface.
                                Market routing determines which provider profile receives live traffic, how proxy mode
                                is applied, and which fallback profiles are available when the primary path fails.
                            </p>
                        </div>
                        <div className="grid grid-cols-2 gap-3">
                            <MetricCard label="Markets" value={platforms.length} tone="slate" />
                            <MetricCard label="Editable" value={editable ? 'Yes' : 'View'} tone={editable ? 'emerald' : 'slate'} />
                        </div>
                    </div>
                </section>

                <div className="grid gap-4 xl:grid-cols-3">
                    {platforms.map((platform) => (
                        <MarketCard
                            key={platform.id}
                            platform={platform}
                            onSelect={() => setSelectedMarket(platform)}
                        />
                    ))}
                </div>
            </div>
        );
    }

    if (routingRulesQuery.isLoading) {
        return (
            <div className="space-y-4 p-5 animate-pulse">
                <div className="h-28 rounded-3xl border border-slate-200 bg-white" />
                <div className="grid gap-4">
                    {[...Array(4)].map((_, index) => (
                        <div key={index} className="h-60 rounded-3xl border border-slate-200 bg-white" />
                    ))}
                </div>
            </div>
        );
    }

    if (routingRulesQuery.isError) {
        if (isForbiddenQueryError(routingRulesQuery.error)) {
            return (
                <div className="space-y-4 p-5">
                    <BillingStateNotice
                        state="forbidden"
                        eyebrow="Market Routing"
                        title="Routing rules are outside your billing scope"
                        message="This role cannot inspect market-level routing details for the selected market."
                    />
                    <BackButton onClick={() => setSelectedMarket(null)} />
                </div>
            );
        }

        return (
            <div className="space-y-4 p-5">
                <BillingStateNotice
                    state="degraded"
                    eyebrow="Market Routing"
                    title="Routing rules unavailable"
                    message="CRM could not load routing rules for this market. Refresh the page to retry."
                />
                <BackButton onClick={() => setSelectedMarket(null)} />
            </div>
        );
    }

    if (profiles.length === 0) {
        return (
            <div className="space-y-4 p-5">
                <BillingStateNotice
                    state="empty"
                    eyebrow={`Market Routing - ${selectedMarket.name}`}
                    title="No provider profiles are available for this market"
                    message="Create at least one provider profile before assigning primary and fallback routes."
                />
                <BackButton onClick={() => setSelectedMarket(null)} />
            </div>
        );
    }

    return (
        <div className="space-y-5 p-5">
            <section className="rounded-3xl border border-slate-200 bg-white p-5 shadow-sm shadow-slate-950/[0.03]">
                <div className="flex flex-col gap-5 xl:flex-row xl:items-start xl:justify-between">
                    <div>
                        <div className="flex flex-wrap items-center gap-2">
                            <span className="rounded-full border border-slate-200 bg-slate-50 px-3 py-1 text-[11px] font-semibold uppercase tracking-[0.08em] text-slate-600">
                                {selectedMarket.country || 'Market'}
                            </span>
                            <span className="rounded-full border border-slate-200 bg-slate-50 px-3 py-1 text-[11px] font-semibold uppercase tracking-[0.08em] text-slate-600">
                                {profiles.length} profile{profiles.length === 1 ? '' : 's'}
                            </span>
                        </div>
                        <h4 className="mt-3 text-2xl font-semibold tracking-tight text-slate-950">
                            {selectedMarket.name} routing map
                        </h4>
                        <p className="mt-3 max-w-3xl text-sm leading-6 text-slate-600">
                            Configure which provider profile owns each billing surface, whether traffic goes direct or
                            through the proxy, and what fallback order is available when the primary route cannot be
                            used.
                        </p>
                    </div>

                    <div className="flex flex-col gap-3 xl:min-w-[290px]">
                        <div className="grid grid-cols-2 gap-3">
                            <MetricCard label="Surfaces" value={surfaces.length} tone="slate" />
                            <MetricCard
                                label="Unsaved"
                                value={dirty ? 'Yes' : 'No'}
                                tone={dirty ? 'amber' : 'emerald'}
                            />
                        </div>
                        <div className="flex gap-3">
                            <BackButton onClick={() => setSelectedMarket(null)} />
                            <button
                                type="button"
                                onClick={() => {
                                    saveMutation.mutate({
                                        rules: Object.values(normalizeDraftMap(draftMap)),
                                    });
                                }}
                                disabled={!editable || !dirty || saveMutation.isPending}
                                className="crm-btn-primary flex-1 justify-center px-4 py-3 disabled:cursor-not-allowed disabled:opacity-60"
                            >
                                {saveMutation.isPending ? 'Saving…' : 'Save routing'}
                            </button>
                        </div>
                    </div>
                </div>
            </section>

            {!editable ? (
                <BillingStateNotice
                    state="forbidden"
                    eyebrow="Market Routing"
                    title="Routing is visible but protected"
                    message="Only admin users can change market routing. Sub-admins can review the route posture without modifying payment paths."
                />
            ) : null}

            <div className="grid gap-5">
                {surfaces.map((surface) => {
                    const entry = draftMap[surface.key] || {
                        billing_surface: surface.key,
                        active: false,
                        primary_profile_id: null,
                        fallback_profile_ids: [],
                        execution_mode: 'direct',
                        operator_enabled: true,
                        self_service_enabled: false,
                        notes: '',
                    };

                    const eligibleProfiles = profiles.filter((profile) => {
                        const marketScoped = profile.market_id === null || Number(profile.market_id) === Number(selectedMarket.id);
                        return marketScoped && profile.active !== false;
                    });

                    return (
                        <RoutingSurfaceCard
                            key={surface.key}
                            surface={surface}
                            entry={entry}
                            editable={editable}
                            saving={saveMutation.isPending}
                            eligibleProfiles={eligibleProfiles}
                            profileMap={profileMap}
                            onChange={(nextEntry) => {
                                setDraftMap((current) => ({
                                    ...current,
                                    [surface.key]: nextEntry,
                                }));
                            }}
                        />
                    );
                })}
            </div>
        </div>
    );
}

function RoutingSurfaceCard({
    surface,
    entry,
    editable,
    saving,
    eligibleProfiles,
    profileMap,
    onChange,
}) {
    const meta = SURFACE_COPY[surface.key] || {
        title: surface.label,
        description: 'Routing policy for this billing surface.',
    };

    const primaryProfile = entry.primary_profile_id ? profileMap[entry.primary_profile_id] : null;
    const fallbackProfiles = (entry.fallback_profile_ids || [])
        .map((id) => profileMap[id])
        .filter(Boolean);

    return (
        <section className="rounded-3xl border border-slate-200 bg-white p-5 shadow-sm shadow-slate-950/[0.03]">
            <div className="flex flex-col gap-5 xl:flex-row xl:items-start xl:justify-between">
                <div className="max-w-2xl">
                    <div className="flex flex-wrap items-center gap-2">
                        <span className="rounded-full border border-slate-200 bg-slate-50 px-3 py-1 text-[11px] font-semibold uppercase tracking-[0.08em] text-slate-600">
                            {formatKey(surface.key)}
                        </span>
                        <span className={`rounded-full border px-3 py-1 text-[11px] font-semibold uppercase tracking-[0.08em] ${statusTone(entry.active)}`}>
                            {entry.active ? 'Active' : 'Disabled'}
                        </span>
                    </div>
                    <h5 className="mt-3 text-xl font-semibold text-slate-950">{meta.title}</h5>
                    <p className="mt-2 text-sm leading-6 text-slate-600">{meta.description}</p>
                </div>

                <label className="flex items-center gap-3 rounded-2xl border border-slate-200 bg-slate-50 px-4 py-3">
                    <input
                        type="checkbox"
                        checked={Boolean(entry.active)}
                        disabled={!editable || saving}
                        onChange={(event) => onChange({
                            ...entry,
                            active: event.target.checked,
                        })}
                        className="h-4 w-4 rounded border-slate-300 text-slate-900 focus:ring-slate-700"
                    />
                    <div>
                        <p className="text-sm font-semibold text-slate-900">Route enabled</p>
                        <p className="text-xs text-slate-500">Allow this surface to choose a primary provider.</p>
                    </div>
                </label>
            </div>

            <div className="mt-5 grid gap-4 xl:grid-cols-2">
                <label className="block space-y-2">
                    <span className="text-[11px] font-semibold uppercase tracking-[0.08em] text-slate-500">
                        Primary provider profile
                    </span>
                    <select
                        value={entry.primary_profile_id || ''}
                        disabled={!editable || saving}
                        onChange={(event) => {
                            const nextPrimary = event.target.value ? Number(event.target.value) : null;
                            onChange({
                                ...entry,
                                primary_profile_id: nextPrimary,
                                fallback_profile_ids: (entry.fallback_profile_ids || []).filter((id) => id !== nextPrimary),
                            });
                        }}
                        className="crm-select w-full"
                    >
                        <option value="">No primary route selected</option>
                        {eligibleProfiles.map((profile) => (
                            <option key={profile.id} value={profile.id}>
                                {profile.profile_name} · {profile.provider_label} · {formatKey(profile.environment)}
                            </option>
                        ))}
                    </select>
                </label>

                <label className="block space-y-2">
                    <span className="text-[11px] font-semibold uppercase tracking-[0.08em] text-slate-500">
                        Execution mode
                    </span>
                    <select
                        value={entry.execution_mode || 'direct'}
                        disabled={!editable || saving}
                        onChange={(event) => onChange({
                            ...entry,
                            execution_mode: event.target.value,
                        })}
                        className="crm-select w-full"
                    >
                        {EXECUTION_MODES.map((mode) => (
                            <option key={mode.key} value={mode.key}>
                                {mode.label}
                            </option>
                        ))}
                    </select>
                    <p className="text-xs leading-5 text-slate-500">
                        {(EXECUTION_MODES.find((mode) => mode.key === entry.execution_mode) || EXECUTION_MODES[0]).description}
                    </p>
                </label>
            </div>

            <div className="mt-5 grid gap-4 xl:grid-cols-[minmax(0,1.15fr)_minmax(280px,0.85fr)]">
                <div className="rounded-2xl border border-slate-200 bg-slate-50/80 p-4">
                    <div className="flex items-center justify-between gap-3">
                        <div>
                            <p className="text-[11px] font-semibold uppercase tracking-[0.08em] text-slate-500">Fallback order</p>
                            <h6 className="mt-2 text-sm font-semibold text-slate-900">Choose backup profiles for this surface</h6>
                        </div>
                        <span className="rounded-full border border-slate-200 bg-white px-2.5 py-1 text-[11px] font-semibold uppercase tracking-[0.08em] text-slate-600">
                            {fallbackProfiles.length} fallback
                        </span>
                    </div>

                    <div className="mt-4 flex flex-wrap gap-2">
                        {eligibleProfiles
                            .filter((profile) => Number(profile.id) !== Number(entry.primary_profile_id || 0))
                            .map((profile) => {
                                const active = (entry.fallback_profile_ids || []).includes(profile.id);

                                return (
                                    <button
                                        key={profile.id}
                                        type="button"
                                        disabled={!editable || saving}
                                        onClick={() => {
                                            const current = new Set(entry.fallback_profile_ids || []);
                                            if (current.has(profile.id)) {
                                                current.delete(profile.id);
                                            } else {
                                                current.add(profile.id);
                                            }

                                            onChange({
                                                ...entry,
                                                fallback_profile_ids: Array.from(current),
                                            });
                                        }}
                                        className={`rounded-full border px-3 py-1.5 text-xs font-semibold transition ${
                                            active
                                                ? 'border-amber-200 bg-amber-50 text-amber-800'
                                                : 'border-slate-200 bg-white text-slate-600 hover:border-slate-300 hover:text-slate-900'
                                        }`}
                                    >
                                        {profile.profile_name}
                                    </button>
                                );
                            })}
                    </div>
                </div>

                <div className="space-y-4">
                    <ToggleCard
                        label="Operator initiated"
                        description="Allow internal operators to use this route in CRM-driven billing flows."
                        checked={entry.operator_enabled !== false}
                        editable={editable}
                        saving={saving}
                        onChange={(checked) => onChange({ ...entry, operator_enabled: checked })}
                    />
                    <ToggleCard
                        label="Self-service"
                        description="Expose this route to customer-controlled surfaces where applicable."
                        checked={Boolean(entry.self_service_enabled)}
                        editable={editable}
                        saving={saving}
                        onChange={(checked) => onChange({ ...entry, self_service_enabled: checked })}
                    />
                </div>
            </div>

            <div className="mt-5 grid gap-4 xl:grid-cols-[minmax(0,1fr)_minmax(280px,0.7fr)]">
                <label className="block space-y-2">
                    <span className="text-[11px] font-semibold uppercase tracking-[0.08em] text-slate-500">
                        Routing notes
                    </span>
                    <textarea
                        value={entry.notes || ''}
                        disabled={!editable || saving}
                        onChange={(event) => onChange({ ...entry, notes: event.target.value })}
                        rows={3}
                        className="crm-input min-h-[104px] resize-y"
                        placeholder="Describe why this route exists, what should trigger fallback, or any merchant/risk constraints."
                    />
                </label>

                <div className="rounded-2xl border border-slate-200 bg-slate-50 px-4 py-4">
                    <p className="text-[11px] font-semibold uppercase tracking-[0.08em] text-slate-500">Current route summary</p>
                    <dl className="mt-4 space-y-3">
                        <SummaryRow label="Primary" value={primaryProfile ? primaryProfile.profile_name : 'No primary selected'} />
                        <SummaryRow
                            label="Fallbacks"
                            value={fallbackProfiles.length > 0 ? fallbackProfiles.map((profile) => profile.profile_name).join(', ') : 'No fallback sequence'}
                        />
                        <SummaryRow label="Mode" value={formatKey(entry.execution_mode || 'direct')} />
                    </dl>
                </div>
            </div>
        </section>
    );
}

function ToggleCard({ label, description, checked, editable, saving, onChange }) {
    return (
        <label className="flex items-start gap-3 rounded-2xl border border-slate-200 bg-white px-4 py-4">
            <input
                type="checkbox"
                checked={checked}
                disabled={!editable || saving}
                onChange={(event) => onChange(event.target.checked)}
                className="mt-1 h-4 w-4 rounded border-slate-300 text-slate-900 focus:ring-slate-700"
            />
            <div>
                <p className="text-sm font-semibold text-slate-900">{label}</p>
                <p className="mt-1 text-sm leading-6 text-slate-500">{description}</p>
            </div>
        </label>
    );
}

function SummaryRow({ label, value }) {
    return (
        <div className="flex items-start justify-between gap-4">
            <dt className="text-xs font-semibold uppercase tracking-[0.08em] text-slate-500">{label}</dt>
            <dd className="text-right text-sm font-medium text-slate-900">{value}</dd>
        </div>
    );
}

function BackButton({ onClick }) {
    return (
        <button
            type="button"
            onClick={onClick}
            className="crm-btn-secondary px-4 py-3"
        >
            Back
        </button>
    );
}

function MarketCard({ platform, onSelect }) {
    return (
        <button
            type="button"
            onClick={onSelect}
            className="rounded-3xl border border-slate-200 bg-white p-5 text-left shadow-sm shadow-slate-950/[0.03] transition hover:-translate-y-0.5 hover:border-slate-300 hover:shadow-md hover:shadow-slate-950/[0.05]"
        >
            <div className="flex items-center justify-between gap-3">
                <div>
                    <p className="text-[11px] font-semibold uppercase tracking-[0.1em] text-slate-500">Market</p>
                    <h5 className="mt-2 text-lg font-semibold text-slate-950">{platform.name}</h5>
                    {platform.country ? (
                        <p className="mt-1 text-sm text-slate-500">{platform.country}</p>
                    ) : null}
                </div>
                <span className="rounded-full border border-slate-200 bg-slate-50 px-3 py-1 text-[11px] font-semibold uppercase tracking-[0.08em] text-slate-600">
                    Open
                </span>
            </div>

            <div className="mt-5 grid grid-cols-2 gap-3">
                <MetricCard
                    label="Wallet"
                    value={platform.wallet?.enabled ? 'On' : 'Off'}
                    tone={platform.wallet?.enabled ? 'emerald' : 'slate'}
                />
                <MetricCard
                    label="Mode"
                    value={formatKey(platform.wallet?.mode_override || 'production')}
                    tone="slate"
                />
            </div>
        </button>
    );
}

function MetricCard({ label, value, tone = 'slate' }) {
    const tones = {
        emerald: 'border-emerald-200 bg-[linear-gradient(180deg,rgba(236,253,245,0.95)_0%,rgba(255,255,255,1)_100%)] text-emerald-950',
        amber: 'border-amber-200 bg-[linear-gradient(180deg,rgba(255,251,235,0.95)_0%,rgba(255,255,255,1)_100%)] text-amber-950',
        slate: 'border-slate-200 bg-[linear-gradient(180deg,rgba(248,250,252,0.95)_0%,rgba(255,255,255,1)_100%)] text-slate-950',
    };

    return (
        <div className={`rounded-2xl border px-4 py-4 ${tones[tone] || tones.slate}`}>
            <p className="text-[10px] font-semibold uppercase tracking-[0.1em] opacity-65">{label}</p>
            <p className="mt-3 text-xl font-semibold tracking-tight">{value}</p>
        </div>
    );
}
