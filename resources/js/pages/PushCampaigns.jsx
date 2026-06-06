import React, { useEffect, useMemo, useState } from 'react';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { Link } from 'react-router-dom';
import api from '../services/api';
import DataTable from '../components/DataTable';
import MetricCard from '../components/MetricCard';
import PageHeader from '../components/PageHeader';
import UploadModal from '../components/PushCampaigns/UploadModal';
import CampaignDetail from '../components/PushCampaigns/CampaignDetail';
import CrmEscortModal from '../components/PushCampaigns/CrmEscortModal';
import { useToast } from '../components/ToastProvider';

function prettyStatus(status) {
    return (status || 'unknown').replaceAll('_', ' ');
}

function campaignStatusTone(status) {
    const normalized = String(status || '').toLowerCase();
    if (['completed'].includes(normalized)) {
        return 'bg-emerald-50 text-emerald-700 ring-emerald-200';
    }
    if (['running', 'scheduled'].includes(normalized)) {
        return 'bg-blue-50 text-blue-700 ring-blue-200';
    }
    if (['partial', 'processing'].includes(normalized)) {
        return 'bg-amber-50 text-amber-700 ring-amber-200';
    }
    if (['failed', 'cancelled'].includes(normalized)) {
        return 'bg-rose-50 text-rose-700 ring-rose-200';
    }

    return 'bg-slate-100 text-slate-700 ring-slate-200';
}

function formatQueueDate(value) {
    if (!value) return '—';
    const parsed = new Date(value);
    if (Number.isNaN(parsed.getTime())) return '—';
    return new Intl.DateTimeFormat(undefined, {
        month: 'short',
        day: '2-digit',
        hour: '2-digit',
        minute: '2-digit',
    }).format(parsed);
}

function formatCampaignDate(value, platformTimezone) {
    if (!value) return '—';

    const parsed = new Date(value);
    if (Number.isNaN(parsed.getTime())) {
        return value;
    }

    const options = {
        month: 'short',
        day: '2-digit',
        year: 'numeric',
        hour: '2-digit',
        minute: '2-digit',
    };

    try {
        return new Intl.DateTimeFormat(undefined, {
            ...options,
            ...(platformTimezone ? { timeZone: platformTimezone } : {}),
        }).format(parsed);
    } catch (_) {
        return new Intl.DateTimeFormat(undefined, options).format(parsed);
    }
}

export default function PushCampaigns() {
    const toast = useToast();
    const queryClient = useQueryClient();
    const [page, setPage] = useState(1);
    const [perPage, setPerPage] = useState(25);
    const [statusFilter, setStatusFilter] = useState('');
    const [platformFilter, setPlatformFilter] = useState('');
    const [searchInput, setSearchInput] = useState('');
    const [search, setSearch] = useState('');
    const [uploadOpen, setUploadOpen] = useState(false);
    const [crmEscortOpen, setCrmEscortOpen] = useState(false);
    const [activeCampaignId, setActiveCampaignId] = useState(null);
    const [queuePage, setQueuePage] = useState(1);
    const [queuePerPage, setQueuePerPage] = useState(10);
    const [syncDiagnostics, setSyncDiagnostics] = useState([]);
    const [syncingPlatformId, setSyncingPlatformId] = useState(null);

    useEffect(() => {
        const handle = window.setTimeout(() => {
            setSearch(searchInput.trim());
            setPage(1);
        }, 300);

        return () => window.clearTimeout(handle);
    }, [searchInput]);

    const { data: integrationData } = useQuery({
        queryKey: ['settings-integrations', 'push-campaigns-page'],
        queryFn: () => api.get('/crm/settings/integrations').then((response) => response.data),
    });

    const platformOptions = integrationData?.platforms || [];

    const dashboardQuery = useQuery({
        queryKey: ['push-campaigns-dashboard'],
        queryFn: () => api.get('/crm/push-campaigns/dashboard').then((response) => response.data),
    });

    const subscribersQuery = useQuery({
        queryKey: ['push-campaigns-subscribers'],
        queryFn: () => api.get('/crm/push-campaigns/subscribers').then((response) => response.data),
    });

    const queueQuery = useQuery({
        queryKey: ['push-campaigns-upload-queue', queuePage, queuePerPage],
        queryFn: () => api.get('/crm/push-campaigns/upload/queue', {
            params: {
                page: queuePage,
                per_page: queuePerPage,
            },
        }).then((response) => response.data),
        refetchInterval: 5000,
    });

    const campaignsQuery = useQuery({
        queryKey: ['push-campaigns-list', page, perPage, statusFilter, platformFilter, search],
        queryFn: () => api.get('/crm/push-campaigns', {
            params: {
                page,
                per_page: perPage,
                ...(statusFilter ? { status: statusFilter } : {}),
                ...(platformFilter ? { platform_id: Number(platformFilter) } : {}),
                ...(search ? { search } : {}),
            },
        }).then((response) => response.data),
    });

    const syncSubscribersMutation = useMutation({
        mutationFn: () => api.post('/crm/push-campaigns/subscribers/sync', {}).then((response) => response.data),
        onSuccess: (response) => {
            queryClient.invalidateQueries({ queryKey: ['push-campaigns-subscribers'] });
            setSyncDiagnostics(response?.diagnostics || []);
            const synced = Number(response?.synced || 0);
            if (synced > 0) {
                toast.success(response?.message || `Subscriber sync complete (${synced} market${synced === 1 ? '' : 's'}).`);
            } else {
                toast.warning(response?.message || 'No subscriber snapshots were synced. Check provider credentials.');
            }
        },
        onError: (error) => {
            const status = error?.response?.status;
            if (status === 501) {
                toast.warning('Subscriber sync command is not configured yet.');
                return;
            }

            toast.error(error?.response?.data?.message || 'Failed to sync subscriber metrics.');
        },
    });

    const syncSingleMarketMutation = useMutation({
        mutationFn: (platformId) => api.post('/crm/push-campaigns/subscribers/sync', { platform_id: platformId }).then((r) => r.data),
        onSuccess: (response, platformId) => {
            queryClient.invalidateQueries({ queryKey: ['push-campaigns-subscribers'] });
            setSyncingPlatformId(null);
            if (response?.diagnostics?.length > 0) {
                setSyncDiagnostics((prev) => {
                    const filtered = prev.filter((d) => d.platform_id !== platformId);
                    return [...filtered, ...response.diagnostics];
                });
                toast.warning(response?.message || 'Sync failed for this market.');
            } else {
                setSyncDiagnostics((prev) => prev.filter((d) => d.platform_id !== platformId));
                toast.success(response?.message || 'Market synced.');
            }
        },
        onError: (error) => {
            setSyncingPlatformId(null);
            toast.error(error?.response?.data?.message || 'Failed to sync market.');
        },
    });

    const cancelQueueMutation = useMutation({
        mutationFn: (batchId) => api.delete(`/crm/push-campaigns/upload/${batchId}`).then((response) => response.data),
        onSuccess: (response) => {
            toast.success(response?.message || 'Queue item cancelled.');
            queryClient.invalidateQueries({ queryKey: ['push-campaigns-upload-queue'] });
        },
        onError: (error) => {
            toast.error(error?.response?.data?.message || 'Failed to cancel queue item.');
        },
    });

    const processNowMutation = useMutation({
        mutationFn: (batchId) => api.post(`/crm/push-campaigns/upload/${batchId}/process-now`, {}).then((response) => response.data),
        onSuccess: (response) => {
            toast.success(response?.message || 'Queued upload processed.');
            queryClient.invalidateQueries({ queryKey: ['push-campaigns-upload-queue'] });
            queryClient.invalidateQueries({ queryKey: ['push-campaigns-list'] });
            queryClient.invalidateQueries({ queryKey: ['push-campaigns-dashboard'] });
        },
        onError: (error) => {
            toast.error(error?.response?.data?.message || 'Failed to process queued upload.');
        },
    });

    const createFromDryRunMutation = useMutation({
        mutationFn: (batchId) => api.post(`/crm/push-campaigns/upload/${batchId}/create-from-dry-run`, {}).then((response) => response.data),
        onSuccess: (response) => {
            toast.success(response?.message || 'Campaign creation queued from dry-run batch.');
            queryClient.invalidateQueries({ queryKey: ['push-campaigns-upload-queue'] });
            queryClient.invalidateQueries({ queryKey: ['push-campaigns-list'] });
            queryClient.invalidateQueries({ queryKey: ['push-campaigns-dashboard'] });
        },
        onError: (error) => {
            toast.error(error?.response?.data?.message || 'Failed to create campaigns from dry-run batch.');
        },
    });

    const confirmQueueBatchMutation = useMutation({
        mutationFn: (batchId) => api.post(`/crm/push-campaigns/upload/${batchId}/confirm`, {}).then((response) => response.data),
        onSuccess: (response) => {
            const count = Number(response?.confirmed_count || 0);
            toast.success(`Confirmed ${count} campaign${count === 1 ? '' : 's'} for batch.`);
            queryClient.invalidateQueries({ queryKey: ['push-campaigns-upload-queue'] });
            queryClient.invalidateQueries({ queryKey: ['push-campaigns-list'] });
            queryClient.invalidateQueries({ queryKey: ['push-campaigns-dashboard'] });
        },
        onError: (error) => {
            toast.error(error?.response?.data?.message || 'Failed to confirm batch campaigns.');
        },
    });

    const dashboard = dashboardQuery.data || {};
    const subscriberRows = subscribersQuery.data?.items || [];
    const queueRows = queueQuery.data?.data || queueQuery.data?.items || [];
    const queueHealth = queueQuery.data?.health || null;
    const campaigns = campaignsQuery.data?.data || [];
    const pagination = campaignsQuery.data || null;

    const columns = useMemo(() => [
        {
            key: 'name',
            label: 'Campaign',
            render: (row) => (
                <div className="min-w-[220px]">
                    <div className="flex flex-wrap items-center gap-2">
                        <p className="font-medium text-slate-800">{row.name}</p>
                        {row.auto_push_plan_id ? (
                            <span className="rounded-md bg-teal-100 px-2 py-0.5 text-[11px] font-medium text-teal-700">
                                auto
                            </span>
                        ) : null}
                    </div>
                    <p className="text-xs text-slate-500">{row.source_filename || 'Manual campaign'}</p>
                </div>
            ),
        },
        {
            key: 'platform',
            label: 'Platform',
            render: (row) => row.platform?.name || row.platform?.country || '—',
        },
        {
            key: 'status',
            label: 'Status',
            render: (row) => (
                <span className={`rounded-md px-2 py-0.5 text-xs font-medium ring-1 ring-inset ${campaignStatusTone(row.status)}`}>
                    {prettyStatus(row.status)}
                </span>
            ),
        },
        {
            key: 'items',
            label: 'Items',
            render: (row) => (row.total_items || 0).toLocaleString(),
        },
        {
            key: 'delivery',
            label: 'Sent / Failed',
            render: (row) => `${row.sent_count || 0} / ${row.failed_count || 0}`,
        },
        {
            key: 'schedule',
            label: 'Scheduled',
            render: (row) => formatCampaignDate(row.scheduled_at, row.platform?.timezone || null),
        },
        {
            key: 'created',
            label: 'Created',
            render: (row) => formatCampaignDate(row.created_at, row.platform?.timezone || null),
        },
    ], []);

    const queueColumns = useMemo(() => [
        {
            key: 'file',
            label: 'File',
            render: (row) => (
                <span className="inline-block max-w-[260px] truncate align-middle" title={row.source_filename}>
                    {row.source_filename || 'n/a'}
                </span>
            ),
        },
        {
            key: 'status',
            label: 'Status',
            render: (row) => prettyStatus(row.status),
        },
        {
            key: 'mode',
            label: 'Mode',
            render: (row) => (row.dry_run ? 'Dry run' : 'Create campaigns'),
        },
        {
            key: 'queued',
            label: 'Queued',
            render: (row) => formatQueueDate(row.queued_at),
        },
        {
            key: 'updated',
            label: 'Updated',
            render: (row) => formatQueueDate(row.updated_at || row.started_at),
        },
        {
            key: 'items',
            label: 'Items',
            render: (row) => (row.total_items || 0).toLocaleString(),
        },
        {
            key: 'action',
            label: 'Action',
            render: (row) => (
                <div className="flex items-center gap-2">
                    {row.can_process_now ? (
                        <button
                            type="button"
                            onClick={() => processNowMutation.mutate(row.batch_id)}
                            disabled={processNowMutation.isPending}
                            className="crm-btn-primary px-2 py-1 text-xs disabled:cursor-not-allowed disabled:opacity-60"
                        >
                            {processNowMutation.isPending ? 'Processing...' : 'Process now'}
                        </button>
                    ) : null}
                    {row.can_create_from_dry_run ? (
                        <button
                            type="button"
                            onClick={() => createFromDryRunMutation.mutate(row.batch_id)}
                            disabled={createFromDryRunMutation.isPending}
                            className="crm-btn-primary px-2 py-1 text-xs disabled:cursor-not-allowed disabled:opacity-60"
                        >
                            {createFromDryRunMutation.isPending ? 'Queuing...' : 'Create campaigns'}
                        </button>
                    ) : null}
                    {row.can_confirm ? (
                        <button
                            type="button"
                            onClick={() => confirmQueueBatchMutation.mutate(row.batch_id)}
                            disabled={confirmQueueBatchMutation.isPending}
                            className="crm-btn-primary px-2 py-1 text-xs disabled:cursor-not-allowed disabled:opacity-60"
                        >
                            {confirmQueueBatchMutation.isPending ? 'Confirming...' : 'Confirm'}
                        </button>
                    ) : null}
                    {row.can_cancel ? (
                        <button
                            type="button"
                            onClick={() => cancelQueueMutation.mutate(row.batch_id)}
                            disabled={cancelQueueMutation.isPending}
                            className="crm-btn-secondary px-2 py-1 text-xs disabled:cursor-not-allowed disabled:opacity-60"
                        >
                            {cancelQueueMutation.isPending ? 'Cancelling...' : 'Cancel'}
                        </button>
                    ) : null}
                    {!row.can_process_now && !row.can_create_from_dry_run && !row.can_confirm && !row.can_cancel ? (
                        <span className="text-slate-400">—</span>
                    ) : null}
                </div>
            ),
        },
    ], [
        cancelQueueMutation,
        confirmQueueBatchMutation,
        createFromDryRunMutation,
        processNowMutation,
    ]);

    const queuePagination = useMemo(() => {
        if (queueQuery.data?.current_page && queueQuery.data?.last_page) {
            return {
                current_page: Number(queueQuery.data.current_page),
                last_page: Number(queueQuery.data.last_page),
                per_page: Number(queueQuery.data.per_page || queuePerPage),
                total: Number(queueQuery.data.total || 0),
            };
        }

        const fallbackTotal = queueRows.length;
        return {
            current_page: 1,
            last_page: 1,
            per_page: queuePerPage,
            total: fallbackTotal,
        };
    }, [queuePerPage, queueQuery.data, queueRows.length]);

    return (
        <div className="space-y-4">
            <PageHeader
                title="Push Campaigns"
                subtitle="Upload country workbooks, monitor extraction, and execute broadcast pushes by market."
            />

            <section className="grid gap-4 md:grid-cols-4">
                <MetricCard
                    label="Total Campaigns"
                    value={(dashboard.total_campaigns || 0).toLocaleString()}
                    meta="campaigns in scope"
                    tone="accent"
                />
                <MetricCard
                    label="Pending"
                    value={(dashboard.pending_campaigns || 0).toLocaleString()}
                    meta="processing, draft, scheduled, running"
                    tone="warning"
                />
                <MetricCard
                    label="Sent Today"
                    value={(dashboard.sent_today || 0).toLocaleString()}
                    meta="items delivered today"
                    tone="success"
                />
                <MetricCard
                    label="Avg CTR"
                    value={dashboard.avg_click_rate == null ? 'n/a' : `${dashboard.avg_click_rate}%`}
                    meta="from refreshed analytics"
                    tone="default"
                />
            </section>

            <section className="crm-surface p-4">
                <div className="grid gap-2 md:grid-cols-[1fr_180px_180px_auto_auto_auto]">
                    <input
                        value={searchInput}
                        onChange={(event) => setSearchInput(event.target.value)}
                        className="crm-input"
                        placeholder="Search campaign name / batch / filename"
                    />
                    <select
                        value={platformFilter}
                        onChange={(event) => {
                            setPlatformFilter(event.target.value);
                            setPage(1);
                        }}
                        className="crm-select"
                    >
                        <option value="">All markets</option>
                        {platformOptions.map((platform) => (
                            <option key={platform.platform_id} value={platform.platform_id}>{platform.platform_name}</option>
                        ))}
                    </select>
                    <select
                        value={statusFilter}
                        onChange={(event) => {
                            setStatusFilter(event.target.value);
                            setPage(1);
                        }}
                        className="crm-select"
                    >
                        <option value="">All statuses</option>
                        <option value="processing">Processing</option>
                        <option value="draft">Draft</option>
                        <option value="scheduled">Scheduled</option>
                        <option value="running">Running</option>
                        <option value="completed">Completed</option>
                        <option value="partial">Partial</option>
                        <option value="failed">Failed</option>
                        <option value="cancelled">Cancelled</option>
                    </select>
                    <button type="button" onClick={() => setUploadOpen(true)} className="crm-btn-primary">
                        Upload workbook
                    </button>
                    <Link to="/auto-push" className="crm-btn-secondary inline-flex items-center justify-center">
                        Open Auto Push
                    </Link>
                    <button type="button" onClick={() => setCrmEscortOpen(true)} className="crm-btn-secondary">
                        Select CRM escorts
                    </button>
                </div>
            </section>

            <DataTable
                columns={columns}
                data={campaigns}
                pagination={pagination}
                isLoading={campaignsQuery.isLoading}
                onPageChange={setPage}
                onRowClick={(row) => setActiveCampaignId(row.id)}
                perPage={perPage}
                onPerPageChange={setPerPage}
                emptyMessage="No push campaigns found for current filters."
            />

            <section className="crm-surface overflow-hidden">
                <header className="crm-panel-header">
                    <div>
                        <h3 className="crm-panel-title">Subscriber Overview</h3>
                        <p className="crm-panel-subtitle">Per-market subscriber metrics and push setup visibility.</p>
                    </div>
                    <button
                        type="button"
                        onClick={() => syncSubscribersMutation.mutate()}
                        disabled={syncSubscribersMutation.isPending}
                        className="crm-btn-secondary disabled:cursor-not-allowed disabled:opacity-60"
                    >
                        {syncSubscribersMutation.isPending ? 'Syncing...' : 'Sync subscribers'}
                    </button>
                </header>

                {(subscribersQuery.data?.note || '').trim() ? (
                    <p className="px-4 pb-2 text-xs text-amber-700">{subscribersQuery.data.note}</p>
                ) : null}

                <div className="overflow-auto px-4 pb-4">
                    <table className="min-w-full text-xs">
                        <thead>
                            <tr className="border-b border-slate-200 text-left text-slate-500">
                                <th className="px-2 py-2 font-medium">Market</th>
                                <th className="px-2 py-2 font-medium">Domain</th>
                                <th className="px-2 py-2 font-medium">Provider</th>
                                <th className="px-2 py-2 font-medium">Status</th>
                                <th className="px-2 py-2 font-medium">Active</th>
                                <th className="px-2 py-2 font-medium">Total</th>
                                <th className="px-2 py-2 font-medium">Last Sync</th>
                                <th className="px-2 py-2 font-medium"></th>
                            </tr>
                        </thead>
                        <tbody>
                            {subscriberRows.map((row) => {
                                const diag = syncDiagnostics.find((d) => d.platform_id === row.platform_id);
                                const hasSyncData = row.total_subscribers != null && row.total_subscribers > 0;
                                const isSyncing = syncingPlatformId === row.platform_id;

                                return (
                                    <tr key={row.platform_id} className="border-b border-slate-100">
                                        <td className="px-2 py-2 font-medium text-slate-700">{row.platform_name}</td>
                                        <td className="px-2 py-2 text-slate-600">{row.domain || '—'}</td>
                                        <td className="px-2 py-2 text-slate-600">{row.provider || 'n/a'}</td>
                                        <td className="px-2 py-2">
                                            {hasSyncData ? (
                                                <span className="inline-flex items-center rounded-md bg-emerald-50 px-1.5 py-0.5 text-[11px] font-medium text-emerald-700 ring-1 ring-inset ring-emerald-600/20">synced</span>
                                            ) : diag?.error ? (
                                                <span className="inline-flex items-center rounded-md bg-amber-50 px-1.5 py-0.5 text-[11px] font-medium text-amber-800 ring-1 ring-inset ring-amber-600/20" title={diag.error}>{diag.error}</span>
                                            ) : (
                                                <span className="inline-flex items-center rounded-md bg-slate-50 px-1.5 py-0.5 text-[11px] font-medium text-slate-500 ring-1 ring-inset ring-slate-500/10">not configured</span>
                                            )}
                                        </td>
                                        <td className="px-2 py-2 text-slate-600">{row.active_subscribers ?? '—'}</td>
                                        <td className="px-2 py-2 text-slate-600">{row.total_subscribers ?? '—'}</td>
                                        <td className="px-2 py-2 text-slate-600">{row.last_synced_at || '—'}</td>
                                        <td className="px-2 py-2">
                                            <button
                                                type="button"
                                                onClick={() => {
                                                    setSyncingPlatformId(row.platform_id);
                                                    syncSingleMarketMutation.mutate(row.platform_id);
                                                }}
                                                disabled={isSyncing || syncSubscribersMutation.isPending}
                                                className="crm-btn-secondary px-2 py-1 text-[11px] disabled:cursor-not-allowed disabled:opacity-60"
                                                title="Sync this market"
                                            >
                                                {isSyncing ? '...' : '\u21BB'}
                                            </button>
                                        </td>
                                    </tr>
                                );
                            })}
                            {!subscribersQuery.isLoading && subscriberRows.length === 0 ? (
                                <tr>
                                    <td colSpan={8} className="px-2 py-4 text-center text-slate-500">No subscriber rows available.</td>
                                </tr>
                            ) : null}
                        </tbody>
                    </table>
                </div>
            </section>

            <section className="space-y-2">
                <div className="crm-panel-header">
                    <div>
                        <h3 className="crm-panel-title">Upload Queue</h3>
                        <p className="crm-panel-subtitle">Track uploads, convert dry-runs into campaigns, and confirm ready batches.</p>
                    </div>
                    <button
                        type="button"
                        onClick={() => queueQuery.refetch()}
                        className="crm-btn-secondary"
                    >
                        Refresh queue
                    </button>
                </div>

                {queueHealth?.worker_likely_offline ? (
                    <p className="rounded-lg border border-rose-200 bg-rose-50 px-4 py-2 text-xs text-rose-700">
                        Queue worker appears offline. Start `php artisan queue:work` (or Horizon) to process queued uploads.
                    </p>
                ) : null}

                <DataTable
                    columns={queueColumns}
                    data={queueRows}
                    pagination={queuePagination}
                    isLoading={queueQuery.isLoading}
                    onPageChange={setQueuePage}
                    perPage={queuePerPage}
                    onPerPageChange={(next) => {
                        setQueuePerPage(next);
                        setQueuePage(1);
                    }}
                    perPageOptions={[10, 25, 50]}
                    emptyMessage="No upload queue items found."
                />
            </section>

            <UploadModal
                open={uploadOpen}
                onClose={() => setUploadOpen(false)}
                platformOptions={platformOptions}
                onCreated={() => {
                    queryClient.invalidateQueries({ queryKey: ['push-campaigns-list'] });
                    queryClient.invalidateQueries({ queryKey: ['push-campaigns-dashboard'] });
                    queryClient.invalidateQueries({ queryKey: ['push-campaigns-upload-queue'] });
                }}
                onQueueChanged={() => queryClient.invalidateQueries({ queryKey: ['push-campaigns-upload-queue'] })}
            />

            <CrmEscortModal
                open={crmEscortOpen}
                onClose={() => setCrmEscortOpen(false)}
                platformOptions={platformOptions}
                onCreated={() => {
                    queryClient.invalidateQueries({ queryKey: ['push-campaigns-list'] });
                    queryClient.invalidateQueries({ queryKey: ['push-campaigns-dashboard'] });
                }}
            />

            <CampaignDetail
                campaignId={activeCampaignId}
                onClose={() => setActiveCampaignId(null)}
                onChanged={() => {
                    queryClient.invalidateQueries({ queryKey: ['push-campaigns-list'] });
                    queryClient.invalidateQueries({ queryKey: ['push-campaigns-dashboard'] });
                }}
            />
        </div>
    );
}
