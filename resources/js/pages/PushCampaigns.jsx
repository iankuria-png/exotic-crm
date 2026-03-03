import React, { useEffect, useMemo, useState } from 'react';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
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
        queryKey: ['push-campaigns-upload-queue'],
        queryFn: () => api.get('/crm/push-campaigns/upload/queue', {
            params: { limit: 20 },
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
    const queueRows = queueQuery.data?.items || [];
    const queueHealth = queueQuery.data?.health || null;
    const campaigns = campaignsQuery.data?.data || [];
    const pagination = campaignsQuery.data || null;

    const columns = useMemo(() => [
        {
            key: 'name',
            label: 'Campaign',
            render: (row) => (
                <div className="min-w-[220px]">
                    <p className="font-medium text-slate-800">{row.name}</p>
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
                <span className="rounded-md bg-slate-100 px-2 py-0.5 text-xs font-medium text-slate-700">
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
            render: (row) => row.scheduled_at || '—',
        },
        {
            key: 'created',
            label: 'Created',
            render: (row) => row.created_at || '—',
        },
    ], []);

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
                <div className="grid gap-2 md:grid-cols-[1fr_180px_180px_auto_auto]">
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
                    </select>
                    <button type="button" onClick={() => setUploadOpen(true)} className="crm-btn-primary">
                        Upload workbook
                    </button>
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
                </header>

                {queueHealth?.worker_likely_offline ? (
                    <p className="px-4 pb-2 text-xs text-rose-700">
                        Queue worker appears offline. Start `php artisan queue:work` (or Horizon) to process queued uploads.
                    </p>
                ) : null}

                <div className="overflow-auto px-4 pb-4">
                    <table className="min-w-full text-xs">
                        <thead>
                            <tr className="border-b border-slate-200 text-left text-slate-500">
                                <th className="px-2 py-2 font-medium">File</th>
                                <th className="px-2 py-2 font-medium">Status</th>
                                <th className="px-2 py-2 font-medium">Mode</th>
                                <th className="px-2 py-2 font-medium">Queued</th>
                                <th className="px-2 py-2 font-medium">Updated</th>
                                <th className="px-2 py-2 font-medium">Items</th>
                                <th className="px-2 py-2 font-medium">Action</th>
                            </tr>
                        </thead>
                        <tbody>
                            {queueRows.map((row) => (
                                <tr key={row.batch_id} className="border-b border-slate-100">
                                    <td className="max-w-[280px] truncate px-2 py-2 font-medium text-slate-700" title={row.source_filename}>
                                        {row.source_filename}
                                    </td>
                                    <td className="px-2 py-2 text-slate-600">{prettyStatus(row.status)}</td>
                                    <td className="px-2 py-2 text-slate-600">{row.dry_run ? 'Dry run' : 'Create campaigns'}</td>
                                    <td className="px-2 py-2 text-slate-600">{formatQueueDate(row.queued_at)}</td>
                                    <td className="px-2 py-2 text-slate-600">{formatQueueDate(row.updated_at || row.started_at)}</td>
                                    <td className="px-2 py-2 text-slate-600">{(row.total_items || 0).toLocaleString()}</td>
                                    <td className="px-2 py-2">
                                        <div className="flex flex-wrap gap-2">
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
                                    </td>
                                </tr>
                            ))}
                            {!queueQuery.isLoading && queueRows.length === 0 ? (
                                <tr>
                                    <td colSpan={7} className="px-2 py-4 text-center text-slate-500">No upload queue items found.</td>
                                </tr>
                            ) : null}
                        </tbody>
                    </table>
                </div>
            </section>

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
                                <th className="px-2 py-2 font-medium">Active</th>
                                <th className="px-2 py-2 font-medium">Total</th>
                                <th className="px-2 py-2 font-medium">Last Sync</th>
                            </tr>
                        </thead>
                        <tbody>
                            {subscriberRows.map((row) => (
                                <tr key={row.platform_id} className="border-b border-slate-100">
                                    <td className="px-2 py-2 font-medium text-slate-700">{row.platform_name}</td>
                                    <td className="px-2 py-2 text-slate-600">{row.domain || '—'}</td>
                                    <td className="px-2 py-2 text-slate-600">{row.provider || 'n/a'}</td>
                                    <td className="px-2 py-2 text-slate-600">{row.active_subscribers ?? '—'}</td>
                                    <td className="px-2 py-2 text-slate-600">{row.total_subscribers ?? '—'}</td>
                                    <td className="px-2 py-2 text-slate-600">{row.last_synced_at || '—'}</td>
                                </tr>
                            ))}
                            {!subscribersQuery.isLoading && subscriberRows.length === 0 ? (
                                <tr>
                                    <td colSpan={6} className="px-2 py-4 text-center text-slate-500">No subscriber rows available.</td>
                                </tr>
                            ) : null}
                        </tbody>
                    </table>
                </div>
            </section>

            <UploadModal
                open={uploadOpen}
                onClose={() => setUploadOpen(false)}
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
