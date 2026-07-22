import React from 'react';
import ReportingCurrencyControl from '../ReportingCurrencyControl';
import { marketLabel } from './ceoFormatters';

const HORIZONS = [
    { key: 'today', label: 'Today' },
    { key: '7d', label: '7 days' },
    { key: '30d', label: '30 days' },
    { key: '90d', label: '90 days' },
    { key: 'ytd', label: 'YTD' },
    { key: 'custom', label: 'Custom' },
];

function todayInput() {
    const now = new Date();
    const year = now.getFullYear();
    const month = String(now.getMonth() + 1).padStart(2, '0');
    const day = String(now.getDate()).padStart(2, '0');
    return `${year}-${month}-${day}`;
}

function shiftDay(dateStr, delta) {
    const [year, month, day] = String(dateStr || todayInput()).split('-').map(Number);
    const date = new Date(year, month - 1, day);
    date.setDate(date.getDate() + delta);
    const y = date.getFullYear();
    const m = String(date.getMonth() + 1).padStart(2, '0');
    const d = String(date.getDate()).padStart(2, '0');
    return `${y}-${m}-${d}`;
}

function humanizeDay(dateStr, isToday) {
    if (!dateStr) return '';
    const [year, month, day] = dateStr.split('-').map(Number);
    const date = new Date(year, month - 1, day);
    const label = date.toLocaleDateString(undefined, { weekday: 'long', month: 'short', day: 'numeric' });
    return isToday ? `${label} · today` : label;
}

export default function CeoHeader({
    user,
    horizon,
    onHorizonChange,
    customRange,
    onCustomRangeChange,
    dayDate,
    onDayDateChange,
    window: windowMeta,
    selectedMarket,
    markets = [],
    platformFilter,
    onPlatformChange,
    reporting,
    onSwitchAdmin,
}) {
    const today = new Date().toLocaleDateString(undefined, {
        weekday: 'long',
        month: 'short',
        day: 'numeric',
        year: 'numeric',
    });

    const todayStr = todayInput();
    const activeDay = dayDate || todayStr;
    const isToday = activeDay === todayStr;
    const priorDayLabel = humanizeDay(shiftDay(activeDay, -1), false);

    return (
        <header className="-mx-1 space-y-3">
            <div className="rounded-lg border border-slate-200 bg-white px-4 py-3 shadow-sm">
                <div className="flex flex-wrap items-start justify-between gap-3">
                    <div className="min-w-0">
                        <p className="text-[11px] font-semibold uppercase tracking-[0.16em] text-teal-700">CEO dashboard</p>
                        <h1 className="mt-1 text-xl font-semibold tracking-tight text-slate-950">
                            {user?.name ? `Good day, ${user.name.split(' ')[0]}` : 'Business command view'}
                        </h1>
                        <p className="mt-1 text-sm text-slate-500">{today}</p>
                    </div>

                    <button
                        type="button"
                        onClick={onSwitchAdmin}
                        className="rounded-md border border-slate-300 bg-white px-3 py-2 text-sm font-semibold text-slate-700 shadow-sm transition hover:bg-slate-50 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-teal-500"
                    >
                        Switch to Admin view
                    </button>
                </div>
            </div>

            <div className="sticky top-0 z-20 rounded-lg border border-slate-200 bg-white/95 px-3 py-2 shadow-sm backdrop-blur">
                <div className="grid gap-2 xl:grid-cols-[auto_minmax(220px,1fr)_auto]">
                    <div className="flex flex-wrap items-center gap-2" role="group" aria-label="Dashboard horizon">
                        {HORIZONS.map((item) => (
                            <button
                                key={item.key}
                                type="button"
                                onClick={() => onHorizonChange(item.key)}
                                className={`h-9 rounded-md px-3 text-xs font-semibold transition focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-teal-500 ${
                                    horizon === item.key
                                        ? 'bg-slate-900 text-white shadow-sm'
                                        : 'border border-slate-300 bg-white text-slate-700 hover:bg-slate-50'
                                }`}
                            >
                                {item.label}
                            </button>
                        ))}
                    </div>

                    <div className="flex flex-wrap items-center gap-2">
                        <select
                            value={platformFilter || ''}
                            onChange={(event) => onPlatformChange(event.target.value || null)}
                            className="h-9 min-w-[220px] rounded-md border border-slate-300 bg-white px-3 text-sm font-medium text-slate-700 focus:border-teal-500 focus:outline-none focus:ring-2 focus:ring-teal-100"
                            aria-label="Market scope"
                        >
                            <option value="">All accessible markets</option>
                            {markets.map((market) => (
                                <option key={market.id || market.platform_id} value={market.id || market.platform_id}>
                                    {marketLabel({ name: market.name || market.platform_name, country: market.country || market.platform_country })}
                                </option>
                            ))}
                        </select>

                        {selectedMarket ? (
                            <button
                                type="button"
                                onClick={() => onPlatformChange(null)}
                                className="inline-flex h-9 items-center gap-2 rounded-md border border-teal-200 bg-teal-50 px-3 text-xs font-semibold text-teal-800 transition hover:bg-teal-100 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-teal-500"
                                aria-label={`Clear ${selectedMarket.name} market scope`}
                            >
                                <span>{marketLabel(selectedMarket)}</span>
                                <span aria-hidden="true">x</span>
                            </button>
                        ) : null}
                    </div>

                    <ReportingCurrencyControl reporting={reporting} className="justify-start xl:justify-end" />
                </div>

                {horizon === 'today' ? (
                    <div className="mt-2 flex flex-wrap items-center gap-2 border-t border-slate-100 pt-2">
                        <div className="inline-flex items-center rounded-md border border-slate-300 bg-white">
                            <button
                                type="button"
                                onClick={() => onDayDateChange?.(shiftDay(activeDay, -1))}
                                className="flex h-9 w-9 items-center justify-center rounded-l-md text-slate-600 transition hover:bg-slate-50 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-teal-500"
                                aria-label="Previous day"
                            >
                                <span aria-hidden="true">‹</span>
                            </button>
                            <input
                                type="date"
                                value={activeDay}
                                max={todayStr}
                                onChange={(event) => onDayDateChange?.(event.target.value || todayStr)}
                                className="h-9 w-auto border-x border-slate-200 bg-white px-2 text-sm font-medium text-slate-700 focus:outline-none focus:ring-2 focus:ring-teal-100"
                                aria-label="Select day"
                            />
                            <button
                                type="button"
                                onClick={() => !isToday && onDayDateChange?.(shiftDay(activeDay, 1))}
                                disabled={isToday}
                                className="flex h-9 w-9 items-center justify-center rounded-r-md text-slate-600 transition hover:bg-slate-50 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-teal-500 disabled:cursor-not-allowed disabled:text-slate-300 disabled:hover:bg-white"
                                aria-label="Next day"
                            >
                                <span aria-hidden="true">›</span>
                            </button>
                        </div>
                        {!isToday ? (
                            <button
                                type="button"
                                onClick={() => onDayDateChange?.(todayStr)}
                                className="inline-flex h-9 items-center rounded-md border border-teal-200 bg-teal-50 px-3 text-xs font-semibold text-teal-800 transition hover:bg-teal-100 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-teal-500"
                            >
                                Jump to today
                            </button>
                        ) : null}
                        <span className="text-xs text-slate-500">
                            <span className="font-semibold text-slate-700">{humanizeDay(activeDay, isToday)}</span>
                            {' · vs '}{priorDayLabel}
                            {isToday && windowMeta?.timezone ? ` · ${windowMeta.timezone}` : ''}
                        </span>
                    </div>
                ) : null}

                {horizon === 'custom' ? (
                    <div className="mt-2 flex flex-wrap items-center gap-2 border-t border-slate-100 pt-2">
                        <label className="text-xs font-semibold uppercase tracking-[0.10em] text-slate-500" htmlFor="ceo-from">From</label>
                        <input
                            id="ceo-from"
                            type="date"
                            value={customRange.from}
                            onChange={(event) => onCustomRangeChange({ ...customRange, from: event.target.value })}
                            className="crm-input h-9 w-auto"
                        />
                        <label className="text-xs font-semibold uppercase tracking-[0.10em] text-slate-500" htmlFor="ceo-to">To</label>
                        <input
                            id="ceo-to"
                            type="date"
                            value={customRange.to}
                            onChange={(event) => onCustomRangeChange({ ...customRange, to: event.target.value })}
                            className="crm-input h-9 w-auto"
                        />
                    </div>
                ) : null}
            </div>
        </header>
    );
}
