import React, { useMemo, useState } from 'react';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { useNavigate } from 'react-router-dom';
import api from '../services/api';
import DataTable from '../components/DataTable';
import MetricCard from '../components/MetricCard';
import PageHeader from '../components/PageHeader';
import StatusBadge from '../components/StatusBadge';

function expiryTone(daysLeft) {
    if (daysLeft === null || daysLeft === undefined) return 'text-slate-500';
    if (daysLeft < 0) return 'text-rose-700';
    if (daysLeft <= 3) return 'text-rose-700';
    if (daysLeft <= 14) return 'text-amber-700';
    return 'text-emerald-700';
}

function bucketLabel(bucket) {
    if (bucket === 'risk') return 'At Risk';
    if (bucket === 'pending') return 'Pending';
    if (bucket === 'stable') return 'Stable';
    if (bucket === 'expired') return 'Expired';
    if (bucket === 'lapsed') return 'Lapsed';
    if (bucket === 'paused') return 'Paused';
    return 'Unknown';
}

export default function Renewals() {
    const navigate = useNavigate();
    const queryClient = useQueryClient();
    const [page, setPage] = useState(1);
    const [searchInput, setSearchInput] = useState('');
    const [search, setSearch] = useState('');
    const [bucketFilter, setBucketFilter] = useState('');
    const [activeDeal, setActiveDeal] = useState(null);
    const [clearSelectionKey, setClearSelectionKey] = useState(0);
    const [feedback, setFeedback] = useState(null);
    const [perPage, setPerPage] = useState(50);
    const [isGlobalSelected, setIsGlobalSelected] = useState(false);
    const [selectedRows, setSelectedRows] = useState([]);
    const [renewDialog, setRenewDialog] = useState({
        open: false,
        deal: null,
        days: '30',
        reason: 'Manual renewal from renewals workspace',
    });
    const [pauseDialog, setPauseDialog] = useState({
        open: false,
        deal: null,
        pauseUntil: '',
        reason: 'Pause reminders from renewals workspace',
    });
    const [resumeDialog, setResumeDialog] = useState({
        open: false,
        deal: null,
        reason: 'Resume reminders from renewals workspace',
    });

    const { data, isLoading } = useQuery({
        queryKey: ['renewals-overview', page, search, bucketFilter, perPage],
        queryFn: () =>
            api.get('/crm/renewals', {
                params: {
                    page,
                    per_page: perPage,
                    ...(search ? { search } : {}),
                    ...(bucketFilter ? { bucket: bucketFilter } : {}),
                },
            }).then((response) => response.data),
    });

    const runCampaignsMutation = useMutation({
        mutationFn: () => api.post('/crm/renewals/run', {}).then((response) => response.data),
        onSuccess: (result) => {
            queryClient.invalidateQueries({ queryKey: ['renewals-overview'] });
            const totals = result?.totals || {};
            setFeedback({
                tone: totals.failed > 0 ? 'warning' : 'success',
                text: `Campaign run complete: ${totals.sent || 0} sent, ${totals.failed || 0} failed, ${totals.skipped || 0} skipped.`,
            });
        },
        onError: (error) => {
            setFeedback({
                tone: 'warning',
                text: error?.response?.data?.message || 'Campaign run failed.',
            });
        },
    });

    const remindMutation = useMutation({
        mutationFn: ({ dealId, clientId, templateId }) =>
            api.post('/crm/renewals/remind', {
                deal_id: dealId,
                client_id: clientId,
                template_id: templateId,
            }).then((response) => response.data),
        onSuccess: () => {
            queryClient.invalidateQueries({ queryKey: ['renewals-overview'] });
        },
    });

    const manualRenewMutation = useMutation({
        mutationFn: ({ dealId, days, reason }) =>
            api.post(`/crm/deals/${dealId}/extend`, {
                additional_days: Number(days),
                reason,
            }).then((response) => response.data),
        onSuccess: (_, variables) => {
            queryClient.invalidateQueries({ queryKey: ['renewals-overview'] });
            queryClient.invalidateQueries({ queryKey: ['dashboard'] });
            setRenewDialog({
                open: false,
                deal: null,
                days: '30',
                reason: 'Manual renewal from renewals workspace',
            });
            setFeedback({
                tone: 'success',
                text: `Subscription renewed for ${variables.days} days.`,
            });
        },
        onError: (error) => {
            setFeedback({
                tone: 'warning',
                text: error?.response?.data?.message || 'Manual renewal failed.',
            });
        },
    });

    const pauseRemindersMutation = useMutation({
        mutationFn: ({ dealId, clientId, pauseUntil, reason }) =>
            api.post('/crm/renewals/pause', {
                deal_id: dealId,
                client_id: clientId,
                pause_until: pauseUntil || null,
                reason,
            }).then((response) => response.data),
        onSuccess: (_, variables) => {
            queryClient.invalidateQueries({ queryKey: ['renewals-overview'] });
            setPauseDialog({
                open: false,
                deal: null,
                pauseUntil: '',
                reason: 'Pause reminders from renewals workspace',
            });
            setFeedback({
                tone: 'success',
                text: `Reminders paused for ${variables.dealName || 'subscription'}.`,
            });
        },
        onError: (error) => {
            setFeedback({
                tone: 'warning',
                text: error?.response?.data?.message || 'Pause reminders failed.',
            });
        },
    });

    const resumeRemindersMutation = useMutation({
        mutationFn: ({ dealId, reason }) =>
            api.post('/crm/renewals/resume', {
                deal_id: dealId,
                reason,
            }).then((response) => response.data),
        onSuccess: (_, variables) => {
            queryClient.invalidateQueries({ queryKey: ['renewals-overview'] });
            setResumeDialog({
                open: false,
                deal: null,
                reason: 'Resume reminders from renewals workspace',
            });
            setFeedback({
                tone: 'success',
                text: `Reminders resumed for ${variables.dealName || 'subscription'}.`,
            });
        },
        onError: (error) => {
            setFeedback({
                tone: 'warning',
                text: error?.response?.data?.message || 'Resume reminders failed.',
            });
        },
    });

    const bulkRemindMutation = useMutation({
        mutationFn: async ({ selection, selectAll }) => {
            const response = await api.post('/crm/renewals/bulk-remind', {
                selection: selectAll ? [] : selection.map(row => ({
                    deal_id: row.id,
                    client_id: row.client_id,
                    expires_at: row.expires_at
                })),
                select_all: selectAll,
                search,
                bucket: bucketFilter
            });
            return response.data;
        },
        onSuccess: (result) => {
            queryClient.invalidateQueries({ queryKey: ['renewals-overview'] });
            setClearSelectionKey((value) => value + 1);
            setIsGlobalSelected(false);
            setFeedback({
                tone: result.failed > 0 ? 'warning' : 'success',
                text: `Reminder batch complete: ${result.success}/${result.total}${result.failed ? ` (${result.failed} failed)` : ''}.`,
            });
        },
        onError: (error) => {
            setFeedback({
                tone: 'warning',
                text: error?.response?.data?.message || 'Bulk reminder failed.',
            });
        }
    });

    const handleSearch = (event) => {
        event.preventDefault();
        setSearch(searchInput.trim());
        setPage(1);
    };

    const rows = data?.targets?.data || [];
    const activeDealRow = useMemo(() => {
        if (!activeDeal) {
            return null;
        }

        return rows.find((row) => {
            if (row.is_virtual && activeDeal.is_virtual) {
                return Number(row.client_id) === Number(activeDeal.client_id);
            }
            return Number(row.id) === Number(activeDeal.id);
        }) || activeDeal;
    }, [activeDeal, rows]);

    const summary = {
        risk: data?.summary?.risk ?? 0,
        pending: data?.summary?.pending ?? 0,
        renewedThisMonth: data?.summary?.renewed_this_month ?? 0,
        paused: data?.summary?.paused_reminders ?? 0,
        expired: data?.summary?.expired_deals ?? 0,
        lapsed: data?.summary?.lapsed_deals ?? 0,
    };

    const activeCampaigns = useMemo(
        () => (data?.campaigns || []).filter((campaign) => !!campaign.enabled).length,
        [data?.campaigns],
    );

    const lastRun = data?.recent_runs?.[0];

    const columns = [
        {
            key: 'client',
            label: 'Client',
            render: (row) => (
                <div className="flex flex-col">
                    <div className="flex items-center gap-1.5">
                        <span className="text-sm font-semibold text-slate-900">{row.client?.name || 'Unknown'}</span>
                        {row.is_virtual && (
                            <span className="inline-flex items-center rounded-sm bg-slate-100 px-1 py-0.5 text-[10px] font-medium text-slate-600 ring-1 ring-inset ring-slate-200">
                                Legacy Record
                            </span>
                        )}
                    </div>
                    <span className="crm-mono text-xs text-slate-500">{row.client?.phone_normalized || ''}</span>
                </div>
            ),
        },
        {
            key: 'package',
            label: 'Package',
            render: (row) => (
                <div>
                    <p className="text-sm text-slate-800">{row.product?.name || row.plan_type}</p>
                    <p className="text-xs uppercase tracking-wide text-slate-500">{row.duration}</p>
                </div>
            ),
        },
        {
            key: 'expires_at',
            label: 'Expires',
            render: (row) => (
                <div>
                    <p className={`text-sm font-semibold ${expiryTone(row.days_left)}`}>
                        {row.expires_at ? new Date(row.expires_at).toLocaleDateString() : 'Not set'}
                    </p>
                    <p className="text-xs text-slate-500">{row.days_left === null || row.days_left === undefined ? '--' : `${row.days_left} days`}</p>
                </div>
            ),
        },
        {
            key: 'status',
            label: 'Status',
            render: (row) => <StatusBadge status={row.status} />,
        },
        {
            key: 'renewal_bucket',
            label: 'Renewal State',
            render: (row) => (
                <span className={`inline-flex items-center rounded-md px-2.5 py-0.5 text-xs font-medium ring-1 ring-inset ${row.renewal_bucket === 'paused'
                    ? 'bg-slate-100 text-slate-700 ring-slate-300'
                    : row.renewal_bucket === 'risk'
                        ? 'bg-rose-50 text-rose-700 ring-rose-200'
                        : row.renewal_bucket === 'pending'
                            ? 'bg-amber-50 text-amber-700 ring-amber-200'
                            : row.renewal_bucket === 'expired'
                                ? 'bg-slate-200 text-slate-700 ring-slate-300'
                                : row.renewal_bucket === 'lapsed'
                                    ? 'bg-rose-100 text-rose-800 ring-rose-300'
                                    : 'bg-emerald-50 text-emerald-700 ring-emerald-200'
                    }`}>
                    {bucketLabel(row.renewal_bucket)}
                </span>
            ),
        },
        {
            key: 'reminders_sent_count',
            label: 'Reminders',
            render: (row) => {
                const sent = Number(row.reminders_sent_count || 0);
                const failed = Number(row.reminders_failed_count || 0);
                return (
                    <div>
                        <p className="text-xs font-semibold text-slate-800">{sent} sent{failed > 0 ? ` • ${failed} failed` : ''}</p>
                        <p className="text-[11px] text-slate-500">
                            {row.last_renewal_reminder_at ? new Date(row.last_renewal_reminder_at).toLocaleString() : 'No reminders yet'}
                        </p>
                        {row.reminders_paused ? (
                            <p className="text-[11px] font-medium text-amber-700">
                                Paused {row.renewal_paused_until ? `until ${new Date(row.renewal_paused_until).toLocaleDateString()}` : 'until resumed'}
                            </p>
                        ) : null}
                    </div>
                );
            },
        },
        {
            key: 'actions',
            label: 'Actions',
            render: (row) => (
                <div className="flex items-center gap-1.5">
                    <button
                        onClick={(event) => {
                            event.stopPropagation();
                            remindMutation.mutate({
                                dealId: row.is_virtual ? null : row.id,
                                clientId: row.client_id,
                            }, {
                                onSuccess: () => setFeedback({ tone: 'success', text: `Reminder sent for ${row.client?.name || 'client'}.` }),
                                onError: () => setFeedback({ tone: 'warning', text: `Reminder failed for ${row.client?.name || 'client'}.` }),
                            });
                        }}
                        disabled={remindMutation.isPending || row.reminders_paused}
                        className="crm-btn-secondary px-3 py-1.5 text-xs disabled:cursor-not-allowed disabled:opacity-50"
                    >
                        {row.reminders_paused ? 'Paused' : (remindMutation.isPending ? '...' : 'Remind')}
                    </button>
                    <button
                        type="button"
                        onClick={(event) => {
                            event.stopPropagation();
                            setActiveDeal(row);
                        }}
                        className="rounded-md bg-slate-900 px-3 py-1.5 text-xs font-semibold text-white transition hover:bg-slate-800"
                    >
                        Manage
                    </button>
                </div>
            ),
        },
    ];

    const bulkActions = [
        {
            key: 'bulk-remind',
            label: isGlobalSelected ? `Send reminders to all ${data?.targets?.total || ''} matches` : 'Send reminder',
            loadingLabel: 'Sending...',
            variant: 'primary',
            onClick: async (rowsSelection) => {
                await bulkRemindMutation.mutateAsync({
                    selection: rowsSelection,
                    selectAll: isGlobalSelected
                });
            },
        },
    ];

    return (
        <div className="space-y-4">
            <PageHeader
                title="Renewals"
                subtitle={data?.targets?.total ? `${data.targets.total.toLocaleString()} renewal targets in scope` : 'Manage upcoming expiries and SMS campaign runs.'}
                actions={(
                    <button
                        type="button"
                        onClick={() => runCampaignsMutation.mutate()}
                        disabled={runCampaignsMutation.isPending}
                        className="crm-btn-primary disabled:cursor-not-allowed disabled:opacity-50"
                    >
                        {runCampaignsMutation.isPending ? 'Running...' : 'Run Campaigns'}
                    </button>
                )}
            />

            <section className="grid gap-4 md:grid-cols-4 xl:grid-cols-7">
                <MetricCard label="At Risk (0-3 days)" value={summary.risk.toLocaleString()} meta="urgent outreach" tone="danger" />
                <MetricCard label="Pending (4-14 days)" value={summary.pending.toLocaleString()} meta="scheduled reminders" tone="warning" />
                <MetricCard label="Renewed This Month" value={summary.renewedThisMonth.toLocaleString()} meta="already extended" tone="success" />
                <MetricCard label="Paused Reminders" value={summary.paused.toLocaleString()} meta="manual hold state" tone="warning" />
                <MetricCard label="Active Campaigns" value={activeCampaigns.toLocaleString()} meta="enabled automation rules" tone="accent" />
                <MetricCard label="Recently Expired" value={summary.expired.toLocaleString()} meta="within 14 days" tone="danger" />
                <MetricCard label="Lapsed" value={summary.lapsed.toLocaleString()} meta="expired > 14 days" tone="neutral" />
            </section>

            {lastRun ? (
                <section className="crm-surface p-4">
                    <p className="text-xs uppercase tracking-[0.12em] text-slate-500">Most Recent Renewal Run</p>
                    <p className="mt-1 text-sm font-semibold text-slate-900">
                        {new Date(lastRun.run_at).toLocaleString()} • {lastRun.status}
                    </p>
                    <p className="mt-1 text-xs text-slate-600">
                        Targeted: {lastRun.total_targeted} • Sent: {lastRun.sent_count} • Failed: {lastRun.failed_count} • Skipped: {lastRun.skipped_count}
                    </p>
                </section>
            ) : null}

            <section className="crm-filter-row">
                <div className="flex flex-wrap items-center gap-3">
                    <form onSubmit={handleSearch} className="min-w-[240px] flex-1">
                        <div className="relative">
                            <input
                                value={searchInput}
                                onChange={(event) => setSearchInput(event.target.value)}
                                placeholder="Search by client name or phone..."
                                className="crm-input pr-10"
                            />
                            <button type="submit" aria-label="Run renewals search" className="absolute right-2 top-1/2 -translate-y-1/2 rounded p-1 text-slate-400 transition hover:text-slate-600">
                                <svg className="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
                                    <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z" />
                                </svg>
                            </button>
                        </div>
                    </form>

                    <select
                        value={bucketFilter}
                        onChange={(event) => {
                            setBucketFilter(event.target.value);
                            setPage(1);
                        }}
                        className="crm-select"
                    >
                        <option value="">All states</option>
                        <option value="risk">At Risk</option>
                        <option value="pending">Pending</option>
                        <option value="stable">Stable</option>
                        <option value="expired">Expired</option>
                        <option value="lapsed">Lapsed</option>
                        <option value="paused">Paused</option>
                    </select>

                    <div className="flex items-center gap-2">
                        <span className="text-xs font-medium text-slate-500">Per page:</span>
                        <select
                            value={perPage}
                            onChange={(e) => {
                                setPerPage(Number(e.target.value));
                                setPage(1);
                            }}
                            className="crm-select py-1 text-xs"
                        >
                            {[25, 50, 100, 250, 500].map(n => (
                                <option key={n} value={n}>{n}</option>
                            ))}
                        </select>
                    </div>

                    {(search || bucketFilter) ? (
                        <button
                            type="button"
                            onClick={() => {
                                setSearch('');
                                setSearchInput('');
                                setBucketFilter('');
                                setPage(1);
                            }}
                            className="crm-btn-secondary px-3 py-2"
                        >
                            Reset
                        </button>
                    ) : null}
                </div>

                {feedback ? (
                    <p className={`mt-2 text-xs font-medium ${feedback.tone === 'success' ? 'text-emerald-700' : 'text-amber-700'}`}>
                        {feedback.text}
                    </p>
                ) : null}
            </section>

            {selectedRows.length === (data?.targets?.data?.length || 0) && data?.targets?.total > selectedRows.length && !isGlobalSelected ? (
                <div className="rounded-lg border border-teal-100 bg-teal-50/50 p-3 text-center">
                    <p className="text-sm text-teal-800 font-medium">
                        All {selectedRows.length} visible records are selected.{' '}
                        <button
                            type="button"
                            onClick={() => setIsGlobalSelected(true)}
                            className="text-teal-700 underline hover:text-teal-900 ml-1"
                        >
                            Select all {data.targets.total.toLocaleString()} records matching these filters
                        </button>
                    </p>
                </div>
            ) : isGlobalSelected ? (
                <div className="rounded-lg border border-teal-200 bg-teal-100/50 p-3 text-center">
                    <p className="text-sm text-teal-900 font-medium">
                        All {data?.targets?.total?.toLocaleString()} records matching current filters are selected.{' '}
                        <button
                            type="button"
                            onClick={() => setIsGlobalSelected(false)}
                            className="text-teal-700 underline hover:text-teal-900 ml-1"
                        >
                            Clear global selection
                        </button>
                    </p>
                </div>
            ) : null}

            <DataTable
                columns={columns}
                data={rows}
                pagination={data?.targets}
                onPageChange={setPage}
                onRowClick={(row) => setActiveDeal(row)}
                isLoading={isLoading}
                emptyMessage="No renewals match current filters."
                compact
                selectable
                bulkActions={bulkActions}
                onSelectionChange={setSelectedRows}
                clearSelectionKey={clearSelectionKey}
            />

            {activeDealRow ? (
                <div className="fixed inset-0 z-40 bg-slate-900/25" onClick={() => setActiveDeal(null)}>
                    <aside
                        className="absolute right-0 top-0 h-full w-full max-w-md overflow-y-auto border-l border-slate-200 bg-white shadow-2xl"
                        onClick={(event) => event.stopPropagation()}
                    >
                        <header className="crm-panel-header sticky top-0 z-10 border-b border-slate-100 bg-white">
                            <div>
                                <h3 className="crm-panel-title">Renewal Control Panel</h3>
                                <p className="crm-panel-subtitle">{activeDealRow.client?.name || 'Unknown client'} • {activeDealRow.product?.name || activeDealRow.plan_type}</p>
                            </div>
                            <button
                                type="button"
                                className="crm-btn-secondary px-2 py-1 text-xs"
                                onClick={() => setActiveDeal(null)}
                            >
                                Close
                            </button>
                        </header>

                        <div className="space-y-4 p-4">
                            <section className="rounded-md border border-slate-200 bg-slate-50 p-3 text-sm">
                                <p className="font-semibold text-slate-900">Subscription Snapshot</p>
                                <p className="mt-1 text-slate-600">Expires: {activeDealRow.expires_at ? new Date(activeDealRow.expires_at).toLocaleString() : 'Not set'}</p>
                                <p className="text-slate-600">Days left: {activeDealRow.days_left ?? '--'}</p>
                                <p className="text-slate-600">State: {bucketLabel(activeDealRow.renewal_bucket)}</p>
                                <p className="text-slate-600">
                                    Reminders: {Number(activeDealRow.reminders_sent_count || 0)} sent
                                    {Number(activeDealRow.reminders_failed_count || 0) > 0 ? ` • ${Number(activeDealRow.reminders_failed_count)} failed` : ''}
                                </p>
                                {activeDealRow.reminders_paused ? (
                                    <p className="mt-1 font-medium text-amber-700">
                                        Paused {activeDealRow.renewal_paused_until ? `until ${new Date(activeDealRow.renewal_paused_until).toLocaleDateString()}` : 'until resumed'}
                                    </p>
                                ) : null}
                            </section>

                            <section className="space-y-2">
                                <p className="text-xs font-semibold uppercase tracking-[0.1em] text-slate-500">Actions</p>
                                <div className="grid gap-2 sm:grid-cols-2">
                                    <button
                                        type="button"
                                        onClick={() => {
                                            remindMutation.mutate({
                                                dealId: activeDealRow.is_virtual ? null : activeDealRow.id,
                                                clientId: activeDealRow.client_id,
                                            }, {
                                                onSuccess: () => setFeedback({ tone: 'success', text: `Reminder sent for ${activeDealRow.client?.name || 'client'}.` }),
                                                onError: () => setFeedback({ tone: 'warning', text: `Reminder failed for ${activeDealRow.client?.name || 'client'}.` }),
                                            });
                                        }}
                                        disabled={activeDealRow.reminders_paused || remindMutation.isPending}
                                        className="crm-btn-secondary text-xs"
                                    >
                                        {activeDealRow.reminders_paused ? 'Reminders paused' : (remindMutation.isPending ? 'Sending...' : 'Send reminder')}
                                    </button>
                                    <button
                                        type="button"
                                        onClick={() => {
                                            if (activeDealRow.is_virtual) {
                                                setFeedback({ tone: 'warning', text: 'Please create a subscription for this client first to use manual extension.' });
                                                return;
                                            }
                                            setRenewDialog({
                                                open: true,
                                                deal: activeDealRow,
                                                days: '30',
                                                reason: 'Manual renewal from renewals workspace',
                                            });
                                        }}
                                        className={`crm-btn-primary text-xs ${activeDealRow.is_virtual ? 'opacity-50 grayscale' : ''}`}
                                    >
                                        Manual renew
                                    </button>
                                    <button
                                        type="button"
                                        onClick={() => {
                                            if (activeDealRow.reminders_paused) {
                                                setResumeDialog({
                                                    open: true,
                                                    deal: activeDealRow,
                                                    reason: 'Resume reminders from renewals workspace',
                                                });
                                                return;
                                            }

                                            setPauseDialog({
                                                open: true,
                                                deal: activeDealRow,
                                                pauseUntil: '',
                                                reason: 'Pause reminders from renewals workspace',
                                            });
                                        }}
                                        className="crm-btn-secondary text-xs"
                                    >
                                        {activeDealRow.reminders_paused ? 'Resume reminders' : 'Pause reminders'}
                                    </button>
                                    <button
                                        type="button"
                                        onClick={() => {
                                            if (activeDealRow.client?.id) {
                                                navigate(`/clients/${activeDealRow.client.id}`);
                                            }
                                        }}
                                        className="crm-btn-secondary text-xs"
                                    >
                                        View client profile
                                    </button>
                                    <button
                                        type="button"
                                        onClick={() => {
                                            if (activeDealRow.client?.id) {
                                                navigate(`/clients/${activeDealRow.client.id}?tab=payments`);
                                            }
                                        }}
                                        className="crm-btn-secondary text-xs sm:col-span-2"
                                    >
                                        View payment history
                                    </button>
                                </div>
                            </section>

                            <section className="rounded-md border border-slate-200 bg-white p-3 text-xs text-slate-600">
                                <p className="font-semibold text-slate-900">Progressive disclosure</p>
                                <p className="mt-1">Use this panel for high-impact actions while keeping the table focused on triage.</p>
                            </section>
                        </div>
                    </aside>
                </div>
            ) : null}

            {renewDialog.open && renewDialog.deal ? (
                <div
                    className="fixed inset-0 z-50 flex items-center justify-center bg-slate-900/45 p-4"
                    onClick={() => setRenewDialog({ open: false, deal: null, days: '30', reason: 'Manual renewal from renewals workspace' })}
                >
                    <div
                        className="w-full max-w-lg rounded-lg border border-slate-200 bg-white shadow-xl"
                        onClick={(event) => event.stopPropagation()}
                    >
                        <header className="crm-panel-header">
                            <div>
                                <h3 className="crm-panel-title">Manual Renew Subscription</h3>
                                <p className="crm-panel-subtitle">
                                    {renewDialog.deal.client?.name || 'Unknown client'} • {renewDialog.deal.product?.name || renewDialog.deal.plan_type}
                                </p>
                            </div>
                        </header>

                        <div className="space-y-3 p-4">
                            <div>
                                <label htmlFor="renew-days" className="mb-1 block text-sm font-medium text-slate-700">
                                    Additional days
                                </label>
                                <input
                                    id="renew-days"
                                    type="number"
                                    min={1}
                                    value={renewDialog.days}
                                    onChange={(event) => setRenewDialog((current) => ({ ...current, days: event.target.value }))}
                                    className="crm-input"
                                />
                            </div>

                            <div>
                                <label htmlFor="renew-reason" className="mb-1 block text-sm font-medium text-slate-700">
                                    Reason
                                </label>
                                <textarea
                                    id="renew-reason"
                                    rows={3}
                                    value={renewDialog.reason}
                                    onChange={(event) => setRenewDialog((current) => ({ ...current, reason: event.target.value }))}
                                    className="crm-input"
                                />
                            </div>
                        </div>

                        <footer className="flex items-center justify-end gap-2 border-t border-slate-100 p-4">
                            <button
                                type="button"
                                className="crm-btn-secondary"
                                onClick={() => setRenewDialog({ open: false, deal: null, days: '30', reason: 'Manual renewal from renewals workspace' })}
                            >
                                Cancel
                            </button>
                            <button
                                type="button"
                                disabled={!renewDialog.reason.trim() || Number(renewDialog.days) < 1 || manualRenewMutation.isPending}
                                onClick={() => manualRenewMutation.mutate({
                                    dealId: renewDialog.deal.id,
                                    days: Number(renewDialog.days),
                                    reason: renewDialog.reason.trim(),
                                })}
                                className="crm-btn-primary disabled:cursor-not-allowed disabled:opacity-50"
                            >
                                {manualRenewMutation.isPending ? 'Renewing...' : 'Confirm renewal'}
                            </button>
                        </footer>
                    </div>
                </div>
            ) : null}

            {pauseDialog.open && pauseDialog.deal ? (
                <div
                    className="fixed inset-0 z-50 flex items-center justify-center bg-slate-900/45 p-4"
                    onClick={() => setPauseDialog({ open: false, deal: null, pauseUntil: '', reason: 'Pause reminders from renewals workspace' })}
                >
                    <div
                        className="w-full max-w-lg rounded-lg border border-slate-200 bg-white shadow-xl"
                        onClick={(event) => event.stopPropagation()}
                    >
                        <header className="crm-panel-header">
                            <div>
                                <h3 className="crm-panel-title">Pause Renewal Reminders</h3>
                                <p className="crm-panel-subtitle">
                                    {pauseDialog.deal.client?.name || 'Unknown client'} • {pauseDialog.deal.product?.name || pauseDialog.deal.plan_type}
                                </p>
                            </div>
                        </header>

                        <div className="space-y-3 p-4">
                            <div>
                                <label htmlFor="pause-until" className="mb-1 block text-sm font-medium text-slate-700">
                                    Pause until (optional)
                                </label>
                                <input
                                    id="pause-until"
                                    type="date"
                                    value={pauseDialog.pauseUntil}
                                    onChange={(event) => setPauseDialog((current) => ({ ...current, pauseUntil: event.target.value }))}
                                    className="crm-input"
                                />
                                <p className="mt-1 text-xs text-slate-500">Leave blank to pause until a manual resume action.</p>
                            </div>

                            <div>
                                <label htmlFor="pause-reason" className="mb-1 block text-sm font-medium text-slate-700">
                                    Reason
                                </label>
                                <textarea
                                    id="pause-reason"
                                    rows={3}
                                    value={pauseDialog.reason}
                                    onChange={(event) => setPauseDialog((current) => ({ ...current, reason: event.target.value }))}
                                    className="crm-input"
                                />
                            </div>
                        </div>

                        <footer className="flex items-center justify-end gap-2 border-t border-slate-100 p-4">
                            <button
                                type="button"
                                className="crm-btn-secondary"
                                onClick={() => setPauseDialog({ open: false, deal: null, pauseUntil: '', reason: 'Pause reminders from renewals workspace' })}
                            >
                                Cancel
                            </button>
                            <button
                                type="button"
                                disabled={!pauseDialog.reason.trim() || pauseRemindersMutation.isPending}
                                onClick={() => pauseRemindersMutation.mutate({
                                    dealId: pauseDialog.deal.id,
                                    dealName: pauseDialog.deal.client?.name,
                                    pauseUntil: pauseDialog.pauseUntil || null,
                                    reason: pauseDialog.reason.trim(),
                                })}
                                className="crm-btn-primary disabled:cursor-not-allowed disabled:opacity-50"
                            >
                                {pauseRemindersMutation.isPending ? 'Pausing...' : 'Confirm pause'}
                            </button>
                        </footer>
                    </div>
                </div>
            ) : null}

            {resumeDialog.open && resumeDialog.deal ? (
                <div
                    className="fixed inset-0 z-50 flex items-center justify-center bg-slate-900/45 p-4"
                    onClick={() => setResumeDialog({ open: false, deal: null, reason: 'Resume reminders from renewals workspace' })}
                >
                    <div
                        className="w-full max-w-lg rounded-lg border border-slate-200 bg-white shadow-xl"
                        onClick={(event) => event.stopPropagation()}
                    >
                        <header className="crm-panel-header">
                            <div>
                                <h3 className="crm-panel-title">Resume Renewal Reminders</h3>
                                <p className="crm-panel-subtitle">{resumeDialog.deal.client?.name || 'Unknown client'}</p>
                            </div>
                        </header>

                        <div className="space-y-3 p-4">
                            <div className="rounded-md border border-slate-200 bg-slate-50 px-3 py-2 text-xs text-slate-600">
                                Reminders will rejoin automated campaign targeting immediately after resume.
                            </div>
                            <div>
                                <label htmlFor="resume-reason" className="mb-1 block text-sm font-medium text-slate-700">
                                    Reason
                                </label>
                                <textarea
                                    id="resume-reason"
                                    rows={3}
                                    value={resumeDialog.reason}
                                    onChange={(event) => setResumeDialog((current) => ({ ...current, reason: event.target.value }))}
                                    className="crm-input"
                                />
                            </div>
                        </div>

                        <footer className="flex items-center justify-end gap-2 border-t border-slate-100 p-4">
                            <button
                                type="button"
                                className="crm-btn-secondary"
                                onClick={() => setResumeDialog({ open: false, deal: null, reason: 'Resume reminders from renewals workspace' })}
                            >
                                Cancel
                            </button>
                            <button
                                type="button"
                                disabled={!resumeDialog.reason.trim() || resumeRemindersMutation.isPending}
                                onClick={() => resumeRemindersMutation.mutate({
                                    dealId: resumeDialog.deal.id,
                                    dealName: resumeDialog.deal.client?.name,
                                    reason: resumeDialog.reason.trim(),
                                })}
                                className="crm-btn-primary disabled:cursor-not-allowed disabled:opacity-50"
                            >
                                {resumeRemindersMutation.isPending ? 'Resuming...' : 'Confirm resume'}
                            </button>
                        </footer>
                    </div>
                </div>
            ) : null}
        </div>
    );
}
