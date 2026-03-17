import React, { useEffect, useMemo, useState } from 'react';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { useNavigate, useSearchParams } from 'react-router-dom';
import api from '../services/api';
import DataTable from '../components/DataTable';
import FilterSelect from '../components/FilterSelect';
import StatusBadge from '../components/StatusBadge';
import MetricCard from '../components/MetricCard';
import PageHeader from '../components/PageHeader';
import ConfirmDialog from '../components/ConfirmDialog';
import CredentialDispatchDrawer from '../components/CredentialDispatchDrawer';
import { useToast } from '../components/ToastProvider';
import { platformOptionsWithFlags } from '../utils/flags';
import { normalizePhone } from '../utils/phone';
import { useAuth } from '../hooks/useAuth';

const CSV_ERROR_PREVIEW_LIMIT = 8;
const DASHBOARD_MARKET_STORAGE_KEY = 'exoticcrm.dashboard.market_filter';

function percentage(part, total) {
    if (!total) return 0;
    return Math.round((Number(part || 0) / Number(total)) * 100);
}

function normalizePlatformFilter(value) {
    const raw = String(value ?? '').trim();
    if (raw === '') {
        return '';
    }

    return /^\d+$/.test(raw) ? raw : '';
}

export default function Clients() {
    const allowedStatuses = new Set(['publish', 'private', 'draft', 'pending']);
    const allowedPlans = new Set(['premium', 'featured', 'basic']);
    const allowedVerifiedFilters = new Set(['1', '0']);
    const allowedOnlineFilters = new Set(['5', '15', '30', '60', '360', '1440', '10080']);
    const navigate = useNavigate();
    const queryClient = useQueryClient();
    const toast = useToast();
    const { user } = useAuth();
    const isReadOnly = user?.role === 'marketing';
    const [searchParams] = useSearchParams();

    const [page, setPage] = useState(1);
    const [perPage, setPerPage] = useState(50);
    const [search, setSearch] = useState('');
    const [searchInput, setSearchInput] = useState('');
    const [statusFilter, setStatusFilter] = useState(() => {
        const requested = (searchParams.get('status') || '').trim();
        return allowedStatuses.has(requested) ? requested : '';
    });
    const [planFilter, setPlanFilter] = useState(() => {
        const requested = (searchParams.get('plan') || '').trim();
        return allowedPlans.has(requested) ? requested : '';
    });
    const [verifiedFilter, setVerifiedFilter] = useState(() => {
        const requested = (searchParams.get('verified') || '').trim();
        return allowedVerifiedFilters.has(requested) ? requested : '';
    });
    const [onlineFilter, setOnlineFilter] = useState(() => {
        const requested = (searchParams.get('online_within') || '').trim();
        return allowedOnlineFilters.has(requested) ? requested : '';
    });
    const [platformFilter, setPlatformFilter] = useState(() => {
        const requested = normalizePlatformFilter(searchParams.get('platform_id'));
        if (requested) {
            return requested;
        }

        if (typeof window === 'undefined') {
            return '';
        }

        return normalizePlatformFilter(window.localStorage.getItem(DASHBOARD_MARKET_STORAGE_KEY));
    });

    const [showCreateModal, setShowCreateModal] = useState(false);
    const [showCsvModal, setShowCsvModal] = useState(false);
    const [showCsvConfirm, setShowCsvConfirm] = useState(false);
    const [csvResult, setCsvResult] = useState(null);
    const [credentialDrawer, setCredentialDrawer] = useState({
        open: false,
        client: null,
        source: 'clients_page',
    });
    const [createForm, setCreateForm] = useState({
        platform_id: '',
        name: '',
        phone_normalized: '',
        email: '',
        city: '',
        profile_status: 'private',
        assigned_to: '',
        onboarding_mode: 'manual',
        wp_username: '',
        wp_password: '',
    });
    const [csvForm, setCsvForm] = useState({
        platform_id: '',
        has_header: true,
        file: null,
        reason: 'CSV client upload from clients page',
    });

    const { data, isLoading } = useQuery({
        queryKey: ['clients', page, perPage, search, statusFilter, planFilter, verifiedFilter, onlineFilter, platformFilter],
        queryFn: () =>
            api.get('/crm/clients', {
                params: {
                    page,
                    per_page: perPage,
                    ...(search && { search }),
                    ...(statusFilter && { status: statusFilter }),
                    ...(planFilter && { plan: planFilter }),
                    ...(verifiedFilter !== '' && { verified: verifiedFilter }),
                    ...(onlineFilter && { online_within: Number(onlineFilter) }),
                    ...(platformFilter && { platform_id: Number(platformFilter) }),
                },
            }).then((response) => response.data),
    });

    const { data: integrationData } = useQuery({
        queryKey: ['settings-integrations', 'client-create'],
        queryFn: () => api.get('/crm/settings/integrations').then((response) => response.data),
    });

    const platformOptions = integrationData?.platforms || [];
    const preferredPlatformId = platformFilter
        && platformOptions.some((platform) => String(platform.platform_id) === String(platformFilter))
        ? String(platformFilter)
        : (platformOptions.length > 0 ? String(platformOptions[0].platform_id) : '');
    const selectedCreatePlatform = platformOptions.find(
        (platform) => String(platform.platform_id) === String(createForm.platform_id),
    ) || null;
    const createPhonePrefix = selectedCreatePlatform?.phone_prefix || platformOptions[0]?.phone_prefix || '254';

    useEffect(() => {
        if (typeof window === 'undefined') {
            return;
        }

        if (platformFilter) {
            window.localStorage.setItem(DASHBOARD_MARKET_STORAGE_KEY, platformFilter);
            return;
        }

        window.localStorage.removeItem(DASHBOARD_MARKET_STORAGE_KEY);
    }, [platformFilter]);

    useEffect(() => {
        if (!platformFilter || !platformOptions.length) {
            return;
        }

        const platformStillAccessible = platformOptions.some(
            (platform) => String(platform.platform_id) === String(platformFilter),
        );

        if (!platformStillAccessible) {
            setPlatformFilter('');
            setPage(1);
        }
    }, [platformFilter, platformOptions]);

    useEffect(() => {
        if (!showCreateModal) {
            return;
        }

        if (!createForm.platform_id && platformOptions.length > 0) {
            setCreateForm((current) => ({
                ...current,
                platform_id: preferredPlatformId,
            }));
        }
    }, [showCreateModal, platformOptions, preferredPlatformId, createForm.platform_id]);

    useEffect(() => {
        if (!showCsvModal) {
            return;
        }

        if (!csvForm.platform_id && platformOptions.length > 0) {
            setCsvForm((current) => ({
                ...current,
                platform_id: preferredPlatformId,
            }));
        }
    }, [showCsvModal, platformOptions, preferredPlatformId, csvForm.platform_id]);

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
        onSuccess: (createdClient, variables) => {
            queryClient.invalidateQueries({ queryKey: ['clients'] });
            queryClient.invalidateQueries({ queryKey: ['dashboard'] });
            setShowCreateModal(false);
            setCreateForm({
                platform_id: preferredPlatformId,
                name: '',
                phone_normalized: '',
                email: '',
                city: '',
                profile_status: 'private',
                assigned_to: '',
                onboarding_mode: 'manual',
                wp_username: '',
                wp_password: '',
            });

            const isWpProvision = variables?.onboarding_mode === 'wp_provision';
            if (isWpProvision && createdClient?.id) {
                toast.success('Client provisioned. Dispatch credentials to complete onboarding.');
                setCredentialDrawer({
                    open: true,
                    client: createdClient,
                    source: 'clients_add_modal',
                });
                return;
            }

            toast.success('Client created successfully.');
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
        onSuccess: (result, variables) => {
            queryClient.invalidateQueries({ queryKey: ['clients'] });
            queryClient.invalidateQueries({ queryKey: ['dashboard'] });
            setShowCsvModal(false);
            setShowCsvConfirm(false);
            setCsvForm({
                platform_id: preferredPlatformId,
                has_header: true,
                file: null,
                reason: 'CSV client upload from clients page',
            });

            const marketName = platformOptions.find(
                (platform) => Number(platform.platform_id) === Number(variables?.platform_id),
            )?.platform_name || 'Selected market';
            setCsvResult({
                kind: 'clients',
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
            toast.success(`CSV upload completed: ${created} clients created.`);
        },
        onError: (error) => {
            setShowCsvConfirm(false);
            toast.error(error?.response?.data?.message || 'Client CSV upload failed.');
        },
    });

    const handleSearch = (event) => {
        event.preventDefault();
        setSearch(searchInput.trim());
        setPage(1);
    };

    const rows = data?.data || [];
    const selectedCsvPlatformName = platformOptions.find((platform) => String(platform.platform_id) === String(csvForm.platform_id))?.platform_name || 'Selected market';
    const requiresProvisionContact =
        createForm.onboarding_mode === 'wp_provision'
        && !createForm.email.trim()
        && !createForm.phone_normalized.trim();
    const canSubmitCreate =
        Boolean(createForm.platform_id)
        && createForm.name.trim().length > 0
        && !createMutation.isPending
        && !requiresProvisionContact;

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

    const metricShare = useMemo(() => ({
        active: percentage(stats.active, stats.total),
        premium: percentage(stats.premium, stats.total),
        verified: percentage(stats.verified, stats.total),
    }), [stats]);

    const activeMetric = useMemo(() => {
        if (statusFilter === 'publish' && planFilter === '' && verifiedFilter === '' && onlineFilter === '') return 'active';
        if (planFilter === 'premium' && statusFilter === '' && verifiedFilter === '' && onlineFilter === '') return 'premium';
        if (verifiedFilter === '1' && statusFilter === '' && planFilter === '' && onlineFilter === '') return 'verified';
        return '';
    }, [statusFilter, planFilter, verifiedFilter, onlineFilter]);

    const applyMetricFilter = (metricKey) => {
        if (activeMetric === metricKey) {
            setStatusFilter('');
            setPlanFilter('');
            setVerifiedFilter('');
            setOnlineFilter('');
            setPage(1);
            return;
        }

        if (metricKey === 'active') {
            setStatusFilter('publish');
            setPlanFilter('');
            setVerifiedFilter('');
        } else if (metricKey === 'premium') {
            setStatusFilter('');
            setPlanFilter('premium');
            setVerifiedFilter('');
        } else if (metricKey === 'verified') {
            setStatusFilter('');
            setPlanFilter('');
            setVerifiedFilter('1');
        }

        setOnlineFilter('');
        setPage(1);
    };

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
                    <span className={`inline-flex items-center rounded-md px-2.5 py-0.5 text-xs font-medium ring-1 ring-inset ${
                        row.plan_label === 'Premium'
                            ? 'bg-teal-50 text-teal-700 ring-teal-200'
                            : row.plan_label === 'Featured'
                                ? 'bg-amber-50 text-amber-700 ring-amber-200'
                                : 'bg-slate-100 text-slate-600 ring-slate-200'
                    }`}>
                        {row.plan_label || 'Basic'}
                    </span>
                    {row.verified ? (
                        <span className="inline-flex items-center rounded-md bg-emerald-50 px-2.5 py-0.5 text-xs font-medium text-emerald-700 ring-1 ring-inset ring-emerald-200">
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
                subtitle={stats.total
                    ? `${stats.total.toLocaleString()} clients in scope • ${stats.active.toLocaleString()} active • ${stats.verified.toLocaleString()} verified`
                    : 'Manage client records and subscription status.'}
                actions={!isReadOnly ? (
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
                ) : null}
            />

            <section className="grid gap-4 md:grid-cols-3">
                <MetricCard
                    label="Active Clients"
                    value={stats.active.toLocaleString()}
                    meta={`${metricShare.active}% of current scope in publish status`}
                    tone="success"
                    onClick={() => applyMetricFilter('active')}
                    active={activeMetric === 'active'}
                />
                <MetricCard
                    label="Premium Profiles"
                    value={stats.premium.toLocaleString()}
                    meta={`${metricShare.premium}% of current scope on premium plan`}
                    tone="accent"
                    onClick={() => applyMetricFilter('premium')}
                    active={activeMetric === 'premium'}
                />
                <MetricCard
                    label="Verified Profiles"
                    value={stats.verified.toLocaleString()}
                    meta={`${metricShare.verified}% of current scope identity verified`}
                    tone="default"
                    onClick={() => applyMetricFilter('verified')}
                    active={activeMetric === 'verified'}
                />
            </section>

            <p className="px-1 text-xs text-slate-500">Click a metric card to segment the table. Click the same card again to clear.</p>

            <section className="crm-filter-row">
                <div className="flex flex-wrap items-end gap-3">
                    <form onSubmit={handleSearch} className="min-w-[220px] flex-1">
                        <div className="flex flex-col gap-1">
                            <span className="text-[10px] font-semibold uppercase tracking-[0.1em] text-slate-400">Search</span>
                            <div className="relative">
                                <input
                                    type="text"
                                    value={searchInput}
                                    onChange={(event) => setSearchInput(event.target.value)}
                                    placeholder="Name, phone, email, or ID..."
                                    className="crm-input pr-10"
                                />
                                <button type="submit" aria-label="Run client search" className="absolute right-2 top-1/2 -translate-y-1/2 rounded p-1 text-slate-400 transition hover:text-slate-600">
                                    <svg className="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
                                        <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z" />
                                    </svg>
                                </button>
                            </div>
                        </div>
                    </form>

                    <FilterSelect
                        label="Market"
                        value={platformFilter}
                        onChange={(event) => { setPlatformFilter(event.target.value); setPage(1); }}
                        options={platformOptionsWithFlags(platformOptions)}
                    />

                    <FilterSelect
                        label="Status"
                        value={statusFilter}
                        onChange={(event) => { setStatusFilter(event.target.value); setPage(1); }}
                        options={[
                            { value: '', label: 'All statuses' },
                            { value: 'publish', label: 'Active' },
                            { value: 'private', label: 'Inactive' },
                            { value: 'draft', label: 'Draft' },
                            { value: 'pending', label: 'Pending' },
                        ]}
                    />

                    <FilterSelect
                        label="Plan"
                        value={planFilter}
                        onChange={(event) => { setPlanFilter(event.target.value); setPage(1); }}
                        options={[
                            { value: '', label: 'All plans' },
                            { value: 'premium', label: 'Premium' },
                            { value: 'featured', label: 'Featured' },
                            { value: 'basic', label: 'Basic' },
                        ]}
                    />

                    <FilterSelect
                        label="Verified"
                        value={verifiedFilter}
                        onChange={(event) => { setVerifiedFilter(event.target.value); setPage(1); }}
                        options={[
                            { value: '', label: 'All' },
                            { value: '1', label: 'Verified only' },
                            { value: '0', label: 'Not verified' },
                        ]}
                    />

                    <FilterSelect
                        label="Online"
                        value={onlineFilter}
                        onChange={(event) => { setOnlineFilter(event.target.value); setPage(1); }}
                        options={[
                            { value: '', label: 'Any time' },
                            { value: '5', label: 'Last 5 min' },
                            { value: '15', label: 'Last 15 min' },
                            { value: '30', label: 'Last 30 min' },
                            { value: '60', label: 'Last 1 hour' },
                            { value: '360', label: 'Last 6 hours' },
                            { value: '1440', label: 'Last 24 hours' },
                            { value: '10080', label: 'Last 7 days' },
                        ]}
                    />

                    {(search || statusFilter || planFilter || verifiedFilter !== '' || onlineFilter || platformFilter) ? (
                        <button
                            type="button"
                            onClick={() => {
                                setSearch('');
                                setSearchInput('');
                                setStatusFilter('');
                                setPlanFilter('');
                                setVerifiedFilter('');
                                setOnlineFilter('');
                                setPlatformFilter('');
                                setPage(1);
                            }}
                            className="mb-0.5 rounded-lg px-3 py-2 text-xs font-semibold text-slate-500 transition hover:bg-slate-100 hover:text-slate-700"
                        >
                            Reset all
                        </button>
                    ) : null}
                </div>
            </section>

            {csvResult ? (
                <section className={`rounded-lg border px-4 py-3 ${
                    Number(csvResult?.totals?.failed || 0) > 0
                        ? 'border-amber-200 bg-amber-50/70'
                        : 'border-emerald-200 bg-emerald-50/70'
                }`}>
                    <div className="flex flex-wrap items-start justify-between gap-3">
                        <div>
                            <p className="text-sm font-semibold text-slate-900">Client CSV upload summary</p>
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
                onRowClick={(row) => navigate(`/clients/${row.id}`)}
                isLoading={isLoading}
                emptyMessage="No clients found matching your filters."
                compact
                perPage={perPage}
                onPerPageChange={(n) => { setPerPage(n); setPage(1); }}
            />

            <ConfirmDialog
                open={showCsvConfirm}
                title="Confirm Clients CSV Upload"
                message="This upload creates new client records only. It does not update or delete existing clients."
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

            {showCreateModal ? (
                <div className="fixed inset-0 z-50 flex items-center justify-center bg-slate-900/45 p-4" onClick={() => setShowCreateModal(false)}>
                    <div className="w-full max-w-2xl rounded-lg border border-slate-200 bg-white shadow-xl" onClick={(event) => event.stopPropagation()}>
                        <header className="crm-panel-header">
                            <div>
                                <h3 className="crm-panel-title">Add Client</h3>
                                <p className="crm-panel-subtitle">
                                    {createForm.onboarding_mode === 'wp_provision'
                                        ? 'Provision a real WordPress profile and link it to CRM in one flow.'
                                        : 'Create a manual CRM client record for outreach and deal tracking.'}
                                </p>
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

                            <div className="md:col-span-2">
                                <label className="mb-1 block text-sm font-medium text-slate-700">Onboarding mode</label>
                                <div className="inline-flex rounded-lg border border-slate-200 bg-slate-50 p-1">
                                    <button
                                        type="button"
                                        onClick={() => setCreateForm((current) => ({ ...current, onboarding_mode: 'manual', wp_username: '', wp_password: '' }))}
                                        className={`rounded-md px-3 py-1.5 text-sm font-medium transition ${
                                            createForm.onboarding_mode === 'manual'
                                                ? 'bg-white text-slate-900 shadow-sm'
                                                : 'text-slate-600 hover:text-slate-900'
                                        }`}
                                    >
                                        CRM only
                                    </button>
                                    <button
                                        type="button"
                                        onClick={() => setCreateForm((current) => ({ ...current, onboarding_mode: 'wp_provision' }))}
                                        className={`rounded-md px-3 py-1.5 text-sm font-medium transition ${
                                            createForm.onboarding_mode === 'wp_provision'
                                                ? 'bg-white text-slate-900 shadow-sm'
                                                : 'text-slate-600 hover:text-slate-900'
                                        }`}
                                    >
                                        Provision in WordPress
                                    </button>
                                </div>
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
                                    placeholder={`e.g. ${createPhonePrefix}712345678`}
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

                            {createForm.onboarding_mode === 'wp_provision' ? (
                                <>
                                    <div>
                                        <label htmlFor="client-wp-username" className="mb-1 block text-sm font-medium text-slate-700">WP username (optional)</label>
                                        <input
                                            id="client-wp-username"
                                            type="text"
                                            value={createForm.wp_username}
                                            onChange={(event) => setCreateForm((current) => ({ ...current, wp_username: event.target.value }))}
                                            className="crm-input"
                                            placeholder="Auto-generated if blank"
                                        />
                                    </div>

                                    <div>
                                        <label htmlFor="client-wp-password" className="mb-1 block text-sm font-medium text-slate-700">Temp password (optional)</label>
                                        <input
                                            id="client-wp-password"
                                            type="text"
                                            value={createForm.wp_password}
                                            onChange={(event) => setCreateForm((current) => ({ ...current, wp_password: event.target.value }))}
                                            className="crm-input"
                                            placeholder="Auto-generated if blank"
                                        />
                                    </div>
                                </>
                            ) : null}
                        </div>

                        <div className="border-t border-slate-100 px-4 py-3">
                            {createForm.onboarding_mode === 'wp_provision' ? (
                                <p className="text-xs text-slate-500">
                                    WordPress provisioning creates a real user/profile now. Include either email or phone so credentials can be sent in the next step.
                                </p>
                            ) : (
                                <p className="text-xs text-slate-500">
                                    Manual clients are CRM-managed records for sales operations. WordPress profile linkage can be added later.
                                </p>
                            )}
                            {requiresProvisionContact ? (
                                <p className="mt-2 text-xs font-medium text-amber-700">
                                    Add at least one contact channel (email or phone) to continue with WordPress provisioning.
                                </p>
                            ) : null}
                        </div>

                        <footer className="flex items-center justify-end gap-2 border-t border-slate-100 p-4">
                            <button type="button" className="crm-btn-secondary" onClick={() => setShowCreateModal(false)}>
                                Cancel
                            </button>
                            <button
                                type="button"
                                disabled={!canSubmitCreate}
                                onClick={() => {
                                    createMutation.mutate({
                                        platform_id: Number(createForm.platform_id),
                                        name: createForm.name.trim(),
                                        phone_normalized: normalizePhone(createForm.phone_normalized.trim(), createPhonePrefix),
                                        email: createForm.email.trim() || null,
                                        city: createForm.city.trim() || null,
                                        profile_status: createForm.profile_status,
                                        assigned_to: createForm.assigned_to ? Number(createForm.assigned_to) : null,
                                        onboarding_mode: createForm.onboarding_mode,
                                        wp_username: createForm.onboarding_mode === 'wp_provision' ? (createForm.wp_username.trim() || null) : null,
                                        wp_password: createForm.onboarding_mode === 'wp_provision' ? (createForm.wp_password.trim() || null) : null,
                                        reason: createForm.onboarding_mode === 'wp_provision'
                                            ? 'WordPress-provisioned client create from clients page'
                                            : 'Manual client create from clients page',
                                    });
                                }}
                                className="crm-btn-primary disabled:cursor-not-allowed disabled:opacity-50"
                            >
                                {createMutation.isPending
                                    ? 'Creating...'
                                    : createForm.onboarding_mode === 'wp_provision'
                                        ? 'Provision and create client'
                                        : 'Create client'}
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

            <CredentialDispatchDrawer
                open={credentialDrawer.open}
                client={credentialDrawer.client}
                defaultSource={credentialDrawer.source}
                defaultReason="Client onboarding credential dispatch from add-client flow"
                onClose={() => setCredentialDrawer({
                    open: false,
                    client: null,
                    source: 'clients_page',
                })}
                onSuccess={() => {
                    queryClient.invalidateQueries({ queryKey: ['clients'] });
                }}
            />
        </div>
    );
}
