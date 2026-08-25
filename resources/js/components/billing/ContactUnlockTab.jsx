import React, { useEffect, useMemo, useState } from 'react';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import api from '../../services/api';
import { useToast } from '../ToastProvider';
import { formatCurrency } from '../../utils/currency';

const SCOPE_OPTIONS = [
    { value: 'single_profile', label: 'One-time profile unlock' },
    { value: 'market_inactive_profiles', label: 'All inactive contacts' },
];

const UNLOCK_STATUS_OPTIONS = ['initiated', 'pending_payment', 'active', 'failed', 'expired', 'revoked', 'refunded'];
const PAYMENT_STATUS_OPTIONS = ['initiated', 'pending', 'completed', 'failed', 'canceled'];
const PER_PAGE_OPTIONS = [10, 25, 50, 100];
const PULSE_RANGE_OPTIONS = [
    { value: 'today', label: 'Today' },
    { value: '7d', label: '7 days' },
    { value: '30d', label: '30 days' },
];

const DEFAULT_UNLOCK_FILTERS = {
    search: '',
    platform_id: 'all',
    status: 'all',
    payment_status: 'all',
    scope: 'all',
    sort: 'id',
    direction: 'desc',
    page: 1,
    per_page: 10,
};

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

function readinessStatusClasses(status) {
    const normalized = String(status || '').toLowerCase();
    if (normalized === 'ready' || normalized === 'pass') {
        return 'border-emerald-200 bg-emerald-50 text-emerald-700';
    }
    if (normalized === 'blocked' || normalized === 'fail') {
        return 'border-rose-200 bg-rose-50 text-rose-700';
    }
    if (normalized === 'warning' || normalized === 'warn') {
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

function shortDate(value) {
    if (!value) return '';
    return new Date(value).toLocaleString(undefined, {
        month: 'short',
        day: 'numeric',
        hour: '2-digit',
        minute: '2-digit',
    });
}

function sortLabel(key, filters) {
    if (filters.sort !== key) return 'Sort';
    return filters.direction === 'asc' ? 'Asc' : 'Desc';
}

function buildUnlockParams(filters) {
    return Object.entries(filters).reduce((params, [key, value]) => {
        if (value === '' || value === 'all' || value === null || value === undefined) {
            return params;
        }
        params[key] = value;
        return params;
    }, {});
}

function moneyRowsLabel(rows = [], fallback = '-') {
    if (!rows.length) return fallback;
    return rows
        .map((entry) => formatCurrency(entry.amount || 0, entry.currency || 'USD'))
        .join(' + ');
}

function perViewRevenueRows(rows = [], views = 0) {
    const denominator = Number(views || 0);
    if (!denominator) return [];
    return rows.map((entry) => ({
        ...entry,
        amount: Number(entry.amount || 0) / denominator,
    }));
}

function percentLabel(value) {
    return `${Number(value || 0).toFixed(Number(value || 0) % 1 === 0 ? 0 : 1)}%`;
}

function visitorDeviceLabel(context = {}) {
    const platform = context.platform || '';
    const mobile = context.mobile_hint === true || Number(context.device?.max_touch_points || 0) > 0;
    if (platform && mobile) return `${platform} mobile`;
    if (platform) return platform;
    return mobile ? 'Touch device' : 'Device unknown';
}

function visitorContextParts(context = {}) {
    return [
        context.locale || '',
        context.timezone || '',
        visitorDeviceLabel(context),
        context.viewport?.width && context.viewport?.height ? `${context.viewport.width}x${context.viewport.height}` : '',
        context.ip_masked ? `IP ${context.ip_masked}` : '',
    ].filter(Boolean);
}

export default function ContactUnlockTab() {
    const queryClient = useQueryClient();
    const toast = useToast();
    const [enabled, setEnabled] = useState(false);
    const [sandboxOnly, setSandboxOnly] = useState(true);
    const [marketIds, setMarketIds] = useState([]);
    const [rules, setRules] = useState([]);
    const [unlockFilters, setUnlockFilters] = useState(DEFAULT_UNLOCK_FILTERS);
    const [readinessMarketId, setReadinessMarketId] = useState('all');
    const [readinessResult, setReadinessResult] = useState(null);
    const [pulseRange, setPulseRange] = useState('today');
    const [pulseMarketId, setPulseMarketId] = useState('all');

    const unlockQuery = useQuery({
        queryKey: ['billing-contact-unlock', unlockFilters],
        queryFn: () => api.get('/crm/settings/billing/contact-unlock', {
            params: buildUnlockParams(unlockFilters),
        }).then((response) => response.data),
        staleTime: 30_000,
    });

    const data = unlockQuery.data || {};
    const markets = data.markets || [];
    const summary = data.summary || {};
    const recentUnlocks = data.recent_unlocks || [];
    const unlocksMeta = data.recent_unlocks_meta || {
        current_page: 1,
        last_page: 1,
        per_page: unlockFilters.per_page,
        total: recentUnlocks.length,
        from: recentUnlocks.length ? 1 : 0,
        to: recentUnlocks.length,
    };

    const pulseQuery = useQuery({
        queryKey: ['billing-contact-unlock-pulse', pulseRange, pulseMarketId],
        queryFn: () => api.get('/crm/settings/billing/contact-unlock/pulse', {
            params: {
                range: pulseRange,
                ...(pulseMarketId !== 'all' ? { platform_id: Number(pulseMarketId) } : {}),
                timezone: Intl.DateTimeFormat().resolvedOptions().timeZone,
            },
        }).then((response) => response.data),
        enabled: Boolean(unlockQuery.data),
        staleTime: 30_000,
    });

    const pulse = pulseQuery.data || {};
    const pulseKpis = pulse.kpis || {};
    const pulseRevenue = pulseKpis.revenue || [];
    const pulseAverageOrderValue = pulseKpis.average_order_value || [];
    const pulseRevenuePerView = perViewRevenueRows(pulseRevenue, pulseKpis.eligible_profile_views);
    const pulseTopGroups = [
        { key: 'cities', title: 'Top cities', rows: pulse.top_cities || [] },
        { key: 'profiles', title: 'Top profiles', rows: pulse.top_profiles || [] },
        { key: 'sources', title: 'Top converting traffic sources', rows: pulse.top_sources || [] },
        { key: 'hours', title: 'Top hours', rows: pulse.top_hours || [] },
    ];

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
            queryClient.setQueryData(['billing-contact-unlock', unlockFilters], payload);
            queryClient.invalidateQueries({ queryKey: ['billing-contact-unlock'] });
            toast.success('Contact unlock settings saved.');
        },
        onError: (error) => toast.error(firstErrorMessage(error)),
    });

    const deleteMutation = useMutation({
        mutationFn: (ruleId) => api.delete(`/crm/settings/billing/contact-unlock/rules/${ruleId}`).then((response) => response.data),
        onSuccess: (payload) => {
            queryClient.setQueryData(['billing-contact-unlock', unlockFilters], payload);
            queryClient.invalidateQueries({ queryKey: ['billing-contact-unlock'] });
            toast.success('Pricing rule removed.');
        },
        onError: (error) => toast.error(firstErrorMessage(error)),
    });

    const readinessMutation = useMutation({
        mutationFn: () => api.post('/crm/settings/billing/contact-unlock/readiness', {
            ...(readinessMarketId !== 'all' ? { platform_id: Number(readinessMarketId) } : {}),
        }).then((response) => response.data),
        onSuccess: (payload) => {
            setReadinessResult(payload);
            const blocked = Number(payload?.summary?.blocked || 0);
            const warnings = Number(payload?.summary?.warning || 0);
            if (blocked > 0) {
                toast.error(`${blocked} market${blocked === 1 ? '' : 's'} blocked contact unlock readiness.`);
            } else if (warnings > 0) {
                toast.info(`Contact unlock readiness completed with ${warnings} warning${warnings === 1 ? '' : 's'}.`);
            } else {
                toast.success('Contact unlock readiness passed.');
            }
        },
        onError: (error) => toast.error(error?.response?.data?.message || 'CRM could not run contact unlock readiness checks.'),
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

    function updateUnlockFilter(key, value) {
        setUnlockFilters((current) => ({
            ...current,
            [key]: value,
            page: 1,
        }));
    }

    function setUnlockPage(page) {
        setUnlockFilters((current) => ({
            ...current,
            page: Math.max(1, Math.min(Number(page) || 1, Number(unlocksMeta.last_page || 1))),
        }));
    }

    function toggleUnlockSort(key) {
        setUnlockFilters((current) => ({
            ...current,
            sort: key,
            direction: current.sort === key && current.direction === 'desc' ? 'asc' : 'desc',
            page: 1,
        }));
    }

    function resetUnlockFilters() {
        setUnlockFilters(DEFAULT_UNLOCK_FILTERS);
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
            <section className="overflow-hidden rounded-xl border border-slate-200 bg-white shadow-sm shadow-slate-950/[0.02]">
                <div className="border-b border-slate-100 bg-slate-950 px-5 py-4 text-white">
                    <div className="flex flex-col gap-4 xl:flex-row xl:items-end xl:justify-between">
                        <div>
                            <p className="text-[10px] font-semibold uppercase tracking-[0.18em] text-teal-200">
                                Contact Unlock Pulse
                            </p>
                            <h3 className="mt-2 text-2xl font-semibold tracking-tight">
                                Paid-contact demand, live funnel, and upgrade momentum
                            </h3>
                            <p className="mt-2 max-w-3xl text-sm leading-6 text-slate-300">
                                A same-day operating view for profile demand, checkout health, buyer quality, and all-access upsell adoption.
                            </p>
                        </div>
                        <div className="flex flex-col gap-2 sm:flex-row">
                            <select
                                className="rounded-lg border border-white/10 bg-white/10 px-3 py-2 text-sm font-semibold text-white"
                                value={pulseMarketId}
                                onChange={(event) => setPulseMarketId(event.target.value)}
                            >
                                <option className="text-slate-900" value="all">All markets</option>
                                {markets.map((market) => (
                                    <option className="text-slate-900" key={market.id} value={market.id}>{market.name}</option>
                                ))}
                            </select>
                            <div className="inline-flex rounded-lg border border-white/10 bg-white/10 p-1">
                                {PULSE_RANGE_OPTIONS.map((option) => (
                                    <button
                                        key={option.value}
                                        type="button"
                                        className={`rounded-md px-3 py-1.5 text-sm font-semibold ${pulseRange === option.value ? 'bg-white text-slate-950 shadow-sm' : 'text-slate-300 hover:text-white'}`}
                                        onClick={() => setPulseRange(option.value)}
                                    >
                                        {option.label}
                                    </button>
                                ))}
                            </div>
                        </div>
                    </div>
                </div>

                <div className={`p-5 ${pulseQuery.isFetching ? 'opacity-70' : ''}`}>
                    <div className="grid gap-3 md:grid-cols-2 xl:grid-cols-4">
                        {[
                            ['Eligible profile views', pulseKpis.eligible_profile_views ?? 0],
                            ['Unlock CTA clicks', `${pulseKpis.unlock_cta_clicks ?? 0} · ${percentLabel(pulseKpis.cta_rate_percent)} CTA`],
                            ['Checkout starts', `${pulseKpis.checkout_starts ?? 0} · ${percentLabel(pulseKpis.checkout_rate_percent)} checkout`],
                            ['Successful payments', `${pulseKpis.successful_payments ?? 0} · ${percentLabel(pulseKpis.payment_completion_percent)} complete`],
                            ['Pending payments', pulseKpis.pending_payments ?? 0],
                            ['Unlock conversion', percentLabel(pulseKpis.unlock_conversion_percent)],
                            ['Revenue', moneyRowsLabel(pulseRevenue, 'No revenue yet')],
                            ['Average order value', moneyRowsLabel(pulseAverageOrderValue, '-')],
                            ['Single-profile purchases', pulseKpis.single_profile_purchases ?? 0],
                            ['Full-access purchases', pulseKpis.full_access_purchases ?? 0],
                            ['Revenue/profile view', moneyRowsLabel(pulseRevenuePerView, '-')],
                            ['Repeat and upgrades', `${percentLabel(pulseKpis.repeat_buyer_percent)} repeat · ${percentLabel(pulseKpis.upgrade_rate_percent)} upgrade`],
                        ].map(([label, value]) => (
                            <div key={label} className="rounded-lg border border-slate-200 bg-slate-50 p-4">
                                <p className="text-[10px] font-semibold uppercase tracking-[0.14em] text-slate-500">{label}</p>
                                <p className="mt-2 text-xl font-semibold tracking-tight text-slate-950">{value}</p>
                            </div>
                        ))}
                    </div>

                    <div className="mt-3 rounded-lg border border-emerald-200 bg-emerald-50 px-4 py-3">
                        <div className="flex flex-col gap-1 sm:flex-row sm:items-center sm:justify-between">
                            <p className="text-sm font-semibold text-emerald-950">Advertiser recovery signal</p>
                            <p className="text-sm text-emerald-800">
                                {pulseKpis.renewed_after_paid_demand ?? 0} inactive advertiser{Number(pulseKpis.renewed_after_paid_demand || 0) === 1 ? '' : 's'} renewed after paid-contact demand
                            </p>
                        </div>
                    </div>

                    <div className="mt-4 grid gap-4 xl:grid-cols-4">
                        {pulseTopGroups.map((group) => (
                            <div key={group.key} className="rounded-xl border border-slate-200 bg-white p-4">
                                <h4 className="text-sm font-semibold text-slate-950">{group.title}</h4>
                                <div className="mt-3 space-y-2">
                                    {group.rows.length ? group.rows.map((row, index) => (
                                        <div key={`${group.key}-${row.label}-${index}`} className="flex items-center justify-between gap-3 text-sm">
                                            <div className="min-w-0">
                                                <p className="truncate font-semibold text-slate-700">{row.label || 'Unknown'}</p>
                                                {row.amount ? <p className="text-xs text-slate-500">{Number(row.amount).toLocaleString()} paid volume</p> : null}
                                            </div>
                                            <span className="rounded-full border border-slate-200 bg-slate-50 px-2.5 py-1 text-xs font-semibold text-slate-600">
                                                {row.count || 0}
                                            </span>
                                        </div>
                                    )) : (
                                        <p className="text-sm text-slate-500">No signal yet.</p>
                                    )}
                                </div>
                            </div>
                        ))}
                    </div>
                </div>
            </section>

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

            <section className="rounded-xl border border-sky-200 bg-sky-50 p-5 shadow-sm shadow-slate-950/[0.02]">
                <div className="flex flex-col gap-4 lg:flex-row lg:items-start lg:justify-between">
                    <div>
                        <h4 className="text-base font-semibold text-slate-950">Readiness check</h4>
                        <p className="mt-1 max-w-3xl text-sm leading-6 text-slate-700">
                            Verify the full checkout path: CRM availability, pricing, provider runtime, inactive profile sample,
                            and the market WordPress contact-unlock proxy back to CRM.
                        </p>
                    </div>
                    <div className="flex flex-col gap-2 sm:flex-row sm:items-center">
                        <select
                            className="rounded-lg border border-sky-200 bg-white px-3 py-2 text-sm text-slate-900"
                            value={readinessMarketId}
                            onChange={(event) => setReadinessMarketId(event.target.value)}
                        >
                            <option value="all">All active markets</option>
                            {markets.map((market) => (
                                <option key={market.id} value={market.id}>{market.name}</option>
                            ))}
                        </select>
                        <button
                            type="button"
                            className="rounded-lg bg-sky-700 px-4 py-2 text-sm font-semibold text-white disabled:opacity-60"
                            disabled={readinessMutation.isPending}
                            onClick={() => readinessMutation.mutate()}
                        >
                            {readinessMutation.isPending ? 'Checking…' : 'Run readiness'}
                        </button>
                    </div>
                </div>

                {readinessResult ? (
                    <div className="mt-4 space-y-3">
                        <div className="flex flex-wrap gap-2 text-xs font-semibold">
                            <span className="rounded-full border border-slate-200 bg-white px-3 py-1 text-slate-600">
                                {readinessResult.summary?.markets_checked || 0} checked
                            </span>
                            <span className="rounded-full border border-emerald-200 bg-emerald-50 px-3 py-1 text-emerald-700">
                                {readinessResult.summary?.ready || 0} ready
                            </span>
                            <span className="rounded-full border border-amber-200 bg-amber-50 px-3 py-1 text-amber-700">
                                {readinessResult.summary?.warning || 0} warning
                            </span>
                            <span className="rounded-full border border-rose-200 bg-rose-50 px-3 py-1 text-rose-700">
                                {readinessResult.summary?.blocked || 0} blocked
                            </span>
                        </div>

                        {(readinessResult.markets || []).map((market) => (
                            <div key={market.platform_id} className="rounded-lg border border-sky-100 bg-white p-4">
                                <div className="flex flex-col gap-2 sm:flex-row sm:items-start sm:justify-between">
                                    <div>
                                        <h5 className="font-semibold text-slate-950">{market.name}</h5>
                                        <p className="mt-1 text-xs text-slate-500">
                                            {market.domain || 'No domain'} · {market.currency_code || 'No currency'}
                                            {market.sample_profile?.wp_post_id ? ` · sample #${market.sample_profile.wp_post_id}` : ''}
                                        </p>
                                    </div>
                                    <span className={`inline-flex rounded-full border px-3 py-1 text-xs font-semibold ${readinessStatusClasses(market.status)}`}>
                                        {titleize(market.status)}
                                    </span>
                                </div>

                                <div className="mt-3 grid gap-2 lg:grid-cols-2">
                                    {(market.checks || []).map((check) => (
                                        <div key={check.key} className={`rounded-lg border p-3 text-sm ${readinessStatusClasses(check.status)}`}>
                                            <div className="flex items-center justify-between gap-3">
                                                <span className="font-semibold">{check.label}</span>
                                                <span className="text-xs font-bold uppercase">{titleize(check.status)}</span>
                                            </div>
                                            <p className="mt-1 leading-5">{check.message}</p>
                                            {check.hint ? <p className="mt-2 text-xs font-semibold">{check.hint}</p> : null}
                                            {check.endpoint ? <p className="mt-2 break-all font-mono text-[11px] opacity-80">{check.endpoint}</p> : null}
                                            {check.http_status ? <p className="mt-1 text-xs opacity-80">HTTP {check.http_status} · {check.latency_ms || 0} ms</p> : null}
                                        </div>
                                    ))}
                                </div>
                            </div>
                        ))}
                    </div>
                ) : null}
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
                        {unlocksMeta.total || 0} records
                    </div>
                </div>

                <div className="mt-4 grid gap-3 rounded-xl border border-slate-200 bg-slate-50 p-3 xl:grid-cols-[minmax(220px,1.2fr)_repeat(5,minmax(140px,1fr))_auto]">
                    <label className="min-w-0 text-xs font-semibold uppercase tracking-[0.12em] text-slate-500">
                        Search
                        <input
                            className="mt-1 block w-full rounded-lg border border-slate-200 bg-white px-3 py-2 text-sm font-normal normal-case tracking-normal text-slate-900"
                            value={unlockFilters.search}
                            onChange={(event) => updateUnlockFilter('search', event.target.value)}
                            placeholder="Reference, visitor, profile"
                        />
                    </label>
                    <label className="text-xs font-semibold uppercase tracking-[0.12em] text-slate-500">
                        Market
                        <select
                            className="mt-1 block w-full rounded-lg border border-slate-200 bg-white px-3 py-2 text-sm font-normal normal-case tracking-normal text-slate-900"
                            value={unlockFilters.platform_id}
                            onChange={(event) => updateUnlockFilter('platform_id', event.target.value)}
                        >
                            <option value="all">All markets</option>
                            {markets.map((market) => (
                                <option key={market.id} value={market.id}>{market.name}</option>
                            ))}
                        </select>
                    </label>
                    <label className="text-xs font-semibold uppercase tracking-[0.12em] text-slate-500">
                        Entitlement
                        <select
                            className="mt-1 block w-full rounded-lg border border-slate-200 bg-white px-3 py-2 text-sm font-normal normal-case tracking-normal text-slate-900"
                            value={unlockFilters.status}
                            onChange={(event) => updateUnlockFilter('status', event.target.value)}
                        >
                            <option value="all">All statuses</option>
                            {UNLOCK_STATUS_OPTIONS.map((status) => (
                                <option key={status} value={status}>{titleize(status)}</option>
                            ))}
                        </select>
                    </label>
                    <label className="text-xs font-semibold uppercase tracking-[0.12em] text-slate-500">
                        Payment
                        <select
                            className="mt-1 block w-full rounded-lg border border-slate-200 bg-white px-3 py-2 text-sm font-normal normal-case tracking-normal text-slate-900"
                            value={unlockFilters.payment_status}
                            onChange={(event) => updateUnlockFilter('payment_status', event.target.value)}
                        >
                            <option value="all">All payments</option>
                            {PAYMENT_STATUS_OPTIONS.map((status) => (
                                <option key={status} value={status}>{titleize(status)}</option>
                            ))}
                        </select>
                    </label>
                    <label className="text-xs font-semibold uppercase tracking-[0.12em] text-slate-500">
                        Scope
                        <select
                            className="mt-1 block w-full rounded-lg border border-slate-200 bg-white px-3 py-2 text-sm font-normal normal-case tracking-normal text-slate-900"
                            value={unlockFilters.scope}
                            onChange={(event) => updateUnlockFilter('scope', event.target.value)}
                        >
                            <option value="all">All scopes</option>
                            {SCOPE_OPTIONS.map((scope) => (
                                <option key={scope.value} value={scope.value}>{scope.label}</option>
                            ))}
                        </select>
                    </label>
                    <label className="text-xs font-semibold uppercase tracking-[0.12em] text-slate-500">
                        Rows
                        <select
                            className="mt-1 block w-full rounded-lg border border-slate-200 bg-white px-3 py-2 text-sm font-normal normal-case tracking-normal text-slate-900"
                            value={unlockFilters.per_page}
                            onChange={(event) => updateUnlockFilter('per_page', Number(event.target.value))}
                        >
                            {PER_PAGE_OPTIONS.map((option) => (
                                <option key={option} value={option}>{option}</option>
                            ))}
                        </select>
                    </label>
                    <div className="flex items-end">
                        <button
                            type="button"
                            className="min-h-10 rounded-lg border border-slate-200 bg-white px-3 py-2 text-sm font-semibold text-slate-700 hover:border-slate-300 hover:text-slate-950"
                            onClick={resetUnlockFilters}
                        >
                            Reset
                        </button>
                    </div>
                </div>

                <div className="mt-4 overflow-hidden rounded-xl border border-slate-200">
                    <div className="hidden grid-cols-[92px_1fr_1fr_1.25fr_1.25fr] gap-4 border-b border-slate-200 bg-slate-50 px-4 py-3 text-[10px] font-semibold uppercase tracking-[0.14em] text-slate-500 xl:grid">
                        {[
                            ['id', 'Reference'],
                            ['profile', 'Profile'],
                            ['status', 'Status'],
                            ['amount', 'Payment'],
                            ['visitor', 'Visitor'],
                        ].map(([key, label]) => (
                            <button
                                key={key}
                                type="button"
                                className="flex items-center gap-2 text-left text-[10px] font-semibold uppercase tracking-[0.14em] text-slate-500 hover:text-slate-900"
                                onClick={() => toggleUnlockSort(key)}
                            >
                                <span>{label}</span>
                                <span className="rounded border border-slate-200 bg-white px-1.5 py-0.5 text-[9px] normal-case tracking-normal text-slate-500">
                                    {sortLabel(key, unlockFilters)}
                                </span>
                            </button>
                        ))}
                    </div>
                    <div className={`divide-y divide-slate-100 ${unlockQuery.isFetching ? 'opacity-60' : ''}`}>
                        {recentUnlocks.map((unlock) => {
                            const payment = unlock.payment || {};
                            const pricing = unlock.pricing || {};
                            const visitorContext = unlock.visitor_context || {};
                            const contextParts = visitorContextParts(visitorContext);
                            const paymentReference = payment.reference || (payment.id ? `#${payment.id}` : '-');
                            return (
                                <div key={unlock.id} className="grid gap-4 px-4 py-4 text-sm transition-colors hover:bg-slate-50/70 xl:grid-cols-[92px_1fr_1fr_1.25fr_1.25fr] xl:items-start">
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
                                        {Number(pricing.credit_amount || 0) > 0 ? (
                                            <p className="mt-1 text-xs font-semibold text-emerald-700">
                                                {formatCurrency(pricing.credit_amount, payment.currency || 'USD')} credited from earlier unlocks
                                            </p>
                                        ) : null}
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
                                        {unlock.visitor_email_masked ? (
                                            <p className="mt-1 text-xs text-slate-500">{unlock.visitor_email_masked}</p>
                                        ) : null}
                                        {contextParts.length ? (
                                            <div className="mt-2 flex flex-wrap gap-1.5">
                                                {contextParts.slice(0, 5).map((part) => (
                                                    <span key={part} className="rounded-md border border-slate-200 bg-white px-2 py-1 text-[11px] font-semibold text-slate-600">
                                                        {part}
                                                    </span>
                                                ))}
                                            </div>
                                        ) : null}
                                        {visitorContext.referrer_host ? (
                                            <p className="mt-2 truncate text-xs text-slate-500" title={`${visitorContext.referrer_host}${visitorContext.referrer_path || ''}`}>
                                                Ref {visitorContext.referrer_host}{visitorContext.referrer_path || ''}
                                            </p>
                                        ) : null}
                                        <p className="mt-2 text-xs text-slate-500">{shortDate(unlock.created_at)}</p>
                                    </div>
                                </div>
                            );
                        })}
                        {!recentUnlocks.length ? (
                            <div className="px-4 py-8 text-center text-sm text-slate-500">No visitor unlocks match these filters.</div>
                        ) : null}
                    </div>
                </div>

                <div className="mt-4 flex flex-col gap-3 text-sm text-slate-600 sm:flex-row sm:items-center sm:justify-between">
                    <p>
                        Showing {unlocksMeta.from || 0}-{unlocksMeta.to || 0} of {unlocksMeta.total || 0}
                    </p>
                    <div className="flex items-center gap-2">
                        <button
                            type="button"
                            className="rounded-lg border border-slate-200 bg-white px-3 py-2 font-semibold text-slate-700 disabled:cursor-not-allowed disabled:opacity-50"
                            disabled={Number(unlocksMeta.current_page || 1) <= 1}
                            onClick={() => setUnlockPage(Number(unlocksMeta.current_page || 1) - 1)}
                        >
                            Previous
                        </button>
                        <span className="min-w-24 text-center text-xs font-semibold uppercase tracking-[0.12em] text-slate-500">
                            Page {unlocksMeta.current_page || 1} / {unlocksMeta.last_page || 1}
                        </span>
                        <button
                            type="button"
                            className="rounded-lg border border-slate-200 bg-white px-3 py-2 font-semibold text-slate-700 disabled:cursor-not-allowed disabled:opacity-50"
                            disabled={Number(unlocksMeta.current_page || 1) >= Number(unlocksMeta.last_page || 1)}
                            onClick={() => setUnlockPage(Number(unlocksMeta.current_page || 1) + 1)}
                        >
                            Next
                        </button>
                    </div>
                </div>
            </section>
        </div>
    );
}
