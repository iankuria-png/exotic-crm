import React, { useEffect, useMemo, useState } from 'react';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { Link } from 'react-router-dom';
import PageHeader from '../components/PageHeader';
import { useAuth } from '../hooks/useAuth';
import { useToast } from '../components/ToastProvider';
import api from '../services/api';
import kyc from '../services/kyc';
import { aiLabel } from '../components/kyc/KycAiFindings';
import KycPanel from '../components/kyc/KycPanel';

function statusChip(status) {
    const value = String(status || 'unverified');
    if (value === 'approved') return 'bg-emerald-50 text-emerald-700 ring-emerald-200';
    if (value === 'in_review') return 'bg-sky-50 text-sky-700 ring-sky-200';
    if (value === 'info_requested') return 'bg-amber-50 text-amber-700 ring-amber-200';
    if (value === 'rejected') return 'bg-rose-50 text-rose-700 ring-rose-200';
    if (value === 'expired') return 'bg-violet-50 text-violet-700 ring-violet-200';
    return 'bg-slate-100 text-slate-700 ring-slate-200';
}

function formatDate(value) {
    if (!value) return '—';
    const date = new Date(value);
    if (Number.isNaN(date.getTime())) return '—';
    return date.toLocaleString();
}

function isHighRevenue(row) {
    const slug = String(row?.client?.active_deal?.product?.slug || row?.client?.active_deal?.product?.tier || '').toLowerCase();
    return slug.includes('vip') || slug.includes('premium') || slug.includes('featured');
}

export default function Kyc() {
    const { user } = useAuth();
    const toast = useToast();
    const queryClient = useQueryClient();
    const role = user?.role || '';
    const [statusFilter, setStatusFilter] = useState('');
    const [aiFilter, setAiFilter] = useState('');
    const [page, setPage] = useState(1);
    const [reviewRow, setReviewRow] = useState(null);
    const [sort, setSort] = useState('oldest_in_review');
    const [platformId, setPlatformId] = useState('');
    const [ageFilter, setAgeFilter] = useState('');
    const [mineOnly, setMineOnly] = useState(role === 'sales');
    const [selectedIds, setSelectedIds] = useState([]);
    useEffect(() => { setPage(1); setSelectedIds([]); }, [aiFilter, statusFilter, platformId, sort, ageFilter]);
    useEffect(() => {
        if (!reviewRow) return;
        const previous = document.activeElement;
        const dialog = document.querySelector('[data-kyc-review-dialog]');
        dialog?.querySelector('button')?.focus();
        const handler = (event) => {
            if (event.defaultPrevented) return;
            const nested = [...dialog.querySelectorAll('.fixed')].some(el => el !== dialog && el.offsetParent !== null);
            if (nested) return;
            if (event.key === 'Escape') setReviewRow(null);
            if (event.key === 'Tab') {
                const items = [...dialog.querySelectorAll('button:not(:disabled), a[href], input:not(:disabled), textarea:not(:disabled), select:not(:disabled)')].filter(el => el.offsetParent !== null);
                const first = items[0], last = items[items.length - 1];
                if (event.shiftKey && document.activeElement === first) {event.preventDefault();last?.focus();}
                else if (!event.shiftKey && document.activeElement === last) {event.preventDefault();first?.focus();}
            }
        };
        document.addEventListener('keydown', handler);
        return () => { document.removeEventListener('keydown', handler); previous?.focus(); };
    }, [reviewRow]);

    const settingsQuery = useQuery({
        queryKey: ['kyc-settings-summary'],
        queryFn: () => kyc.getSettings(),
    });

    const platformsQuery = useQuery({
        queryKey: ['kyc-platforms'],
        queryFn: () => api.get('/platforms').then((response) => response.data?.platforms || []),
    });

    const queueQuery = useQuery({
        queryKey: ['kyc-queue', { statusFilter, sort, platformId, aiFilter, page, ageFilter }],
        queryFn: () => kyc.getQueue({
            per_page: 25, page, ai_filter: aiFilter, age_days: ageFilter,
            sort,
            ...(statusFilter ? { status: statusFilter } : {}),
            ...(platformId ? { platform_id: platformId } : {}),
        }),
    });

    const bulkReRequestMutation = useMutation({
        mutationFn: () => kyc.bulkReRequest(selectedIds, 'Bulk re-verification requested from queue'),
        onSuccess: (payload) => {
            toast.success(`Queued ${payload.count || selectedIds.length} subjects for re-verification.`);
            setSelectedIds([]);
            queryClient.invalidateQueries({ queryKey: ['kyc-queue'] });
            queryClient.invalidateQueries({ queryKey: ['kyc-queue-count'] });
        },
        onError: (error) => toast.error(error?.response?.data?.message || 'Could not bulk re-request verification.'),
    });

    const enabledPlatformIds = settingsQuery.data?.settings?.enabled_platform_ids || [];
    const queueRows = queueQuery.data?.data || [];

    const visibleRows = useMemo(() => {
        let rows = [...queueRows];

        if (mineOnly && role === 'sales') {
            rows = rows.filter((row) => row?.client?.platform_id);
        }


        return rows;
    }, [queueRows, mineOnly, role, ageFilter]);

    const selectedRows = visibleRows.filter((row) => selectedIds.includes(row.id));
    const allVisibleSelected = visibleRows.length > 0 && visibleRows.every((row) => selectedIds.includes(row.id));

    const toggleAll = () => {
        if (allVisibleSelected) {
            setSelectedIds([]);
            return;
        }
        setSelectedIds(visibleRows.map((row) => row.id));
    };

    const toggleOne = (subjectId) => {
        setSelectedIds((current) => current.includes(subjectId)
            ? current.filter((id) => id !== subjectId)
            : [...current, subjectId]);
    };

    const queueDisabled = enabledPlatformIds.length === 0;

    return (
        <div className="space-y-4">
            <PageHeader
                title="KYC queue"
                subtitle="Review identity checks, resolve photo retakes, and give clear submissions their verified badge."
                actions={(
                    <div className="flex flex-wrap items-center gap-2">
                        <button
                            type="button"
                            onClick={() => kyc.exportQueueCsv(selectedRows.length > 0 ? selectedRows : visibleRows)}
                            disabled={visibleRows.length === 0}
                            className="inline-flex items-center rounded-lg border border-slate-200 bg-white px-3 py-2 text-sm font-medium text-slate-700 transition hover:bg-slate-50 disabled:cursor-not-allowed disabled:opacity-50"
                        >
                            Export CSV
                        </button>
                        {role === 'admin' ? (
                            <button
                                type="button"
                                onClick={() => bulkReRequestMutation.mutate()}
                                disabled={selectedIds.length === 0 || bulkReRequestMutation.isPending}
                                className="inline-flex items-center rounded-lg bg-slate-900 px-3 py-2 text-sm font-semibold text-white transition hover:bg-slate-800 disabled:cursor-not-allowed disabled:opacity-60"
                            >
                                {bulkReRequestMutation.isPending ? 'Updating…' : 'Bulk re-request'}
                            </button>
                        ) : null}
                    </div>
                )}
            />

            {queueDisabled ? (
                <section className="crm-surface px-5 py-6">
                    <div className="max-w-3xl space-y-3">
                        <h3 className="text-lg font-semibold text-slate-900">KYC is deployed, but no markets are enabled yet.</h3>
                        <p className="text-sm text-slate-600">That is the expected passive rollout posture. Once the playbook, training, translations, and market sign-off are ready, enable a platform in Settings → KYC.</p>
                        {role === 'admin' || role === 'sub_admin' ? (
                            <Link to="/settings?tab=kyc" className="inline-flex items-center rounded-lg bg-teal-700 px-4 py-2 text-sm font-semibold text-white transition hover:bg-teal-800">Open KYC settings</Link>
                        ) : (
                            <p className="text-sm text-slate-500">Ask an admin or sub-admin to finish the KYC setup wizard for the first market.</p>
                        )}
                    </div>
                </section>
            ) : (
                <>
                    <section className="crm-surface px-5 py-5">
                        <div className="grid gap-3 lg:grid-cols-[1.2fr_1fr_1fr_1fr_auto]">
                            <div>
                                <label className="mb-1 block text-sm font-medium text-slate-700">Platform</label>
                                <select value={platformId} onChange={(event) => setPlatformId(event.target.value)} className="crm-select w-full">
                                    <option value="">All enabled markets</option>
                                    {(platformsQuery.data || []).map((platform) => (
                                        <option key={platform.id} value={platform.id} disabled={!enabledPlatformIds.includes(Number(platform.id))}>
                                            {platform.name || platform.platform_name}{!enabledPlatformIds.includes(Number(platform.id)) ? ' — configure to enable' : ''}
                                        </option>
                                    ))}
                                </select>
                            </div>
                            <div>
                                <label className="mb-1 block text-sm font-medium text-slate-700">Status</label>
                                <select value={statusFilter} onChange={(event) => setStatusFilter(event.target.value)} className="crm-select w-full">
                                    <option value="">All statuses</option>
                                    <option value="in_review">In review</option>
                                    <option value="info_requested">Info requested</option>
                                    <option value="approved">Approved</option>
                                    <option value="rejected">Rejected</option>
                                    <option value="expired">Overdue / reverify</option>
                                </select>
                            </div>
                            <div>
                                <label className="mb-1 block text-sm font-medium text-slate-700">Sort</label>
                                <select value={sort} onChange={(event) => setSort(event.target.value)} className="crm-select w-full">
                                    <option value="oldest_in_review">Oldest in review first</option>
                                    <option value="overdue">Overdue first</option><option value="ai_flagged">Priority AI findings first</option>
                                </select>
                            </div>
                            <div>
                                <label className="mb-1 block text-sm font-medium text-slate-700">Age</label>
                                <select value={ageFilter} onChange={(event) => setAgeFilter(event.target.value)} className="crm-select w-full">
                                    <option value="">Any age</option>
                                    <option value="1">1+ day old</option>
                                    <option value="3">3+ days old</option>
                                    <option value="7">7+ days old</option>
                                    <option value="14">14+ days old</option>
                                </select>
                            </div>
                            {role === 'sales' ? (
                                <label className="flex items-end gap-2 pb-2 text-sm font-medium text-slate-700">
                                    <input type="checkbox" checked={mineOnly} onChange={(event) => setMineOnly(event.target.checked)} disabled className="h-4 w-4 rounded border-slate-300 text-teal-600" />
                                    Mine only
                                </label>
                            ) : <div />}
                        </div>
                    </section>

                    <div className="flex flex-wrap items-center gap-2" aria-label="Automated review filters">
                        {[['', 'All checks'], ['needs_human', 'Needs human'], ['qa', 'AI approvals · QA'], ['retake', 'Retake requested']].map(([key, label]) => <button key={key} type="button" aria-pressed={aiFilter === key} onClick={() => setAiFilter(key)} className={`rounded-lg border px-3 py-2 text-xs font-semibold ${aiFilter === key ? 'border-teal-700 bg-teal-50 text-teal-800' : 'border-slate-200 bg-white text-slate-600'}`}>{label}</button>)}
                        <span className="ml-auto text-xs tabular-nums text-slate-500">{queueQuery.data?.total || 0} submissions</span>
                    </div>
                    {queueQuery.isLoading ? <p role="status" className="p-6 text-sm text-slate-500">Loading submissions…</p> : null}
                    {queueQuery.isError ? <p role="alert" className="rounded-lg border border-amber-200 bg-amber-50 p-4 text-sm text-amber-800">Could not load the queue. <button type="button" onClick={() => queueQuery.refetch()} className="underline">Try again</button></p> : null}
                    <section className="crm-surface overflow-hidden px-0 py-0">
                        <div className="hidden overflow-x-auto sm:block">
                            <table className="min-w-full divide-y divide-slate-200">
                                <thead className="bg-slate-50">
                                    <tr>
                                        <th className="px-4 py-3 text-left text-xs font-semibold uppercase tracking-[0.12em] text-slate-500">
                                            <input type="checkbox" checked={allVisibleSelected} onChange={toggleAll} className="h-4 w-4 rounded border-slate-300 text-teal-600" />
                                        </th>
                                        <th className="px-4 py-3 text-left text-xs font-semibold uppercase tracking-[0.12em] text-slate-500">Client</th>
                                        <th className="px-4 py-3 text-left text-xs font-semibold uppercase tracking-[0.12em] text-slate-500">Market</th>
                                        <th className="px-4 py-3 text-left text-xs font-semibold uppercase tracking-[0.12em] text-slate-500">Status</th>
                                        <th className="px-4 py-3 text-left text-xs font-semibold uppercase tracking-[0.12em] text-slate-500">AI check</th>
                                        <th className="px-4 py-3 text-left text-xs font-semibold uppercase tracking-[0.12em] text-slate-500">Updated</th>
                                        <th className="px-4 py-3 text-right text-xs font-semibold uppercase tracking-[0.12em] text-slate-500">Review</th>
                                    </tr>
                                </thead>
                                <tbody className="divide-y divide-slate-200 bg-white">
                                    {visibleRows.map((row) => (
                                        <tr key={row.id} className="hover:bg-slate-50/80">
                                            <td className="px-4 py-4 align-top"><input type="checkbox" checked={selectedIds.includes(row.id)} onChange={() => toggleOne(row.id)} className="h-4 w-4 rounded border-slate-300 text-teal-600" /></td>
                                            <td className="px-4 py-4 align-top">
                                                <div className="flex items-start gap-2">
                                                    <div>
                                                        <p className="text-sm font-semibold text-slate-900">{row.client?.name || `Client #${row.client?.id}`}</p>
                                                        <p className="mt-1 text-xs text-slate-500">Subject #{row.id} • {row.client?.verified ? 'Public badge on' : 'Not publicly verified'}</p>
                                                    </div>
                                                    {isHighRevenue(row) ? <span className="inline-flex items-center rounded-md bg-violet-50 px-2 py-0.5 text-[11px] font-semibold text-violet-700 ring-1 ring-inset ring-violet-200">High revenue</span> : null}
                                                </div>
                                            </td>
                                            <td className="px-4 py-4 align-top text-sm text-slate-600">{row.client?.platform?.name || row.client?.platform?.platform_name || '—'}</td>
                                            <td className="px-4 py-4 align-top"><span className={`inline-flex items-center rounded-md px-2.5 py-1 text-xs font-semibold ring-1 ring-inset ${statusChip(row.status)}`}>{String(row.status || '').replaceAll('_', ' ')}</span></td>
                                            <td className="px-4 py-4 align-top text-sm text-slate-600">{aiLabel(row.ai_review)}</td>
                                            <td className="px-4 py-4 align-top text-sm text-slate-600">{formatDate(row.updated_at)}</td>
                                            <td className="px-4 py-4 text-right align-top">
                                                <button type="button" onClick={() => setReviewRow(row)} className="inline-flex items-center rounded-lg border border-slate-200 bg-white px-3 py-2 text-xs font-semibold text-slate-700 transition hover:bg-slate-50">Review</button>
                                            </td>
                                        </tr>
                                    ))}
                                </tbody>
                            </table>
                        </div>

                        <div className="space-y-3 p-4 sm:hidden">
                            {visibleRows.map((row) => (
                                <div key={row.id} className="rounded-2xl border border-slate-200 bg-white p-4 shadow-sm">
                                    <div className="flex items-start justify-between gap-3">
                                        <div>
                                            <p className="text-sm font-semibold text-slate-900">{row.client?.name || `Client #${row.client?.id}`}</p>
                                            <p className="mt-1 text-xs text-slate-500">{row.client?.platform?.name || row.client?.platform?.platform_name || 'Unknown market'}</p>
                                        </div>
                                        <input type="checkbox" checked={selectedIds.includes(row.id)} onChange={() => toggleOne(row.id)} className="mt-1 h-4 w-4 rounded border-slate-300 text-teal-600" />
                                    </div>
                                    <div className="mt-3 flex flex-wrap gap-2">
                                        <span className={`inline-flex items-center rounded-md px-2.5 py-1 text-xs font-semibold ring-1 ring-inset ${statusChip(row.status)}`}>{String(row.status || '').replaceAll('_', ' ')}</span>
                                        {isHighRevenue(row) ? <span className="inline-flex items-center rounded-md bg-violet-50 px-2.5 py-1 text-xs font-semibold text-violet-700 ring-1 ring-inset ring-violet-200">High revenue</span> : null}
                                    </div>
                                    <dl className="mt-3 grid gap-2 text-sm text-slate-600">
                                        <div className="flex items-center justify-between gap-3"><dt>AI check</dt><dd>{aiLabel(row.ai_review)}</dd></div>
                                        <div className="flex items-center justify-between gap-3"><dt>Updated</dt><dd>{formatDate(row.updated_at)}</dd></div>
                                    </dl>
                                    <button type="button" onClick={() => setReviewRow(row)} className="mt-4 inline-flex w-full items-center justify-center rounded-lg border border-slate-200 bg-white px-3 py-2 text-sm font-semibold text-slate-700 transition hover:bg-slate-50">Review submission</button>
                                </div>
                            ))}
                        </div>

                        {!queueQuery.isLoading && !queueQuery.isError && visibleRows.length === 0 ? (
                            <div className="border-t border-slate-200 px-5 py-8 text-center text-sm text-slate-500">No submissions match these filters.</div>
                        ) : null}
                    </section>
                    <nav className="flex items-center justify-between text-xs text-slate-600" aria-label="Queue pages"><button type="button" disabled={page <= 1} onClick={() => setPage(page - 1)} className="rounded-lg border border-slate-200 px-3 py-2 disabled:opacity-40">Previous</button><span>Page {page} of {queueQuery.data?.last_page || 1}</span><button type="button" disabled={page >= (queueQuery.data?.last_page || 1)} onClick={() => setPage(page + 1)} className="rounded-lg border border-slate-200 px-3 py-2 disabled:opacity-40">Next</button></nav>
                </>
            )}
            {reviewRow ? <div className="fixed inset-0 z-40 flex justify-end bg-slate-950/60 p-2 sm:p-5" onClick={(event) => {if (event.target === event.currentTarget) setReviewRow(null);}}><div role="dialog" aria-modal="true" aria-label={`Review ${reviewRow.client?.name || 'submission'}`} data-kyc-review-dialog className="w-full max-w-5xl overflow-y-auto rounded-xl bg-slate-50 shadow-xl"><div className="sticky top-0 z-10 flex items-center justify-between border-b border-slate-200 bg-white px-5 py-4"><h2 className="text-base font-semibold text-slate-900">{reviewRow.client?.name} · Identity review</h2><button type="button" onClick={() => setReviewRow(null)} className="rounded-lg border border-slate-200 px-3 py-2 text-sm text-slate-700">Close review</button></div><KycPanel client={{...reviewRow.client, kyc_subject: {id:reviewRow.id, status:reviewRow.status}}} canReview={['admin','sub_admin','sales'].includes(role)} /></div></div> : null}
        </div>
    );
}
