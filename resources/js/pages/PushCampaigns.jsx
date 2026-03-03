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

    const dashboard = dashboardQuery.data || {};
    const subscriberRows = subscribersQuery.data?.items || [];
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
                }}
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
