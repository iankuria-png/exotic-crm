import React, { useMemo, useState } from 'react';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import SectionFrame from '../SectionFrame';
import MetricCard from '../MetricCard';
import { InsightEmptyState } from '../shared/InsightStates';
import contactUnlocks from '../../services/contactUnlocks';
import { compactNumber, copyText, shortDate, titleize } from './visitorFormat';

/**
 * Safety slice of the Web Visitors workspace.
 *
 * Phase 7 recorded member safety reports but gave staff no way to move one, so
 * a member's status could only ever read "Received". This panel is the missing
 * half: read the queue, open a report, move its status, leave a staff-only note.
 *
 * It shows no member free text (none is stored) and no member identity beyond
 * whether an account link still exists. A report is a request for attention,
 * never an accusation to attribute in a table.
 */

const STATUS_TONE = {
    received: 'warning',
    under_review: 'neutral',
    closed: 'success',
};

const STATUS_LABEL = {
    received: 'Received',
    under_review: 'Under review',
    closed: 'Closed',
};

function StatusPill({ status }) {
    const tone = STATUS_TONE[status] || 'neutral';
    const cls = tone === 'warning'
        ? 'bg-amber-500/15 text-amber-300 ring-amber-500/30'
        : tone === 'success'
            ? 'bg-emerald-500/15 text-emerald-300 ring-emerald-500/30'
            : 'bg-slate-500/15 text-slate-300 ring-slate-500/30';

    return (
        <span className={`inline-flex items-center rounded px-2 py-0.5 text-xs font-semibold ring-1 ${cls}`}>
            {STATUS_LABEL[status] || titleize(status)}
        </span>
    );
}

function ReviewRow({ report, canManage, onSaved, toast }) {
    const [open, setOpen] = useState(false);
    const [status, setStatus] = useState(report.status);
    const [note, setNote] = useState(report.review_note || '');

    const mutation = useMutation({
        mutationFn: () => contactUnlocks.updateSafetyReport(report.id, { status, review_note: note }),
        onSuccess: () => {
            toast?.success?.(`${report.reference} moved to ${STATUS_LABEL[status] || status}.`);
            setOpen(false);
            onSaved?.();
        },
        onError: (error) => {
            toast?.error?.(error?.response?.data?.message || 'Could not update the report. Try again.');
        },
    });

    const dirty = status !== report.status || (note || '') !== (report.review_note || '');

    return (
        <>
            <tr className="border-t border-white/5 align-top">
                <td className="px-3 py-2">
                    <button
                        type="button"
                        className="font-mono text-xs text-slate-200 underline decoration-dotted underline-offset-4"
                        onClick={() => copyText(report.reference, toast)}
                        title="Copy reference"
                    >
                        {report.reference}
                    </button>
                    {!report.has_account_link && (
                        <div className="mt-0.5 text-[11px] text-slate-500">Account link removed by retention</div>
                    )}
                </td>
                <td className="px-3 py-2 text-sm text-slate-200">
                    {report.client_name || `Profile #${report.wp_post_id}`}
                    <div className="text-[11px] text-slate-500">{report.platform_name}</div>
                </td>
                <td className="px-3 py-2 text-sm text-slate-300">{titleize(report.category)}</td>
                <td className="px-3 py-2"><StatusPill status={report.status} /></td>
                <td className="px-3 py-2 text-xs text-slate-400">{shortDate(report.submitted_at)}</td>
                <td className="px-3 py-2 text-right">
                    {canManage ? (
                        <button
                            type="button"
                            className="crm-btn-secondary px-3 py-1.5 text-xs"
                            onClick={() => setOpen((value) => !value)}
                            aria-expanded={open}
                        >
                            {open ? 'Close' : 'Review'}
                        </button>
                    ) : (
                        <span className="text-xs text-slate-500">Read only</span>
                    )}
                </td>
            </tr>
            {open && canManage && (
                <tr className="bg-white/[0.02]">
                    <td colSpan={6} className="px-3 pb-4 pt-1">
                        <div className="grid gap-3 md:grid-cols-[220px_minmax(0,1fr)_auto] md:items-end">
                            <label className="block text-xs font-semibold text-slate-300">
                                Status
                                <select
                                    className="crm-input mt-1 w-full"
                                    value={status}
                                    onChange={(event) => setStatus(event.target.value)}
                                >
                                    <option value="received">Received</option>
                                    <option value="under_review">Under review</option>
                                    <option value="closed">Closed</option>
                                </select>
                            </label>
                            <label className="block text-xs font-semibold text-slate-300">
                                Staff note
                                <span className="ml-1 font-normal text-slate-500">Never shown to the member</span>
                                <input
                                    type="text"
                                    className="crm-input mt-1 w-full"
                                    value={note}
                                    maxLength={2000}
                                    placeholder="What did you check, and what did you decide?"
                                    onChange={(event) => setNote(event.target.value)}
                                />
                            </label>
                            <button
                                type="button"
                                className="crm-btn-primary px-4 py-2 text-xs"
                                disabled={!dirty || mutation.isPending}
                                onClick={() => mutation.mutate()}
                            >
                                {mutation.isPending ? 'Saving...' : 'Save'}
                            </button>
                        </div>
                        {report.reviewed_at && (
                            <p className="mt-2 text-[11px] text-slate-500">
                                Last moved {shortDate(report.reviewed_at)}
                                {report.reviewed_by ? ` by user #${report.reviewed_by}` : ''}
                            </p>
                        )}
                    </td>
                </tr>
            )}
        </>
    );
}

export default function VisitorSafetyPanel({ platformId = 'all', fromDate = '', toDate = '', toast }) {
    const queryClient = useQueryClient();
    const [statusFilter, setStatusFilter] = useState('all');
    const [categoryFilter, setCategoryFilter] = useState('all');
    const [search, setSearch] = useState('');
    const [page, setPage] = useState(1);

    const params = useMemo(() => {
        const next = { page, per_page: 25 };
        if (platformId && platformId !== 'all') next.platform_id = platformId;
        if (statusFilter !== 'all') next.status = statusFilter;
        if (categoryFilter !== 'all') next.category = categoryFilter;
        if (search.trim()) next.search = search.trim();
        if (fromDate) next.from = fromDate;
        if (toDate) next.to = toDate;
        return next;
    }, [categoryFilter, fromDate, page, platformId, search, statusFilter, toDate]);

    const query = useQuery({
        queryKey: ['customer-safety-reports', params],
        queryFn: () => contactUnlocks.getSafetyReports(params),
        staleTime: 30_000,
    });

    const data = query.data || {};
    const reports = data.reports || [];
    const counts = data.counts || {};
    const canManage = data.permissions?.can_manage !== false;
    const pagination = data.pagination || {};

    const refresh = () => queryClient.invalidateQueries({ queryKey: ['customer-safety-reports'] });

    return (
        <div className="space-y-4">
            <div className="grid gap-3 sm:grid-cols-2 xl:grid-cols-4">
                <MetricCard label="Open reports" value={compactNumber(counts.open)} tone={Number(counts.open || 0) > 0 ? 'warning' : 'neutral'} />
                <MetricCard label="Received" value={compactNumber(counts.received)} />
                <MetricCard label="Under review" value={compactNumber(counts.under_review)} />
                <MetricCard label="Closed" value={compactNumber(counts.closed)} tone="success" />
            </div>

            <SectionFrame
                title="Member safety reports"
                description="Reports filed by signed-in members. The member's written detail is emailed to staff and never stored here."
            >
                <div className="mb-3 flex flex-wrap items-end gap-3">
                    <label className="block text-xs font-semibold text-slate-300">
                        Status
                        <select
                            className="crm-input mt-1"
                            value={statusFilter}
                            onChange={(event) => { setStatusFilter(event.target.value); setPage(1); }}
                        >
                            <option value="all">All</option>
                            <option value="received">Received</option>
                            <option value="under_review">Under review</option>
                            <option value="closed">Closed</option>
                        </select>
                    </label>
                    <label className="block text-xs font-semibold text-slate-300">
                        Category
                        <select
                            className="crm-input mt-1"
                            value={categoryFilter}
                            onChange={(event) => { setCategoryFilter(event.target.value); setPage(1); }}
                        >
                            <option value="all">All</option>
                            {(data.categories || []).map((category) => (
                                <option key={category} value={category}>{titleize(category)}</option>
                            ))}
                        </select>
                    </label>
                    <label className="block text-xs font-semibold text-slate-300">
                        Reference or profile id
                        <input
                            type="search"
                            className="crm-input mt-1"
                            value={search}
                            placeholder="SR-1A2B3C4D"
                            onChange={(event) => { setSearch(event.target.value); setPage(1); }}
                        />
                    </label>
                </div>

                {query.isLoading ? (
                    <div className="space-y-2" aria-busy="true">
                        {[0, 1, 2, 3].map((row) => (
                            <div key={row} className="h-10 animate-pulse rounded bg-white/5" />
                        ))}
                    </div>
                ) : query.isError ? (
                    <InsightEmptyState
                        title="Reports are offline"
                        message="The safety queue could not be loaded. Everything else in this workspace still works."
                    />
                ) : reports.length === 0 ? (
                    <InsightEmptyState
                        title="No reports match"
                        message={statusFilter === 'all' && categoryFilter === 'all' && !search
                            ? 'No member has filed a safety report in this market and range. Nothing to action.'
                            : 'Nothing matches these filters. Clear them to see the whole queue.'}
                    />
                ) : (
                    <>
                        <div className="overflow-x-auto">
                            <table className="w-full min-w-[720px] text-left">
                                <thead>
                                    <tr className="text-[11px] uppercase tracking-wide text-slate-500">
                                        <th scope="col" className="px-3 py-2 font-semibold">Reference</th>
                                        <th scope="col" className="px-3 py-2 font-semibold">Reported profile</th>
                                        <th scope="col" className="px-3 py-2 font-semibold">Category</th>
                                        <th scope="col" className="px-3 py-2 font-semibold">Status</th>
                                        <th scope="col" className="px-3 py-2 font-semibold">Submitted</th>
                                        <th scope="col" className="px-3 py-2 text-right font-semibold">Action</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    {reports.map((report) => (
                                        <ReviewRow
                                            key={report.id}
                                            report={report}
                                            canManage={canManage}
                                            onSaved={refresh}
                                            toast={toast}
                                        />
                                    ))}
                                </tbody>
                            </table>
                        </div>
                        {pagination.last_page > 1 && (
                            <div className="mt-3 flex items-center justify-between text-xs text-slate-400">
                                <span>Page {pagination.page} of {pagination.last_page} · {compactNumber(pagination.total)} reports</span>
                                <div className="flex gap-2">
                                    <button
                                        type="button"
                                        className="crm-btn-secondary px-3 py-1.5 text-xs"
                                        disabled={pagination.page <= 1}
                                        onClick={() => setPage((value) => Math.max(1, value - 1))}
                                    >
                                        Previous
                                    </button>
                                    <button
                                        type="button"
                                        className="crm-btn-secondary px-3 py-1.5 text-xs"
                                        disabled={pagination.page >= pagination.last_page}
                                        onClick={() => setPage((value) => value + 1)}
                                    >
                                        Next
                                    </button>
                                </div>
                            </div>
                        )}
                    </>
                )}
            </SectionFrame>
        </div>
    );
}
