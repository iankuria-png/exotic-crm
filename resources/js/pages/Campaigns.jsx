import React, { useEffect, useMemo, useState } from 'react';
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

export default function Campaigns() {
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
        reason: 'Manual renewal from campaigns workspace',
    });
    const [pauseDialog, setPauseDialog] = useState({
        open: false,
        deal: null,
        pauseUntil: '',
        reason: 'Pause reminders from campaigns workspace',
    });
    const [resumeDialog, setResumeDialog] = useState({
        open: false,
        deal: null,
        reason: 'Resume reminders from campaigns workspace',
    });
    const [runConfigOpen, setRunConfigOpen] = useState(false);
    const [runConfig, setRunConfig] = useState({
        campaign_ids: [],
        bucket: '',
        platform_id: '',
        search: '',
        channel: 'sms',
        dry_run: true,
        reason: 'Campaign run configured from campaigns page',
    });
    const [runPreview, setRunPreview] = useState(null);

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

    const { data: runScopePreview, isFetching: isFetchingRunScopePreview } = useQuery({
        queryKey: [
            'campaign-run-scope-preview',
            runConfigOpen,
            runConfig.bucket,
            runConfig.platform_id,
            runConfig.search,
        ],
        enabled: runConfigOpen,
        queryFn: () =>
            api.get('/crm/renewals', {
                params: {
                    page: 1,
                    per_page: 1,
                    ...(runConfig.bucket ? { bucket: runConfig.bucket } : {}),
                    ...(runConfig.platform_id ? { platform_id: Number(runConfig.platform_id) } : {}),
                    ...(runConfig.search.trim() ? { search: runConfig.search.trim() } : {}),
                },
            }).then((response) => response.data),
        staleTime: 15 * 1000,
    });

    const runCampaignsMutation = useMutation({
        mutationFn: (payload) => api.post('/crm/renewals/run', payload).then((response) => response.data),
        onSuccess: (result, variables) => {
            queryClient.invalidateQueries({ queryKey: ['renewals-overview'] });
            const totals = result?.totals || {};
            if (variables?.dry_run) {
                setRunPreview(result);
            } else {
                setRunPreview(null);
                setRunConfigOpen(false);
            }
            setFeedback({
                tone: totals.failed > 0 ? 'warning' : 'success',
                text: variables?.dry_run
                    ? `Dry run preview: ${totals.targeted || 0} targets across selected campaigns.`
                    : `Campaign run complete: ${totals.sent || 0} sent, ${totals.failed || 0} failed, ${totals.skipped || 0} skipped.`,
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
                reason: 'Manual renewal from campaigns workspace',
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
                reason: 'Pause reminders from campaigns workspace',
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
                reason: 'Resume reminders from campaigns workspace',
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

    const campaignOptions = data?.campaigns || [];
    const platformOptions = useMemo(() => {
        const byId = new Map();
        (rows || []).forEach((row) => {
            if (row?.client?.platform?.id) {
                byId.set(row.client.platform.id, row.client.platform);
            }
        });
        return Array.from(byId.values());
    }, [rows]);

    const previewTargets = useMemo(
        () => (
            runPreview?.campaigns || []
        ).flatMap((campaign) => (
            (campaign.targets_preview || []).map((target) => ({
                ...target,
                campaign_id: campaign.campaign_id,
            }))
        )).slice(0, 12),
        [runPreview],
    );

    useEffect(() => {
        if (!campaignOptions.length) {
            return;
        }

        if (!runConfig.campaign_ids.length) {
            setRunConfig((current) => ({
                ...current,
                campaign_ids: campaignOptions
                    .filter((campaign) => campaign.enabled)
                    .map((campaign) => campaign.id),
            }));
        }
    }, [campaignOptions, runConfig.campaign_ids.length]);

    const targetCountPreview = runScopePreview?.targets?.total ?? data?.targets?.total ?? 0;

    const openRunConfigDialog = () => {
        setRunPreview(null);
        setRunConfig((current) => ({
            ...current,
            search: search || current.search,
            bucket: bucketFilter || current.bucket,
        }));
        setRunConfigOpen(true);
    };

    const updateRunConfig = (patch) => {
        setRunPreview(null);
        setRunConfig((current) => ({
            ...current,
            ...patch,
        }));
    };

    const toggleCampaignSelection = (campaignId) => {
        setRunPreview(null);
        setRunConfig((current) => {
            const nextCampaignIds = current.campaign_ids.includes(campaignId)
                ? current.campaign_ids.filter((id) => id !== campaignId)
                : [...current.campaign_ids, campaignId];

            return {
                ...current,
                campaign_ids: nextCampaignIds,
            };
        });
    };

    const submitCampaignRun = () => {
        if (!runConfig.campaign_ids.length) {
            setFeedback({
                tone: 'warning',
                text: 'Select at least one campaign before running.',
            });
            return;
        }

        const payload = {
            campaign_ids: runConfig.campaign_ids,
            channel: runConfig.channel,
            dry_run: runConfig.dry_run,
            reason: runConfig.reason.trim() || 'Campaign run configured from campaigns page',
            ...(runConfig.bucket ? { bucket: runConfig.bucket } : {}),
            ...(runConfig.platform_id ? { platform_id: Number(runConfig.platform_id) } : {}),
            ...(runConfig.search.trim() ? { search: runConfig.search.trim() } : {}),
        };

        runCampaignsMutation.mutate(payload);
    };

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
                title="Campaigns"
                subtitle={data?.targets?.total ? `${data.targets.total.toLocaleString()} campaign targets in scope` : 'Configure outreach campaigns and manage reminder automation.'}
                actions={(
                    <button
                        type="button"
                        onClick={openRunConfigDialog}
                        className="crm-btn-primary"
                    >
                        Run Campaigns
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
                    <p className="text-xs uppercase tracking-[0.12em] text-slate-500">Most Recent Campaign Run</p>
                    <p className="mt-1 text-sm font-semibold text-slate-900">
                        {new Date(lastRun.run_at).toLocaleString()} • {lastRun.status}
                    </p>
                    <p className="mt-1 text-xs text-slate-600">
                        Targeted: {lastRun.total_targeted} • Sent: {lastRun.sent_count} • Failed: {lastRun.failed_count} • Skipped: {lastRun.skipped_count}
                    </p>
                </section>
            ) : null}

            {runPreview ? (
                <section className="crm-surface p-4">
                    <div className="flex flex-wrap items-center justify-between gap-3">
                        <div>
                            <p className="text-xs uppercase tracking-[0.12em] text-slate-500">Dry-Run Preview</p>
                            <p className="mt-1 text-sm font-semibold text-slate-900">
                                Targeted: {runPreview?.totals?.targeted || 0} • Sent: {runPreview?.totals?.sent || 0} • Failed: {runPreview?.totals?.failed || 0}
                            </p>
                        </div>
                        <button
                            type="button"
                            onClick={() => setRunPreview(null)}
                            className="crm-btn-secondary px-3 py-1.5 text-xs"
                        >
                            Clear preview
                        </button>
                    </div>
                    {previewTargets.length ? (
                        <div className="mt-3 grid gap-2 md:grid-cols-2 xl:grid-cols-3">
                            {previewTargets.map((target, index) => (
                                <div key={`${target.client_id || target.deal_id || 'target'}_${index}`} className="rounded-md border border-slate-200 bg-slate-50 p-2.5 text-xs">
                                    <p className="font-semibold text-slate-900">{target.client_name || target.phone || `Client #${target.client_id || 'N/A'}`}</p>
                                    <p className="text-slate-600">{target.phone || 'No phone'}</p>
                                    <p className="text-slate-500">Campaign: {target.campaign_name || target.campaign_id || 'N/A'}</p>
                                </div>
                            ))}
                        </div>
                    ) : (
                        <p className="mt-2 text-xs text-slate-500">No targets matched the current dry-run configuration.</p>
                    )}
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
                            <button type="submit" aria-label="Run campaigns search" className="absolute right-2 top-1/2 -translate-y-1/2 rounded p-1 text-slate-400 transition hover:text-slate-600">
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
                emptyMessage="No campaign targets match current filters."
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
                                                reason: 'Manual renewal from campaigns workspace',
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
                                                    reason: 'Resume reminders from campaigns workspace',
                                                });
                                                return;
                                            }

                                            setPauseDialog({
                                                open: true,
                                                deal: activeDealRow,
                                                pauseUntil: '',
                                                reason: 'Pause reminders from campaigns workspace',
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

            {runConfigOpen ? (
                <div
                    className="fixed inset-0 z-50 flex items-center justify-center bg-slate-900/45 p-4"
                    onClick={() => setRunConfigOpen(false)}
                >
                    <div
                        className="w-full max-w-3xl rounded-lg border border-slate-200 bg-white shadow-xl"
                        onClick={(event) => event.stopPropagation()}
                    >
                        <header className="crm-panel-header">
                            <div>
                                <h3 className="crm-panel-title">Campaign Run Configuration</h3>
                                <p className="crm-panel-subtitle">Choose campaigns, target scope, and execution mode before sending.</p>
                            </div>
                        </header>

                        <div className="max-h-[70vh] space-y-4 overflow-y-auto p-4">
                            <section className="space-y-2">
                                <div className="flex flex-wrap items-center justify-between gap-2">
                                    <p className="text-sm font-semibold text-slate-900">Campaign selection</p>
                                    <button
                                        type="button"
                                        className="crm-btn-secondary px-2.5 py-1 text-xs"
                                        onClick={() =>
                                            updateRunConfig({
                                                campaign_ids: campaignOptions
                                                    .filter((campaign) => campaign.enabled)
                                                    .map((campaign) => campaign.id),
                                            })
                                        }
                                    >
                                        Select enabled
                                    </button>
                                </div>
                                <div className="grid gap-2 md:grid-cols-2">
                                    {campaignOptions.map((campaign) => {
                                        const selected = runConfig.campaign_ids.includes(campaign.id);
                                        const campaignLabel = campaign.name || campaign.title || campaign.template?.title || `Campaign #${campaign.id}`;
                                        const campaignChannel = campaign.template?.channel || campaign.channel || 'N/A';
                                        return (
                                            <label
                                                key={campaign.id}
                                                className={`flex cursor-pointer items-start gap-2 rounded-md border px-3 py-2 text-sm transition ${
                                                    selected
                                                        ? 'border-teal-200 bg-teal-50'
                                                        : 'border-slate-200 bg-white hover:border-slate-300'
                                                }`}
                                            >
                                                <input
                                                    type="checkbox"
                                                    checked={selected}
                                                    onChange={() => toggleCampaignSelection(campaign.id)}
                                                    className="mt-0.5 h-4 w-4 rounded border-slate-300 text-teal-700 focus:ring-teal-200"
                                                />
                                                <span className="min-w-0">
                                                    <span className="block font-medium text-slate-900">{campaignLabel}</span>
                                                    <span className="block text-xs text-slate-500">
                                                        Trigger: {campaign.trigger_days} day(s) • Template: {campaign.template?.title || 'N/A'} • Channel: {campaignChannel}
                                                    </span>
                                                </span>
                                            </label>
                                        );
                                    })}
                                </div>
                                {!campaignOptions.length ? (
                                    <p className="text-xs text-slate-500">No campaign rules are configured yet. Create campaigns in settings before running.</p>
                                ) : null}
                            </section>

                            <section className="grid gap-3 md:grid-cols-2">
                                <div>
                                    <label htmlFor="campaign-bucket" className="mb-1 block text-sm font-medium text-slate-700">
                                        Target bucket
                                    </label>
                                    <select
                                        id="campaign-bucket"
                                        value={runConfig.bucket}
                                        onChange={(event) => updateRunConfig({ bucket: event.target.value })}
                                        className="crm-select"
                                    >
                                        <option value="">All targets</option>
                                        <option value="active">Active</option>
                                        <option value="risk">At Risk</option>
                                        <option value="pending">Pending</option>
                                        <option value="stable">Stable</option>
                                        <option value="expired">Expired</option>
                                        <option value="lapsed">Lapsed</option>
                                        <option value="paused">Paused</option>
                                    </select>
                                </div>
                                <div>
                                    <label htmlFor="campaign-platform" className="mb-1 block text-sm font-medium text-slate-700">
                                        Market
                                    </label>
                                    <select
                                        id="campaign-platform"
                                        value={runConfig.platform_id}
                                        onChange={(event) => updateRunConfig({ platform_id: event.target.value })}
                                        className="crm-select"
                                    >
                                        <option value="">All accessible markets</option>
                                        {platformOptions.map((platform) => (
                                            <option key={platform.id} value={platform.id}>
                                                {platform.name}
                                            </option>
                                        ))}
                                    </select>
                                </div>
                                <div>
                                    <label htmlFor="campaign-channel" className="mb-1 block text-sm font-medium text-slate-700">
                                        Channel
                                    </label>
                                    <select
                                        id="campaign-channel"
                                        value={runConfig.channel}
                                        onChange={(event) => updateRunConfig({ channel: event.target.value })}
                                        className="crm-select"
                                    >
                                        <option value="sms">SMS</option>
                                        <option value="email">Email</option>
                                    </select>
                                </div>
                                <div>
                                    <label htmlFor="campaign-search" className="mb-1 block text-sm font-medium text-slate-700">
                                        Search scope
                                    </label>
                                    <input
                                        id="campaign-search"
                                        value={runConfig.search}
                                        onChange={(event) => updateRunConfig({ search: event.target.value })}
                                        placeholder="Name or phone"
                                        className="crm-input"
                                    />
                                </div>
                            </section>

                            <section className="space-y-2">
                                <label className="flex items-center gap-2 text-sm text-slate-700">
                                    <input
                                        type="checkbox"
                                        checked={runConfig.dry_run}
                                        onChange={(event) => updateRunConfig({ dry_run: event.target.checked })}
                                        className="h-4 w-4 rounded border-slate-300 text-teal-700 focus:ring-teal-200"
                                    />
                                    Dry run (preview recipients without sending messages)
                                </label>
                                <label htmlFor="campaign-reason" className="block text-sm font-medium text-slate-700">
                                    Reason
                                </label>
                                <textarea
                                    id="campaign-reason"
                                    rows={3}
                                    value={runConfig.reason}
                                    onChange={(event) => updateRunConfig({ reason: event.target.value })}
                                    className="crm-input"
                                />
                                <div className="rounded-md border border-slate-200 bg-slate-50 px-3 py-2 text-xs text-slate-600">
                                    Target count preview:{' '}
                                    <span className="font-semibold text-slate-900">
                                        {isFetchingRunScopePreview ? 'Calculating...' : targetCountPreview.toLocaleString()}
                                    </span>
                                </div>
                            </section>
                        </div>

                        <footer className="flex items-center justify-end gap-2 border-t border-slate-100 p-4">
                            <button
                                type="button"
                                className="crm-btn-secondary"
                                onClick={() => setRunConfigOpen(false)}
                            >
                                Cancel
                            </button>
                            <button
                                type="button"
                                className="crm-btn-primary disabled:cursor-not-allowed disabled:opacity-50"
                                disabled={runCampaignsMutation.isPending || !runConfig.campaign_ids.length}
                                onClick={submitCampaignRun}
                            >
                                {runCampaignsMutation.isPending
                                    ? 'Running...'
                                    : runConfig.dry_run
                                        ? 'Run Dry Preview'
                                        : 'Execute Campaigns'}
                            </button>
                        </footer>
                    </div>
                </div>
            ) : null}

            {renewDialog.open && renewDialog.deal ? (
                <div
                    className="fixed inset-0 z-50 flex items-center justify-center bg-slate-900/45 p-4"
                    onClick={() => setRenewDialog({ open: false, deal: null, days: '30', reason: 'Manual renewal from campaigns workspace' })}
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
                                onClick={() => setRenewDialog({ open: false, deal: null, days: '30', reason: 'Manual renewal from campaigns workspace' })}
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
                    onClick={() => setPauseDialog({ open: false, deal: null, pauseUntil: '', reason: 'Pause reminders from campaigns workspace' })}
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
                                onClick={() => setPauseDialog({ open: false, deal: null, pauseUntil: '', reason: 'Pause reminders from campaigns workspace' })}
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
                    onClick={() => setResumeDialog({ open: false, deal: null, reason: 'Resume reminders from campaigns workspace' })}
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
                                onClick={() => setResumeDialog({ open: false, deal: null, reason: 'Resume reminders from campaigns workspace' })}
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
