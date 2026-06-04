import React, { useEffect, useMemo, useState } from 'react';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { useNavigate } from 'react-router-dom';
import api from '../../services/api';
import { useToast } from '../ToastProvider';
import ConfirmDialog from '../ConfirmDialog';

const REASON_LABELS = {
    not_serious: 'Not Serious',
    no_response: 'No Response',
    declined: 'Declined to Proceed',
    invalid_contact: 'Invalid Contact Details',
    inappropriate: 'Inappropriate Behaviour',
    payment_issue: 'Payment Issue Not Resolved',
    duplicate: 'Duplicate Contact',
    other: 'Other',
};

const SUMMARY_RANGE_OPTIONS = [
    { value: 7, label: '7d' },
    { value: 30, label: '30d' },
    { value: 90, label: '90d' },
];

function daysUntil(dateString) {
    if (!dateString) return null;
    const target = new Date(dateString).getTime();
    if (Number.isNaN(target)) return null;
    const diffMs = target - Date.now();
    return Math.ceil(diffMs / (24 * 60 * 60 * 1000));
}

function formatPurgeDate(dateString) {
    if (!dateString) return '—';
    const date = new Date(dateString);
    if (Number.isNaN(date.getTime())) return '—';
    return date.toLocaleDateString(undefined, { day: 'numeric', month: 'short', year: 'numeric' });
}

function CountdownBadge({ purgeAfter }) {
    const days = daysUntil(purgeAfter);
    if (days === null) return <span className="text-xs text-slate-400">—</span>;

    const isPast = days <= 0;
    const tone = isPast
        ? 'bg-slate-200 text-slate-700'
        : days <= 7
            ? 'bg-rose-100 text-rose-800'
            : 'bg-amber-100 text-amber-800';

    return (
        <span className={`inline-flex items-center gap-1 rounded-full px-2.5 py-0.5 text-xs font-semibold ${tone}`}>
            {isPast ? 'Purging next run' : `Purges in ${days}d`}
            <span className="font-normal opacity-70">· {formatPurgeDate(purgeAfter)}</span>
        </span>
    );
}

function TrendPill({ delta }) {
    const value = Number(delta || 0);
    const tone = value > 0
        ? 'bg-amber-50 text-amber-700 ring-amber-200'
        : value < 0
            ? 'bg-emerald-50 text-emerald-700 ring-emerald-200'
            : 'bg-slate-50 text-slate-600 ring-slate-200';
    const label = value > 0 ? `+${value.toLocaleString()}` : value.toLocaleString();

    return (
        <span className={`inline-flex items-center rounded-full px-2 py-0.5 text-[11px] font-semibold ring-1 ${tone}`}>
            {label} vs prior period
        </span>
    );
}

function ClosedReasonsSummary({ data, isLoading, isError, rangeDays, onRangeChange, onOpenClient }) {
    const total = Number(data?.totals?.closed || 0);
    const withNotes = Number(data?.totals?.with_notes || 0);
    const topReason = data?.top_reason;
    const reasons = (data?.reasons || []).filter((reason) => Number(reason.count || 0) > 0);
    const maxCount = Math.max(1, ...reasons.map((reason) => Number(reason.count || 0)));

    return (
        <section className="overflow-hidden rounded-lg border border-slate-200 bg-white">
            <header className="flex flex-wrap items-start justify-between gap-3 border-b border-slate-100 px-4 py-3">
                <div>
                    <h3 className="text-sm font-semibold text-slate-900">Closed-lost reasons</h3>
                    <p className="mt-0.5 text-xs text-slate-500">Reason patterns from recently closed client cases.</p>
                </div>
                <div className="inline-flex rounded-md border border-slate-200 bg-slate-50 p-1" aria-label="Closed reasons range">
                    {SUMMARY_RANGE_OPTIONS.map((option) => {
                        const active = Number(rangeDays) === option.value;
                        return (
                            <button
                                key={option.value}
                                type="button"
                                onClick={() => onRangeChange(option.value)}
                                className={`rounded px-2.5 py-1 text-xs font-semibold transition focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-teal-500 ${
                                    active ? 'bg-white text-teal-700 shadow-sm' : 'text-slate-500 hover:text-slate-800'
                                }`}
                            >
                                {option.label}
                            </button>
                        );
                    })}
                </div>
            </header>

            {isLoading ? (
                <div className="grid gap-4 p-4 lg:grid-cols-[minmax(0,0.7fr)_minmax(0,1.3fr)]">
                    <div className="h-32 animate-pulse rounded-md bg-slate-100" />
                    <div className="space-y-2">
                        {[1, 2, 3, 4].map((item) => <div key={item} className="h-8 animate-pulse rounded-md bg-slate-100" />)}
                    </div>
                </div>
            ) : isError ? (
                <div className="px-4 py-8 text-sm text-rose-600">Could not load closed-lost reasons.</div>
            ) : total === 0 ? (
                <div className="px-4 py-8 text-sm text-slate-500">No closed cases in this range.</div>
            ) : (
                <div className="grid gap-4 p-4 lg:grid-cols-[minmax(260px,0.75fr)_minmax(0,1.25fr)]">
                    <div className="space-y-3">
                        <div className="rounded-md border border-slate-200 bg-slate-50 p-3">
                            <p className="text-[11px] font-semibold uppercase tracking-wide text-slate-500">Top reason</p>
                            <p className="mt-2 text-xl font-semibold text-slate-900">{topReason?.label || 'No reason captured'}</p>
                            <div className="mt-2 flex flex-wrap items-center gap-2 text-xs text-slate-500">
                                <span>{Number(topReason?.count || 0).toLocaleString()} of {total.toLocaleString()} cases</span>
                                <span>{Number(topReason?.share || 0).toFixed(1)}%</span>
                                <TrendPill delta={data?.totals?.delta} />
                            </div>
                        </div>
                        <div className="grid grid-cols-2 gap-2">
                            <div className="rounded-md border border-slate-200 p-3">
                                <p className="text-[11px] font-semibold uppercase tracking-wide text-slate-500">Closed</p>
                                <p className="mt-1 text-xl font-semibold text-slate-900">{total.toLocaleString()}</p>
                            </div>
                            <div className="rounded-md border border-slate-200 p-3">
                                <p className="text-[11px] font-semibold uppercase tracking-wide text-slate-500">With notes</p>
                                <p className="mt-1 text-xl font-semibold text-slate-900">{withNotes.toLocaleString()}</p>
                            </div>
                        </div>
                    </div>

                    <div className="space-y-3">
                        <div className="space-y-2">
                            {reasons.slice(0, 6).map((reason) => {
                                const count = Number(reason.count || 0);
                                const width = Math.max(6, Math.round((count / maxCount) * 100));

                                return (
                                    <div key={reason.code} className="grid gap-2 sm:grid-cols-[150px_minmax(0,1fr)_80px] sm:items-center">
                                        <div className="min-w-0">
                                            <p className="truncate text-xs font-semibold text-slate-700">{reason.label}</p>
                                            <p className="text-[11px] text-slate-400">{Number(reason.share || 0).toFixed(1)}%</p>
                                        </div>
                                        <div className="h-2.5 overflow-hidden rounded-full bg-slate-100">
                                            <div className="h-full rounded-full bg-teal-600" style={{ width: `${width}%` }} />
                                        </div>
                                        <div className="flex items-center justify-start gap-2 sm:justify-end">
                                            <span className="text-xs font-semibold text-slate-800">{count.toLocaleString()}</span>
                                            {Number(reason.delta || 0) !== 0 ? (
                                                <span className={`rounded-full px-1.5 py-0.5 text-[10px] font-semibold ${Number(reason.delta) > 0 ? 'bg-amber-50 text-amber-700' : 'bg-emerald-50 text-emerald-700'}`}>
                                                    {Number(reason.delta) > 0 ? '+' : ''}{Number(reason.delta).toLocaleString()}
                                                </span>
                                            ) : null}
                                        </div>
                                    </div>
                                );
                            })}
                        </div>

                        {data?.recent_notes?.length ? (
                            <div className="border-t border-slate-100 pt-3">
                                <p className="mb-2 text-[11px] font-semibold uppercase tracking-wide text-slate-500">Recent notes</p>
                                <div className="space-y-2">
                                    {data.recent_notes.map((note) => (
                                        <button
                                            key={note.id}
                                            type="button"
                                            onClick={() => onOpenClient(note.id)}
                                            className="block w-full rounded-md border border-slate-200 px-3 py-2 text-left transition hover:bg-slate-50 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-teal-500"
                                        >
                                            <div className="flex flex-wrap items-center gap-2 text-xs">
                                                <span className="font-semibold text-slate-800">{note.name || '—'}</span>
                                                <span className="text-slate-400">·</span>
                                                <span className="text-slate-500">{note.reason_label || REASON_LABELS[note.reason_code] || note.reason_code || 'Closed'}</span>
                                            </div>
                                            <p className="mt-1 line-clamp-1 text-xs text-slate-500">{note.reason_note}</p>
                                        </button>
                                    ))}
                                </div>
                            </div>
                        ) : null}
                    </div>
                </div>
            )}
        </section>
    );
}

export default function ClosedCasesView({ platformId = '' }) {
    const navigate = useNavigate();
    const queryClient = useQueryClient();
    const toast = useToast();
    const [page, setPage] = useState(1);
    const [summaryRangeDays, setSummaryRangeDays] = useState(30);
    const [reopenDialog, setReopenDialog] = useState({ open: false, client: null });

    useEffect(() => {
        setPage(1);
    }, [platformId]);

    const closedQuery = useQuery({
        queryKey: ['clients', 'closed', platformId || 'all', page],
        queryFn: () => api.get('/crm/clients', {
            params: {
                view: 'closed',
                page,
                per_page: 25,
                sort_by: 'closed_at',
                sort_direction: 'desc',
                ...(platformId ? { platform_id: Number(platformId) } : {}),
            },
        }).then((r) => r.data),
        keepPreviousData: true,
    });

    const reasonsSummaryQuery = useQuery({
        queryKey: ['clients', 'closed-reasons-summary', platformId || 'all', summaryRangeDays],
        queryFn: () => api.get('/crm/clients/closed-reasons-summary', {
            params: {
                range_days: summaryRangeDays,
                ...(platformId ? { platform_id: Number(platformId) } : {}),
            },
        }).then((r) => r.data),
        keepPreviousData: true,
    });

    const reopenMutation = useMutation({
        mutationFn: ({ clientId }) => api.post(`/crm/clients/${clientId}/reopen`).then((r) => r.data),
        onSuccess: () => {
            queryClient.invalidateQueries({ queryKey: ['clients'] });
            toast?.success?.('Case reopened.');
            setReopenDialog({ open: false, client: null });
        },
        onError: (err) => toast?.error?.(err?.response?.data?.message || 'Reopen failed.'),
    });

    const rows = closedQuery.data?.data ?? [];
    const stats = closedQuery.data?.stats ?? {};

    const cards = useMemo(() => ([
        { label: 'Closed last 7 days', value: stats.closed_recent_7d ?? 0 },
        { label: 'Closed last 30 days', value: stats.closed_recent ?? 0 },
        { label: 'Purging this week', value: stats.purging_soon ?? 0, tone: 'warning' },
    ]), [stats]);

    return (
        <section className="space-y-4">
            <div className="grid gap-3 md:grid-cols-3">
                {cards.map((c) => (
                    <div key={c.label} className={`rounded-lg border bg-white p-3 ${c.tone === 'warning' ? 'border-amber-200' : 'border-slate-200'}`}>
                        <p className={`text-[11px] font-semibold uppercase tracking-wide ${c.tone === 'warning' ? 'text-amber-700' : 'text-slate-500'}`}>{c.label}</p>
                        <p className="mt-1 text-2xl font-semibold text-slate-900">{Number(c.value).toLocaleString()}</p>
                    </div>
                ))}
            </div>

            <ClosedReasonsSummary
                data={reasonsSummaryQuery.data}
                isLoading={reasonsSummaryQuery.isLoading}
                isError={reasonsSummaryQuery.isError}
                rangeDays={summaryRangeDays}
                onRangeChange={setSummaryRangeDays}
                onOpenClient={(clientId) => navigate(`/clients/${clientId}`)}
            />

            <div className="overflow-hidden rounded-lg border border-slate-200 bg-white">
                <table className="min-w-full divide-y divide-slate-200 text-sm">
                    <thead className="bg-slate-50">
                        <tr>
                            <th className="px-4 py-2 text-left text-[11px] font-semibold uppercase tracking-wide text-slate-500">Client</th>
                            <th className="px-4 py-2 text-left text-[11px] font-semibold uppercase tracking-wide text-slate-500">Reason</th>
                            <th className="px-4 py-2 text-left text-[11px] font-semibold uppercase tracking-wide text-slate-500">Closed by</th>
                            <th className="px-4 py-2 text-left text-[11px] font-semibold uppercase tracking-wide text-slate-500">Purges in</th>
                            <th className="px-4 py-2 text-right text-[11px] font-semibold uppercase tracking-wide text-slate-500">Actions</th>
                        </tr>
                    </thead>
                    <tbody className="divide-y divide-slate-100">
                        {closedQuery.isLoading ? (
                            <tr><td colSpan={5} className="px-4 py-8 text-center text-sm text-slate-500">Loading closed cases…</td></tr>
                        ) : rows.length === 0 ? (
                            <tr><td colSpan={5} className="px-4 py-8 text-center text-sm text-slate-500">No closed cases. Closed cases auto-delete after 30 days.</td></tr>
                        ) : rows.map((row) => (
                            <tr key={row.id} className="hover:bg-slate-50">
                                <td className="px-4 py-2.5">
                                    <button type="button" onClick={() => navigate(`/clients/${row.id}`)} className="text-left">
                                        <p className="text-sm font-semibold text-slate-800 hover:text-teal-700">{row.name || '—'}</p>
                                        <p className="text-xs text-slate-500 crm-mono">{row.phone_normalized || ''}</p>
                                    </button>
                                </td>
                                <td className="px-4 py-2.5">
                                    <p className="text-sm text-slate-700">{REASON_LABELS[row.close_reason_code] || row.close_reason_code || '—'}</p>
                                    {row.close_reason_note ? (
                                        <p className="mt-0.5 line-clamp-1 max-w-xs text-xs text-slate-500" title={row.close_reason_note}>{row.close_reason_note}</p>
                                    ) : null}
                                </td>
                                <td className="px-4 py-2.5 text-xs text-slate-600">
                                    {row.closedBy?.name || row.closedBy?.email || '—'}
                                    {row.closed_at ? (
                                        <p className="text-[11px] text-slate-400">{new Date(row.closed_at).toLocaleDateString(undefined, { day: 'numeric', month: 'short' })}</p>
                                    ) : null}
                                </td>
                                <td className="px-4 py-2.5"><CountdownBadge purgeAfter={row.purge_after} /></td>
                                <td className="px-4 py-2.5 text-right">
                                    <button
                                        type="button"
                                        onClick={() => setReopenDialog({ open: true, client: row })}
                                        className="rounded-md border border-teal-300 bg-white px-3 py-1 text-xs font-semibold text-teal-700 transition hover:bg-teal-50"
                                    >
                                        Reopen
                                    </button>
                                </td>
                            </tr>
                        ))}
                    </tbody>
                </table>

                {closedQuery.data && closedQuery.data.last_page > 1 ? (
                    <div className="flex items-center justify-between border-t border-slate-100 px-4 py-2.5 text-xs text-slate-500">
                        <span>Page {closedQuery.data.current_page} of {closedQuery.data.last_page} • {closedQuery.data.total} total</span>
                        <div className="flex gap-1">
                            <button
                                type="button"
                                disabled={page <= 1}
                                onClick={() => setPage((p) => Math.max(1, p - 1))}
                                className="rounded-md border border-slate-200 px-2 py-1 text-xs font-medium disabled:opacity-40"
                            >
                                Prev
                            </button>
                            <button
                                type="button"
                                disabled={page >= closedQuery.data.last_page}
                                onClick={() => setPage((p) => p + 1)}
                                className="rounded-md border border-slate-200 px-2 py-1 text-xs font-medium disabled:opacity-40"
                            >
                                Next
                            </button>
                        </div>
                    </div>
                ) : null}
            </div>

            <ConfirmDialog
                open={reopenDialog.open}
                title={`Reopen case for ${reopenDialog.client?.name || 'this client'}?`}
                message="Reopening clears closed_at so the client returns to active queues. Failed payments stay resolved (they were closed under prior judgment)."
                confirmLabel="Reopen case"
                tone="default"
                isPending={reopenMutation.isPending}
                onCancel={() => { if (!reopenMutation.isPending) setReopenDialog({ open: false, client: null }); }}
                onConfirm={() => reopenMutation.mutate({ clientId: reopenDialog.client.id })}
            />
        </section>
    );
}
