import React, { useEffect, useMemo, useState } from 'react';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import api from '../services/api';
import DataTable from '../components/DataTable';
import StatusBadge from '../components/StatusBadge';
import MetricCard from '../components/MetricCard';
import PageHeader from '../components/PageHeader';
import ConfirmDialog from '../components/ConfirmDialog';
import { useToast } from '../components/ToastProvider';

const STATUSES = ['new', 'contacted', 'qualified', 'converted', 'lost'];
const CSV_ERROR_PREVIEW_LIMIT = 8;
const DEFAULT_LEAD_ARCHIVE_REASON = 'Lead archived from leads page';
const DEFAULT_LEAD_DELETE_REASON = 'Lead deleted from leads page';
const DEFAULT_SCRAPE_REASON = 'Scrape lead intake from leads page';

function nextLeadStage(currentStatus) {
    const currentIndex = STATUSES.indexOf(currentStatus);
    if (currentIndex < 0 || currentIndex >= STATUSES.length - 1) {
        return null;
    }
    return STATUSES[currentIndex + 1];
}

function normalizePhone(phone) {
    if (!phone) return '';
    const cleaned = String(phone).replace(/[^\d+]/g, '').replace(/^\+/, '');
    if (cleaned.startsWith('0')) return `254${cleaned.slice(1)}`;
    return cleaned;
}

export default function Leads() {
    const queryClient = useQueryClient();
    const toast = useToast();

    const [page, setPage] = useState(1);
    const [search, setSearch] = useState('');
    const [searchInput, setSearchInput] = useState('');
    const [statusFilter, setStatusFilter] = useState('');
    const [ownerFilter, setOwnerFilter] = useState('');
    const [bulkTargetStatus, setBulkTargetStatus] = useState('contacted');
    const [clearSelectionKey, setClearSelectionKey] = useState(0);

    const [showImportConfirm, setShowImportConfirm] = useState(false);
    const [showCreateModal, setShowCreateModal] = useState(false);
    const [showCsvModal, setShowCsvModal] = useState(false);
    const [showScrapeModal, setShowScrapeModal] = useState(false);
    const [showCsvConfirm, setShowCsvConfirm] = useState(false);
    const [csvResult, setCsvResult] = useState(null);
    const [assignDialog, setAssignDialog] = useState({ lead: null, assigned_to: '', reason: 'Lead reassigned from leads page' });
    const [archiveDialog, setArchiveDialog] = useState({ lead: null, reason: DEFAULT_LEAD_ARCHIVE_REASON });
    const [deleteDialog, setDeleteDialog] = useState({ lead: null, reason: DEFAULT_LEAD_DELETE_REASON });
    const [bulkLeadActionDialog, setBulkLeadActionDialog] = useState({
        open: false,
        action: 'archive',
        leads: [],
        reason: 'Bulk lead archive from leads page',
    });
    const [reconcileDialog, setReconcileDialog] = useState({
        lead: null,
        action: 'convert',
        client_id: '',
        reason: 'Lead reconciled from leads page',
    });

    const [createForm, setCreateForm] = useState({
        platform_id: '',
        name: '',
        phone_normalized: '',
        email: '',
        source: 'outbound',
        assigned_to: '',
    });
    const [csvForm, setCsvForm] = useState({
        platform_id: '',
        has_header: true,
        file: null,
        reason: 'CSV lead upload from leads page',
    });
    const [scrapeForm, setScrapeForm] = useState({
        platform_id: '',
        source_url: '',
        name: '',
        phone_normalized: '',
        email: '',
        assigned_to: '',
        reason: DEFAULT_SCRAPE_REASON,
    });

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

    const { data: integrationData } = useQuery({
        queryKey: ['settings-integrations', 'lead-create'],
        queryFn: () => api.get('/crm/settings/integrations').then((response) => response.data),
    });

    const platformOptions = integrationData?.platforms || [];

    useEffect(() => {
        if (!showCreateModal) {
            return;
        }

        if (!createForm.platform_id && platformOptions.length > 0) {
            setCreateForm((current) => ({
                ...current,
                platform_id: String(platformOptions[0].platform_id),
            }));
        }
    }, [showCreateModal, platformOptions, createForm.platform_id]);

    useEffect(() => {
        if (!showCsvModal) {
            return;
        }

        if (!csvForm.platform_id && platformOptions.length > 0) {
            setCsvForm((current) => ({
                ...current,
                platform_id: String(platformOptions[0].platform_id),
            }));
        }
    }, [showCsvModal, platformOptions, csvForm.platform_id]);

    useEffect(() => {
        if (!showScrapeModal) {
            return;
        }

        if (!scrapeForm.platform_id && platformOptions.length > 0) {
            setScrapeForm((current) => ({
                ...current,
                platform_id: String(platformOptions[0].platform_id),
            }));
        }
    }, [showScrapeModal, platformOptions, scrapeForm.platform_id]);

    const { data: createOwnersData, isLoading: createOwnersLoading } = useQuery({
        queryKey: ['settings-owners', 'lead-create', createForm.platform_id],
        queryFn: () =>
            api.get('/crm/settings/owners', {
                params: { platform_id: Number(createForm.platform_id) },
            }).then((response) => response.data),
        enabled: showCreateModal && !!createForm.platform_id,
    });

    const { data: assignOwnersData, isLoading: assignOwnersLoading } = useQuery({
        queryKey: ['settings-owners', 'lead-assign', assignDialog.lead?.platform_id],
        queryFn: () =>
            api.get('/crm/settings/owners', {
                params: { platform_id: Number(assignDialog.lead?.platform_id) },
            }).then((response) => response.data),
        enabled: !!assignDialog.lead?.platform_id,
    });

    const { data: scrapeOwnersData, isLoading: scrapeOwnersLoading } = useQuery({
        queryKey: ['settings-owners', 'lead-scrape', scrapeForm.platform_id],
        queryFn: () =>
            api.get('/crm/settings/owners', {
                params: { platform_id: Number(scrapeForm.platform_id) },
            }).then((response) => response.data),
        enabled: showScrapeModal && !!scrapeForm.platform_id,
    });

    const updateStatusMutation = useMutation({
        mutationFn: ({ leadId, status }) => api.patch(`/crm/leads/${leadId}/status`, { status }).then((response) => response.data),
        onSuccess: (_, variables) => {
            queryClient.invalidateQueries({ queryKey: ['leads'] });
            queryClient.invalidateQueries({ queryKey: ['dashboard'] });
            toast.success(`Lead moved to ${variables.status}.`);
        },
        onError: (error) => {
            toast.error(error?.response?.data?.message || 'Lead status update failed.');
        },
    });

    const assignLeadMutation = useMutation({
        mutationFn: ({ leadId, assignedTo, reason }) =>
            api.patch(`/crm/leads/${leadId}/assign`, {
                assigned_to: assignedTo,
                reason,
            }).then((response) => response.data),
        onSuccess: () => {
            queryClient.invalidateQueries({ queryKey: ['leads'] });
            toast.success('Lead assignment updated.');
            setAssignDialog({ lead: null, assigned_to: '', reason: 'Lead reassigned from leads page' });
        },
        onError: (error) => {
            toast.error(error?.response?.data?.message || 'Lead assignment failed.');
        },
    });

    const reconcileLeadMutation = useMutation({
        mutationFn: ({ leadId, action, clientId, reason }) =>
            api.post(`/crm/leads/${leadId}/reconcile`, {
                action,
                client_id: clientId || null,
                reason,
            }).then((response) => response.data),
        onSuccess: () => {
            queryClient.invalidateQueries({ queryKey: ['leads'] });
            queryClient.invalidateQueries({ queryKey: ['dashboard'] });
            setReconcileDialog({
                lead: null,
                action: 'convert',
                client_id: '',
                reason: 'Lead reconciled from leads page',
            });
            toast.success('Lead reconciliation completed.');
        },
        onError: (error) => {
            toast.error(error?.response?.data?.message || 'Lead reconciliation failed.');
        },
    });

    const archiveLeadMutation = useMutation({
        mutationFn: ({ leadId, reason }) =>
            api.patch(`/crm/leads/${leadId}/archive`, {
                reason,
            }).then((response) => response.data),
        onSuccess: (_, variables) => {
            queryClient.invalidateQueries({ queryKey: ['leads'] });
            queryClient.invalidateQueries({ queryKey: ['dashboard'] });
            setArchiveDialog({ lead: null, reason: DEFAULT_LEAD_ARCHIVE_REASON });
            toast.success(`Lead archived: ${variables.leadName || 'Lead'}.`);
        },
        onError: (error) => {
            toast.error(error?.response?.data?.message || 'Lead archive failed.');
        },
    });

    const deleteLeadMutation = useMutation({
        mutationFn: ({ leadId, reason }) =>
            api.delete(`/crm/leads/${leadId}`, {
                data: { reason },
            }).then((response) => response.data),
        onSuccess: (_, variables) => {
            queryClient.invalidateQueries({ queryKey: ['leads'] });
            queryClient.invalidateQueries({ queryKey: ['dashboard'] });
            setDeleteDialog({ lead: null, reason: DEFAULT_LEAD_DELETE_REASON });
            toast.success(`Lead deleted: ${variables.leadName || 'Lead'}.`);
        },
        onError: (error) => {
            toast.error(error?.response?.data?.message || 'Lead deletion failed.');
        },
    });

    const bulkLeadActionMutation = useMutation({
        mutationFn: async ({ action, rowsSelection, reason }) => {
            const results = await Promise.allSettled(rowsSelection.map((row) => (
                action === 'delete'
                    ? api.delete(`/crm/leads/${row.id}`, { data: { reason } })
                    : api.patch(`/crm/leads/${row.id}/archive`, { reason })
            )));

            const success = results.filter((result) => result.status === 'fulfilled').length;
            const failed = rowsSelection.length - success;

            return {
                action,
                total: rowsSelection.length,
                success,
                failed,
            };
        },
        onSuccess: (result) => {
            queryClient.invalidateQueries({ queryKey: ['leads'] });
            queryClient.invalidateQueries({ queryKey: ['dashboard'] });
            setClearSelectionKey((value) => value + 1);
            setBulkLeadActionDialog({
                open: false,
                action: 'archive',
                leads: [],
                reason: 'Bulk lead archive from leads page',
            });

            if (result.failed > 0) {
                toast.warning(`Bulk ${result.action} completed with issues: ${result.success}/${result.total} succeeded.`);
                return;
            }

            toast.success(`Bulk ${result.action} complete: ${result.success}/${result.total} processed.`);
        },
        onError: (error) => {
            toast.error(error?.response?.data?.message || 'Bulk lead action failed.');
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
            queryClient.invalidateQueries({ queryKey: ['dashboard'] });
            setClearSelectionKey((value) => value + 1);
            if (result.failed > 0) {
                toast.warning(`Bulk stage update completed with issues: ${result.success}/${result.total} succeeded.`);
                return;
            }
            toast.success(`Bulk stage update complete: ${result.success}/${result.total} moved to ${result.targetStatus}.`);
        },
    });

    const importMutation = useMutation({
        mutationFn: () => api.post('/crm/leads/import', { dry_run: false }).then((response) => response.data),
        onSuccess: (result) => {
            queryClient.invalidateQueries({ queryKey: ['leads'] });
            queryClient.invalidateQueries({ queryKey: ['dashboard'] });
            toast.success(`WordPress lead sync complete: ${result?.totals?.created ?? 0} created, ${result?.totals?.updated ?? 0} refreshed.`);
            setShowImportConfirm(false);
        },
        onError: (error) => {
            toast.error(error?.response?.data?.message || 'Lead sync from WordPress failed.');
        },
    });

    const createMutation = useMutation({
        mutationFn: (payload) => api.post('/crm/leads', payload).then((response) => response.data),
        onSuccess: () => {
            queryClient.invalidateQueries({ queryKey: ['leads'] });
            queryClient.invalidateQueries({ queryKey: ['dashboard'] });
            toast.success('Lead created successfully.');
            setShowCreateModal(false);
            setCreateForm({
                platform_id: platformOptions.length > 0 ? String(platformOptions[0].platform_id) : '',
                name: '',
                phone_normalized: '',
                email: '',
                source: 'outbound',
                assigned_to: '',
            });
        },
        onError: (error) => {
            toast.error(error?.response?.data?.message || 'Lead creation failed.');
        },
    });

    const scrapeLeadMutation = useMutation({
        mutationFn: (payload) => api.post('/crm/leads/scrape-entry', payload).then((response) => response.data),
        onSuccess: (result) => {
            queryClient.invalidateQueries({ queryKey: ['leads'] });
            queryClient.invalidateQueries({ queryKey: ['dashboard'] });
            setShowScrapeModal(false);
            setScrapeForm({
                platform_id: platformOptions.length > 0 ? String(platformOptions[0].platform_id) : '',
                source_url: '',
                name: '',
                phone_normalized: '',
                email: '',
                assigned_to: '',
                reason: DEFAULT_SCRAPE_REASON,
            });
            toast.success(`Scrape intake created lead ${result?.lead?.name || ''}`.trim());
        },
        onError: (error) => {
            toast.error(error?.response?.data?.message || 'Scrape intake failed.');
        },
    });

    const uploadCsvMutation = useMutation({
        mutationFn: (payload) => {
            const formData = new FormData();
            formData.append('platform_id', String(payload.platform_id));
            formData.append('has_header', payload.has_header ? '1' : '0');
            formData.append('reason', payload.reason);
            formData.append('file', payload.file);

            return api.post('/crm/leads/upload-csv', formData, {
                headers: {
                    'Content-Type': 'multipart/form-data',
                },
            }).then((response) => response.data);
        },
        onSuccess: (result, variables) => {
            queryClient.invalidateQueries({ queryKey: ['leads'] });
            queryClient.invalidateQueries({ queryKey: ['dashboard'] });
            setShowCsvModal(false);
            setShowCsvConfirm(false);
            setCsvForm({
                platform_id: platformOptions.length > 0 ? String(platformOptions[0].platform_id) : '',
                has_header: true,
                file: null,
                reason: 'CSV lead upload from leads page',
            });

            const marketName = platformOptions.find(
                (platform) => Number(platform.platform_id) === Number(variables?.platform_id),
            )?.platform_name || 'Selected market';
            setCsvResult({
                kind: 'leads',
                uploadedAt: new Date().toISOString(),
                marketName,
                fileName: variables?.file?.name || 'Uploaded CSV',
                totals: result?.totals || { rows: 0, created: 0, failed: 0 },
                errors: result?.errors || [],
            });

            const created = Number(result?.totals?.created || 0);
            const failed = Number(result?.totals?.failed || 0);
            if (failed > 0) {
                toast.warning(`CSV upload completed: ${created} created, ${failed} failed.`);
                return;
            }
            toast.success(`CSV upload completed: ${created} leads created.`);
        },
        onError: (error) => {
            setShowCsvConfirm(false);
            toast.error(error?.response?.data?.message || 'Lead CSV upload failed.');
        },
    });

    const handleSearch = (event) => {
        event.preventDefault();
        setSearch(searchInput.trim());
        setPage(1);
    };

    const rows = data?.data || [];
    const owners = createOwnersData?.owners || [];
    const assignOwners = assignOwnersData?.owners || [];
    const scrapeOwners = scrapeOwnersData?.owners || [];
    const selectedCsvPlatformName = platformOptions.find((platform) => String(platform.platform_id) === String(csvForm.platform_id))?.platform_name || 'Selected market';
    const selectedAssignOwner = assignOwners.find((owner) => String(owner.id) === String(assignDialog.assigned_to));

    const ownerOptions = useMemo(() => {
        const map = new Map();
        rows.forEach((row) => {
            if (row.assigned_agent?.id) {
                map.set(String(row.assigned_agent.id), row.assigned_agent.name || `Agent #${row.assigned_agent.id}`);
            }
        });
        return Array.from(map.entries());
    }, [rows]);

    const stats = useMemo(() => {
        if (data?.stats) {
            return {
                new: Number(data.stats.new || 0),
                contacted: Number(data.stats.contacted || 0),
                qualified: Number(data.stats.qualified || 0),
                converted: Number(data.stats.converted || 0),
                lost: Number(data.stats.lost || 0),
            };
        }

        return {
            new: rows.filter((row) => row.status === 'new').length,
            contacted: rows.filter((row) => row.status === 'contacted').length,
            qualified: rows.filter((row) => row.status === 'qualified').length,
            converted: rows.filter((row) => row.status === 'converted').length,
            lost: rows.filter((row) => row.status === 'lost').length,
        };
    }, [data?.stats, rows]);

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
        {
            key: 'bulk-archive',
            label: 'Archive selected',
            variant: 'secondary',
            onClick: (rowsSelection) => {
                setBulkLeadActionDialog({
                    open: true,
                    action: 'archive',
                    leads: rowsSelection,
                    reason: 'Bulk lead archive from leads page',
                });
            },
        },
        {
            key: 'bulk-delete',
            label: 'Delete selected',
            variant: 'danger',
            onClick: (rowsSelection) => {
                setBulkLeadActionDialog({
                    open: true,
                    action: 'delete',
                    leads: rowsSelection,
                    reason: 'Bulk lead delete from leads page',
                });
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
            key: 'matched_client',
            label: 'Linked Client',
            render: (row) => {
                const linked = row.converted_client || row.matched_client || null;
                if (!linked) {
                    return <span className="text-xs text-slate-400">No match</span>;
                }

                return (
                    <div>
                        <p className="text-xs font-semibold text-slate-900">
                            {linked.name || `Client #${linked.id}`}
                        </p>
                        <p className="text-[11px] text-slate-500">
                            CRM #{linked.id}
                            {row.match_confidence ? ` • ${row.match_confidence}` : ''}
                        </p>
                    </div>
                );
            },
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
            label: 'Actions',
            render: (row) => {
                const nextStatus = nextLeadStage(row.status);

                return (
                    <div className="flex items-center gap-1.5">
                        {nextStatus && row.status !== 'lost' ? (
                            <button
                                type="button"
                                onClick={(event) => {
                                    event.stopPropagation();
                                    updateStatusMutation.mutate({ leadId: row.id, status: nextStatus });
                                }}
                                className="rounded-md bg-teal-700 px-2.5 py-1 text-xs font-semibold text-white transition hover:bg-teal-800 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-teal-600"
                            >
                                Move to {nextStatus}
                            </button>
                        ) : null}

                        <button
                            type="button"
                            onClick={(event) => {
                                event.stopPropagation();
                                setAssignDialog({
                                    lead: row,
                                    assigned_to: row.assigned_to ? String(row.assigned_to) : '',
                                    reason: 'Lead reassigned from leads page',
                                });
                            }}
                            className="crm-btn-secondary px-2.5 py-1 text-xs"
                        >
                            Assign
                        </button>

                        {row.matched_client || row.converted_client ? (
                            <button
                                type="button"
                                onClick={(event) => {
                                    event.stopPropagation();
                                    const linked = row.converted_client || row.matched_client;
                                    setReconcileDialog({
                                        lead: row,
                                        action: row.status === 'converted' ? 'link' : 'convert',
                                        client_id: linked?.id ? String(linked.id) : '',
                                        reason: 'Lead reconciled from leads page',
                                    });
                                }}
                                className="rounded-md border border-teal-200 bg-teal-50 px-2.5 py-1 text-xs font-semibold text-teal-700 transition hover:bg-teal-100"
                            >
                                Reconcile
                            </button>
                        ) : null}

                        <button
                            type="button"
                            onClick={(event) => {
                                event.stopPropagation();
                                setArchiveDialog({
                                    lead: row,
                                    reason: DEFAULT_LEAD_ARCHIVE_REASON,
                                });
                            }}
                            className="crm-btn-secondary px-2.5 py-1 text-xs"
                        >
                            Archive
                        </button>

                        <button
                            type="button"
                            onClick={(event) => {
                                event.stopPropagation();
                                setDeleteDialog({
                                    lead: row,
                                    reason: DEFAULT_LEAD_DELETE_REASON,
                                });
                            }}
                            className="crm-btn-danger px-2.5 py-1 text-xs"
                        >
                            Delete
                        </button>
                    </div>
                );
            },
        },
    ];

    return (
        <div className="space-y-4">
            <PageHeader
                title="Leads"
                subtitle={data?.total ? `${data.total.toLocaleString()} leads in pipeline` : 'Lead pipeline and conversion tracking'}
                actions={(
                    <>
                        <button
                            type="button"
                            onClick={() => setShowImportConfirm(true)}
                            disabled={importMutation.isPending}
                            className="crm-btn-secondary disabled:cursor-not-allowed disabled:opacity-50"
                        >
                            {importMutation.isPending ? 'Syncing...' : 'Sync leads from WordPress'}
                        </button>
                        <button
                            type="button"
                            onClick={() => setShowCsvModal(true)}
                            className="crm-btn-secondary"
                        >
                            Upload CSV
                        </button>
                        <button
                            type="button"
                            onClick={() => setShowScrapeModal(true)}
                            className="crm-btn-secondary"
                        >
                            Scrape lead
                        </button>
                        <button
                            type="button"
                            onClick={() => setShowCreateModal(true)}
                            className="crm-btn-primary"
                        >
                            Add lead
                        </button>
                    </>
                )}
            />

            <section className="grid gap-4 sm:grid-cols-2 xl:grid-cols-5">
                <MetricCard label="New" value={stats.new.toLocaleString()} tone="accent" />
                <MetricCard label="Contacted" value={stats.contacted.toLocaleString()} />
                <MetricCard label="Qualified" value={stats.qualified.toLocaleString()} />
                <MetricCard label="Converted" value={stats.converted.toLocaleString()} tone="success" />
                <MetricCard label="Lost" value={stats.lost.toLocaleString()} tone="danger" />
            </section>

            <section className="crm-filter-row">
                <div className="flex flex-wrap items-center gap-3">
                    <form onSubmit={handleSearch} className="min-w-[240px] flex-1">
                        <div className="relative">
                            <input
                                type="text"
                                value={searchInput}
                                onChange={(event) => setSearchInput(event.target.value)}
                                placeholder="Search leads by name, phone, or email..."
                                className="crm-input pr-10"
                            />
                            <button type="submit" aria-label="Run lead search" className="absolute right-2 top-1/2 -translate-y-1/2 rounded p-1 text-slate-400 transition hover:text-slate-600">
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
                        <option value="">All statuses</option>
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
                        <option value="">All owners</option>
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

                <p className="mt-2 text-xs text-slate-500">
                    “Sync from WordPress” imports registered profiles needing payment follow-up into this lead pipeline. Archive and delete actions always require a reason.
                </p>
            </section>

            {csvResult ? (
                <section className={`rounded-lg border px-4 py-3 ${
                    Number(csvResult?.totals?.failed || 0) > 0
                        ? 'border-amber-200 bg-amber-50/70'
                        : 'border-emerald-200 bg-emerald-50/70'
                }`}>
                    <div className="flex flex-wrap items-start justify-between gap-3">
                        <div>
                            <p className="text-sm font-semibold text-slate-900">Lead CSV upload summary</p>
                            <p className="text-xs text-slate-600">
                                {csvResult.fileName} • {csvResult.marketName} • {new Date(csvResult.uploadedAt).toLocaleString()}
                            </p>
                        </div>
                        <button
                            type="button"
                            onClick={() => setCsvResult(null)}
                            className="text-xs font-semibold text-slate-600 underline decoration-slate-300 underline-offset-2 hover:text-slate-800"
                        >
                            Dismiss
                        </button>
                    </div>

                    <div className="mt-3 grid gap-2 sm:grid-cols-3">
                        <p className="rounded-md border border-slate-200 bg-white px-3 py-2 text-xs text-slate-700">
                            Rows: <span className="crm-mono font-semibold text-slate-900">{Number(csvResult?.totals?.rows || 0)}</span>
                        </p>
                        <p className="rounded-md border border-emerald-200 bg-white px-3 py-2 text-xs text-emerald-700">
                            Created: <span className="crm-mono font-semibold">{Number(csvResult?.totals?.created || 0)}</span>
                        </p>
                        <p className="rounded-md border border-amber-200 bg-white px-3 py-2 text-xs text-amber-700">
                            Failed: <span className="crm-mono font-semibold">{Number(csvResult?.totals?.failed || 0)}</span>
                        </p>
                    </div>

                    {csvResult.errors?.length ? (
                        <div className="mt-3 rounded-md border border-amber-200 bg-white p-3">
                            <p className="text-xs font-semibold uppercase tracking-[0.09em] text-amber-700">Row errors</p>
                            <div className="mt-2 space-y-1.5">
                                {csvResult.errors.slice(0, CSV_ERROR_PREVIEW_LIMIT).map((errorRow) => (
                                    <p key={`${errorRow.row}-${errorRow.message}`} className="text-xs text-slate-700">
                                        <span className="crm-mono font-semibold text-slate-900">Row {errorRow.row}:</span> {errorRow.message}
                                    </p>
                                ))}
                            </div>
                            {csvResult.errors.length > CSV_ERROR_PREVIEW_LIMIT ? (
                                <p className="mt-2 text-xs text-slate-500">
                                    +{csvResult.errors.length - CSV_ERROR_PREVIEW_LIMIT} additional row errors hidden.
                                </p>
                            ) : null}
                        </div>
                    ) : null}
                </section>
            ) : null}

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

            <ConfirmDialog
                open={showImportConfirm}
                title="Sync Leads from WordPress"
                message="This will import or refresh lead records from WordPress for markets you can access."
                confirmLabel={importMutation.isPending ? 'Syncing...' : 'Start sync'}
                onCancel={() => setShowImportConfirm(false)}
                onConfirm={() => importMutation.mutate()}
                confirmDisabled={importMutation.isPending}
                isPending={importMutation.isPending}
            />

            <ConfirmDialog
                open={!!assignDialog.lead}
                title="Assign Lead Owner"
                message={assignDialog.lead ? `Assign ${assignDialog.lead.name || `Lead #${assignDialog.lead.id}`} to a sales owner.` : ''}
                confirmLabel="Save assignment"
                onCancel={() => setAssignDialog({ lead: null, assigned_to: '', reason: 'Lead reassigned from leads page' })}
                onConfirm={() => {
                    if (!assignDialog.lead?.id) return;
                    assignLeadMutation.mutate({
                        leadId: assignDialog.lead.id,
                        assignedTo: assignDialog.assigned_to ? Number(assignDialog.assigned_to) : null,
                        reason: assignDialog.reason.trim() || 'Lead reassigned from leads page',
                    });
                }}
                confirmDisabled={!assignDialog.assigned_to || assignLeadMutation.isPending}
                isPending={assignLeadMutation.isPending}
            >
                <label htmlFor="assign-owner" className="mb-1 block text-sm font-medium text-slate-700">Owner</label>
                <select
                    id="assign-owner"
                    value={assignDialog.assigned_to}
                    onChange={(event) => setAssignDialog((current) => ({ ...current, assigned_to: event.target.value }))}
                    className="crm-select w-full"
                    disabled={assignOwnersLoading}
                >
                    <option value="">{assignOwnersLoading ? 'Loading owners...' : 'Select owner'}</option>
                    {assignOwners.map((owner) => (
                        <option key={owner.id} value={owner.id}>
                            {owner.name} ({owner.role_label || owner.role})
                        </option>
                    ))}
                </select>

                {selectedAssignOwner ? (
                    <div className="rounded-md border border-slate-200 bg-slate-50 px-3 py-2 text-xs text-slate-700">
                        <p className="font-semibold text-slate-900">{selectedAssignOwner.name}</p>
                        <p className="mt-0.5">
                            Role: <span className="font-medium">{selectedAssignOwner.role_label || selectedAssignOwner.role}</span>
                        </p>
                        <p className="mt-1 text-slate-600">
                            Markets: {(selectedAssignOwner.assigned_markets || []).map((market) => market.name).join(', ') || 'None configured'}
                        </p>
                    </div>
                ) : null}

                {assignOwners.length > 0 ? (
                    <div className="space-y-2 rounded-md border border-slate-200 bg-white p-2">
                        <p className="text-[11px] font-semibold uppercase tracking-[0.1em] text-slate-500">Owner Directory</p>
                        <div className="max-h-36 space-y-1.5 overflow-y-auto pr-1">
                            {assignOwners.map((owner) => (
                                <button
                                    key={`assign-owner-card-${owner.id}`}
                                    type="button"
                                    onClick={() => setAssignDialog((current) => ({ ...current, assigned_to: String(owner.id) }))}
                                    className={`w-full rounded-md border px-2 py-1.5 text-left text-xs transition ${
                                        String(assignDialog.assigned_to) === String(owner.id)
                                            ? 'border-teal-300 bg-teal-50'
                                            : 'border-slate-200 bg-slate-50 hover:border-slate-300'
                                    }`}
                                >
                                    <p className="font-semibold text-slate-900">{owner.name}</p>
                                    <p className="text-slate-600">{owner.role_label || owner.role}</p>
                                    <p className="text-slate-500">
                                        {(owner.assigned_markets || []).map((market) => market.name).join(', ') || 'No assigned markets'}
                                    </p>
                                </button>
                            ))}
                        </div>
                    </div>
                ) : null}

                <label htmlFor="assign-reason" className="mb-1 mt-2 block text-sm font-medium text-slate-700">Reason</label>
                <textarea
                    id="assign-reason"
                    rows={3}
                    value={assignDialog.reason}
                    onChange={(event) => setAssignDialog((current) => ({ ...current, reason: event.target.value }))}
                    className="crm-input"
                />
            </ConfirmDialog>

            <ConfirmDialog
                open={!!archiveDialog.lead}
                title="Archive Lead"
                message={archiveDialog.lead ? `Archive ${archiveDialog.lead.name || `Lead #${archiveDialog.lead.id}`} from the active sales pipeline?` : ''}
                confirmLabel={archiveLeadMutation.isPending ? 'Archiving...' : 'Archive lead'}
                onCancel={() => setArchiveDialog({ lead: null, reason: DEFAULT_LEAD_ARCHIVE_REASON })}
                onConfirm={() => {
                    if (!archiveDialog.lead?.id) return;
                    archiveLeadMutation.mutate({
                        leadId: archiveDialog.lead.id,
                        leadName: archiveDialog.lead.name,
                        reason: archiveDialog.reason.trim(),
                    });
                }}
                confirmDisabled={!archiveDialog.reason.trim() || archiveLeadMutation.isPending}
                isPending={archiveLeadMutation.isPending}
            >
                <div className="rounded-md border border-slate-200 bg-slate-50 px-3 py-2 text-xs text-slate-700">
                    <p>This removes the lead from default pipeline views but keeps audit history and timeline logs.</p>
                </div>
                <label htmlFor="archive-lead-reason" className="mb-1 mt-2 block text-sm font-medium text-slate-700">Reason</label>
                <textarea
                    id="archive-lead-reason"
                    rows={3}
                    value={archiveDialog.reason}
                    onChange={(event) => setArchiveDialog((current) => ({ ...current, reason: event.target.value }))}
                    className="crm-input"
                />
            </ConfirmDialog>

            <ConfirmDialog
                open={!!reconcileDialog.lead}
                title="Reconcile Lead"
                message={reconcileDialog.lead ? `Reconcile ${reconcileDialog.lead.name || `Lead #${reconcileDialog.lead.id}`} with a matched client.` : ''}
                confirmLabel={reconcileLeadMutation.isPending ? 'Applying...' : 'Apply reconciliation'}
                onCancel={() => setReconcileDialog({
                    lead: null,
                    action: 'convert',
                    client_id: '',
                    reason: 'Lead reconciled from leads page',
                })}
                onConfirm={() => {
                    if (!reconcileDialog.lead?.id) return;
                    reconcileLeadMutation.mutate({
                        leadId: reconcileDialog.lead.id,
                        action: reconcileDialog.action,
                        clientId: reconcileDialog.client_id ? Number(reconcileDialog.client_id) : null,
                        reason: reconcileDialog.reason.trim(),
                    });
                }}
                confirmDisabled={!reconcileDialog.client_id || !reconcileDialog.reason.trim() || reconcileLeadMutation.isPending}
                isPending={reconcileLeadMutation.isPending}
            >
                <div className="space-y-2 rounded-md border border-slate-200 bg-slate-50 px-3 py-2 text-xs text-slate-700">
                    <p>
                        Matched client:{' '}
                        <span className="font-semibold text-slate-900">
                            {reconcileDialog.lead?.matched_client?.name
                                || reconcileDialog.lead?.converted_client?.name
                                || 'Not available'}
                        </span>
                    </p>
                    <p>
                        Confidence:{' '}
                        <span className="font-semibold text-slate-900">
                            {reconcileDialog.lead?.match_confidence || 'n/a'}
                        </span>
                    </p>
                </div>

                <label htmlFor="reconcile-action" className="mb-1 mt-2 block text-sm font-medium text-slate-700">Action</label>
                <select
                    id="reconcile-action"
                    value={reconcileDialog.action}
                    onChange={(event) => setReconcileDialog((current) => ({ ...current, action: event.target.value }))}
                    className="crm-select w-full"
                >
                    <option value="convert">Convert lead</option>
                    <option value="link">Link only (keep current status)</option>
                    <option value="archive">Link + archive</option>
                </select>

                <label htmlFor="reconcile-client" className="mb-1 mt-2 block text-sm font-medium text-slate-700">Client</label>
                <select
                    id="reconcile-client"
                    value={reconcileDialog.client_id}
                    onChange={(event) => setReconcileDialog((current) => ({ ...current, client_id: event.target.value }))}
                    className="crm-select w-full"
                >
                    <option value="">Select client</option>
                    {[reconcileDialog.lead?.matched_client, reconcileDialog.lead?.converted_client]
                        .filter((clientOption, index, array) => clientOption?.id && array.findIndex((entry) => entry?.id === clientOption.id) === index)
                        .map((clientOption) => (
                            <option key={clientOption.id} value={clientOption.id}>
                                {clientOption.name || `Client #${clientOption.id}`} (CRM #{clientOption.id})
                            </option>
                        ))}
                </select>

                <label htmlFor="reconcile-reason" className="mb-1 mt-2 block text-sm font-medium text-slate-700">Reason</label>
                <textarea
                    id="reconcile-reason"
                    rows={3}
                    value={reconcileDialog.reason}
                    onChange={(event) => setReconcileDialog((current) => ({ ...current, reason: event.target.value }))}
                    className="crm-input"
                />
            </ConfirmDialog>

            <ConfirmDialog
                open={!!deleteDialog.lead}
                title="Delete Lead"
                message={deleteDialog.lead ? `Delete ${deleteDialog.lead.name || `Lead #${deleteDialog.lead.id}`} permanently?` : ''}
                confirmLabel={deleteLeadMutation.isPending ? 'Deleting...' : 'Delete lead'}
                onCancel={() => setDeleteDialog({ lead: null, reason: DEFAULT_LEAD_DELETE_REASON })}
                onConfirm={() => {
                    if (!deleteDialog.lead?.id) return;
                    deleteLeadMutation.mutate({
                        leadId: deleteDialog.lead.id,
                        leadName: deleteDialog.lead.name,
                        reason: deleteDialog.reason.trim(),
                    });
                }}
                confirmDisabled={!deleteDialog.reason.trim() || deleteLeadMutation.isPending}
                isPending={deleteLeadMutation.isPending}
                tone="danger"
            >
                <div className="rounded-md border border-rose-200 bg-rose-50 px-3 py-2 text-xs text-rose-700">
                    <p>This action is permanent and removes the lead record from CRM tables.</p>
                </div>
                <label htmlFor="delete-lead-reason" className="mb-1 mt-2 block text-sm font-medium text-slate-700">Reason</label>
                <textarea
                    id="delete-lead-reason"
                    rows={3}
                    value={deleteDialog.reason}
                    onChange={(event) => setDeleteDialog((current) => ({ ...current, reason: event.target.value }))}
                    className="crm-input"
                />
            </ConfirmDialog>

            <ConfirmDialog
                open={bulkLeadActionDialog.open}
                title={bulkLeadActionDialog.action === 'delete' ? 'Delete Selected Leads' : 'Archive Selected Leads'}
                message={`Apply this action to ${bulkLeadActionDialog.leads.length} selected lead(s)?`}
                confirmLabel={bulkLeadActionMutation.isPending
                    ? (bulkLeadActionDialog.action === 'delete' ? 'Deleting...' : 'Archiving...')
                    : (bulkLeadActionDialog.action === 'delete' ? 'Delete selected' : 'Archive selected')}
                onCancel={() => setBulkLeadActionDialog({
                    open: false,
                    action: 'archive',
                    leads: [],
                    reason: 'Bulk lead archive from leads page',
                })}
                onConfirm={() => {
                    bulkLeadActionMutation.mutate({
                        action: bulkLeadActionDialog.action,
                        rowsSelection: bulkLeadActionDialog.leads,
                        reason: bulkLeadActionDialog.reason.trim(),
                    });
                }}
                confirmDisabled={!bulkLeadActionDialog.reason.trim() || bulkLeadActionMutation.isPending}
                isPending={bulkLeadActionMutation.isPending}
                tone={bulkLeadActionDialog.action === 'delete' ? 'danger' : 'default'}
            >
                <div className={`rounded-md border px-3 py-2 text-xs ${
                    bulkLeadActionDialog.action === 'delete'
                        ? 'border-rose-200 bg-rose-50 text-rose-700'
                        : 'border-slate-200 bg-slate-50 text-slate-700'
                }`}>
                    <p>
                        {bulkLeadActionDialog.action === 'delete'
                            ? 'Bulk delete permanently removes selected leads.'
                            : 'Bulk archive removes selected leads from default pipeline views.'}
                    </p>
                </div>
                <label htmlFor="bulk-lead-reason" className="mb-1 mt-2 block text-sm font-medium text-slate-700">Reason</label>
                <textarea
                    id="bulk-lead-reason"
                    rows={3}
                    value={bulkLeadActionDialog.reason}
                    onChange={(event) => setBulkLeadActionDialog((current) => ({ ...current, reason: event.target.value }))}
                    className="crm-input"
                />
            </ConfirmDialog>

            <ConfirmDialog
                open={showCsvConfirm}
                title="Confirm Leads CSV Upload"
                message="This upload creates new lead records only. It does not update or delete existing leads."
                confirmLabel={uploadCsvMutation.isPending ? 'Uploading...' : 'Start upload'}
                onCancel={() => setShowCsvConfirm(false)}
                onConfirm={() => {
                    uploadCsvMutation.mutate({
                        platform_id: Number(csvForm.platform_id),
                        has_header: csvForm.has_header,
                        file: csvForm.file,
                        reason: csvForm.reason.trim(),
                    });
                }}
                confirmDisabled={!csvForm.platform_id || !csvForm.file || !csvForm.reason.trim() || uploadCsvMutation.isPending}
                isPending={uploadCsvMutation.isPending}
            >
                <div className="rounded-md border border-slate-200 bg-slate-50 px-3 py-2 text-xs text-slate-700">
                    <p><span className="font-semibold text-slate-900">Market:</span> {selectedCsvPlatformName}</p>
                    <p className="mt-1"><span className="font-semibold text-slate-900">File:</span> {csvForm.file?.name || 'No file selected'}</p>
                    <p className="mt-1"><span className="font-semibold text-slate-900">Header row:</span> {csvForm.has_header ? 'Included' : 'Not included'}</p>
                    <p className="mt-2 text-slate-500">Limit: up to 500 rows per upload.</p>
                </div>
            </ConfirmDialog>

            {showScrapeModal ? (
                <div className="fixed inset-0 z-50 flex items-center justify-center bg-slate-900/45 p-4" onClick={() => setShowScrapeModal(false)}>
                    <div className="w-full max-w-2xl rounded-lg border border-slate-200 bg-white shadow-xl" onClick={(event) => event.stopPropagation()}>
                        <header className="crm-panel-header">
                            <div>
                                <h3 className="crm-panel-title">Scrape Lead Intake</h3>
                                <p className="crm-panel-subtitle">Create a lead from an external source URL with controlled metadata.</p>
                            </div>
                        </header>

                        <div className="grid gap-3 p-4 md:grid-cols-2">
                            <div className="md:col-span-2">
                                <label htmlFor="scrape-market" className="mb-1 block text-sm font-medium text-slate-700">Market</label>
                                <select
                                    id="scrape-market"
                                    value={scrapeForm.platform_id}
                                    onChange={(event) => setScrapeForm((current) => ({ ...current, platform_id: event.target.value, assigned_to: '' }))}
                                    className="crm-select w-full"
                                >
                                    <option value="">Select market</option>
                                    {platformOptions.map((platform) => (
                                        <option key={platform.platform_id} value={platform.platform_id}>
                                            {platform.platform_name}
                                        </option>
                                    ))}
                                </select>
                            </div>

                            <div className="md:col-span-2">
                                <label htmlFor="scrape-source-url" className="mb-1 block text-sm font-medium text-slate-700">Source URL</label>
                                <input
                                    id="scrape-source-url"
                                    type="url"
                                    value={scrapeForm.source_url}
                                    onChange={(event) => setScrapeForm((current) => ({ ...current, source_url: event.target.value }))}
                                    className="crm-input"
                                    placeholder="https://example.com/listing/lead-profile"
                                />
                            </div>

                            <div>
                                <label htmlFor="scrape-name" className="mb-1 block text-sm font-medium text-slate-700">Lead name (optional)</label>
                                <input
                                    id="scrape-name"
                                    type="text"
                                    value={scrapeForm.name}
                                    onChange={(event) => setScrapeForm((current) => ({ ...current, name: event.target.value }))}
                                    className="crm-input"
                                    placeholder="Derived from URL if blank"
                                />
                            </div>

                            <div>
                                <label htmlFor="scrape-phone" className="mb-1 block text-sm font-medium text-slate-700">Phone (optional)</label>
                                <input
                                    id="scrape-phone"
                                    type="text"
                                    value={scrapeForm.phone_normalized}
                                    onChange={(event) => setScrapeForm((current) => ({ ...current, phone_normalized: event.target.value }))}
                                    className="crm-input"
                                    placeholder="e.g. 254712345678"
                                />
                            </div>

                            <div>
                                <label htmlFor="scrape-email" className="mb-1 block text-sm font-medium text-slate-700">Email (optional)</label>
                                <input
                                    id="scrape-email"
                                    type="email"
                                    value={scrapeForm.email}
                                    onChange={(event) => setScrapeForm((current) => ({ ...current, email: event.target.value }))}
                                    className="crm-input"
                                    placeholder="name@example.com"
                                />
                            </div>

                            <div>
                                <label htmlFor="scrape-owner" className="mb-1 block text-sm font-medium text-slate-700">Owner (optional)</label>
                                <select
                                    id="scrape-owner"
                                    value={scrapeForm.assigned_to}
                                    onChange={(event) => setScrapeForm((current) => ({ ...current, assigned_to: event.target.value }))}
                                    className="crm-select w-full"
                                    disabled={!scrapeForm.platform_id || scrapeOwnersLoading}
                                >
                                    <option value="">{scrapeOwnersLoading ? 'Loading owners...' : 'Auto-assign owner'}</option>
                                    {scrapeOwners.map((owner) => (
                                        <option key={owner.id} value={owner.id}>
                                            {owner.name} ({owner.role_label || owner.role})
                                        </option>
                                    ))}
                                </select>
                            </div>

                            <div className="md:col-span-2">
                                <label htmlFor="scrape-reason" className="mb-1 block text-sm font-medium text-slate-700">Reason</label>
                                <textarea
                                    id="scrape-reason"
                                    rows={3}
                                    value={scrapeForm.reason}
                                    onChange={(event) => setScrapeForm((current) => ({ ...current, reason: event.target.value }))}
                                    className="crm-input"
                                />
                            </div>
                        </div>

                        <div className="mx-4 mb-3 rounded-md border border-slate-200 bg-slate-50 px-3 py-2 text-xs text-slate-600">
                            This is a controlled intake path. It records the source URL in audit/timeline and creates a lead in the standard pipeline.
                        </div>

                        <footer className="flex items-center justify-end gap-2 border-t border-slate-100 p-4">
                            <button type="button" className="crm-btn-secondary" onClick={() => setShowScrapeModal(false)}>
                                Cancel
                            </button>
                            <button
                                type="button"
                                disabled={!scrapeForm.platform_id || !scrapeForm.source_url.trim() || !scrapeForm.reason.trim() || scrapeLeadMutation.isPending}
                                onClick={() => {
                                    scrapeLeadMutation.mutate({
                                        platform_id: Number(scrapeForm.platform_id),
                                        source_url: scrapeForm.source_url.trim(),
                                        name: scrapeForm.name.trim() || null,
                                        phone_normalized: normalizePhone(scrapeForm.phone_normalized.trim()) || null,
                                        email: scrapeForm.email.trim() || null,
                                        assigned_to: scrapeForm.assigned_to ? Number(scrapeForm.assigned_to) : null,
                                        reason: scrapeForm.reason.trim(),
                                    });
                                }}
                                className="crm-btn-primary disabled:cursor-not-allowed disabled:opacity-50"
                            >
                                {scrapeLeadMutation.isPending ? 'Creating...' : 'Create scrape entry'}
                            </button>
                        </footer>
                    </div>
                </div>
            ) : null}

            {showCreateModal ? (
                <div className="fixed inset-0 z-50 flex items-center justify-center bg-slate-900/45 p-4" onClick={() => setShowCreateModal(false)}>
                    <div className="w-full max-w-2xl rounded-lg border border-slate-200 bg-white shadow-xl" onClick={(event) => event.stopPropagation()}>
                        <header className="crm-panel-header">
                            <div>
                                <h3 className="crm-panel-title">Add Lead</h3>
                                <p className="crm-panel-subtitle">Create a lead manually and assign it to a sales owner.</p>
                            </div>
                        </header>

                        <div className="grid gap-3 p-4 md:grid-cols-2">
                            <div className="md:col-span-2">
                                <label htmlFor="lead-market" className="mb-1 block text-sm font-medium text-slate-700">Market</label>
                                <select
                                    id="lead-market"
                                    value={createForm.platform_id}
                                    onChange={(event) => setCreateForm((current) => ({ ...current, platform_id: event.target.value, assigned_to: '' }))}
                                    className="crm-select w-full"
                                >
                                    <option value="">Select market</option>
                                    {platformOptions.map((platform) => (
                                        <option key={platform.platform_id} value={platform.platform_id}>
                                            {platform.platform_name}
                                        </option>
                                    ))}
                                </select>
                            </div>

                            <div>
                                <label htmlFor="lead-name" className="mb-1 block text-sm font-medium text-slate-700">Lead name</label>
                                <input
                                    id="lead-name"
                                    type="text"
                                    value={createForm.name}
                                    onChange={(event) => setCreateForm((current) => ({ ...current, name: event.target.value }))}
                                    className="crm-input"
                                    placeholder="Enter lead name"
                                />
                            </div>

                            <div>
                                <label htmlFor="lead-phone" className="mb-1 block text-sm font-medium text-slate-700">Phone</label>
                                <input
                                    id="lead-phone"
                                    type="text"
                                    value={createForm.phone_normalized}
                                    onChange={(event) => setCreateForm((current) => ({ ...current, phone_normalized: event.target.value }))}
                                    className="crm-input"
                                    placeholder="e.g. 254712345678"
                                />
                            </div>

                            <div>
                                <label htmlFor="lead-email" className="mb-1 block text-sm font-medium text-slate-700">Email</label>
                                <input
                                    id="lead-email"
                                    type="email"
                                    value={createForm.email}
                                    onChange={(event) => setCreateForm((current) => ({ ...current, email: event.target.value }))}
                                    className="crm-input"
                                    placeholder="name@example.com"
                                />
                            </div>

                            <div>
                                <label htmlFor="lead-source" className="mb-1 block text-sm font-medium text-slate-700">Source</label>
                                <select
                                    id="lead-source"
                                    value={createForm.source}
                                    onChange={(event) => setCreateForm((current) => ({ ...current, source: event.target.value }))}
                                    className="crm-select w-full"
                                >
                                    <option value="outbound">Outbound</option>
                                    <option value="referral">Referral</option>
                                    <option value="registration">Registration</option>
                                    <option value="import">Import</option>
                                </select>
                            </div>

                            <div className="md:col-span-2">
                                <label htmlFor="lead-owner" className="mb-1 block text-sm font-medium text-slate-700">Owner</label>
                                <select
                                    id="lead-owner"
                                    value={createForm.assigned_to}
                                    onChange={(event) => setCreateForm((current) => ({ ...current, assigned_to: event.target.value }))}
                                    className="crm-select w-full"
                                    disabled={!createForm.platform_id || createOwnersLoading}
                                >
                                    <option value="">{createOwnersLoading ? 'Loading owners...' : 'Auto-assign owner'}</option>
                                    {owners.map((owner) => (
                                        <option key={owner.id} value={owner.id}>
                                            {owner.name} ({owner.role_label || owner.role})
                                        </option>
                                    ))}
                                </select>
                            </div>
                        </div>

                        <footer className="flex items-center justify-end gap-2 border-t border-slate-100 p-4">
                            <button type="button" className="crm-btn-secondary" onClick={() => setShowCreateModal(false)}>
                                Cancel
                            </button>
                            <button
                                type="button"
                                disabled={!createForm.platform_id || !createForm.name.trim() || createMutation.isPending}
                                onClick={() => {
                                    createMutation.mutate({
                                        platform_id: Number(createForm.platform_id),
                                        name: createForm.name.trim(),
                                        phone_normalized: normalizePhone(createForm.phone_normalized.trim()),
                                        email: createForm.email.trim() || null,
                                        source: createForm.source,
                                        assigned_to: createForm.assigned_to ? Number(createForm.assigned_to) : null,
                                        reason: 'Manual lead create from leads page',
                                    });
                                }}
                                className="crm-btn-primary disabled:cursor-not-allowed disabled:opacity-50"
                            >
                                {createMutation.isPending ? 'Creating...' : 'Create lead'}
                            </button>
                        </footer>
                    </div>
                </div>
            ) : null}

            {showCsvModal ? (
                <div className="fixed inset-0 z-50 flex items-center justify-center bg-slate-900/45 p-4" onClick={() => {
                    setShowCsvModal(false);
                    setShowCsvConfirm(false);
                }}>
                    <div className="w-full max-w-xl rounded-lg border border-slate-200 bg-white shadow-xl" onClick={(event) => event.stopPropagation()}>
                        <header className="crm-panel-header">
                            <div>
                                <h3 className="crm-panel-title">Upload Leads CSV</h3>
                                <p className="crm-panel-subtitle">Bulk-create lead records from CSV for one market at a time.</p>
                            </div>
                        </header>

                        <div className="space-y-3 p-4">
                            <div>
                                <label htmlFor="leads-csv-market" className="mb-1 block text-sm font-medium text-slate-700">Market</label>
                                <select
                                    id="leads-csv-market"
                                    value={csvForm.platform_id}
                                    onChange={(event) => setCsvForm((current) => ({ ...current, platform_id: event.target.value }))}
                                    className="crm-select w-full"
                                >
                                    <option value="">Select market</option>
                                    {platformOptions.map((platform) => (
                                        <option key={platform.platform_id} value={platform.platform_id}>
                                            {platform.platform_name}
                                        </option>
                                    ))}
                                </select>
                            </div>

                            <div>
                                <label htmlFor="leads-csv-file" className="mb-1 block text-sm font-medium text-slate-700">CSV file</label>
                                <input
                                    id="leads-csv-file"
                                    type="file"
                                    accept=".csv,text/csv,.txt"
                                    onChange={(event) => setCsvForm((current) => ({ ...current, file: event.target.files?.[0] || null }))}
                                    className="crm-input"
                                />
                            </div>

                            <label className="flex items-center gap-2 text-sm text-slate-700">
                                <input
                                    type="checkbox"
                                    checked={csvForm.has_header}
                                    onChange={(event) => setCsvForm((current) => ({ ...current, has_header: event.target.checked }))}
                                    className="h-4 w-4 rounded border-slate-300 text-teal-700 focus:ring-teal-200"
                                />
                                CSV includes a header row
                            </label>

                            <div>
                                <label htmlFor="leads-csv-reason" className="mb-1 block text-sm font-medium text-slate-700">Reason</label>
                                <textarea
                                    id="leads-csv-reason"
                                    rows={3}
                                    value={csvForm.reason}
                                    onChange={(event) => setCsvForm((current) => ({ ...current, reason: event.target.value }))}
                                    className="crm-input"
                                />
                            </div>

                            <p className="rounded-md border border-slate-200 bg-slate-50 px-3 py-2 text-xs text-slate-600">
                                Expected columns: <span className="crm-mono">name, phone, email, source, status, assigned_to</span>.
                            </p>
                        </div>

                        <footer className="flex items-center justify-end gap-2 border-t border-slate-100 p-4">
                            <button
                                type="button"
                                className="crm-btn-secondary"
                                onClick={() => {
                                    setShowCsvModal(false);
                                    setShowCsvConfirm(false);
                                }}
                            >
                                Cancel
                            </button>
                            <button
                                type="button"
                                disabled={!csvForm.platform_id || !csvForm.file || !csvForm.reason.trim() || uploadCsvMutation.isPending}
                                onClick={() => setShowCsvConfirm(true)}
                                className="crm-btn-primary disabled:cursor-not-allowed disabled:opacity-50"
                            >
                                Confirm upload
                            </button>
                        </footer>
                    </div>
                </div>
            ) : null}
        </div>
    );
}
