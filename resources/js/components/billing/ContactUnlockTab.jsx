import React, { useEffect, useState } from 'react';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import api from '../../services/api';
import { useToast } from '../ToastProvider';
import { formatCurrency } from '../../utils/currency';
import ReportingCurrencyControl from '../ReportingCurrencyControl';
import FxNormalizationNotice from '../FxNormalizationNotice';
import useReportingCurrency from '../../hooks/useReportingCurrency';

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

const SECTION_LINKS = [
    { id: 'contact-unlock-revenue', label: 'Revenue' },
    { id: 'contact-unlock-signals', label: 'Demand' },
    { id: 'contact-unlock-readiness', label: 'Readiness' },
    { id: 'contact-unlock-availability', label: 'Availability' },
    { id: 'contact-unlock-pricing', label: 'Pricing' },
    { id: 'contact-unlock-trail', label: 'Trail' },
];

const DEFAULT_OPEN_SECTIONS = {
    signals: false,
    readiness: false,
    availability: false,
    pricing: false,
    trail: false,
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

function revenueDisplay({ rows = [], normalizedAmount, normalizedDisplay, normalizedCurrency, reporting, emptyLabel = 'No revenue yet' }) {
    if (reporting?.isFlat && normalizedAmount !== null && normalizedAmount !== undefined) {
        return {
            value: normalizedDisplay || formatCurrency(normalizedAmount, normalizedCurrency || reporting.targetCurrency),
            hint: `Normalized to ${normalizedCurrency || reporting.targetCurrency}`,
        };
    }

    return {
        value: moneyRowsLabel(rows, emptyLabel),
        hint: reporting?.isFlat ? 'Native value; FX incomplete' : 'Native currencies',
    };
}

function nativeRevenueCount(rows = []) {
    return rows.reduce((sum, entry) => sum + Number(entry.count || 0), 0);
}

function compactNumber(value) {
    return Number(value || 0).toLocaleString();
}

function MiniMetric({ label, value, hint, tone = 'slate' }) {
    const toneClasses = {
        slate: 'border-slate-200 bg-white text-slate-950',
        emerald: 'border-emerald-200 bg-emerald-50 text-emerald-950',
        amber: 'border-amber-200 bg-amber-50 text-amber-950',
        sky: 'border-sky-200 bg-sky-50 text-sky-950',
    };

    return (
        <div className={`rounded-lg border p-3 ${toneClasses[tone] || toneClasses.slate}`}>
            <p className="text-xs font-semibold text-slate-500">{label}</p>
            <p className="mt-1 text-xl font-semibold tabular-nums">{value}</p>
            {hint ? <p className="mt-1 text-xs leading-5 text-slate-500">{hint}</p> : null}
        </div>
    );
}

function FunnelStep({ label, count, rate, tone = 'slate' }) {
    const barTone = {
        slate: 'accent-slate-700',
        teal: 'accent-teal-700',
        emerald: 'accent-emerald-600',
        amber: 'accent-amber-500',
    }[tone] || 'accent-slate-700';
    const width = Math.max(4, Math.min(100, Number(rate || 0)));

    return (
        <div className="rounded-lg border border-slate-200 bg-white p-3">
            <div className="flex items-baseline justify-between gap-3">
                <p className="text-xs font-semibold text-slate-500">{label}</p>
                <p className="text-sm font-semibold tabular-nums text-slate-900">{compactNumber(count)}</p>
            </div>
            <progress className={`mt-3 h-2 w-full overflow-hidden rounded-full bg-slate-100 ${barTone}`} value={width} max="100" aria-label={`${label} rate`} />
            <p className="mt-2 text-xs text-slate-500">{percentLabel(rate)} rate</p>
        </div>
    );
}

function SectionNav() {
    return (
        <nav className="sticky top-2 z-10 overflow-x-auto rounded-xl border border-slate-200 bg-white/95 p-1 shadow-sm shadow-slate-950/[0.03] backdrop-blur" aria-label="Contact unlock sections">
            <div className="flex min-w-max gap-1">
                {SECTION_LINKS.map((item) => (
                    <a
                        key={item.id}
                        href={`#${item.id}`}
                        className="rounded-lg px-3 py-2 text-sm font-semibold text-slate-600 transition hover:bg-slate-100 hover:text-slate-950 focus:outline-none focus-visible:ring-2 focus-visible:ring-teal-500"
                    >
                        {item.label}
                    </a>
                ))}
            </div>
        </nav>
    );
}

function WorkSection({ id, title, description, summary, open, onToggle, children, actions, tone = 'slate' }) {
    const toneClasses = {
        slate: 'border-slate-200 bg-white',
        sky: 'border-sky-200 bg-sky-50',
        amber: 'border-amber-200 bg-amber-50',
    };

    return (
        <section id={id} className={`scroll-mt-24 rounded-xl border p-4 shadow-sm shadow-slate-950/[0.02] ${toneClasses[tone] || toneClasses.slate}`}>
            <div className="flex flex-col gap-4 lg:flex-row lg:items-start lg:justify-between">
                <div className="min-w-0">
                    <h3 className="text-lg font-semibold text-slate-950">{title}</h3>
                    {description ? <p className="mt-1 max-w-3xl text-sm leading-6 text-slate-600">{description}</p> : null}
                    {summary ? <p className="mt-2 text-sm font-semibold text-slate-700">{summary}</p> : null}
                </div>
                <div className="flex shrink-0 flex-wrap items-center gap-2">
                    {actions}
                    <button
                        type="button"
                        className="min-h-10 rounded-lg border border-slate-200 bg-white px-3 py-2 text-sm font-semibold text-slate-700 transition hover:border-slate-300 hover:text-slate-950 focus:outline-none focus-visible:ring-2 focus-visible:ring-teal-500"
                        aria-expanded={open}
                        aria-controls={`${id}-panel`}
                        onClick={onToggle}
                    >
                        {open ? 'Hide' : 'Open'} <span aria-hidden="true">{open ? '-' : '+'}</span>
                    </button>
                </div>
            </div>
            {open ? (
                <div id={`${id}-panel`} className="mt-4">
                    {children}
                </div>
            ) : null}
        </section>
    );
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
    const [openSections, setOpenSections] = useState(DEFAULT_OPEN_SECTIONS);
    const reportingCurrency = useReportingCurrency({ preferFlat: true });
    const unlockQueryKey = ['billing-contact-unlock', unlockFilters, reportingCurrency.displayMode, reportingCurrency.targetCurrency];

    const unlockQuery = useQuery({
        queryKey: unlockQueryKey,
        queryFn: () => api.get('/crm/settings/billing/contact-unlock', {
            params: {
                ...buildUnlockParams(unlockFilters),
                ...reportingCurrency.queryParams,
            },
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
        queryKey: ['billing-contact-unlock-pulse', pulseRange, pulseMarketId, reportingCurrency.targetCurrency],
        queryFn: () => api.get('/crm/settings/billing/contact-unlock/pulse', {
            params: {
                range: pulseRange,
                ...(pulseMarketId !== 'all' ? { platform_id: Number(pulseMarketId) } : {}),
                reporting_currency: reportingCurrency.targetCurrency,
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
    const pulseRevenueDisplay = revenueDisplay({
        rows: pulseRevenue,
        normalizedAmount: pulseKpis.revenue_normalized,
        normalizedDisplay: pulseKpis.revenue_normalized_display,
        normalizedCurrency: pulseKpis.normalized_currency || reportingCurrency.targetCurrency,
        reporting: reportingCurrency,
    });
    const summaryRevenueDisplay = revenueDisplay({
        rows: summary.confirmed_revenue_native || [],
        normalizedAmount: summary.confirmed_revenue_normalized,
        normalizedDisplay: summary.confirmed_revenue_normalized_display,
        normalizedCurrency: summary.normalized_currency || reportingCurrency.targetCurrency,
        reporting: reportingCurrency,
        emptyLabel: 'No confirmed revenue yet',
    });
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
            queryClient.setQueryData(unlockQueryKey, payload);
            queryClient.invalidateQueries({ queryKey: ['billing-contact-unlock'] });
            toast.success('Contact unlock settings saved.');
        },
        onError: (error) => toast.error(firstErrorMessage(error)),
    });

    const deleteMutation = useMutation({
        mutationFn: (ruleId) => api.delete(`/crm/settings/billing/contact-unlock/rules/${ruleId}`).then((response) => response.data),
        onSuccess: (payload) => {
            queryClient.setQueryData(unlockQueryKey, payload);
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

    function toggleSection(section) {
        setOpenSections((current) => ({
            ...current,
            [section]: !current[section],
        }));
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
        <div className="space-y-4 p-4 sm:p-5">
            <section id="contact-unlock-revenue" className="scroll-mt-24 overflow-hidden rounded-xl border border-slate-200 bg-white shadow-sm shadow-slate-950/[0.02]">
                <div className="bg-slate-950 px-4 py-4 text-white sm:px-5">
                    <div className="flex flex-col gap-4 xl:flex-row xl:items-end xl:justify-between">
                        <div className="max-w-3xl">
                            <p className="text-xs font-semibold text-teal-200">Contact unlock workspace</p>
                            <h3 className="mt-2 text-2xl font-semibold leading-tight sm:text-3xl">
                                Revenue, funnel health, and checkout control in one operating view
                            </h3>
                            <p className="mt-2 text-sm leading-6 text-slate-300">
                                Confirmed visitor unlock payments stay separate from subscription revenue, while the setup controls stay tucked away until you need them.
                            </p>
                        </div>
                        <div className="flex flex-col gap-2 lg:items-end">
                            <ReportingCurrencyControl reporting={reportingCurrency} className="justify-start lg:justify-end" />
                            <div className="flex flex-col gap-2 sm:flex-row">
                                <select
                                    className="min-h-11 rounded-lg border border-white/15 bg-white/10 px-3 py-2 text-sm font-semibold text-white focus:outline-none focus-visible:ring-2 focus-visible:ring-teal-300"
                                    value={pulseMarketId}
                                    onChange={(event) => setPulseMarketId(event.target.value)}
                                    aria-label="Contact unlock market"
                                >
                                    <option className="text-slate-900" value="all">All markets</option>
                                    {markets.map((market) => (
                                        <option className="text-slate-900" key={market.id} value={market.id}>{market.name}</option>
                                    ))}
                                </select>
                                <div className="inline-flex min-h-11 rounded-lg border border-white/15 bg-white/10 p-1">
                                    {PULSE_RANGE_OPTIONS.map((option) => (
                                        <button
                                            key={option.value}
                                            type="button"
                                            className={`rounded-md px-3 py-2 text-sm font-semibold transition focus:outline-none focus-visible:ring-2 focus-visible:ring-teal-300 ${pulseRange === option.value ? 'bg-white text-slate-950 shadow-sm' : 'text-slate-300 hover:text-white'}`}
                                            onClick={() => setPulseRange(option.value)}
                                        >
                                            {option.label}
                                        </button>
                                    ))}
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <div className={`grid gap-4 p-4 sm:p-5 xl:grid-cols-[minmax(0,1.15fr)_minmax(360px,0.85fr)] ${pulseQuery.isFetching || unlockQuery.isFetching ? 'opacity-70' : ''}`}>
                    <div className="rounded-xl border border-emerald-200 bg-emerald-50 p-4">
                        <p className="text-sm font-semibold text-emerald-800">Confirmed unlock revenue</p>
                        <p className="mt-2 text-3xl font-semibold leading-tight text-emerald-950 sm:text-4xl">
                            {summaryRevenueDisplay.value}
                        </p>
                        <div className="mt-2 flex flex-wrap items-center gap-2 text-sm text-emerald-800">
                            <span>{summaryRevenueDisplay.hint}</span>
                            <span>{nativeRevenueCount(summary.confirmed_revenue_native || [])} completed payments</span>
                            <FxNormalizationNotice meta={reportingCurrency.isFlat ? summary.confirmed_revenue_normalization_meta : null} />
                        </div>
                        <div className="mt-4 grid gap-3 sm:grid-cols-3">
                            <MiniMetric label="Active unlocks" value={compactNumber(summary.active_unlocks)} tone="emerald" />
                            <MiniMetric label="Pending payment" value={compactNumber(summary.pending_unlocks)} tone={Number(summary.pending_unlocks || 0) > 0 ? 'amber' : 'slate'} />
                            <MiniMetric label="Total unlocks" value={compactNumber(summary.total_unlocks)} />
                        </div>
                    </div>

                    <div className="rounded-xl border border-slate-200 bg-slate-50 p-4">
                        <div className="flex flex-col gap-1 sm:flex-row sm:items-start sm:justify-between">
                            <div>
                                <p className="text-sm font-semibold text-slate-700">Selected window revenue</p>
                                <p className="mt-1 text-2xl font-semibold tabular-nums text-slate-950">{pulseRevenueDisplay.value}</p>
                            </div>
                            <div className="rounded-lg border border-slate-200 bg-white px-3 py-2 text-xs font-semibold text-slate-600">
                                {pulseRevenueDisplay.hint}
                            </div>
                        </div>
                        <FxNormalizationNotice meta={reportingCurrency.isFlat ? pulseKpis.revenue_normalization_meta : null} className="mt-2" />
                        <div className="mt-4 grid gap-3 sm:grid-cols-2">
                            <MiniMetric
                                label="AOV"
                                value={reportingCurrency.isFlat && pulseKpis.average_order_value_normalized !== null && pulseKpis.average_order_value_normalized !== undefined
                                    ? formatCurrency(pulseKpis.average_order_value_normalized, pulseKpis.normalized_currency || reportingCurrency.targetCurrency)
                                    : moneyRowsLabel(pulseAverageOrderValue, '-')}
                                hint={`${compactNumber(pulseKpis.successful_payments)} successful`}
                            />
                            <MiniMetric
                                label="Repeat and upgrades"
                                value={`${percentLabel(pulseKpis.repeat_buyer_percent)} / ${percentLabel(pulseKpis.upgrade_rate_percent)}`}
                                hint="Repeat buyers / upgrades"
                            />
                        </div>
                    </div>

                    <div className="xl:col-span-2">
                        <div className="grid gap-3 sm:grid-cols-2 xl:grid-cols-4">
                            <FunnelStep label="Eligible views" count={pulseKpis.eligible_profile_views} rate={100} tone="slate" />
                            <FunnelStep label="CTA clicks" count={pulseKpis.unlock_cta_clicks} rate={pulseKpis.cta_rate_percent} tone="teal" />
                            <FunnelStep label="Checkout starts" count={pulseKpis.checkout_starts} rate={pulseKpis.checkout_rate_percent} tone="amber" />
                            <FunnelStep label="Paid unlocks" count={pulseKpis.successful_payments} rate={pulseKpis.payment_completion_percent} tone="emerald" />
                        </div>
                    </div>
                </div>
            </section>

            <SectionNav />

            <WorkSection
                id="contact-unlock-signals"
                title="Demand patterns"
                description="Open this when you need market, profile, source, and hour context behind the revenue."
                summary={`${compactNumber(pulseKpis.renewed_after_paid_demand)} inactive advertisers renewed after paid-contact demand`}
                open={openSections.signals}
                onToggle={() => toggleSection('signals')}
            >
                <div className="grid gap-4 xl:grid-cols-4">
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
                <div className="mt-4 grid gap-3 sm:grid-cols-2 xl:grid-cols-4">
                    <MiniMetric label="Unlock conversion" value={percentLabel(pulseKpis.unlock_conversion_percent)} />
                    <MiniMetric label="Pending payments" value={compactNumber(pulseKpis.pending_payments)} tone={Number(pulseKpis.pending_payments || 0) > 0 ? 'amber' : 'slate'} />
                    <MiniMetric label="Single-profile purchases" value={compactNumber(pulseKpis.single_profile_purchases)} />
                    <MiniMetric label="Full-access purchases" value={compactNumber(pulseKpis.full_access_purchases)} />
                    <MiniMetric label="Revenue per view" value={moneyRowsLabel(pulseRevenuePerView, '-')} />
                </div>
            </WorkSection>

            <WorkSection
                id="contact-unlock-readiness"
                title="Readiness check"
                description="Verify CRM availability, pricing, provider runtime, inactive profile sample, and the WordPress proxy."
                summary={readinessResult ? `${readinessResult.summary?.ready || 0} ready, ${readinessResult.summary?.warning || 0} warning, ${readinessResult.summary?.blocked || 0} blocked` : 'Run only when setup or provider routing changes.'}
                open={openSections.readiness}
                onToggle={() => toggleSection('readiness')}
                tone="sky"
                actions={(
                    <>
                        <select
                            className="min-h-10 rounded-lg border border-sky-200 bg-white px-3 py-2 text-sm text-slate-900 focus:outline-none focus-visible:ring-2 focus-visible:ring-sky-500"
                            value={readinessMarketId}
                            onChange={(event) => setReadinessMarketId(event.target.value)}
                            aria-label="Readiness market"
                        >
                            <option value="all">All active markets</option>
                            {markets.map((market) => (
                                <option key={market.id} value={market.id}>{market.name}</option>
                            ))}
                        </select>
                        <button
                            type="button"
                            className="min-h-10 rounded-lg bg-sky-700 px-4 py-2 text-sm font-semibold text-white transition hover:bg-sky-800 focus:outline-none focus-visible:ring-2 focus-visible:ring-sky-500 disabled:opacity-60"
                            disabled={readinessMutation.isPending}
                            onClick={() => readinessMutation.mutate()}
                        >
                            {readinessMutation.isPending ? 'Checking…' : 'Run readiness'}
                        </button>
                    </>
                )}
            >
                {readinessResult ? (
                    <div className="space-y-3">
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
                ) : (
                    <div className="rounded-lg border border-sky-100 bg-white p-4 text-sm text-slate-600">
                        Pick a market and run readiness to see pass, warning, and blocked checks here.
                    </div>
                )}
            </WorkSection>

            <WorkSection
                id="contact-unlock-availability"
                title="Availability"
                description="Enable the paywall only on markets with mobile-money rails and active pricing."
                summary={`${marketIds.length} of ${markets.length} markets enabled. ${sandboxOnly ? 'Sandbox only' : 'Production/default'} checkout mode.`}
                open={openSections.availability}
                onToggle={() => toggleSection('availability')}
                actions={(
                    <label className="inline-flex min-h-10 cursor-pointer items-center gap-3 rounded-lg border border-slate-200 bg-white px-3 py-2 text-sm font-semibold text-slate-700">
                        <input
                            type="checkbox"
                            className="h-4 w-4 rounded border-slate-300 text-teal-600"
                            checked={enabled}
                            onChange={(event) => setEnabled(event.target.checked)}
                        />
                        Feature enabled
                    </label>
                )}
            >
                <div className="grid gap-3 rounded-lg border border-amber-200 bg-amber-50 p-4 lg:grid-cols-[minmax(0,1fr)_auto] lg:items-center">
                    <div>
                        <h5 className="text-sm font-semibold text-amber-950">Checkout environment</h5>
                        <p className="mt-1 text-sm text-amber-800">
                            Production provider profiles need production/default mode. Sandbox-only mode is for test profiles and will not use the live Kenya pawaPay profile.
                        </p>
                    </div>
                    <div className="inline-flex rounded-lg border border-amber-200 bg-white p-1 text-sm font-semibold shadow-sm">
                        <button
                            type="button"
                            className={`min-h-10 rounded-md px-4 py-2 transition focus:outline-none focus-visible:ring-2 focus-visible:ring-amber-500 ${!sandboxOnly ? 'bg-slate-950 text-white shadow-sm' : 'text-slate-600 hover:text-slate-950'}`}
                            onClick={() => setSandboxOnly(false)}
                        >
                            Production/default
                        </button>
                        <button
                            type="button"
                            className={`min-h-10 rounded-md px-4 py-2 transition focus:outline-none focus-visible:ring-2 focus-visible:ring-amber-500 ${sandboxOnly ? 'bg-slate-950 text-white shadow-sm' : 'text-slate-600 hover:text-slate-950'}`}
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
                            className={`flex min-w-0 cursor-pointer items-center justify-between gap-3 rounded-lg border px-3 py-3 text-sm transition ${marketIds.includes(Number(market.id)) ? 'border-teal-300 bg-teal-50' : 'border-slate-200 bg-white hover:border-slate-300'}`}
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
            </WorkSection>

            <WorkSection
                id="contact-unlock-pricing"
                title="Pricing rules"
                description="Configure one-time and all-inactive access per market."
                summary={`${rules.filter((rule) => rule.is_active).length} active rules across ${new Set(rules.map((rule) => rule.platform_id).filter(Boolean)).size} markets`}
                open={openSections.pricing}
                onToggle={() => toggleSection('pricing')}
                actions={(
                    <button
                        type="button"
                        className="min-h-10 rounded-lg bg-slate-950 px-4 py-2 text-sm font-semibold text-white transition hover:bg-slate-800 focus:outline-none focus-visible:ring-2 focus-visible:ring-slate-500"
                        onClick={() => setRules((current) => [...current, blankRule(markets)])}
                    >
                        Add rule
                    </button>
                )}
            >
                <div className="space-y-3">
                    {rules.map((rule, index) => (
                        <div key={`${rule.id || 'new'}-${index}`} className="grid gap-3 rounded-lg border border-slate-200 p-4 xl:grid-cols-[1.15fr_1fr_.7fr_.7fr_.55fr_auto]">
                            <select
                                className="min-h-11 rounded-lg border border-slate-200 px-3 py-2 text-sm focus:outline-none focus-visible:ring-2 focus-visible:ring-teal-500"
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
                                className="min-h-11 rounded-lg border border-slate-200 px-3 py-2 text-sm focus:outline-none focus-visible:ring-2 focus-visible:ring-teal-500"
                                value={rule.scope}
                                onChange={(event) => updateRule(index, { scope: event.target.value })}
                            >
                                {SCOPE_OPTIONS.map((scope) => (
                                    <option key={scope.value} value={scope.value}>{scope.label}</option>
                                ))}
                            </select>
                            <input
                                className="min-h-11 rounded-lg border border-slate-200 px-3 py-2 text-sm focus:outline-none focus-visible:ring-2 focus-visible:ring-teal-500"
                                value={rule.label}
                                onChange={(event) => updateRule(index, { label: event.target.value })}
                                placeholder="Display label"
                                aria-label="Display label"
                            />
                            <div className="grid grid-cols-[72px_1fr] gap-2">
                                <input
                                    className="min-h-11 rounded-lg border border-slate-200 px-3 py-2 text-sm uppercase focus:outline-none focus-visible:ring-2 focus-visible:ring-teal-500"
                                    value={rule.currency}
                                    onChange={(event) => updateRule(index, { currency: event.target.value.toUpperCase().slice(0, 3) })}
                                    aria-label="Currency"
                                />
                                <input
                                    className="min-h-11 rounded-lg border border-slate-200 px-3 py-2 text-sm focus:outline-none focus-visible:ring-2 focus-visible:ring-teal-500"
                                    type="number"
                                    min="1"
                                    value={rule.amount}
                                    onChange={(event) => updateRule(index, { amount: event.target.value })}
                                    placeholder="Amount"
                                    aria-label="Amount"
                                />
                            </div>
                            <input
                                className="min-h-11 rounded-lg border border-slate-200 px-3 py-2 text-sm focus:outline-none focus-visible:ring-2 focus-visible:ring-teal-500"
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
                                    className="min-h-10 rounded-lg border border-rose-200 px-3 py-2 text-sm font-semibold text-rose-700 transition hover:bg-rose-50 focus:outline-none focus-visible:ring-2 focus-visible:ring-rose-500"
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
                        className="min-h-11 rounded-lg bg-teal-700 px-5 py-2.5 text-sm font-semibold text-white transition hover:bg-teal-800 focus:outline-none focus-visible:ring-2 focus-visible:ring-teal-500 disabled:opacity-60"
                        disabled={saveMutation.isPending}
                        onClick={() => saveMutation.mutate()}
                    >
                        {saveMutation.isPending ? 'Saving…' : 'Save contact unlock'}
                    </button>
                </div>
            </WorkSection>

            <WorkSection
                id="contact-unlock-trail"
                title="Live checkout trail"
                description="Search recent visitor attempts, payment outcomes, references, and browser context."
                summary={`Showing ${unlocksMeta.from || 0}-${unlocksMeta.to || 0} of ${unlocksMeta.total || 0}`}
                open={openSections.trail}
                onToggle={() => toggleSection('trail')}
                actions={(
                    <div className="rounded-lg border border-slate-200 bg-slate-50 px-3 py-2 text-xs font-semibold text-slate-600">
                        {unlocksMeta.total || 0} records
                    </div>
                )}
            >
                <div className="grid gap-3 rounded-xl border border-slate-200 bg-slate-50 p-3 xl:grid-cols-[minmax(220px,1.2fr)_repeat(5,minmax(140px,1fr))_auto]">
                    <label className="min-w-0 text-xs font-semibold uppercase tracking-normal text-slate-500">
                        Search
                        <input
                            className="mt-1 block w-full rounded-lg border border-slate-200 bg-white px-3 py-2 text-sm font-normal normal-case tracking-normal text-slate-900"
                            value={unlockFilters.search}
                            onChange={(event) => updateUnlockFilter('search', event.target.value)}
                            placeholder="Reference, visitor, profile"
                        />
                    </label>
                    <label className="text-xs font-semibold uppercase tracking-normal text-slate-500">
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
                    <label className="text-xs font-semibold uppercase tracking-normal text-slate-500">
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
                    <label className="text-xs font-semibold uppercase tracking-normal text-slate-500">
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
                    <label className="text-xs font-semibold uppercase tracking-normal text-slate-500">
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
                    <label className="text-xs font-semibold uppercase tracking-normal text-slate-500">
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
                            className="min-h-10 rounded-lg border border-slate-200 bg-white px-3 py-2 text-sm font-semibold text-slate-700 transition hover:border-slate-300 hover:text-slate-950 focus:outline-none focus-visible:ring-2 focus-visible:ring-teal-500"
                            onClick={resetUnlockFilters}
                        >
                            Reset
                        </button>
                    </div>
                </div>

                <div className="mt-4 overflow-hidden rounded-xl border border-slate-200">
                    <div className="hidden grid-cols-[92px_1fr_1fr_1.25fr_1.25fr] gap-4 border-b border-slate-200 bg-slate-50 px-4 py-3 text-[10px] font-semibold uppercase tracking-normal text-slate-500 xl:grid">
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
                                className="flex items-center gap-2 text-left text-[10px] font-semibold uppercase tracking-normal text-slate-500 hover:text-slate-900"
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
                            className="min-h-10 rounded-lg border border-slate-200 bg-white px-3 py-2 font-semibold text-slate-700 transition hover:border-slate-300 focus:outline-none focus-visible:ring-2 focus-visible:ring-teal-500 disabled:cursor-not-allowed disabled:opacity-50"
                            disabled={Number(unlocksMeta.current_page || 1) <= 1}
                            onClick={() => setUnlockPage(Number(unlocksMeta.current_page || 1) - 1)}
                        >
                            Previous
                        </button>
                        <span className="min-w-24 text-center text-xs font-semibold uppercase tracking-normal text-slate-500">
                            Page {unlocksMeta.current_page || 1} / {unlocksMeta.last_page || 1}
                        </span>
                        <button
                            type="button"
                            className="min-h-10 rounded-lg border border-slate-200 bg-white px-3 py-2 font-semibold text-slate-700 transition hover:border-slate-300 focus:outline-none focus-visible:ring-2 focus-visible:ring-teal-500 disabled:cursor-not-allowed disabled:opacity-50"
                            disabled={Number(unlocksMeta.current_page || 1) >= Number(unlocksMeta.last_page || 1)}
                            onClick={() => setUnlockPage(Number(unlocksMeta.current_page || 1) + 1)}
                        >
                            Next
                        </button>
                    </div>
                </div>
            </WorkSection>
        </div>
    );
}
