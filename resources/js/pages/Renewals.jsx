import React, { useMemo, useState } from 'react';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
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
    return 'Unknown';
}

export default function Renewals() {
    const queryClient = useQueryClient();
    const [page, setPage] = useState(1);
    const [searchInput, setSearchInput] = useState('');
    const [search, setSearch] = useState('');
    const [bucketFilter, setBucketFilter] = useState('');
    const [clearSelectionKey, setClearSelectionKey] = useState(0);
    const [feedback, setFeedback] = useState(null);

    const { data, isLoading } = useQuery({
        queryKey: ['renewals-overview', page, search, bucketFilter],
        queryFn: () =>
            api.get('/crm/renewals', {
                params: {
                    page,
                    per_page: 50,
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
        mutationFn: ({ dealId }) =>
            api.post('/crm/renewals/remind', {
                deal_id: dealId,
            }).then((response) => response.data),
        onSuccess: () => {
            queryClient.invalidateQueries({ queryKey: ['renewals-overview'] });
        },
    });

    const bulkRemindMutation = useMutation({
        mutationFn: async (rowsSelection) => {
            const results = await Promise.allSettled(
                rowsSelection.map((row) =>
                    api.post('/crm/renewals/remind', {
                        deal_id: row.id,
                    }),
                ),
            );

            const success = results.filter((result) => result.status === 'fulfilled').length;
            const failed = results.length - success;
            return { total: rowsSelection.length, success, failed };
        },
        onSuccess: (result) => {
            queryClient.invalidateQueries({ queryKey: ['renewals-overview'] });
            setClearSelectionKey((value) => value + 1);
            setFeedback({
                tone: result.failed > 0 ? 'warning' : 'success',
                text: `Reminder batch sent: ${result.success}/${result.total}${result.failed ? ` (${result.failed} failed)` : ''}.`,
            });
        },
    });

    const handleSearch = (event) => {
        event.preventDefault();
        setSearch(searchInput.trim());
        setPage(1);
    };

    const rows = data?.targets?.data || [];
    const summary = {
        risk: data?.summary?.risk ?? 0,
        pending: data?.summary?.pending ?? 0,
        renewedThisMonth: data?.summary?.renewed_this_month ?? 0,
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
                <div>
                    <p className="text-sm font-semibold text-slate-900">{row.client?.name || 'Unknown client'}</p>
                    <p className="crm-mono text-xs text-slate-500">{row.client?.phone_normalized || 'No phone'}</p>
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
                <span className={`inline-flex items-center rounded-full px-2.5 py-0.5 text-xs font-medium ring-1 ring-inset ${
                    row.renewal_bucket === 'risk'
                        ? 'bg-rose-50 text-rose-700 ring-rose-200'
                        : row.renewal_bucket === 'pending'
                            ? 'bg-amber-50 text-amber-700 ring-amber-200'
                            : row.renewal_bucket === 'expired'
                                ? 'bg-slate-200 text-slate-700 ring-slate-300'
                                : 'bg-emerald-50 text-emerald-700 ring-emerald-200'
                }`}>
                    {bucketLabel(row.renewal_bucket)}
                </span>
            ),
        },
        {
            key: 'actions',
            label: 'Actions',
            render: (row) => (
                <button
                    type="button"
                    onClick={(event) => {
                        event.stopPropagation();
                        remindMutation.mutate({ dealId: row.id }, {
                            onSuccess: () => setFeedback({ tone: 'success', text: `Reminder sent for ${row.client?.name || 'client'}.` }),
                            onError: () => setFeedback({ tone: 'warning', text: `Reminder failed for ${row.client?.name || 'client'}.` }),
                        });
                    }}
                    disabled={remindMutation.isPending}
                    className="crm-btn-secondary px-3 py-1.5 text-xs disabled:cursor-not-allowed disabled:opacity-50"
                >
                    Remind
                </button>
            ),
        },
    ];

    const bulkActions = [
        {
            key: 'bulk-remind',
            label: 'Send reminder',
            loadingLabel: 'Sending...',
            variant: 'primary',
            onClick: async (rowsSelection) => {
                await bulkRemindMutation.mutateAsync(rowsSelection);
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

            <section className="grid gap-4 md:grid-cols-4">
                <MetricCard label="At Risk (0-3 days)" value={summary.risk.toLocaleString()} meta="urgent outreach" tone="danger" />
                <MetricCard label="Pending (4-14 days)" value={summary.pending.toLocaleString()} meta="scheduled reminders" tone="warning" />
                <MetricCard label="Renewed This Month" value={summary.renewedThisMonth.toLocaleString()} meta="already extended" tone="success" />
                <MetricCard label="Active Campaigns" value={activeCampaigns.toLocaleString()} meta="enabled automation rules" tone="accent" />
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
                            <button type="submit" className="absolute right-2 top-1/2 -translate-y-1/2 rounded p-1 text-slate-400 transition hover:text-slate-600">
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
                    </select>

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

            <DataTable
                columns={columns}
                data={rows}
                pagination={data?.targets}
                onPageChange={setPage}
                isLoading={isLoading}
                emptyMessage="No renewals match current filters."
                compact
                selectable
                bulkActions={bulkActions}
                clearSelectionKey={clearSelectionKey}
            />
        </div>
    );
}

