import React, { useEffect, useMemo, useState } from 'react';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import api from '../../services/api';
import { useToast } from '../ToastProvider';
import { formatCurrency } from '../../utils/currency';

const SCOPE_OPTIONS = [
    { value: 'single_profile', label: 'One-time profile unlock' },
    { value: 'market_inactive_profiles', label: 'All inactive contacts' },
];

function firstErrorMessage(error) {
    const validation = error?.response?.data?.errors;
    if (validation && typeof validation === 'object') {
        const first = Object.values(validation).flat()[0];
        if (first) return String(first);
    }

    return error?.response?.data?.message || 'CRM could not save contact unlock settings.';
}

function blankRule(markets) {
    const market = markets?.[0] || {};
    return {
        id: null,
        platform_id: market.id || '',
        scope: 'single_profile',
        label: 'Unlock this profile',
        currency: market.currency_code || 'KES',
        amount: '',
        duration_days: 1,
        is_active: true,
    };
}

function normalizeRule(rule) {
    return {
        id: rule.id ?? null,
        platform_id: rule.platform_id || '',
        scope: rule.scope || 'single_profile',
        label: rule.label || '',
        currency: rule.currency || 'KES',
        amount: rule.amount ?? '',
        duration_days: rule.duration_days || 1,
        is_active: rule.is_active !== false,
    };
}

function titleize(value) {
    return String(value || '')
        .replace(/[_-]+/g, ' ')
        .replace(/\b\w/g, (letter) => letter.toUpperCase());
}

function statusClasses(status) {
    const normalized = String(status || '').toLowerCase();
    if (['active', 'completed', 'unlocked'].includes(normalized)) {
        return 'border-emerald-200 bg-emerald-50 text-emerald-700';
    }
    if (['failed', 'revoked', 'refunded', 'expired'].includes(normalized)) {
        return 'border-rose-200 bg-rose-50 text-rose-700';
    }
    if (['pending', 'pending_payment', 'initiated'].includes(normalized)) {
        return 'border-amber-200 bg-amber-50 text-amber-700';
    }
    return 'border-slate-200 bg-slate-50 text-slate-600';
}

function providerLabel(provider) {
    const key = String(provider || '').toLowerCase();
    if (key === 'pawapay') return 'pawaPay';
    if (key === 'kopokopo') return 'KopoKopo';
    return titleize(key || 'Provider');
}

export default function ContactUnlockTab() {
    const queryClient = useQueryClient();
    const toast = useToast();
    const [enabled, setEnabled] = useState(false);
    const [sandboxOnly, setSandboxOnly] = useState(true);
    const [marketIds, setMarketIds] = useState([]);
    const [rules, setRules] = useState([]);

    const unlockQuery = useQuery({
        queryKey: ['billing-contact-unlock'],
        queryFn: () => api.get('/crm/settings/billing/contact-unlock').then((response) => response.data),
        staleTime: 30_000,
    });

    const data = unlockQuery.data || {};
    const markets = data.markets || [];
    const summary = data.summary || {};
    const recentUnlocks = data.recent_unlocks || [];

    useEffect(() => {
        if (!unlockQuery.data) return;
        setEnabled(Boolean(unlockQuery.data.settings?.enabled));
        setSandboxOnly(unlockQuery.data.settings?.sandbox_only !== false);
        setMarketIds((unlockQuery.data.settings?.market_ids || []).map(Number));
        setRules((unlockQuery.data.pricing_rules || []).map(normalizeRule));
    }, [unlockQuery.data]);

    const revenueLabel = useMemo(() => {
        const entries = summary.confirmed_revenue_native || [];
        if (!entries.length) return 'No confirmed revenue yet';
        return entries
            .map((entry) => `${formatCurrency(entry.amount, entry.currency || 'USD')} (${entry.count})`)
            .join(' + ');
    }, [summary.confirmed_revenue_native]);

    const saveMutation = useMutation({
        mutationFn: () => api.put('/crm/settings/billing/contact-unlock', {
            enabled,
            sandbox_only: sandboxOnly,
            market_ids: marketIds,
            pricing_rules: rules
                .filter((rule) => rule.platform_id && rule.label && rule.amount)
                .map((rule) => ({
                    ...rule,
                    platform_id: Number(rule.platform_id),
                    amount: Number(rule.amount),
                    duration_days: Number(rule.duration_days || 1),
                    currency: String(rule.currency || '').toUpperCase(),
                })),
        }).then((response) => response.data),
        onSuccess: (payload) => {
            queryClient.setQueryData(['billing-contact-unlock'], payload);
            toast.success('Contact unlock settings saved.');
        },
        onError: (error) => toast.error(firstErrorMessage(error)),
    });

    const deleteMutation = useMutation({
        mutationFn: (ruleId) => api.delete(`/crm/settings/billing/contact-unlock/rules/${ruleId}`).then((response) => response.data),
        onSuccess: (payload) => {
            queryClient.setQueryData(['billing-contact-unlock'], payload);
            toast.success('Pricing rule removed.');
        },
        onError: (error) => toast.error(firstErrorMessage(error)),
    });

    function updateRule(index, patch) {
        setRules((current) => current.map((rule, ruleIndex) => (
            ruleIndex === index ? { ...rule, ...patch } : rule
        )));
    }

    function toggleMarket(id) {
        const marketId = Number(id);
        setMarketIds((current) => (
            current.includes(marketId)
                ? current.filter((item) => item !== marketId)
                : [...current, marketId]
        ));
    }

    function removeRule(index, rule) {
        if (rule.id) {
            deleteMutation.mutate(rule.id);
            return;
        }

        setRules((current) => current.filter((_, ruleIndex) => ruleIndex !== index));
    }

    if (unlockQuery.isLoading) {
        return (
            <div className="p-5">
                <div className="h-40 animate-pulse rounded-xl border border-slate-200 bg-white" />
            </div>
        );
    }

    if (unlockQuery.isError) {
        return (
            <div className="p-5">
                <section className="rounded-xl border border-rose-200 bg-rose-50 p-4">
                    <h4 className="text-sm font-semibold text-rose-900">Contact Unlocks unavailable</h4>
                    <p className="mt-2 text-sm text-rose-700">CRM could not load contact unlock settings.</p>
                </section>
            </div>
        );
    }

    return (
        <div className="space-y-5 p-5">
            <section className="rounded-xl border border-slate-200 bg-white p-5 shadow-sm shadow-slate-950/[0.02]">
                <div className="grid gap-4 xl:grid-cols-[minmax(0,1fr)_360px]">
                    <div>
                        <p className="text-[10px] font-semibold uppercase tracking-[0.18em] text-slate-500">
                            Contact unlock revenue
                        </p>
                        <h3 className="mt-2 text-2xl font-semibold tracking-tight text-slate-950">
                            Visitor payments for inactive profile contacts
                        </h3>
                        <p className="mt-2 max-w-3xl text-sm leading-6 text-slate-600">
                            This is separate from escort subscriptions. Confirmed unlock payments reveal contact details
                            only and do not create deals, renew packages, or count toward subscription revenue.
                        </p>
                    </div>
                    <div className="grid grid-cols-2 gap-3">
                        <div className="rounded-lg border border-slate-200 bg-slate-50 p-4">
                            <p className="text-[10px] font-semibold uppercase tracking-[0.14em] text-slate-500">Active unlocks</p>
                            <p className="mt-2 text-2xl font-semibold text-slate-950">{summary.active_unlocks || 0}</p>
                        </div>
                        <div className="rounded-lg border border-slate-200 bg-slate-50 p-4">
                            <p className="text-[10px] font-semibold uppercase tracking-[0.14em] text-slate-500">Pending</p>
                            <p className="mt-2 text-2xl font-semibold text-slate-950">{summary.pending_unlocks || 0}</p>
                        </div>
                        <div className="col-span-2 rounded-lg border border-emerald-200 bg-emerald-50 p-4">
                            <p className="text-[10px] font-semibold uppercase tracking-[0.14em] text-emerald-700">
                                Confirmed unlock revenue
                            </p>
                            <p className="mt-2 text-lg font-semibold text-emerald-950">{revenueLabel}</p>
                        </div>
                    </div>
                </div>
            </section>

            <section className="rounded-xl border border-slate-200 bg-white p-5 shadow-sm shadow-slate-950/[0.02]">
                <div className="flex flex-col gap-4 lg:flex-row lg:items-start lg:justify-between">
                    <div>
                        <h4 className="text-base font-semibold text-slate-950">Availability</h4>
                        <p className="mt-1 text-sm text-slate-600">
                            Enable the paywall only on markets with mobile-money rails and active pricing.
                        </p>
                    </div>
                    <label className="inline-flex cursor-pointer items-center gap-3 rounded-lg border border-slate-200 px-4 py-3 text-sm font-semibold text-slate-700">
                        <input
                            type="checkbox"
                            className="h-4 w-4 rounded border-slate-300 text-teal-600"
                            checked={enabled}
                            onChange={(event) => setEnabled(event.target.checked)}
                        />
                        Feature enabled
                    </label>
                </div>

                <div className="mt-4 grid gap-3 rounded-lg border border-amber-200 bg-amber-50 p-4 lg:grid-cols-[minmax(0,1fr)_auto] lg:items-center">
                    <div>
                        <h5 className="text-sm font-semibold text-amber-950">Checkout environment</h5>
                        <p className="mt-1 text-sm text-amber-800">
                            Production provider profiles need production/default mode. Sandbox-only mode is for test profiles and will not use the live Kenya pawaPay profile.
                        </p>
                    </div>
                    <div className="inline-flex rounded-lg border border-amber-200 bg-white p-1 text-sm font-semibold shadow-sm">
                        <button
                            type="button"
                            className={`rounded-md px-4 py-2 ${!sandboxOnly ? 'bg-slate-950 text-white shadow-sm' : 'text-slate-600 hover:text-slate-950'}`}
                            onClick={() => setSandboxOnly(false)}
                        >
                            Production/default
                        </button>
                        <button
                            type="button"
                            className={`rounded-md px-4 py-2 ${sandboxOnly ? 'bg-slate-950 text-white shadow-sm' : 'text-slate-600 hover:text-slate-950'}`}
                            onClick={() => setSandboxOnly(true)}
                        >
                            Sandbox only
                        </button>
                    </div>
                </div>

                <div className="mt-4 grid gap-2 md:grid-cols-2 xl:grid-cols-4">
                    {markets.map((market) => (
                        <label
                            key={market.id}
                            className="flex min-w-0 cursor-pointer items-center justify-between gap-3 rounded-lg border border-slate-200 px-3 py-3 text-sm"
                        >
                            <span className="min-w-0">
                                <span className="block truncate font-semibold text-slate-900">{market.name}</span>
                                <span className="block truncate text-xs text-slate-500">{market.currency_code || 'No currency'} · {market.country || 'Market'}</span>
                            </span>
                            <input
                                type="checkbox"
                                className="h-4 w-4 shrink-0 rounded border-slate-300 text-teal-600"
                                checked={marketIds.includes(Number(market.id))}
                                onChange={() => toggleMarket(market.id)}
                            />
                        </label>
                    ))}
                </div>
            </section>

            <section className="rounded-xl border border-slate-200 bg-white p-5 shadow-sm shadow-slate-950/[0.02]">
                <div className="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
                    <div>
                        <h4 className="text-base font-semibold text-slate-950">Pricing rules</h4>
                        <p className="mt-1 text-sm text-slate-600">Configure one-time and all-inactive access per market.</p>
                    </div>
                    <button
                        type="button"
                        className="rounded-lg bg-slate-950 px-4 py-2 text-sm font-semibold text-white"
                        onClick={() => setRules((current) => [...current, blankRule(markets)])}
                    >
                        Add rule
                    </button>
                </div>

                <div className="mt-4 space-y-3">
                    {rules.map((rule, index) => (
                        <div key={`${rule.id || 'new'}-${index}`} className="grid gap-3 rounded-lg border border-slate-200 p-4 xl:grid-cols-[1.15fr_1fr_.7fr_.7fr_.55fr_auto]">
                            <select
                                className="rounded-lg border border-slate-200 px-3 py-2 text-sm"
                                value={rule.platform_id}
                                onChange={(event) => {
                                    const market = markets.find((item) => Number(item.id) === Number(event.target.value));
                                    updateRule(index, {
                                        platform_id: event.target.value,
                                        currency: market?.currency_code || rule.currency,
                                    });
                                }}
                            >
                                {markets.map((market) => (
                                    <option key={market.id} value={market.id}>{market.name}</option>
                                ))}
                            </select>
                            <select
                                className="rounded-lg border border-slate-200 px-3 py-2 text-sm"
                                value={rule.scope}
                                onChange={(event) => updateRule(index, { scope: event.target.value })}
                            >
                                {SCOPE_OPTIONS.map((scope) => (
                                    <option key={scope.value} value={scope.value}>{scope.label}</option>
                                ))}
                            </select>
                            <input
                                className="rounded-lg border border-slate-200 px-3 py-2 text-sm"
                                value={rule.label}
                                onChange={(event) => updateRule(index, { label: event.target.value })}
                                placeholder="Display label"
                            />
                            <div className="grid grid-cols-[72px_1fr] gap-2">
                                <input
                                    className="rounded-lg border border-slate-200 px-3 py-2 text-sm uppercase"
                                    value={rule.currency}
                                    onChange={(event) => updateRule(index, { currency: event.target.value.toUpperCase().slice(0, 3) })}
                                />
                                <input
                                    className="rounded-lg border border-slate-200 px-3 py-2 text-sm"
                                    type="number"
                                    min="1"
                                    value={rule.amount}
                                    onChange={(event) => updateRule(index, { amount: event.target.value })}
                                    placeholder="Amount"
                                />
                            </div>
                            <input
                                className="rounded-lg border border-slate-200 px-3 py-2 text-sm"
                                type="number"
                                min="1"
                                max="366"
                                value={rule.duration_days}
                                onChange={(event) => updateRule(index, { duration_days: event.target.value })}
                                aria-label="Duration days"
                            />
                            <div className="flex items-center gap-3">
                                <label className="inline-flex items-center gap-2 text-sm font-semibold text-slate-700">
                                    <input
                                        type="checkbox"
                                        className="h-4 w-4 rounded border-slate-300 text-teal-600"
                                        checked={Boolean(rule.is_active)}
                                        onChange={(event) => updateRule(index, { is_active: event.target.checked })}
                                    />
                                    On
                                </label>
                                <button
                                    type="button"
                                    className="rounded-lg border border-rose-200 px-3 py-2 text-sm font-semibold text-rose-700"
                                    onClick={() => removeRule(index, rule)}
                                >
                                    Remove
                                </button>
                            </div>
                        </div>
                    ))}

                    {!rules.length ? (
                        <div className="rounded-lg border border-dashed border-slate-300 p-6 text-sm text-slate-500">
                            No contact unlock pricing has been configured yet.
                        </div>
                    ) : null}
                </div>

                <div className="mt-5 flex justify-end">
                    <button
                        type="button"
                        className="rounded-lg bg-teal-700 px-5 py-2.5 text-sm font-semibold text-white disabled:opacity-60"
                        disabled={saveMutation.isPending}
                        onClick={() => saveMutation.mutate()}
                    >
                        {saveMutation.isPending ? 'Saving…' : 'Save contact unlock'}
                    </button>
                </div>
            </section>

            <section className="rounded-xl border border-slate-200 bg-white p-5 shadow-sm shadow-slate-950/[0.02]">
                <div className="flex flex-col gap-3 sm:flex-row sm:items-end sm:justify-between">
                    <div>
                        <p className="text-[10px] font-semibold uppercase tracking-[0.18em] text-slate-500">Live checkout trail</p>
                        <h4 className="mt-1 text-lg font-semibold text-slate-950">Recent unlocks</h4>
                    </div>
                    <div className="rounded-lg border border-slate-200 bg-slate-50 px-3 py-2 text-xs font-semibold text-slate-600">
                        {recentUnlocks.length} latest records
                    </div>
                </div>

                <div className="mt-4 overflow-hidden rounded-xl border border-slate-200">
                    <div className="hidden grid-cols-[92px_1fr_1fr_1.2fr_1fr] gap-4 border-b border-slate-200 bg-slate-50 px-4 py-3 text-[10px] font-semibold uppercase tracking-[0.14em] text-slate-500 xl:grid">
                        <span>Reference</span>
                        <span>Profile</span>
                        <span>Status</span>
                        <span>Payment</span>
                        <span>Visitor</span>
                    </div>
                    <div className="divide-y divide-slate-100">
                        {recentUnlocks.map((unlock) => {
                            const payment = unlock.payment || {};
                            const paymentReference = payment.reference || (payment.id ? `#${payment.id}` : '-');
                            return (
                                <div key={unlock.id} className="grid gap-4 px-4 py-4 text-sm transition-colors hover:bg-slate-50/70 xl:grid-cols-[92px_1fr_1fr_1.2fr_1fr] xl:items-start">
                                    <div>
                                        <p className="font-semibold text-slate-950">#{unlock.id}</p>
                                        <p className="mt-1 text-xs text-slate-500">{unlock.market || 'Market'}</p>
                                    </div>
                                    <div className="min-w-0">
                                        {unlock.profile?.url ? (
                                            <a className="font-semibold text-slate-950 underline-offset-4 hover:text-teal-700 hover:underline" href={unlock.profile.url} target="_blank" rel="noreferrer">
                                                {unlock.profile?.name || unlock.profile?.wp_post_id || 'Profile'}
                                            </a>
                                        ) : (
                                            <p className="font-semibold text-slate-950">{unlock.profile?.name || unlock.profile?.wp_post_id || '-'}</p>
                                        )}
                                        <p className="mt-1 text-xs text-slate-500">{unlock.scope === 'market_inactive_profiles' ? 'All inactive contacts' : 'Single profile'}</p>
                                    </div>
                                    <div className="flex flex-wrap gap-2">
                                        <span className={`inline-flex rounded-full border px-2.5 py-1 text-xs font-semibold ${statusClasses(unlock.status)}`}>
                                            {titleize(unlock.status || 'unknown')}
                                        </span>
                                        {payment.status ? (
                                            <span className={`inline-flex rounded-full border px-2.5 py-1 text-xs font-semibold ${statusClasses(payment.status)}`}>
                                                Payment {titleize(payment.status)}
                                            </span>
                                        ) : null}
                                    </div>
                                    <div className="min-w-0">
                                        <p className="font-semibold text-slate-950">
                                            {payment.currency ? formatCurrency(payment.amount, payment.currency) : '-'}
                                        </p>
                                        <div className="mt-2 flex flex-wrap gap-2 text-xs">
                                            {payment.provider_key ? (
                                                <span className="rounded-full border border-slate-200 bg-white px-2.5 py-1 font-semibold text-slate-600">{providerLabel(payment.provider_key)}</span>
                                            ) : null}
                                            {payment.provider_environment ? (
                                                <span className="rounded-full border border-slate-200 bg-white px-2.5 py-1 font-semibold text-slate-600">{titleize(payment.provider_environment)}</span>
                                            ) : null}
                                            <span className="rounded-full border border-slate-200 bg-white px-2.5 py-1 font-semibold text-slate-500">{paymentReference}</span>
                                        </div>
                                        {payment.failure_reason ? (
                                            <div className="mt-3 rounded-lg border border-rose-100 bg-rose-50 px-3 py-2 text-xs leading-5 text-rose-700">
                                                {payment.failure_reason}
                                                {payment.error_reference ? <span className="mt-1 block font-semibold">Reference {payment.error_reference}</span> : null}
                                            </div>
                                        ) : null}
                                    </div>
                                    <div>
                                        <p className="font-semibold text-slate-700">{unlock.visitor_phone_masked || unlock.visitor_email_masked || '-'}</p>
                                        <p className="mt-1 text-xs text-slate-500">{unlock.created_at ? new Date(unlock.created_at).toLocaleString() : ''}</p>
                                    </div>
                                </div>
                            );
                        })}
                        {!recentUnlocks.length ? (
                            <div className="px-4 py-8 text-center text-sm text-slate-500">No visitor unlocks yet.</div>
                        ) : null}
                    </div>
                </div>
            </section>
        </div>
    );
}
