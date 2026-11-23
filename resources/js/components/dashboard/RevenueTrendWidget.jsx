import React, { useEffect, useMemo, useRef, useState } from 'react';
import {
    Area,
    AreaChart,
    CartesianGrid,
    Customized,
    Line,
    ResponsiveContainer,
    Tooltip,
    XAxis,
    YAxis,
} from 'recharts';
import ProductRevenueView, { cashMoney, CashState } from './ProductRevenueView';
import { RevenueProductSelector, RevenueCollectionSummary, RevenueChartOptions, collectionWindowLabel } from './RevenueCollectionsControls';
import SectionFrame from '../SectionFrame';
import { formatCurrency } from '../../utils/currency';
import CustomerMixView from './CustomerMixView';
import PeakHoursHeatmap from './PeakHoursHeatmap';

const METRICS = [
    { key: 'revenue', label: 'Revenue' },
    { key: 'payments', label: 'Payments' },
    { key: 'average_ticket', label: 'Avg ticket' },
];

const BUCKETS = [
    { key: 'auto', label: 'Auto' },
    { key: 'day', label: 'Daily' },
    { key: 'week', label: 'Weekly' },
    { key: 'month', label: 'Monthly' },
];

const VIEWS = [
    { key: 'trend', label: 'Trend' },
    { key: 'peak', label: 'Peak hours' },
    { key: 'mix', label: 'Customer mix' },
    { key: 'sources', label: 'Where revenue comes from' },
];

const WEEKDAY_LABELS = ['Sunday', 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday'];

function EmptyState({ message }) {
    return (
        <div className="flex h-72 items-center justify-center rounded-lg border border-dashed border-slate-200 bg-slate-50 px-4 text-center text-sm text-slate-500">
            {message}
        </div>
    );
}

function valueForMetric(point, metric, prefix = '') {
    const key = prefix ? `${prefix}_${metric}` : metric;
    if (metric === 'revenue') {
        const value = prefix ? point.prior_value : point.value;
        return value == null ? null : Number(value);
    }

    return point[key] == null ? null : Number(point[key]);
}

function formatMetricValue(value, metric, currency) {
    if (metric === 'revenue' || metric === 'average_ticket') {
        return value == null ? 'FX unavailable' : formatCurrency(value, currency);
    }

    return Number(value || 0).toLocaleString();
}

function weekdayKey(label) {
    if (!/^\d{4}-\d{2}-\d{2}$/.test(String(label || ''))) return null;
    const [year, month, day] = label.split('-').map((part) => Number(part));
    const date = new Date(year, month - 1, day);

    return Number.isNaN(date.getTime()) ? null : date.getDay();
}

function parseDayLabel(label) {
    const match = /^(\d{4})-(\d{2})-(\d{2})$/.exec(String(label || ''));
    if (!match) return null;
    const date = new Date(Number(match[1]), Number(match[2]) - 1, Number(match[3]));
    return Number.isNaN(date.getTime()) ? null : date;
}

// Daily ticks get a subtle weekday under the date ("17 Jul" / "Fri") for faster reading; hourly
// and week/month labels render unchanged.
function DayAxisTick({ x, y, payload }) {
    const label = String(payload?.value ?? '');
    const date = parseDayLabel(label);
    if (!date) {
        return (
            <text x={x} y={y} dy={12} textAnchor="middle" fontSize={11} fill="#64748b">{label}</text>
        );
    }
    const dateLine = date.toLocaleDateString(undefined, { day: 'numeric', month: 'short' });
    const weekday = date.toLocaleDateString(undefined, { weekday: 'short' });
    return (
        <text x={x} y={y} textAnchor="middle">
            <tspan x={x} dy={12} fontSize={11} fill="#475569" fontWeight={600}>{dateLine}</tspan>
            <tspan x={x} dy={13} fontSize={10} fill="#94a3b8">{weekday}</tspan>
        </text>
    );
}

function formatTooltipLabel(label) {
    const date = parseDayLabel(label);
    if (!date) return label;
    return date.toLocaleDateString(undefined, { weekday: 'long', day: 'numeric', month: 'short', year: 'numeric' });
}

function WeekdayBands({ xAxisMap, offset, points, selectedWeekday }) {
    if (selectedWeekday === null || selectedWeekday === undefined) return null;
    const xAxis = Object.values(xAxisMap || {})[0];
    const scale = xAxis?.scale;
    if (typeof scale !== 'function' || !offset) return null;

    const bandWidth = typeof scale.bandwidth === 'function'
        ? scale.bandwidth()
        : Math.max(12, Number(offset.width || 0) / Math.max(points.length, 1));

    return (
        <g aria-hidden="true">
            {points
                .filter((point) => point.weekday === selectedWeekday)
                .map((point) => {
                    const x = scale(point.label);
                    if (!Number.isFinite(x)) return null;

                    return (
                        <rect
                            key={`weekday-band-${point.label}`}
                            x={x - bandWidth / 2}
                            y={offset.top}
                            width={bandWidth}
                            height={offset.height}
                            rx={6}
                            fill="#0f766e"
                            opacity={point.is_selected_day ? 0.12 : 0.065}
                        />
                    );
                })}
        </g>
    );
}

function weekdayDot(selectedWeekday) {
    return function renderWeekdayDot({ cx, cy, payload }) {
        if (selectedWeekday === null || payload?.weekday !== selectedWeekday) return null;

        return (
            <g>
                {payload.is_selected_day ? (
                    <circle cx={cx} cy={cy} r={7} fill="#ffffff" stroke="#0f766e" strokeWidth={2.5} />
                ) : null}
                <circle cx={cx} cy={cy} r={payload.is_selected_day ? 3.5 : 3} fill="#0f766e" stroke="#ffffff" strokeWidth={1.5} />
            </g>
        );
    };
}

function TrendTooltip({ active, payload, label, currency, metric }) {
    if (!active || !payload?.length || payload[0]?.payload?.future) return null;
    const point = payload[0]?.payload || {};
    const current = valueForMetric(point, metric);
    const prior = valueForMetric(point, metric, 'prior');

    return (
        <div className="rounded-lg border border-slate-200 bg-white p-3 shadow-xl">
            <p className="text-xs font-semibold text-slate-500">{formatTooltipLabel(label)}</p>
            <p className="mt-1 text-sm font-semibold text-slate-900">Current: {formatMetricValue(current, metric, currency)}</p>
            <p className="text-sm font-medium text-slate-500">Prior: {formatMetricValue(prior, metric, currency)}</p>
            <p className="mt-2 text-xs text-slate-500">
                {Number(point.payments_count || 0).toLocaleString()} payments · Avg {formatMetricValue(point.average_ticket, 'average_ticket', currency)}
            </p>
        </div>
    );
}

export default function RevenueTrendWidget({
    data,
    isLoading,
    errorMessage,
    currency = 'USD',
    metric = 'revenue',
    onMetricChange,
    bucket = 'auto',
    onBucketChange,
    showComparison = true,
    onShowComparisonChange,
    customerMix,
    view = 'trend',
    onViewChange,
    peakHoursData,
    peakHoursLoading = false,
    peakHoursError = null,
    productRevenue, productLoading, productError, onRetryRevenue, displayMode,
    collections, product = 'all', onProductChange, asOf, onRetryCollections,
}) {
    const [selectedDay, setSelectedDay] = useState(null);
    const [optionsOpen, setOptionsOpen] = useState(false);
    const optionsButton = useRef(null);
    const [reducedMotion, setReducedMotion] = useState(() => typeof window !== 'undefined' && window.matchMedia('(prefers-reduced-motion: reduce)').matches);
    useEffect(() => {
        const query = window.matchMedia('(prefers-reduced-motion: reduce)');
        const update = () => setReducedMotion(query.matches);
        query.addEventListener('change', update);
        return () => query.removeEventListener('change', update);
    }, []);
    const rawPoints = data?.points || [];
    const activeBucket = data?.bucket || 'auto';
    const isHourly = activeBucket === 'hour';
    const points = rawPoints.map((point) => {
        const weekday = weekdayKey(point.label);
        // In the hourly (single-day) view, hours after "now" have no data yet — null them so the
        // current line ends at the present hour instead of crashing to zero, while the prior-day
        // ghost keeps its full curve as the pace to beat.
        const isFuture = Boolean(point.future);

        return {
            ...point,
            weekday,
            is_selected_day: selectedDay === point.label,
            revenue: isFuture || point.value == null ? null : Number(point.value),
            prior_revenue: isFuture || point.prior_value == null ? null : Number(point.prior_value),
            payments: isFuture ? null : Number(point.payments_count || 0),
            prior_payments: isFuture ? null : Number(point.prior_payments_count || 0),
            average_ticket: isFuture || point.average_ticket == null ? null : Number(point.average_ticket),
            prior_average_ticket: isFuture || point.prior_average_ticket == null ? null : Number(point.prior_average_ticket),
        };
    });
    const selectedWeekday = selectedDay ? weekdayKey(selectedDay) : null;
    const selectedWeekdayPoints = useMemo(() => (
        selectedWeekday === null
            ? []
            : points.filter((point) => point.weekday === selectedWeekday)
    ), [points, selectedWeekday]);
    const weekdaySelectionEnabled = activeBucket === 'day';
    const hasData = points.some((point) => Number(point.payments_count || 0) > 0 || Number(point.prior_payments_count || 0) > 0);
    const currentKey = metric === 'revenue' ? 'revenue' : metric;
    const priorKey = `prior_${currentKey}`;
    const activeView = VIEWS.find((item) => item.key === view)?.key || 'trend';
    const productView = activeView === 'trend' || activeView === 'peak';
    const chartMeasure = metric === 'payments' ? 'Payments' : `${metric === 'average_ticket' ? 'Avg ticket' : 'Revenue'} · ${currency}`;
    const chartGrouping = isHourly ? 'Hourly' : BUCKETS.find((item) => item.key === activeBucket)?.label || activeBucket;
    const closeOptions = () => { setOptionsOpen(false); optionsButton.current?.focus(); };

    useEffect(() => {
        if (data && !weekdaySelectionEnabled && selectedDay !== null) {
            setSelectedDay(null);
        }
    }, [data, selectedDay, weekdaySelectionEnabled]);

    const handleChartClick = (state) => {
        if (!weekdaySelectionEnabled) return;
        const point = state?.activePayload?.[0]?.payload;
        const nextDay = point?.label;
        if (!nextDay || weekdayKey(nextDay) === null) return;

        setSelectedDay((current) => current === nextDay ? null : nextDay);
    };

    return (
        <SectionFrame
            title="Revenue collections"
            subtitle="Follow cash received across your products."
            className="overflow-hidden"
            headerClassName="flex-wrap [&>div:first-child]:min-w-[160px]"
            contentClassName="collections-content"
            action={activeView === 'trend' ? (
                <button type="button" className="collections-options-button" ref={optionsButton}
                    aria-expanded={optionsOpen} aria-controls="revenue-chart-options" onClick={() => setOptionsOpen(!optionsOpen)}>
                    Chart options <svg aria-hidden="true" width="12" height="12" viewBox="0 0 12 12" fill="none"><path d="m3 4 3 3 3-3" stroke="currentColor" strokeWidth="1.5" /></svg>
                </button>
            ) : null}
        >
            {productView && <>
                <RevenueProductSelector value={product} onChange={onProductChange} />
                <RevenueCollectionSummary collections={collections} loading={isLoading} error={errorMessage} product={product} displayMode={displayMode} currency={currency} />
                {!isLoading && !errorMessage && collections?.wallet_funded?.payments_count > 0 && collections?.selected?.payments_count === 0 && <p className="collections-wallet-note">
                    {cashMoney(collections.wallet_funded, displayMode)} of wallet-funded service used an existing balance and is excluded from new cash.
                </p>}
            </>}
            {activeView === 'mix' && <p className="collections-context"><strong>Subscription customer mix.</strong> New and existing customer shares use subscription revenue. Other products are not segmented by customer lifecycle here.</p>}
            <div className="collections-view-rail">
                    <div className="cash-tablist rounded-md border border-slate-300 bg-white p-0.5" role="tablist" aria-label="Revenue trend view">
                        {VIEWS.map((item) => (
                            <button
                                key={item.key}
                                type="button"
                                role="tab"
                                id={`revenue-tab-${item.key}`}
                                aria-controls={`revenue-panel-${item.key}`}
                                tabIndex={activeView === item.key ? 0 : -1}
                                aria-selected={activeView === item.key}
                                onKeyDown={(event) => {
                                    const index = VIEWS.findIndex((v) => v.key === activeView);
                                    const next = event.key === 'ArrowRight' ? (index + 1) % VIEWS.length : event.key === 'ArrowLeft' ? (index + VIEWS.length - 1) % VIEWS.length : event.key === 'Home' ? 0 : event.key === 'End' ? VIEWS.length - 1 : null;
                                    if (next === null) return;
                                    event.preventDefault(); onViewChange?.(VIEWS[next].key);
                                    document.getElementById(`revenue-tab-${VIEWS[next].key}`)?.focus();
                                }}
                                onClick={() => onViewChange?.(item.key)}
                                className={`cash-tab rounded px-2.5 py-1.5 text-xs font-semibold ${activeView === item.key ? 'bg-slate-900 text-white' : 'text-slate-600 hover:bg-slate-50'}`}
                            >
                                {item.label}
                            </button>
                        ))}
                    </div>

                {productView && <span className="collections-chart-note">{data?.window?.from && data?.window?.to ? `${collectionWindowLabel(data.window)} · ` : ''}{activeView === 'trend' ? `${chartMeasure} · ${chartGrouping} · ` : ''}EAT</span>}
            </div>
            {activeView === 'trend' && <RevenueChartOptions open={optionsOpen} metric={metric} onMetricChange={onMetricChange} metrics={METRICS}
                bucket={bucket} onBucketChange={onBucketChange} buckets={BUCKETS} showComparison={showComparison}
                onComparisonChange={onShowComparisonChange} hourly={isHourly} onClose={closeOptions} />}
            <div id="revenue-panel-sources" role="tabpanel" aria-labelledby="revenue-tab-sources" hidden={activeView !== 'sources'} className="cash-panel">
                <ProductRevenueView data={productRevenue} loading={productLoading} error={productError} onRetry={onRetryRevenue} displayMode={displayMode} />
            </div>
            <div id="revenue-panel-peak" role="tabpanel" aria-labelledby="revenue-tab-peak" hidden={activeView !== 'peak'} className="cash-panel">
                {peakHoursLoading || peakHoursError ? <CashState loading={peakHoursLoading} error={peakHoursError} onRetry={onRetryCollections} />
                    : collections?.selected?.fx_coverage?.complete === false ? <div className="cash-warning">Peak revenue needs complete FX coverage. Original money and payment counts are retained above; no missing amounts are shown as zero.</div>
                        : collections?.selected?.payments_count === 0 ? <EmptyState message="No external cash payments for this product in the selected period." />
                            : <PeakHoursHeatmap data={peakHoursData} currency={currency} cashBasis scopeKey={`${product}:${data?.window?.from}:${data?.window?.to}`} />}

            </div>
            <div id="revenue-panel-mix" role="tabpanel" aria-labelledby="revenue-tab-mix" hidden={activeView !== 'mix'} className="cash-panel">
                <CustomerMixView mix={customerMix} currency={currency} />
            </div>
            <div id="revenue-panel-trend" role="tabpanel" aria-labelledby="revenue-tab-trend" hidden={activeView !== 'trend'} className="cash-panel">
            {isLoading ? (
                <div className="h-72 animate-pulse rounded-lg bg-slate-100" />
            ) : errorMessage ? (
                <CashState error={errorMessage} onRetry={onRetryCollections} />
            ) : !hasData ? (
                <EmptyState message="No external cash payments for this product in the selected period." />
            ) : (
                <div>
                    {(collections?.selected?.fx_coverage?.complete === false || collections?.prior?.fx_coverage?.complete === false) && <p className="cash-warning">Some current or prior payments have no cached FX. Missing amounts appear as gaps; payment counts and original money remain available.</p>}
                    {weekdaySelectionEnabled ? (
                        <div className="mb-3 flex flex-col gap-2 px-1 text-xs text-slate-500 sm:flex-row sm:items-center sm:justify-between">
                            <span>
                                {selectedWeekday === null
                                    ? 'Click a day to compare that weekday across the window.'
                                    : `${WEEKDAY_LABELS[selectedWeekday]} pattern selected · ${selectedWeekdayPoints.length.toLocaleString()} matching days highlighted`}
                            </span>
                            {selectedWeekday !== null ? (
                                <button
                                    type="button"
                                    onClick={() => setSelectedDay(null)}
                                    className="self-start rounded-md border border-slate-300 bg-white px-2.5 py-1 text-xs font-semibold text-slate-600 transition hover:border-teal-300 hover:text-teal-700 sm:self-auto"
                                >
                                    Clear weekday
                                </button>
                            ) : null}
                        </div>
                    ) : null}
                    <div className="collections-chart">
                        <ResponsiveContainer width="100%" height="100%">
                            <AreaChart
                                data={points}
                                margin={{ top: 12, right: 16, left: 4, bottom: 0 }}
                                onClick={handleChartClick}
                                className={weekdaySelectionEnabled ? 'cursor-pointer' : ''}
                            >
                                <defs>
                                    <linearGradient id="ceoRevenueTrend" x1="0" x2="0" y1="0" y2="1">
                                        <stop offset="5%" stopColor="#0f766e" stopOpacity={0.24} />
                                        <stop offset="95%" stopColor="#0f766e" stopOpacity={0.02} />
                                    </linearGradient>
                                </defs>
                                <CartesianGrid stroke="#e2e8f0" strokeDasharray="3 3" vertical={false} />
                                <Customized content={(props) => (
                                    <WeekdayBands
                                        {...props}
                                        points={points}
                                        selectedWeekday={selectedWeekday}
                                    />
                                )}
                                />
                                <XAxis dataKey="label" tick={<DayAxisTick />} tickLine={false} axisLine={false} minTickGap={24} height={36} interval="preserveStartEnd" />
                                <YAxis tick={{ fontSize: 11, fill: '#64748b' }} tickLine={false} axisLine={false} width={72} tickFormatter={(value) => metric === 'payments' ? Number(value || 0).toLocaleString() : formatCurrency(value, currency).replace(`${currency} `, '')} />
                                <Tooltip content={<TrendTooltip currency={currency} metric={metric} />} />
                                <Area isAnimationActive={!reducedMotion} animationDuration={240} type="monotone" dataKey={currentKey} stroke="#0f766e" strokeWidth={2.5} fill="url(#ceoRevenueTrend)" name="Current" dot={weekdaySelectionEnabled ? weekdayDot(selectedWeekday) : false} activeDot={{ r: 5, strokeWidth: 2, stroke: '#ffffff', fill: '#0f766e' }} />
                                {showComparison ? (
                                    <Line isAnimationActive={!reducedMotion} animationDuration={240} type="monotone" dataKey={priorKey} stroke="#94a3b8" strokeWidth={2} strokeDasharray="5 5" dot={false} name="Prior" />
                                ) : null}
                            </AreaChart>
                        </ResponsiveContainer>
                    </div>
                </div>
            )}
            </div>
            {productView && <details className="collections-basis"><summary>How collections are measured</summary>
                <p>External cash from successful payments, counted once. Wallet spending, top-ups, refunds and ambiguous receipts do not create new cash. Content and gifts include externally paid proceeds credits. Cash timing uses completed, confirmed, then created time; calendar boundaries use Africa/Nairobi. Comparison uses the same elapsed interval in the prior period.</p>
                {collections?.wallet_funded?.payments_count > 0 && <p>Excluded wallet-funded service: {cashMoney(collections.wallet_funded, displayMode)}. This uses an existing balance and does not create new cash.</p>}
                <p>Chart values use cached {currency} rates and per-payment cents. Original money remains available when FX is missing. These are provisional CRM records, not provider settlement certification.{asOf ? ` As of ${new Date(asOf).toLocaleString('en-GB', { timeZone: 'Africa/Nairobi' })} EAT.` : ''}</p>
            </details>}
        </SectionFrame>
    );
}
