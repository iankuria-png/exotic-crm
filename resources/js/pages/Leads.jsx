import React, { useMemo, useState } from 'react';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import api from '../services/api';
import DataTable from '../components/DataTable';
import StatusBadge from '../components/StatusBadge';
import MetricCard from '../components/MetricCard';
import PageHeader from '../components/PageHeader';

const STATUSES = ['new', 'contacted', 'qualified', 'converted', 'lost'];

function nextLeadStage(currentStatus) {
    const currentIndex = STATUSES.indexOf(currentStatus);
    if (currentIndex < 0 || currentIndex >= STATUSES.length - 1) {
        return null;
    }
    return STATUSES[currentIndex + 1];
}

export default function Leads() {
    const queryClient = useQueryClient();
    const [page, setPage] = useState(1);
    const [search, setSearch] = useState('');
    const [searchInput, setSearchInput] = useState('');
    const [statusFilter, setStatusFilter] = useState('');
    const [ownerFilter, setOwnerFilter] = useState('');
    const [bulkTargetStatus, setBulkTargetStatus] = useState('contacted');
    const [clearSelectionKey, setClearSelectionKey] = useState(0);
    const [bulkFeedback, setBulkFeedback] = useState(null);

    const { data, isLoading } = useQuery({
        queryKey: ['leads', page, search, statusFilter, ownerFilter],
        queryFn: () =>
            api.get('/crm/leads', {
                params: {
                    page,
                    per_page: 25,
                    ...(search && { search }),
                    ...(statusFilter && { status: statusFilter }),
                    ...(ownerFilter && { assigned_to: ownerFilter }),
                },
            }).then((response) => response.data),
    });

    const { data: pipeline } = useQuery({
        queryKey: ['lead-pipeline'],
        queryFn: () => api.get('/crm/leads/pipeline').then((response) => response.data),
    });

    const updateStatusMutation = useMutation({
        mutationFn: ({ leadId, status }) => api.patch(`/crm/leads/${leadId}/status`, { status }).then((response) => response.data),
        onSuccess: () => {
            queryClient.invalidateQueries({ queryKey: ['leads'] });
            queryClient.invalidateQueries({ queryKey: ['lead-pipeline'] });
            queryClient.invalidateQueries({ queryKey: ['dashboard'] });
        },
    });

    const bulkStatusMutation = useMutation({
        mutationFn: async ({ rowsSelection, targetStatus }) => {
            const targets = rowsSelection.filter((row) => row.status !== targetStatus);
            const skipped = rowsSelection.length - targets.length;

            const results = await Promise.allSettled(
                targets.map((row) => api.patch(`/crm/leads/${row.id}/status`, { status: targetStatus })),
            );

            const success = results.filter((result) => result.status === 'fulfilled').length;
            const failed = results.length - success;

            return { total: rowsSelection.length, success, failed, skipped, targetStatus };
        },
        onSuccess: (result) => {
            queryClient.invalidateQueries({ queryKey: ['leads'] });
            queryClient.invalidateQueries({ queryKey: ['lead-pipeline'] });
            queryClient.invalidateQueries({ queryKey: ['dashboard'] });
            setClearSelectionKey((value) => value + 1);
            setBulkFeedback({
                tone: result.failed > 0 ? 'warning' : 'success',
                text: `Bulk stage update to ${result.targetStatus}: ${result.success}/${result.total}${result.skipped ? ` (${result.skipped} skipped)` : ''}${result.failed ? ` (${result.failed} failed)` : ''}.`,
            });
        },
    });

    const handleSearch = (event) => {
        event.preventDefault();
        setSearch(searchInput.trim());
        setPage(1);
    };

    const rows = data?.data || [];

    const ownerOptions = useMemo(() => {
        const map = new Map();
        rows.forEach((row) => {
            if (row.assigned_agent?.id) {
                map.set(String(row.assigned_agent.id), row.assigned_agent.name || `Agent #${row.assigned_agent.id}`);
            }
        });
        return Array.from(map.entries());
    }, [rows]);

    const bulkActions = [
        {
            key: 'bulk-stage',
            label: `Move selected to ${bulkTargetStatus}`,
            loadingLabel: 'Updating...',
            variant: 'primary',
            onClick: async (rowsSelection) => {
                await bulkStatusMutation.mutateAsync({ rowsSelection, targetStatus: bulkTargetStatus });
            },
        },
    ];

    const columns = [
        {
            key: 'name',
            label: 'Lead',
            render: (row) => (
                <div>
                    <p className="text-sm font-semibold text-slate-900">{row.name || 'Unnamed'}</p>
                    <p className="text-xs text-slate-500">{row.email || row.phone_normalized || 'No contact detail'}</p>
                </div>
            ),
        },
        {
            key: 'phone_normalized',
            label: 'Phone',
            render: (row) => <span className="crm-mono text-xs text-slate-600">{row.phone_normalized || '—'}</span>,
        },
        {
            key: 'source',
            label: 'Source',
            render: (row) => <span className="capitalize text-xs text-slate-600">{row.source || 'registration'}</span>,
        },
        {
            key: 'status',
            label: 'Status',
            render: (row) => <StatusBadge status={row.status} />,
        },
        {
            key: 'assigned_agent',
            label: 'Owner',
            render: (row) => <span className="text-xs text-slate-500">{row.assigned_agent?.name || 'Unassigned'}</span>,
        },
        {
            key: 'actions',
            label: 'Stage Action',
            render: (row) => {
                const nextStatus = nextLeadStage(row.status);
                if (!nextStatus || row.status === 'lost') {
                    return <span className="text-xs text-slate-400">—</span>;
                }

                return (
                    <button
                        onClick={(event) => {
                            event.stopPropagation();
                            updateStatusMutation.mutate({ leadId: row.id, status: nextStatus });
                        }}
                        className="rounded-md bg-teal-700 px-2.5 py-1 text-xs font-semibold text-white transition hover:bg-teal-800 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-teal-600"
                    >
                        Move to {nextStatus}
                    </button>
                );
            },
        },
    ];

    return (
        <div className="space-y-4">
            <PageHeader
                title="Leads"
                subtitle={data?.total ? `${data.total.toLocaleString()} leads in pipeline` : 'Lead pipeline and conversion tracking'}
            />

            <section className="grid gap-4 sm:grid-cols-2 xl:grid-cols-5">
                <MetricCard label="New" value={(pipeline?.new ?? 0).toLocaleString()} tone="accent" />
                <MetricCard label="Contacted" value={(pipeline?.contacted ?? 0).toLocaleString()} />
                <MetricCard label="Qualified" value={(pipeline?.qualified ?? 0).toLocaleString()} />
                <MetricCard label="Converted" value={(pipeline?.converted ?? 0).toLocaleString()} tone="success" />
                <MetricCard label="Lost" value={(pipeline?.lost ?? 0).toLocaleString()} tone="danger" />
            </section>

            <section className="crm-filter-row">
                <div className="flex flex-wrap items-center gap-3">
                    <form onSubmit={handleSearch} className="min-w-[240px] flex-1">
                        <div className="relative">
                            <input
                                type="text"
                                value={searchInput}
                                onChange={(event) => setSearchInput(event.target.value)}
                                placeholder="Search leads by name, phone, email..."
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
                        value={statusFilter}
                        onChange={(event) => {
                            setStatusFilter(event.target.value);
                            setPage(1);
                        }}
                        className="crm-select"
                    >
                        <option value="">All Statuses</option>
                        {STATUSES.map((status) => (
                            <option key={status} value={status} className="capitalize">
                                {status}
                            </option>
                        ))}
                    </select>

                    <select
                        value={ownerFilter}
                        onChange={(event) => {
                            setOwnerFilter(event.target.value);
                            setPage(1);
                        }}
                        className="crm-select"
                    >
                        <option value="">All Owners</option>
                        {ownerOptions.map(([ownerId, ownerName]) => (
                            <option key={ownerId} value={ownerId}>{ownerName}</option>
                        ))}
                    </select>

                    <select
                        value={bulkTargetStatus}
                        onChange={(event) => setBulkTargetStatus(event.target.value)}
                        className="crm-select"
                    >
                        {STATUSES.filter((status) => status !== 'lost').map((status) => (
                            <option key={status} value={status}>Bulk target: {status}</option>
                        ))}
                    </select>

                    {(search || statusFilter || ownerFilter) ? (
                        <button
                            type="button"
                            onClick={() => {
                                setSearch('');
                                setSearchInput('');
                                setStatusFilter('');
                                setOwnerFilter('');
                                setPage(1);
                            }}
                            className="crm-btn-secondary px-3 py-2"
                        >
                            Reset
                        </button>
                    ) : null}
                </div>

                {bulkFeedback ? (
                    <p className={`mt-2 text-xs font-medium ${bulkFeedback.tone === 'success' ? 'text-emerald-700' : 'text-amber-700'}`}>
                        {bulkFeedback.text}
                    </p>
                ) : null}
            </section>

            <DataTable
                columns={columns}
                data={data?.data}
                pagination={data}
                onPageChange={setPage}
                isLoading={isLoading}
                emptyMessage="No leads found."
                compact
                selectable
                bulkActions={bulkActions}
                clearSelectionKey={clearSelectionKey}
            />
        </div>
    );
}
