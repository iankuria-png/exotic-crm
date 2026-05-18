import React, { useMemo, useState } from 'react';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { Link } from 'react-router-dom';
import PageHeader from '../components/PageHeader';
import { useAuth } from '../hooks/useAuth';
import { useToast } from '../components/ToastProvider';
import api from '../services/api';
import kyc from '../services/kyc';

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
    const [sort, setSort] = useState('oldest_in_review');
    const [platformId, setPlatformId] = useState('');
    const [ageFilter, setAgeFilter] = useState('');
    const [mineOnly, setMineOnly] = useState(role === 'sales');
    const [selectedIds, setSelectedIds] = useState([]);

    const settingsQuery = useQuery({
        queryKey: ['kyc-settings-summary'],
        queryFn: () => kyc.getSettings(),
    });

    const platformsQuery = useQuery({
        queryKey: ['kyc-platforms'],
        queryFn: () => api.get('/platforms').then((response) => response.data?.platforms || []),
    });

    const queueQuery = useQuery({
        queryKey: ['kyc-queue', { statusFilter, sort, platformId }],
        queryFn: () => kyc.getQueue({
            per_page: 100,
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

        if (ageFilter) {
            const minimumDays = Number(ageFilter);
            if (Number.isFinite(minimumDays) && minimumDays > 0) {
                const now = Date.now();
                rows = rows.filter((row) => {
                    const updatedAt = new Date(row.updated_at || row.created_at || 0).getTime();
                    if (!updatedAt) return false;
                    const ageDays = (now - updatedAt) / (1000 * 60 * 60 * 24);
                    return ageDays >= minimumDays;
                });
            }
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
                subtitle="Triage lives here. Actual review work happens on the client detail page so the context, audit trail, and account history stay together."
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
                                    <option value="overdue">Overdue first</option>
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

                    <section className="crm-surface overflow-hidden px-0 py-0">
                        <div className="hidden sm:block">
                            <table className="min-w-full divide-y divide-slate-200">
                                <thead className="bg-slate-50">
                                    <tr>
                                        <th className="px-4 py-3 text-left text-xs font-semibold uppercase tracking-[0.12em] text-slate-500">
                                            <input type="checkbox" checked={allVisibleSelected} onChange={toggleAll} className="h-4 w-4 rounded border-slate-300 text-teal-600" />
                                        </th>
                                        <th className="px-4 py-3 text-left text-xs font-semibold uppercase tracking-[0.12em] text-slate-500">Client</th>
                                        <th className="px-4 py-3 text-left text-xs font-semibold uppercase tracking-[0.12em] text-slate-500">Market</th>
                                        <th className="px-4 py-3 text-left text-xs font-semibold uppercase tracking-[0.12em] text-slate-500">Status</th>
                                        <th className="px-4 py-3 text-left text-xs font-semibold uppercase tracking-[0.12em] text-slate-500">Source</th>
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
                                            <td className="px-4 py-4 align-top text-sm text-slate-600">{row.client?.verified_source || '—'}</td>
                                            <td className="px-4 py-4 align-top text-sm text-slate-600">{formatDate(row.updated_at)}</td>
                                            <td className="px-4 py-4 text-right align-top">
                                                <Link to={`/clients/${row.client?.id}`} className="inline-flex items-center rounded-lg border border-slate-200 bg-white px-3 py-1.5 text-xs font-semibold text-slate-700 transition hover:bg-slate-50">Open client</Link>
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
                                        <div className="flex items-center justify-between gap-3"><dt>Verified source</dt><dd>{row.client?.verified_source || '—'}</dd></div>
                                        <div className="flex items-center justify-between gap-3"><dt>Updated</dt><dd>{formatDate(row.updated_at)}</dd></div>
                                    </dl>
                                    <Link to={`/clients/${row.client?.id}`} className="mt-4 inline-flex w-full items-center justify-center rounded-lg border border-slate-200 bg-white px-3 py-2 text-sm font-semibold text-slate-700 transition hover:bg-slate-50">Open client</Link>
                                </div>
                            ))}
                        </div>

                        {!queueQuery.isLoading && visibleRows.length === 0 ? (
                            <div className="border-t border-slate-200 px-5 py-8 text-center text-sm text-slate-500">No subjects awaiting review. Nice work — check back later.</div>
                        ) : null}
                    </section>
                </>
            )}
        </div>
    );
}
