import React, { useEffect, useMemo, useState } from 'react';
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
        return Number(prefix ? point.prior_value : point.value || 0);
    }

    return Number(point[key] || 0);
}

function formatMetricValue(value, metric, currency) {
    if (metric === 'revenue' || metric === 'average_ticket') {
        return formatCurrency(value || 0, currency);
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
    if (!active || !payload?.length) return null;
    const point = payload[0]?.payload || {};
    const current = valueForMetric(point, metric);
    const prior = valueForMetric(point, metric, 'prior');

    return (
        <div className="rounded-lg border border-slate-200 bg-white p-3 shadow-xl">
            <p className="text-xs font-semibold text-slate-500">{formatTooltipLabel(label)}</p>
            <p className="mt-1 text-sm font-semibold text-slate-900">Current: {formatMetricValue(current, metric, currency)}</p>
            <p className="text-sm font-medium text-slate-500">Prior: {formatMetricValue(prior, metric, currency)}</p>
            <p className="mt-2 text-xs text-slate-500">
                {Number(point.payments_count || 0).toLocaleString()} payments · Avg {formatCurrency(point.average_ticket || 0, currency)}
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
}) {
    const [selectedDay, setSelectedDay] = useState(null);
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
            revenue: isFuture ? null : Number(point.value || 0),
            prior_revenue: Number(point.prior_value || 0),
            payments: isFuture ? null : Number(point.payments_count || 0),
            prior_payments: Number(point.prior_payments_count || 0),
            average_ticket: isFuture ? null : Number(point.average_ticket || 0),
            prior_average_ticket: Number(point.prior_average_ticket || 0),
        };
    });
    const selectedWeekday = selectedDay ? weekdayKey(selectedDay) : null;
    const selectedWeekdayPoints = useMemo(() => (
        selectedWeekday === null
            ? []
            : points.filter((point) => point.weekday === selectedWeekday)
    ), [points, selectedWeekday]);
    const weekdaySelectionEnabled = activeBucket === 'day';
    const hasData = points.some((point) => Number(valueForMetric(point, metric) || 0) > 0 || Number(valueForMetric(point, metric, 'prior') || 0) > 0);
    const currentKey = metric === 'revenue' ? 'revenue' : metric;
    const priorKey = `prior_${currentKey}`;
    const activeMetric = METRICS.find((item) => item.key === metric)?.label || 'Revenue';
    const activeView = VIEWS.find((item) => item.key === view)?.key || 'trend';
    const tzLabel = data?.window?.timezone || 'East Africa';
    const priorToggleLabel = isHourly ? 'Prior day' : 'Prior window';
    const subtitle = activeView === 'trend'
        ? (isHourly
            ? `${activeMetric} by hour (${tzLabel}) — ${data?.window?.is_today ? 'today' : 'selected day'} vs the full prior day.`
            : `${activeMetric} by ${data?.bucket || 'auto'} bucket, with optional prior-window overlay.`)
        : activeView === 'peak'
            ? 'Sales concentration by East Africa hour, normalized to the reporting currency.'
            : 'New vs existing customer revenue, with unmatched revenue separated.';

    useEffect(() => {
        if (!weekdaySelectionEnabled && selectedDay !== null) {
            setSelectedDay(null);
        }
    }, [selectedDay, weekdaySelectionEnabled]);

    const handleChartClick = (state) => {
        if (!weekdaySelectionEnabled) return;
        const point = state?.activePayload?.[0]?.payload;
        const nextDay = point?.label;
        if (!nextDay || weekdayKey(nextDay) === null) return;

        setSelectedDay((current) => current === nextDay ? null : nextDay);
    };

    return (
        <SectionFrame
            title="Revenue Trend"
            subtitle={subtitle}
            className="overflow-hidden"
            contentClassName="min-h-[500px]"
            action={(
                <div className="flex flex-wrap justify-end gap-2">
                    <div className="inline-flex rounded-md border border-slate-300 bg-white p-0.5" role="tablist" aria-label="Revenue trend view">
                        {VIEWS.map((item) => (
                            <button
                                key={item.key}
                                type="button"
                                role="tab"
                                aria-selected={activeView === item.key}
                                onClick={() => onViewChange?.(item.key)}
                                className={`rounded px-2.5 py-1.5 text-xs font-semibold transition ${activeView === item.key ? 'bg-slate-900 text-white' : 'text-slate-600 hover:bg-slate-50'}`}
                            >
                                {item.label}
                            </button>
                        ))}
                    </div>
                    {activeView === 'trend' ? <div className="inline-flex rounded-md border border-slate-300 bg-white p-0.5" role="group" aria-label="Trend metric">
                        {METRICS.map((item) => (
                            <button
                                key={item.key}
                                type="button"
                                onClick={() => onMetricChange(item.key)}
                                className={`rounded px-2.5 py-1.5 text-xs font-semibold transition ${metric === item.key ? 'bg-slate-900 text-white' : 'text-slate-600 hover:bg-slate-50'}`}
                            >
                                {item.label}
                            </button>
                        ))}
                    </div> : null}
                    {activeView === 'trend' && !isHourly ? <div className="inline-flex rounded-md border border-slate-300 bg-white p-0.5" role="group" aria-label="Trend bucket">
                        {BUCKETS.map((item) => (
                            <button
                                key={item.key}
                                type="button"
                                onClick={() => onBucketChange(item.key)}
                                className={`rounded px-2.5 py-1.5 text-xs font-semibold transition ${bucket === item.key ? 'bg-teal-700 text-white' : 'text-slate-600 hover:bg-slate-50'}`}
                            >
                                {item.label}
                            </button>
                        ))}
                    </div> : null}
                    {activeView === 'trend' ? <button
                        type="button"
                        onClick={() => onShowComparisonChange(!showComparison)}
                        className={`rounded-md border px-3 py-1.5 text-xs font-semibold transition focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-teal-500 ${
                            showComparison ? 'border-teal-200 bg-teal-50 text-teal-800' : 'border-slate-300 bg-white text-slate-600 hover:bg-slate-50'
                        }`}
                    >
                        {priorToggleLabel}
                    </button> : null}
                </div>
            )}
        >
            {activeView === 'peak' ? (
                <PeakHoursHeatmap
                    data={peakHoursData}
                    isLoading={peakHoursLoading}
                    errorMessage={peakHoursError}
                    currency={peakHoursData?.target_currency || currency}
                />
            ) : activeView === 'mix' ? (
                <CustomerMixView mix={customerMix} currency={currency} />
            ) : isLoading ? (
                <div className="h-72 animate-pulse rounded-lg bg-slate-100" />
            ) : errorMessage ? (
                <EmptyState message={errorMessage} />
            ) : !hasData ? (
                <EmptyState message="No collected revenue in this window yet." />
            ) : (
                <div>
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
                    <div className="h-80">
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
                                <Area type="monotone" dataKey={currentKey} stroke="#0f766e" strokeWidth={2.5} fill="url(#ceoRevenueTrend)" name="Current" dot={weekdaySelectionEnabled ? weekdayDot(selectedWeekday) : false} activeDot={{ r: 5, strokeWidth: 2, stroke: '#ffffff', fill: '#0f766e' }} />
                                {showComparison ? (
                                    <Line type="monotone" dataKey={priorKey} stroke="#94a3b8" strokeWidth={2} strokeDasharray="5 5" dot={false} name="Prior" />
                                ) : null}
                            </AreaChart>
                        </ResponsiveContainer>
                    </div>
                </div>
            )}
        </SectionFrame>
    );
}
