import React, { useMemo, useState } from 'react';
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

export default function ClosedCasesView() {
    const navigate = useNavigate();
    const queryClient = useQueryClient();
    const toast = useToast();
    const [page, setPage] = useState(1);
    const [reopenDialog, setReopenDialog] = useState({ open: false, client: null });

    const closedQuery = useQuery({
        queryKey: ['clients', 'closed', page],
        queryFn: () => api.get('/crm/clients', {
            params: { view: 'closed', page, per_page: 25, sort_by: 'closed_at', sort_direction: 'desc' },
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
