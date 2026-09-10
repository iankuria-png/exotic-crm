import React, { useEffect, useMemo, useState } from 'react';
import { formatCurrency } from '../../utils/currency';
import exportRowsToCsv from '../../utils/csvExport';
import { getCountryFlag } from '../../utils/flags';
import { compactNumber, copyText } from './visitorFormat';

/**
 * Row-level drill-down for a Demand KPI.
 *
 * The Demand cards are counts with no way back to the rows behind them. This drawer is that
 * way back: it reproduces the card's own scope, shows the rows, and lets the reader search,
 * sort and export without leaving the tab. Every metric gets its own column set because the
 * questions differ — a renewal is about expiry movement, a purchase is about who bought what.
 */

const DATE_FORMAT = { day: 'numeric', month: 'short', year: 'numeric' };
const DATETIME_FORMAT = { day: 'numeric', month: 'short', hour: '2-digit', minute: '2-digit' };

function dateLabel(value) {
    if (!value) return null;
    const date = new Date(value);
    return Number.isNaN(date.getTime()) ? null : date.toLocaleDateString(undefined, DATE_FORMAT);
}

function dateTimeLabel(value) {
    if (!value) return null;
    const date = new Date(value);
    return Number.isNaN(date.getTime()) ? null : date.toLocaleString(undefined, DATETIME_FORMAT);
}

function dayLabel(value) {
    if (value === null || value === undefined) return null;
    const number = Number(value);
    if (!Number.isFinite(number)) return null;
    const rounded = Math.abs(number) < 10 ? number.toFixed(1).replace(/\.0$/, '') : Math.round(number);
    return `${rounded}d`;
}

function money(amount, normalized, currency, normalizedCurrency) {
    if (normalized !== null && normalized !== undefined) {
        return formatCurrency(normalized, normalizedCurrency);
    }
    return currency ? `${formatCurrency(amount, currency)}*` : '--';
}

function Muted({ children }) {
    return <span className="text-slate-400">{children ?? '--'}</span>;
}

function Chip({ children, tone = 'slate' }) {
    const tones = {
        slate: 'border-slate-200 bg-slate-50 text-slate-600',
        emerald: 'border-emerald-200 bg-emerald-50 text-emerald-700',
        amber: 'border-amber-200 bg-amber-50 text-amber-700',
        rose: 'border-rose-200 bg-rose-50 text-rose-700',
        teal: 'border-teal-200 bg-teal-50 text-teal-700',
    };
    return (
        <span className={`inline-flex items-center gap-1 whitespace-nowrap rounded-full border px-1.5 py-0.5 text-[10px] font-semibold ${tones[tone] || tones.slate}`}>
            {children}
        </span>
    );
}

function CopyRef({ value, label, toast }) {
    if (!value) return <Muted />;
    return (
        <button
            type="button"
            onClick={() => copyText(value, toast)}
            title={`Copy ${label || 'reference'}`}
            className="inline-flex max-w-full items-center gap-1 rounded border border-slate-200 bg-slate-50 px-1.5 py-0.5 font-mono text-[11px] text-slate-600 transition hover:border-slate-300 hover:bg-white"
        >
            <span className="truncate">{value}</span>
            <span aria-hidden="true" className="shrink-0 text-slate-400">⧉</span>
        </button>
    );
}

function ProfileCell({ name, url, market, country, city, toast }) {
    return (
        <div className="min-w-0">
            <div className="flex items-center gap-1.5">
                <span className="truncate font-medium text-slate-900">{name || <Muted>Unknown profile</Muted>}</span>
                {url ? (
                    <a
                        href={url}
                        target="_blank"
                        rel="noreferrer"
                        title="Open the live profile"
                        onClick={(event) => event.stopPropagation()}
                        className="shrink-0 text-slate-400 transition hover:text-teal-600"
                    >
                        ↗
                    </a>
                ) : null}
                {url ? (
                    <button
                        type="button"
                        title="Copy profile URL"
                        onClick={() => copyText(url, toast)}
                        className="shrink-0 text-slate-300 transition hover:text-teal-600"
                    >
                        ⧉
                    </button>
                ) : null}
            </div>
            <p className="truncate text-[11px] text-slate-500">
                {[market ? `${getCountryFlag(country)} ${market}` : '', city].filter(Boolean).join(' · ') || '--'}
            </p>
        </div>
    );
}

function VisitorCell({ visitor, toast }) {
    const handle = visitor?.phone_masked || visitor?.email_masked;
    return (
        <div className="min-w-0 space-y-1">
            <div className="flex items-center gap-1.5">
                <span className="truncate font-medium text-slate-900">{handle || <Muted>No contact captured</Muted>}</span>
                {visitor?.is_repeat_buyer ? <Chip tone="teal">Repeat ×{visitor.purchases_in_window}</Chip> : null}
            </div>
            <div className="flex flex-wrap items-center gap-1">
                <CopyRef value={visitor?.reference} label="visitor reference" toast={toast} />
                {visitor?.session_reference ? <CopyRef value={visitor.session_reference} label="session reference" toast={toast} /> : null}
            </div>
        </div>
    );
}

function RevealedCell({ row }) {
    const profiles = row.revealed_profiles || [];
    if (!profiles.length) {
        return (
            <div className="space-y-0.5">
                <Muted>Not revealed yet</Muted>
                <p className="text-[11px] text-slate-400">Paid, no contact viewed</p>
            </div>
        );
    }
    const shown = profiles.slice(0, 3);
    return (
        <div className="min-w-0 space-y-0.5">
            {shown.map((profile) => (
                <p key={profile.client_id} className="truncate text-[12px] text-slate-700">
                    {profile.name}
                    {profile.reveals > 1 ? <span className="ml-1 text-slate-400">×{profile.reveals}</span> : null}
                </p>
            ))}
            {profiles.length > shown.length ? (
                <p className="text-[11px] text-slate-500">+{profiles.length - shown.length} more revealed</p>
            ) : null}
        </div>
    );
}

function ExpiryCell({ value, delta, deltaLabel }) {
    const label = dateLabel(value);
    if (!label) {
        return (
            <div className="space-y-0.5">
                <Muted>Not recorded</Muted>
                <p className="text-[11px] text-slate-400">No dated deal</p>
            </div>
        );
    }
    return (
        <div className="space-y-0.5">
            <p className="whitespace-nowrap font-medium text-slate-900">{label}</p>
            {delta !== null && delta !== undefined ? (
                <p className="whitespace-nowrap text-[11px] text-slate-500">{deltaLabel}</p>
            ) : null}
        </div>
    );
}

// --- metric definitions -------------------------------------------------------------

function renewalColumns(currency, toast) {
    return [
        {
            key: 'client_name',
            label: 'Profile',
            sort: (row) => String(row.client_name || '').toLowerCase(),
            csv: (row) => row.client_name,
            className: 'min-w-[200px]',
            cell: (row) => <ProfileCell name={row.client_name} url={row.profile_url} market={row.market} country={row.market_country} city={row.city} toast={toast} />,
        },
        {
            key: 'unlocks_before_renewal',
            label: 'Unlocks before renewal',
            align: 'right',
            sort: (row) => Number(row.unlocks_before_renewal || 0),
            csv: (row) => row.unlocks_before_renewal,
            cell: (row) => (
                <div className="space-y-0.5 text-right">
                    <p className="font-semibold tabular-nums text-slate-900">{compactNumber(row.unlocks_before_renewal)}</p>
                    {row.unlocks_in_window > row.unlocks_before_renewal ? (
                        <p className="text-[11px] text-slate-500">{compactNumber(row.unlocks_in_window)} in window</p>
                    ) : null}
                </div>
            ),
        },
        {
            key: 'days_to_renewal',
            label: 'Demand → renewal',
            align: 'right',
            sort: (row) => Number(row.days_to_renewal ?? 9999),
            csv: (row) => row.days_to_renewal,
            cell: (row) => (
                <div className="space-y-0.5 text-right">
                    <p className="font-semibold tabular-nums text-slate-900">{dayLabel(row.days_to_renewal) || <Muted />}</p>
                    <p className="whitespace-nowrap text-[11px] text-slate-500">{dateTimeLabel(row.first_demand_at) || '--'}</p>
                </div>
            ),
        },
        {
            key: 'amount_normalized',
            label: 'Amount paid',
            align: 'right',
            sort: (row) => Number(row.amount_normalized ?? -1),
            csv: (row) => (row.amount_normalized ?? row.amount),
            cell: (row) => (
                <div className="space-y-0.5 text-right">
                    <p className="font-semibold tabular-nums text-slate-900">{money(row.amount, row.amount_normalized, row.currency, currency)}</p>
                    <p className="whitespace-nowrap text-[11px] text-slate-500">{dateLabel(row.renewed_at) || '--'}</p>
                </div>
            ),
        },
        {
            key: 'previous_expiry',
            label: 'Previous expiry',
            sort: (row) => String(row.previous_expiry || ''),
            csv: (row) => (row.previous_expiry ? dateLabel(row.previous_expiry) : ''),
            cell: (row) => {
                const days = row.days_before_expiry;
                const label = days === null || days === undefined
                    ? null
                    : days >= 0
                        ? `Renewed ${dayLabel(days)} early`
                        : `Lapsed ${dayLabel(Math.abs(days))} first`;
                return <ExpiryCell value={row.previous_expiry} delta={days} deltaLabel={label} />;
            },
        },
        {
            key: 'current_expiry',
            label: 'Current expiry',
            sort: (row) => String(row.current_expiry || ''),
            csv: (row) => (row.current_expiry ? dateLabel(row.current_expiry) : ''),
            cell: (row) => (
                <ExpiryCell
                    value={row.current_expiry}
                    delta={row.days_extended}
                    deltaLabel={row.days_extended !== null && row.days_extended !== undefined ? `+${dayLabel(Math.abs(row.days_extended))} added` : null}
                />
            ),
        },
        {
            key: 'lapse',
            label: 'Renewal timing',
            sort: (row) => Number(row.days_before_expiry ?? -9999),
            csv: (row) => (row.days_before_expiry === null || row.days_before_expiry === undefined
                ? 'unknown'
                : row.days_before_expiry >= 0 ? 'before expiry' : 'after lapse'),
            cell: (row) => {
                if (row.days_before_expiry === null || row.days_before_expiry === undefined) {
                    return <Chip>Unknown</Chip>;
                }
                return row.days_before_expiry >= 0
                    ? <Chip tone="emerald">Saved before expiry</Chip>
                    : <Chip tone="amber">Won back after lapse</Chip>;
            },
        },
    ];
}

function purchaseColumns(currency, toast, { marketWide }) {
    return [
        {
            key: 'visitor',
            label: 'Visitor',
            sort: (row) => String(row.visitor?.phone_masked || row.visitor?.reference || '').toLowerCase(),
            csv: (row) => [row.visitor?.phone_masked, row.visitor?.reference].filter(Boolean).join(' '),
            className: 'min-w-[190px]',
            cell: (row) => <VisitorCell visitor={row.visitor} toast={toast} />,
        },
        marketWide
            ? {
                key: 'revealed',
                label: 'Profiles revealed',
                sort: (row) => Number(row.revealed_profile_count || 0),
                csv: (row) => (row.revealed_profiles || []).map((profile) => profile.name).join(' | '),
                className: 'min-w-[190px]',
                cell: (row) => <RevealedCell row={row} />,
            }
            : {
                key: 'client',
                label: 'Client profile',
                sort: (row) => String(row.client?.name || '').toLowerCase(),
                csv: (row) => row.client?.name,
                className: 'min-w-[200px]',
                cell: (row) => (
                    <ProfileCell
                        name={row.client?.name}
                        url={row.client?.profile_url}
                        market={row.market}
                        country={row.market_country}
                        city={row.client?.city}
                        toast={toast}
                    />
                ),
            },
        {
            key: 'amount_normalized',
            label: 'Paid',
            align: 'right',
            sort: (row) => Number(row.amount_normalized ?? -1),
            csv: (row) => (row.amount_normalized ?? row.amount),
            cell: (row) => (
                <div className="space-y-0.5 text-right">
                    <p className="font-semibold tabular-nums text-slate-900">{money(row.amount, row.amount_normalized, row.currency, currency)}</p>
                    {Number(row.credit_amount || 0) > 0 ? <p className="text-[11px] text-teal-600">Upgrade credit applied</p> : null}
                </div>
            ),
        },
        {
            key: 'purchased_at',
            label: 'Purchased',
            sort: (row) => String(row.purchased_at || ''),
            csv: (row) => dateTimeLabel(row.purchased_at),
            cell: (row) => (
                <div className="space-y-0.5">
                    <p className="whitespace-nowrap text-slate-900">{dateTimeLabel(row.purchased_at) || <Muted />}</p>
                    <p className="truncate text-[11px] text-slate-500">
                        {[row.traffic_source, row.referrer_host].filter(Boolean).join(' · ') || 'source unknown'}
                    </p>
                </div>
            ),
        },
        {
            key: 'reveal_count',
            label: 'Reveals',
            align: 'right',
            sort: (row) => Number(row.reveal_count || 0),
            csv: (row) => row.reveal_count,
            cell: (row) => (
                <div className="space-y-0.5 text-right">
                    <p className="font-semibold tabular-nums text-slate-900">{compactNumber(row.reveal_count)}</p>
                    <p className="whitespace-nowrap text-[11px] text-slate-500">{dateTimeLabel(row.last_revealed_at) || 'never'}</p>
                </div>
            ),
        },
        {
            key: 'unlock_status',
            label: 'Access',
            sort: (row) => String(row.unlock_status || ''),
            csv: (row) => row.unlock_status,
            cell: (row) => {
                const tone = row.unlock_status === 'active' ? 'emerald' : row.unlock_status === 'expired' ? 'slate' : 'amber';
                return (
                    <div className="space-y-1">
                        <Chip tone={tone}>{row.unlock_status || 'unknown'}</Chip>
                        <p className="whitespace-nowrap text-[11px] text-slate-500">
                            {row.unlock_expires_at ? `until ${dateLabel(row.unlock_expires_at)}` : 'no expiry'}
                        </p>
                    </div>
                );
            },
        },
        {
            key: 'payment_reference',
            label: 'Payment',
            sort: (row) => String(row.payment_reference || ''),
            csv: (row) => row.payment_reference,
            cell: (row) => (
                <div className="space-y-1">
                    <CopyRef value={row.payment_reference} label="payment reference" toast={toast} />
                    <p className="text-[11px] text-slate-500">{row.provider_key || '--'}</p>
                </div>
            ),
        },
    ].filter(Boolean);
}

const METRIC_COPY = {
    renewed_after_demand: {
        title: 'Renewed after demand',
        eyebrow: 'Visitor demand → advertiser revenue',
        definition: 'Advertisers whose profile took at least one paid visitor unlock in this window, and who then paid a subscription within 14 days of that first unlock.',
        caveat: 'Correlation, not attribution — the advertiser may have renewed for other reasons. Expiry dates come from the CRM deal ledger; WordPress remains the source of truth.',
        searchHint: 'Search profile, market or city',
    },
    single_profile_purchases: {
        title: 'Single-profile purchases',
        eyebrow: 'Who bought access to whom',
        definition: 'Successful unlock payments where the visitor bought access to one specific advertiser profile, dated by payment completion.',
        caveat: 'Visitors are anonymous — they hold no account. The masked contact and the V- reference are the only stable handles.',
        searchHint: 'Search visitor, profile or payment reference',
    },
    full_access_purchases: {
        title: 'Full-access purchases',
        eyebrow: 'Who bought market-wide access',
        definition: 'Successful unlock payments for market-wide access to all inactive contacts, dated by payment completion.',
        caveat: 'A market-wide purchase has no single advertiser attached, so the profiles-revealed trail is what links the visitor to the clients they actually contacted.',
        searchHint: 'Search visitor, revealed profile or payment reference',
    },
};

function Stat({ label, value, hint, tone = 'slate' }) {
    const color = tone === 'positive' ? 'text-emerald-700' : tone === 'warning' ? 'text-amber-600' : 'text-slate-900';
    return (
        <div className="rounded-lg border border-slate-200 bg-white px-3 py-2.5">
            <p className="text-[10px] font-semibold uppercase tracking-[0.12em] text-slate-500">{label}</p>
            <p className={`mt-1 text-lg font-semibold tracking-tight ${color}`}>{value}</p>
            {hint ? <p className="mt-0.5 text-[11px] text-slate-500">{hint}</p> : null}
        </div>
    );
}

export default function VisitorDemandDetailDrawer({
    metric,
    data,
    isLoading,
    isError,
    errorMessage,
    onRetry,
    onClose,
    toast,
}) {
    const [search, setSearch] = useState('');
    const [sortKey, setSortKey] = useState(null);
    const [sortDirection, setSortDirection] = useState('desc');

    useEffect(() => {
        setSearch('');
        setSortKey(null);
        setSortDirection('desc');
    }, [metric?.key]);

    useEffect(() => {
        if (!metric) return undefined;
        const onKey = (event) => {
            if (event.key === 'Escape') onClose?.();
        };
        document.addEventListener('keydown', onKey);
        return () => document.removeEventListener('keydown', onKey);
    }, [metric, onClose]);

    const copy = METRIC_COPY[metric?.key] || {};
    const currency = data?.normalized_currency || 'USD';
    const isRenewal = metric?.key === 'renewed_after_demand';
    const columns = useMemo(() => {
        if (!metric) return [];
        return isRenewal
            ? renewalColumns(currency, toast)
            : purchaseColumns(currency, toast, { marketWide: metric.key === 'full_access_purchases' });
    }, [currency, isRenewal, metric, toast]);

    const rows = useMemo(() => {
        const all = data?.rows || [];
        const needle = search.trim().toLowerCase();
        const filtered = needle
            ? all.filter((row) => JSON.stringify(row).toLowerCase().includes(needle))
            : all;
        if (!sortKey) return filtered;
        const column = columns.find((entry) => entry.key === sortKey);
        if (!column) return filtered;
        const sorted = [...filtered].sort((a, b) => {
            const left = column.sort(a);
            const right = column.sort(b);
            if (left === right) return 0;
            return left > right ? 1 : -1;
        });
        return sortDirection === 'desc' ? sorted.reverse() : sorted;
    }, [columns, data, search, sortDirection, sortKey]);

    if (!metric) return null;

    const totals = data?.totals || {};
    const fxPartial = Boolean(totals.normalization_meta?.partial);
    const allRows = data?.rows || [];

    function toggleSort(key) {
        if (sortKey === key) {
            setSortDirection((current) => (current === 'desc' ? 'asc' : 'desc'));
            return;
        }
        setSortKey(key);
        setSortDirection('desc');
    }

    function exportCsv() {
        if (!rows.length) return;
        exportRowsToCsv(
            `crm-${metric.key.replace(/_/g, '-')}`,
            columns.map((column) => ({ label: column.label, value: column.csv })),
            rows
        );
        toast?.success?.(`Exported ${rows.length.toLocaleString()} rows.`);
    }

    return (
        <div className="fixed inset-0 z-[95] flex bg-slate-900/45" onClick={onClose} role="presentation">
            <aside
                className="ml-auto flex h-full w-full max-w-6xl flex-col border-l border-slate-200 bg-slate-50 shadow-xl"
                onClick={(event) => event.stopPropagation()}
                role="dialog"
                aria-modal="true"
                aria-label={`${copy.title || metric.label} detail`}
            >
                <header className="flex items-start justify-between gap-3 border-b border-slate-200 bg-white px-5 py-4">
                    <div className="min-w-0">
                        <p className="text-[10px] font-semibold uppercase tracking-[0.14em] text-teal-700">{copy.eyebrow || 'Demand metric'}</p>
                        <h3 className="mt-1 text-lg font-semibold tracking-tight text-slate-900">{copy.title || metric.label}</h3>
                        <p className="mt-1 text-2xl font-semibold tracking-tight text-slate-950">{metric.value}</p>
                    </div>
                    <button type="button" onClick={onClose} className="crm-btn-secondary shrink-0 px-3 py-1.5 text-xs">Close</button>
                </header>

                <div className="flex-1 space-y-4 overflow-y-auto px-5 py-4">
                    <section className="rounded-lg border border-slate-200 bg-white px-3.5 py-3">
                        <p className="text-[10px] font-semibold uppercase tracking-[0.12em] text-slate-500">How it is measured</p>
                        <p className="mt-1.5 text-sm leading-5 text-slate-700">{copy.definition}</p>
                        {copy.caveat ? <p className="mt-1.5 text-xs text-slate-500">{copy.caveat}</p> : null}
                    </section>

                    {isError ? (
                        <div className="rounded-lg border border-rose-200 bg-rose-50 p-4">
                            <p className="font-semibold text-rose-900">Detail unavailable</p>
                            <p className="mt-1 text-sm text-rose-700">{errorMessage || 'The rows behind this number could not be loaded.'}</p>
                            <button type="button" className="crm-btn-secondary mt-3" onClick={onRetry}>Retry</button>
                        </div>
                    ) : null}

                    {!isError && isLoading ? (
                        <div className="space-y-2">
                            <div className="grid gap-2 sm:grid-cols-4">
                                {Array.from({ length: 4 }).map((_, index) => <div key={index} className="h-16 animate-pulse rounded-lg bg-white" />)}
                            </div>
                            {Array.from({ length: 6 }).map((_, index) => <div key={index} className="h-12 animate-pulse rounded-lg bg-white" />)}
                        </div>
                    ) : null}

                    {!isError && !isLoading ? (
                        <>
                            <section className="grid gap-2 sm:grid-cols-2 xl:grid-cols-4">
                                {isRenewal ? (
                                    <>
                                        <Stat label="Profiles renewed" value={compactNumber(totals.clients)} tone="positive" />
                                        <Stat
                                            label="Renewal revenue"
                                            value={totals.revenue_normalized === null || totals.revenue_normalized === undefined
                                                ? '--'
                                                : formatCurrency(totals.revenue_normalized, currency)}
                                            hint={`Normalized to ${currency}`}
                                        />
                                        <Stat label="Unlocks before renewal" value={compactNumber(totals.unlocks_before_renewal)} hint="Paid demand that preceded the money" />
                                        <Stat label="Median demand → renewal" value={dayLabel(totals.median_days_to_renewal) || '--'} />
                                    </>
                                ) : (
                                    <>
                                        <Stat label="Purchases" value={compactNumber(totals.purchases)} />
                                        <Stat
                                            label="Revenue"
                                            value={totals.revenue_normalized === null || totals.revenue_normalized === undefined
                                                ? '--'
                                                : formatCurrency(totals.revenue_normalized, currency)}
                                            hint={`Normalized to ${currency}`}
                                        />
                                        <Stat label="Distinct visitors" value={compactNumber(totals.distinct_visitors)} hint={`${compactNumber(totals.repeat_buyers)} bought more than once`} />
                                        <Stat
                                            label={metric.key === 'full_access_purchases' ? 'Profiles revealed' : 'Clients bought'}
                                            value={compactNumber(metric.key === 'full_access_purchases'
                                                ? allRows.reduce((sum, row) => sum + Number(row.revealed_profile_count || 0), 0)
                                                : totals.distinct_clients)}
                                        />
                                    </>
                                )}
                            </section>

                            <section className="overflow-hidden rounded-lg border border-slate-200 bg-white">
                                <div className="flex flex-wrap items-center justify-between gap-2 border-b border-slate-100 px-3.5 py-2.5">
                                    <div className="flex flex-1 flex-wrap items-center gap-2">
                                        <input
                                            type="search"
                                            value={search}
                                            onChange={(event) => setSearch(event.target.value)}
                                            placeholder={copy.searchHint || 'Search rows'}
                                            className="crm-input h-9 w-full max-w-xs text-sm"
                                        />
                                        <span className="text-xs text-slate-500">
                                            {rows.length === allRows.length
                                                ? `${compactNumber(rows.length)} rows`
                                                : `${compactNumber(rows.length)} of ${compactNumber(allRows.length)} rows`}
                                        </span>
                                    </div>
                                    <div className="flex items-center gap-2">
                                        {search ? (
                                            <button type="button" className="crm-btn-secondary px-3 py-1.5 text-xs" onClick={() => setSearch('')}>Clear</button>
                                        ) : null}
                                        <button type="button" className="crm-btn-secondary px-3 py-1.5 text-xs" onClick={exportCsv} disabled={!rows.length}>
                                            Export CSV
                                        </button>
                                    </div>
                                </div>

                                {!allRows.length ? (
                                    <div className="px-4 py-10 text-center">
                                        <p className="text-sm font-semibold text-slate-700">Nothing to show yet</p>
                                        <p className="mx-auto mt-1 max-w-sm text-sm text-slate-500">
                                            No rows matched this metric in the selected market and date range. Widen the range or clear the market filter.
                                        </p>
                                    </div>
                                ) : !rows.length ? (
                                    <div className="px-4 py-10 text-center">
                                        <p className="text-sm font-semibold text-slate-700">No rows match "{search}"</p>
                                        <button type="button" className="crm-btn-secondary mt-3 text-xs" onClick={() => setSearch('')}>Clear search</button>
                                    </div>
                                ) : (
                                    <div className="overflow-x-auto">
                                        <table className="min-w-full text-sm">
                                            <thead className="bg-slate-50">
                                                <tr>
                                                    {columns.map((column) => (
                                                        <th
                                                            key={column.key}
                                                            scope="col"
                                                            className={`whitespace-nowrap px-3 py-2 text-[10px] font-semibold uppercase tracking-[0.1em] text-slate-500 ${column.align === 'right' ? 'text-right' : 'text-left'}`}
                                                        >
                                                            <button
                                                                type="button"
                                                                onClick={() => toggleSort(column.key)}
                                                                className="inline-flex items-center gap-1 transition hover:text-slate-800"
                                                            >
                                                                {column.label}
                                                                <span aria-hidden="true" className={sortKey === column.key ? 'text-teal-600' : 'text-slate-300'}>
                                                                    {sortKey === column.key ? (sortDirection === 'desc' ? '▾' : '▴') : '▾'}
                                                                </span>
                                                            </button>
                                                        </th>
                                                    ))}
                                                </tr>
                                            </thead>
                                            <tbody className="divide-y divide-slate-100">
                                                {rows.map((row, index) => (
                                                    <tr key={row.unlock_id || row.client_id || index} className="align-top transition hover:bg-slate-50">
                                                        {columns.map((column) => (
                                                            <td
                                                                key={column.key}
                                                                className={`px-3 py-2.5 ${column.align === 'right' ? 'text-right' : 'text-left'} ${column.className || ''}`}
                                                            >
                                                                {column.cell(row)}
                                                            </td>
                                                        ))}
                                                    </tr>
                                                ))}
                                            </tbody>
                                        </table>
                                    </div>
                                )}
                            </section>

                            {data?.truncated ? (
                                <p className="rounded-lg border border-amber-200 bg-amber-50 px-3 py-2 text-xs text-amber-800">
                                    Showing the first {compactNumber(data.row_limit)} rows. Narrow the market or date range to see the rest.
                                </p>
                            ) : null}

                            {fxPartial ? (
                                <p className="rounded-lg border border-slate-200 bg-white px-3 py-2 text-xs text-slate-600">
                                    Amounts marked <span className="font-semibold">*</span> could not be converted to {currency} — no FX rate is cached for {(totals.normalization_meta?.missing_currencies || []).join(', ') || 'one or more currencies'}.
                                </p>
                            ) : null}
                        </>
                    ) : null}
                </div>
            </aside>
        </div>
    );
}
