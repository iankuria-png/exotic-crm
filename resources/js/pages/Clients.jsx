import React, { useEffect, useMemo, useState } from 'react';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { useNavigate } from 'react-router-dom';
import api from '../services/api';
import DataTable from '../components/DataTable';
import StatusBadge from '../components/StatusBadge';
import MetricCard from '../components/MetricCard';
import PageHeader from '../components/PageHeader';
import { useToast } from '../components/ToastProvider';

function normalizePhone(phone) {
    if (!phone) return '';
    const cleaned = String(phone).replace(/[^\d+]/g, '').replace(/^\+/, '');
    if (cleaned.startsWith('0')) return `254${cleaned.slice(1)}`;
    return cleaned;
}

export default function Clients() {
    const navigate = useNavigate();
    const queryClient = useQueryClient();
    const toast = useToast();

    const [page, setPage] = useState(1);
    const [search, setSearch] = useState('');
    const [searchInput, setSearchInput] = useState('');
    const [statusFilter, setStatusFilter] = useState('');
    const [planFilter, setPlanFilter] = useState('');

    const [showCreateModal, setShowCreateModal] = useState(false);
    const [showCsvModal, setShowCsvModal] = useState(false);
    const [createForm, setCreateForm] = useState({
        platform_id: '',
        name: '',
        phone_normalized: '',
        email: '',
        city: '',
        profile_status: 'private',
        assigned_to: '',
    });
    const [csvForm, setCsvForm] = useState({
        platform_id: '',
        has_header: true,
        file: null,
        reason: 'CSV client upload from clients page',
    });

    const { data, isLoading } = useQuery({
        queryKey: ['clients', page, search, statusFilter, planFilter],
        queryFn: () =>
            api.get('/crm/clients', {
                params: {
                    page,
                    per_page: 25,
                    ...(search && { search }),
                    ...(statusFilter && { status: statusFilter }),
                    ...(planFilter && { plan: planFilter }),
                },
            }).then((response) => response.data),
    });

    const { data: integrationData } = useQuery({
        queryKey: ['settings-integrations', 'client-create'],
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

    const { data: ownersData, isLoading: ownersLoading } = useQuery({
        queryKey: ['settings-owners', 'client-create', createForm.platform_id],
        queryFn: () =>
            api.get('/crm/settings/owners', {
                params: { platform_id: Number(createForm.platform_id) },
            }).then((response) => response.data),
        enabled: showCreateModal && !!createForm.platform_id,
    });

    const createMutation = useMutation({
        mutationFn: (payload) => api.post('/crm/clients', payload).then((response) => response.data),
        onSuccess: () => {
            queryClient.invalidateQueries({ queryKey: ['clients'] });
            queryClient.invalidateQueries({ queryKey: ['dashboard'] });
            toast.success('Client created successfully.');
            setShowCreateModal(false);
            setCreateForm({
                platform_id: platformOptions.length > 0 ? String(platformOptions[0].platform_id) : '',
                name: '',
                phone_normalized: '',
                email: '',
                city: '',
                profile_status: 'private',
                assigned_to: '',
            });
        },
        onError: (error) => {
            toast.error(error?.response?.data?.message || 'Client creation failed. Please review the form and try again.');
        },
    });

    const uploadCsvMutation = useMutation({
        mutationFn: (payload) => {
            const formData = new FormData();
            formData.append('platform_id', String(payload.platform_id));
            formData.append('has_header', payload.has_header ? '1' : '0');
            formData.append('reason', payload.reason);
            formData.append('file', payload.file);

            return api.post('/crm/clients/upload-csv', formData, {
                headers: {
                    'Content-Type': 'multipart/form-data',
                },
            }).then((response) => response.data);
        },
        onSuccess: (result) => {
            queryClient.invalidateQueries({ queryKey: ['clients'] });
            queryClient.invalidateQueries({ queryKey: ['dashboard'] });
            setShowCsvModal(false);
            setCsvForm({
                platform_id: platformOptions.length > 0 ? String(platformOptions[0].platform_id) : '',
                has_header: true,
                file: null,
                reason: 'CSV client upload from clients page',
            });

            const created = Number(result?.totals?.created || 0);
            const failed = Number(result?.totals?.failed || 0);
            if (failed > 0) {
                toast.warning(`CSV upload completed: ${created} created, ${failed} failed.`);
                return;
            }
            toast.success(`CSV upload completed: ${created} clients created.`);
        },
        onError: (error) => {
            toast.error(error?.response?.data?.message || 'Client CSV upload failed.');
        },
    });

    const handleSearch = (event) => {
        event.preventDefault();
        setSearch(searchInput.trim());
        setPage(1);
    };

    const rows = data?.data || [];

    const stats = useMemo(() => {
        if (data?.stats) {
            return {
                active: Number(data.stats.active || 0),
                premium: Number(data.stats.premium || 0),
                verified: Number(data.stats.verified || 0),
                total: Number(data.stats.total || 0),
            };
        }

        return {
            active: rows.filter((row) => row.profile_status === 'publish').length,
            premium: rows.filter((row) => row.premium).length,
            verified: rows.filter((row) => row.verified).length,
            total: Number(data?.total || rows.length),
        };
    }, [data?.stats, data?.total, rows]);

    const columns = [
        {
            key: 'name',
            label: 'Client',
            render: (row) => (
                <div className="flex items-center gap-3">
                    {row.main_image_url ? (
                        <img src={row.main_image_url} alt="" className="h-9 w-9 rounded-full object-cover ring-1 ring-slate-200" />
                    ) : (
                        <div className="flex h-9 w-9 items-center justify-center rounded-full bg-slate-100 text-xs font-semibold text-slate-600 ring-1 ring-slate-200">
                            {row.name?.charAt(0) || '?'}
                        </div>
                    )}
                    <div className="min-w-0">
                        <p className="truncate text-sm font-semibold text-slate-900">{row.name || 'Unnamed'}</p>
                        <p className="truncate text-xs text-slate-500">{row.city || 'City not set'}</p>
                    </div>
                </div>
            ),
        },
        {
            key: 'identifiers',
            label: 'IDs',
            render: (row) => (
                <div>
                    <p className="crm-mono text-xs text-slate-700">CRM #{row.id}</p>
                    <p className="crm-mono text-[11px] text-slate-500">WP User: {row.wp_user_id || '—'}</p>
                </div>
            ),
        },
        {
            key: 'phone_normalized',
            label: 'Phone',
            render: (row) => <span className="crm-mono text-xs text-slate-600">{row.phone_normalized || '—'}</span>,
        },
        {
            key: 'profile_status',
            label: 'Status',
            render: (row) => <StatusBadge status={row.profile_status} />,
        },
        {
            key: 'plan',
            label: 'Plan',
            render: (row) => (
                <div className="flex items-center gap-1.5">
                    <span className={`inline-flex items-center rounded-full px-2.5 py-0.5 text-xs font-medium ring-1 ring-inset ${
                        row.plan_label === 'Premium'
                            ? 'bg-teal-50 text-teal-700 ring-teal-200'
                            : row.plan_label === 'Featured'
                                ? 'bg-amber-50 text-amber-700 ring-amber-200'
                                : 'bg-slate-100 text-slate-600 ring-slate-200'
                    }`}>
                        {row.plan_label || 'Basic'}
                    </span>
                    {row.verified ? (
                        <span className="inline-flex items-center rounded-full bg-emerald-50 px-2.5 py-0.5 text-xs font-medium text-emerald-700 ring-1 ring-inset ring-emerald-200">
                            Verified
                        </span>
                    ) : null}
                </div>
            ),
        },
        {
            key: 'wp_profile_url',
            label: 'Profile URL',
            render: (row) => (
                row.wp_profile_url ? (
                    <a
                        href={row.wp_profile_url}
                        target="_blank"
                        rel="noreferrer"
                        onClick={(event) => event.stopPropagation()}
                        className="text-xs font-medium text-teal-700 underline decoration-teal-200 underline-offset-2 transition hover:text-teal-800"
                    >
                        Open profile
                    </a>
                ) : (
                    <span className="text-xs text-slate-400">Not available</span>
                )
            ),
        },
        {
            key: 'platform',
            label: 'Market',
            render: (row) => <span className="text-xs text-slate-500">{row.platform?.name || '—'}</span>,
        },
    ];

    const owners = ownersData?.owners || [];

    return (
        <div className="space-y-4">
            <PageHeader
                title="Clients"
                subtitle={stats.total ? `${stats.total.toLocaleString()} client records in this scope` : 'Manage client records and subscription status.'}
                actions={(
                    <>
                        <button
                            type="button"
                            onClick={() => setShowCsvModal(true)}
                            className="crm-btn-secondary"
                        >
                            Upload CSV
                        </button>
                        <button
                            type="button"
                            onClick={() => setShowCreateModal(true)}
                            className="crm-btn-primary"
                        >
                            Add client
                        </button>
                    </>
                )}
            />

            <section className="grid gap-4 md:grid-cols-3">
                <MetricCard label="Active Clients" value={stats.active.toLocaleString()} meta="full filtered dataset" tone="success" />
                <MetricCard label="Premium Profiles" value={stats.premium.toLocaleString()} meta="full filtered dataset" tone="accent" />
                <MetricCard label="Verified Profiles" value={stats.verified.toLocaleString()} meta="full filtered dataset" tone="default" />
            </section>

            <section className="crm-filter-row">
                <div className="flex flex-wrap items-center gap-3">
                    <form onSubmit={handleSearch} className="min-w-[240px] flex-1">
                        <div className="relative">
                            <input
                                type="text"
                                value={searchInput}
                                onChange={(event) => setSearchInput(event.target.value)}
                                placeholder="Search by name, phone, or email..."
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
                        <option value="">All statuses</option>
                        <option value="publish">Active</option>
                        <option value="private">Inactive</option>
                        <option value="draft">Draft</option>
                        <option value="pending">Pending</option>
                    </select>

                    <select
                        value={planFilter}
                        onChange={(event) => {
                            setPlanFilter(event.target.value);
                            setPage(1);
                        }}
                        className="crm-select"
                    >
                        <option value="">All plans</option>
                        <option value="premium">Premium</option>
                        <option value="featured">Featured</option>
                        <option value="basic">Basic</option>
                    </select>

                    {(search || statusFilter || planFilter) ? (
                        <button
                            type="button"
                            onClick={() => {
                                setSearch('');
                                setSearchInput('');
                                setStatusFilter('');
                                setPlanFilter('');
                                setPage(1);
                            }}
                            className="crm-btn-secondary px-3 py-2"
                        >
                            Reset
                        </button>
                    ) : null}
                </div>
                <p className="mt-2 text-xs text-slate-500">Plan labels: Basic = standard listing, Featured = promoted visibility, Premium = top-tier placement, Verified = identity badge.</p>
            </section>

            <DataTable
                columns={columns}
                data={data?.data}
                pagination={data}
                onPageChange={setPage}
                onRowClick={(row) => navigate(`/clients/${row.id}`)}
                isLoading={isLoading}
                emptyMessage="No clients found matching your filters."
                compact
            />

            {showCreateModal ? (
                <div className="fixed inset-0 z-50 flex items-center justify-center bg-slate-900/45 p-4" onClick={() => setShowCreateModal(false)}>
                    <div className="w-full max-w-2xl rounded-lg border border-slate-200 bg-white shadow-xl" onClick={(event) => event.stopPropagation()}>
                        <header className="crm-panel-header">
                            <div>
                                <h3 className="crm-panel-title">Add Client</h3>
                                <p className="crm-panel-subtitle">Create a manual CRM client record for outreach and deal tracking.</p>
                            </div>
                        </header>

                        <div className="grid gap-3 p-4 md:grid-cols-2">
                            <div className="md:col-span-2">
                                <label htmlFor="client-market" className="mb-1 block text-sm font-medium text-slate-700">Market</label>
                                <select
                                    id="client-market"
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
                                <label htmlFor="client-name" className="mb-1 block text-sm font-medium text-slate-700">Client name</label>
                                <input
                                    id="client-name"
                                    type="text"
                                    value={createForm.name}
                                    onChange={(event) => setCreateForm((current) => ({ ...current, name: event.target.value }))}
                                    className="crm-input"
                                    placeholder="Enter client name"
                                />
                            </div>

                            <div>
                                <label htmlFor="client-phone" className="mb-1 block text-sm font-medium text-slate-700">Phone</label>
                                <input
                                    id="client-phone"
                                    type="text"
                                    value={createForm.phone_normalized}
                                    onChange={(event) => setCreateForm((current) => ({ ...current, phone_normalized: event.target.value }))}
                                    className="crm-input"
                                    placeholder="e.g. 254712345678"
                                />
                            </div>

                            <div>
                                <label htmlFor="client-email" className="mb-1 block text-sm font-medium text-slate-700">Email</label>
                                <input
                                    id="client-email"
                                    type="email"
                                    value={createForm.email}
                                    onChange={(event) => setCreateForm((current) => ({ ...current, email: event.target.value }))}
                                    className="crm-input"
                                    placeholder="name@example.com"
                                />
                            </div>

                            <div>
                                <label htmlFor="client-city" className="mb-1 block text-sm font-medium text-slate-700">City</label>
                                <input
                                    id="client-city"
                                    type="text"
                                    value={createForm.city}
                                    onChange={(event) => setCreateForm((current) => ({ ...current, city: event.target.value }))}
                                    className="crm-input"
                                    placeholder="Nairobi"
                                />
                            </div>

                            <div>
                                <label htmlFor="client-status" className="mb-1 block text-sm font-medium text-slate-700">Status</label>
                                <select
                                    id="client-status"
                                    value={createForm.profile_status}
                                    onChange={(event) => setCreateForm((current) => ({ ...current, profile_status: event.target.value }))}
                                    className="crm-select w-full"
                                >
                                    <option value="private">Inactive</option>
                                    <option value="publish">Active</option>
                                    <option value="draft">Draft</option>
                                    <option value="pending">Pending</option>
                                </select>
                            </div>

                            <div>
                                <label htmlFor="client-owner" className="mb-1 block text-sm font-medium text-slate-700">Owner</label>
                                <select
                                    id="client-owner"
                                    value={createForm.assigned_to}
                                    onChange={(event) => setCreateForm((current) => ({ ...current, assigned_to: event.target.value }))}
                                    className="crm-select w-full"
                                    disabled={!createForm.platform_id || ownersLoading}
                                >
                                    <option value="">{ownersLoading ? 'Loading owners...' : 'Auto-assign owner'}</option>
                                    {owners.map((owner) => (
                                        <option key={owner.id} value={owner.id}>
                                            {owner.name} ({owner.role})
                                        </option>
                                    ))}
                                </select>
                            </div>
                        </div>

                        <div className="border-t border-slate-100 px-4 py-3">
                            <p className="text-xs text-slate-500">
                                Manual clients are CRM-managed records for sales operations. “Sync from WP” is only available after a valid WordPress profile is linked.
                            </p>
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
                                        city: createForm.city.trim() || null,
                                        profile_status: createForm.profile_status,
                                        assigned_to: createForm.assigned_to ? Number(createForm.assigned_to) : null,
                                        reason: 'Manual client create from clients page',
                                    });
                                }}
                                className="crm-btn-primary disabled:cursor-not-allowed disabled:opacity-50"
                            >
                                {createMutation.isPending ? 'Creating...' : 'Create client'}
                            </button>
                        </footer>
                    </div>
                </div>
            ) : null}

            {showCsvModal ? (
                <div className="fixed inset-0 z-50 flex items-center justify-center bg-slate-900/45 p-4" onClick={() => setShowCsvModal(false)}>
                    <div className="w-full max-w-xl rounded-lg border border-slate-200 bg-white shadow-xl" onClick={(event) => event.stopPropagation()}>
                        <header className="crm-panel-header">
                            <div>
                                <h3 className="crm-panel-title">Upload Clients CSV</h3>
                                <p className="crm-panel-subtitle">Bulk-create client records from CSV for one market at a time.</p>
                            </div>
                        </header>

                        <div className="space-y-3 p-4">
                            <div>
                                <label htmlFor="clients-csv-market" className="mb-1 block text-sm font-medium text-slate-700">Market</label>
                                <select
                                    id="clients-csv-market"
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
                                <label htmlFor="clients-csv-file" className="mb-1 block text-sm font-medium text-slate-700">CSV file</label>
                                <input
                                    id="clients-csv-file"
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
                                <label htmlFor="clients-csv-reason" className="mb-1 block text-sm font-medium text-slate-700">Reason</label>
                                <textarea
                                    id="clients-csv-reason"
                                    rows={3}
                                    value={csvForm.reason}
                                    onChange={(event) => setCsvForm((current) => ({ ...current, reason: event.target.value }))}
                                    className="crm-input"
                                />
                            </div>

                            <p className="rounded-md border border-slate-200 bg-slate-50 px-3 py-2 text-xs text-slate-600">
                                Expected columns: <span className="crm-mono">name, phone, email, city, status, assigned_to, wp_user_id</span>.
                            </p>
                        </div>

                        <footer className="flex items-center justify-end gap-2 border-t border-slate-100 p-4">
                            <button type="button" className="crm-btn-secondary" onClick={() => setShowCsvModal(false)}>
                                Cancel
                            </button>
                            <button
                                type="button"
                                disabled={!csvForm.platform_id || !csvForm.file || !csvForm.reason.trim() || uploadCsvMutation.isPending}
                                onClick={() => uploadCsvMutation.mutate({
                                    platform_id: Number(csvForm.platform_id),
                                    has_header: csvForm.has_header,
                                    file: csvForm.file,
                                    reason: csvForm.reason.trim(),
                                })}
                                className="crm-btn-primary disabled:cursor-not-allowed disabled:opacity-50"
                            >
                                {uploadCsvMutation.isPending ? 'Uploading...' : 'Upload CSV'}
                            </button>
                        </footer>
                    </div>
                </div>
            ) : null}
        </div>
    );
}
