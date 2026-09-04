import React, { useMemo, useState } from 'react';
import { Link } from 'react-router-dom';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import api from '../services/api';
import DataTable from '../components/DataTable';
import PageHeader from '../components/PageHeader';
import StatusBadge from '../components/StatusBadge';
import ConfirmDialog from '../components/ConfirmDialog';
import PbnSeedWizard from '../components/settings/PbnSeedWizard';
import { useAuth } from '../hooks/useAuth';
import { useToast } from '../components/ToastProvider';
import exportRowsToCsv from '../utils/csvExport';

const tabDefs = [
    { id: 'overview', label: 'Overview' },
    { id: 'seed', label: 'Seed' },
    { id: 'batches', label: 'Batches' },
    { id: 'items', label: 'Items' },
    { id: 'observability', label: 'Observability' },
];

const batchStatuses = ['all', 'queued', 'running', 'completed', 'partial', 'failed', 'reverted', 'cancelled'];
const itemStatuses = ['all', 'queued', 'provisioning', 'created', 'media_pending', 'failed', 'cancelled', 'reverted', 'skipped_duplicate'];

const badgeTone = {
    featured: 'border-amber-200 bg-amber-50 text-amber-800',
    premium: 'border-violet-200 bg-violet-50 text-violet-800',
    basic: 'border-slate-200 bg-slate-50 text-slate-600',
};

const badgeLabel = { featured: 'VIP', premium: 'Premium', basic: 'Basic' };

// What actually happened to the bio, as opposed to what was asked for. A batch
// configured to rewrite that quietly fell back everywhere is the failure worth
// spotting at a glance.
function bioOutcome(policy) {
    if (!policy) return { label: '—', tone: 'text-slate-400', hint: null };
    if (policy.bio_mode !== 'rewrite') return { label: 'Verbatim', tone: 'text-slate-500', hint: 'Copied from the source profile.' };

    if (policy.bio_result === 'rewritten') {
        return { label: 'Rewritten', tone: 'text-teal-700', hint: policy.bio_provider ? `via ${policy.bio_provider}` : null };
    }
    if (policy.bio_result === 'template') {
        return { label: 'Template', tone: 'text-sky-700', hint: policy.bio_note };
    }
    if (policy.bio_result === 'fallback') {
        return { label: 'Fell back', tone: 'text-amber-700', hint: policy.bio_note };
    }
    if (policy.bio_result === 'skipped') {
        return { label: 'Skipped', tone: 'text-slate-500', hint: 'The source profile had no bio to rewrite.' };
    }

    return { label: 'Pending', tone: 'text-slate-400', hint: 'Not provisioned yet.' };
}

function imageOutcome(policy) {
    if (!policy) return { label: '—', tone: 'text-slate-400' };
    if (policy.main_image_mode !== 'rotate') return { label: 'Source photo', tone: 'text-slate-500' };
    if (policy.main_image_rotated) return { label: `Rotated · #${policy.main_image_index + 1}`, tone: 'text-teal-700' };

    return { label: 'Not rotated', tone: 'text-slate-500' };
}

const itemPolicyFilters = [
    { id: 'all', label: 'All' },
    { id: 'featured', label: 'VIP', match: (item) => item.policy?.badge === 'featured' },
    { id: 'premium', label: 'Premium', match: (item) => item.policy?.badge === 'premium' },
    { id: 'basic', label: 'Basic', match: (item) => (item.policy?.badge || 'basic') === 'basic' },
    { id: 'bio_template', label: 'Bio from template', match: (item) => item.policy?.bio_result === 'template' },
    { id: 'bio_fallback', label: 'Bio fell back', match: (item) => item.policy?.bio_result === 'fallback' },
    { id: 'not_rotated', label: 'Photo not rotated', match: (item) => item.policy?.main_image_mode === 'rotate' && !item.policy?.main_image_rotated },
    { id: 'awaiting', label: 'Awaiting release', match: (item) => item.policy?.awaiting_release },
];

function formatNumber(value) {
    return Number(value || 0).toLocaleString();
}

function formatDateTime(value) {
    if (!value) return 'Never';
    const date = new Date(value);
    if (Number.isNaN(date.getTime())) return 'Never';
    return date.toLocaleString();
}

function statusLabel(status) {
    return String(status || 'unknown').replaceAll('_', ' ');
}

function eventTone(level) {
    if (level === 'error') return 'failed';
    if (level === 'warning') return 'partial';
    return 'completed';
}

function apiErrorMessage(error, fallback) {
    const errors = error?.response?.data?.errors;
    const firstValidationMessage = errors ? Object.values(errors).flat().find(Boolean) : null;

    return firstValidationMessage || error?.response?.data?.message || fallback;
}

function readyTone(site) {
    if (!site?.is_active) return 'draft';
    if (site.status === 'ready') return 'completed';
    if (site.status === 'warning') return 'partial';
    return 'failed';
}

function StatTile({ label, value, accent = 'slate', detail }) {
    const accentClass = {
        teal: 'border-teal-200 bg-teal-50 text-teal-800',
        emerald: 'border-emerald-200 bg-emerald-50 text-emerald-800',
        amber: 'border-amber-200 bg-amber-50 text-amber-800',
        rose: 'border-rose-200 bg-rose-50 text-rose-800',
        sky: 'border-sky-200 bg-sky-50 text-sky-800',
        slate: 'border-slate-200 bg-white text-slate-900',
    }[accent] || 'border-slate-200 bg-white text-slate-900';

    return (
        <div className={`rounded-lg border px-4 py-3 ${accentClass}`}>
            <p className="text-[11px] font-semibold uppercase tracking-[0.12em] opacity-75">{label}</p>
            <p className="mt-1 text-2xl font-semibold">{formatNumber(value)}</p>
            {detail ? <p className="mt-1 text-xs opacity-75">{detail}</p> : null}
        </div>
    );
}

function SiteSelector({ sites, selectedSiteId, onSelect }) {
    return (
        <div className="crm-surface overflow-hidden">
            <div className="border-b border-slate-100 px-4 py-3">
                <p className="text-sm font-semibold text-slate-900">PBN Sites</p>
            </div>
            <div className="hidden max-h-[68vh] space-y-2 overflow-y-auto p-3 lg:block">
                {sites.map((site) => (
                    <button
                        key={site.id}
                        type="button"
                        onClick={() => onSelect(site.id)}
                        className={`w-full rounded-lg border px-3 py-3 text-left transition ${Number(selectedSiteId) === Number(site.id) ? 'border-teal-300 bg-teal-50 shadow-sm' : 'border-slate-200 bg-white hover:border-slate-300 hover:bg-slate-50'}`}
                    >
                        <div className="flex items-start justify-between gap-2">
                            <div className="min-w-0">
                                <p className="truncate text-sm font-semibold text-slate-900">{site.name}</p>
                                <p className="truncate text-xs text-slate-500">{site.domain}</p>
                            </div>
                            <StatusBadge status={readyTone(site)} label={statusLabel(site.status || 'draft')} />
                        </div>
                        <p className="mt-2 text-xs text-slate-500">{(site.source_platform_ids || []).length} sources • {site.latest_seed ? statusLabel(site.latest_seed.status) : 'No seed yet'}</p>
                    </button>
                ))}
                {sites.length === 0 ? (
                    <div className="rounded-lg border border-dashed border-slate-200 bg-slate-50 px-3 py-8 text-center text-sm text-slate-500">
                        No PBN sites configured.
                    </div>
                ) : null}
            </div>
            <div className="p-3 lg:hidden">
                <select value={selectedSiteId || ''} onChange={(event) => onSelect(event.target.value)} className="crm-select w-full">
                    {sites.map((site) => <option key={site.id} value={site.id}>{site.name} · {statusLabel(site.status)}</option>)}
                </select>
            </div>
        </div>
    );
}

function EmptyState({ title, body, action }) {
    return (
        <section className="crm-surface flex flex-col items-center justify-center px-5 py-12 text-center">
            <div className="flex h-12 w-12 items-center justify-center rounded-xl bg-slate-100 text-slate-500">
                <svg className="h-6 w-6" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
                    <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M4.5 6.75h15M4.5 12h15M4.5 17.25h15" />
                </svg>
            </div>
            <h3 className="mt-3 text-base font-semibold text-slate-900">{title}</h3>
            <p className="mt-1 max-w-md text-sm text-slate-500">{body}</p>
            {action ? <div className="mt-4">{action}</div> : null}
        </section>
    );
}

function FilterSelect({ value, onChange, options, label }) {
    return (
        <label className="flex min-w-[150px] flex-col gap-1 text-xs font-semibold uppercase tracking-[0.1em] text-slate-500">
            {label}
            <select value={value} onChange={(event) => onChange(event.target.value)} className="crm-select text-sm font-normal normal-case tracking-normal text-slate-800">
                {options.map((option) => <option key={option} value={option}>{statusLabel(option)}</option>)}
            </select>
        </label>
    );
}

function SeedItemActionDialog({ item, onClose }) {
    if (!item) {
        return null;
    }

    const sourceUrl = item.source_client?.profile_url || null;
    const targetUrl = item.target_profile_url || null;

    return (
        <div className="fixed inset-0 z-[60] flex items-center justify-center bg-slate-950/45 p-4" role="dialog" aria-modal="true">
            <div className="w-full max-w-md rounded-lg border border-slate-200 bg-white shadow-2xl">
                <div className="border-b border-slate-100 px-4 py-3">
                    <div className="flex items-start justify-between gap-3">
                        <div className="min-w-0">
                            <p className="truncate text-base font-semibold text-slate-900">{item.source_client?.name || `Client #${item.source_client_id}`}</p>
                            <p className="mt-1 text-sm text-slate-500">{item.source_platform_name || 'Source market'} · Source WP {item.source_wp_post_id}</p>
                        </div>
                        <button type="button" className="crm-btn-secondary px-3 py-2 text-sm" onClick={onClose}>Close</button>
                    </div>
                </div>
                <div className="space-y-3 p-4">
                    <div className="flex items-center justify-between gap-2 rounded-lg border border-slate-200 bg-slate-50 px-3 py-2">
                        <span className="text-sm text-slate-600">Current status</span>
                        <StatusBadge status={item.status} label={statusLabel(item.status)} />
                    </div>

                    {targetUrl ? (
                        <a href={targetUrl} target="_blank" rel="noreferrer" className="flex min-h-11 items-center justify-between rounded-lg border border-teal-200 bg-teal-50 px-4 py-3 text-sm font-semibold text-teal-800 transition hover:border-teal-300 hover:bg-teal-100">
                            <span>Open PBN profile</span>
                            <span aria-hidden="true">-&gt;</span>
                        </a>
                    ) : (
                        <button type="button" disabled className="flex min-h-11 w-full items-center justify-between rounded-lg border border-slate-200 bg-slate-50 px-4 py-3 text-left text-sm font-semibold text-slate-400">
                            <span>PBN profile not created yet</span>
                            <span aria-hidden="true">-&gt;</span>
                        </button>
                    )}

                    {sourceUrl ? (
                        <a href={sourceUrl} target="_blank" rel="noreferrer" className="flex min-h-11 items-center justify-between rounded-lg border border-slate-200 bg-white px-4 py-3 text-sm font-semibold text-slate-800 transition hover:border-slate-300 hover:bg-slate-50">
                            <span>Open Exotic profile</span>
                            <span aria-hidden="true">-&gt;</span>
                        </a>
                    ) : (
                        <button type="button" disabled className="flex min-h-11 w-full items-center justify-between rounded-lg border border-slate-200 bg-slate-50 px-4 py-3 text-left text-sm font-semibold text-slate-400">
                            <span>Source profile URL unavailable</span>
                            <span aria-hidden="true">-&gt;</span>
                        </button>
                    )}

                    <div className="grid grid-cols-2 gap-2 text-xs text-slate-500">
                        <div className="rounded-lg border border-slate-200 px-3 py-2">
                            <p className="font-semibold uppercase tracking-[0.1em]">PBN WP</p>
                            <p className="mt-1 text-sm font-semibold text-slate-900">{item.target_wp_post_id || 'Pending'}</p>
                        </div>
                        <div className="rounded-lg border border-slate-200 px-3 py-2">
                            <p className="font-semibold uppercase tracking-[0.1em]">Source WP</p>
                            <p className="mt-1 text-sm font-semibold text-slate-900">{item.source_wp_post_id || '-'}</p>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    );
}

export default function Pbn() {
    const { user } = useAuth();
    const role = user?.role || '';
    const toast = useToast();
    const queryClient = useQueryClient();
    const [activeTab, setActiveTab] = useState('overview');
    const [selectedSiteId, setSelectedSiteId] = useState('');
    const [seedOpen, setSeedOpen] = useState(false);
    const [selectedBatch, setSelectedBatch] = useState(null);
    const [selectedItem, setSelectedItem] = useState(null);
    const [revertOpen, setRevertOpen] = useState(false);
    const [revertReason, setRevertReason] = useState('');
    const [cancelOpen, setCancelOpen] = useState(false);
    const [cancelReason, setCancelReason] = useState('');
    const [batchFilters, setBatchFilters] = useState({ page: 1, per_page: 50, status: 'all', q: '' });
    const [itemFilters, setItemFilters] = useState({ page: 1, per_page: 50, status: 'all', q: '' });
    const [eventFilters, setEventFilters] = useState({ page: 1, per_page: 25, level: 'all' });

    const sitesQuery = useQuery({
        queryKey: ['settings-pbn-sites'],
        queryFn: () => api.get('/crm/settings/integrations/pbn-sites').then((response) => response.data),
        staleTime: 30_000,
    });
    const overviewQuery = useQuery({
        queryKey: ['pbn-overview'],
        queryFn: () => api.get('/crm/pbn/overview').then((response) => response.data),
        refetchInterval: 30_000,
    });

    const sites = sitesQuery.data?.sites || [];
    const platforms = sitesQuery.data?.platforms || [];
    const selectedSite = useMemo(() => (
        sites.find((site) => Number(site.id) === Number(selectedSiteId)) || sites[0] || null
    ), [selectedSiteId, sites]);
    const effectiveSiteId = selectedSite?.id || '';
    const readySites = sites.filter((site) => site.is_active && site.status === 'ready');
    const canRevert = ['admin', 'sub_admin'].includes(role);

    const batchQueryParams = {
        ...batchFilters,
        pbn_site_id: activeTab === 'batches' && effectiveSiteId ? effectiveSiteId : undefined,
    };
    const itemQueryParams = {
        ...itemFilters,
        pbn_site_id: activeTab === 'items' && effectiveSiteId ? effectiveSiteId : undefined,
    };
    const eventQueryParams = {
        ...eventFilters,
        pbn_site_id: activeTab === 'observability' && effectiveSiteId ? effectiveSiteId : undefined,
    };

    const batchesQuery = useQuery({
        queryKey: ['pbn-batches', batchQueryParams],
        queryFn: () => api.get('/crm/pbn/batches', { params: batchQueryParams }).then((response) => response.data),
        enabled: ['overview', 'batches'].includes(activeTab),
        refetchInterval: activeTab === 'batches' ? 15_000 : 30_000,
    });
    const itemsQuery = useQuery({
        queryKey: ['pbn-items', itemQueryParams],
        queryFn: () => api.get('/crm/pbn/items', { params: itemQueryParams }).then((response) => response.data),
        enabled: ['overview', 'items'].includes(activeTab),
        refetchInterval: activeTab === 'items' ? 20_000 : 30_000,
    });
    const eventsQuery = useQuery({
        queryKey: ['pbn-events', eventQueryParams],
        queryFn: () => api.get('/crm/pbn/events', { params: eventQueryParams }).then((response) => response.data),
        enabled: activeTab === 'observability',
        refetchInterval: 20_000,
    });
    const [itemPolicyFilter, setItemPolicyFilter] = useState('all');
    const [linkCheckOpen, setLinkCheckOpen] = useState(false);
    const batchDetailQuery = useQuery({
        queryKey: ['pbn-batch-detail', selectedBatch?.id],
        queryFn: () => api.get(`/crm/pbn/batches/${selectedBatch.id}`).then((response) => response.data),
        enabled: Boolean(selectedBatch?.id),
        refetchInterval: selectedBatch && ['queued', 'running'].includes(selectedBatch.status) ? 8000 : false,
    });
    const batchItemsQuery = useQuery({
        queryKey: ['pbn-batch-items', selectedBatch?.id],
        queryFn: () => api.get('/crm/pbn/items', { params: { batch_id: selectedBatch.id, per_page: 100 } }).then((response) => response.data),
        enabled: Boolean(selectedBatch?.id),
        refetchInterval: selectedBatch && ['queued', 'running'].includes(selectedBatch.status) ? 8000 : false,
    });
    // Read-only, and only once the drawer asks for it — the check opens a
    // connection to the destination WordPress database.
    const profileLinksQuery = useQuery({
        queryKey: ['pbn-batch-profile-links', selectedBatch?.id],
        queryFn: () => api.get(`/crm/pbn/batches/${selectedBatch.id}/profile-links`).then((response) => response.data),
        enabled: Boolean(selectedBatch?.id) && linkCheckOpen,
    });
    const repairLinksMutation = useMutation({
        mutationFn: () => api.post(`/crm/pbn/batches/${selectedBatch.id}/profile-links/repair`).then((response) => response.data),
        onSuccess: (data) => {
            toast.success(data?.repaired > 0
                ? `Repaired ${data.repaired} profile${data.repaired === 1 ? '' : 's'}.`
                : 'Nothing needed repairing.');
            queryClient.invalidateQueries({ queryKey: ['pbn-batch-profile-links', selectedBatch?.id] });
        },
        onError: (error) => toast.error(apiErrorMessage(error, 'Could not repair profile links.')),
    });

    const batchEventsQuery = useQuery({
        queryKey: ['pbn-batch-events', selectedBatch?.id],
        queryFn: () => api.get('/crm/pbn/events', { params: { batch_id: selectedBatch.id, per_page: 25 } }).then((response) => response.data),
        enabled: Boolean(selectedBatch?.id),
        refetchInterval: selectedBatch && ['queued', 'running'].includes(selectedBatch.status) ? 8000 : false,
    });

    const invalidateOperations = () => {
        queryClient.invalidateQueries({ queryKey: ['pbn-overview'] });
        queryClient.invalidateQueries({ queryKey: ['pbn-batches'] });
        queryClient.invalidateQueries({ queryKey: ['pbn-items'] });
        queryClient.invalidateQueries({ queryKey: ['pbn-events'] });
        queryClient.invalidateQueries({ queryKey: ['pbn-batch-detail'] });
        queryClient.invalidateQueries({ queryKey: ['pbn-batch-items'] });
        queryClient.invalidateQueries({ queryKey: ['pbn-batch-events'] });
        queryClient.invalidateQueries({ queryKey: ['settings-pbn-sites'] });
    };

    const retryMutation = useMutation({
        mutationFn: (batchId) => api.post(`/crm/pbn/batches/${batchId}/retry`).then((response) => response.data),
        onSuccess: (response) => {
            toast.success(response?.message || 'PBN retry queued.');
            invalidateOperations();
        },
        onError: (error) => toast.error(apiErrorMessage(error, 'Could not retry PBN batch.')),
    });
    const cancelMutation = useMutation({
        mutationFn: () => api.post(`/crm/pbn/batches/${selectedBatch.id}/cancel`, { reason: cancelReason }).then((response) => response.data),
        onSuccess: (response) => {
            toast.success(response?.message || 'PBN seed batch stopped.');
            setCancelOpen(false);
            setCancelReason('');
            setSelectedBatch(response?.batch || selectedBatch);
            invalidateOperations();
        },
        onError: (error) => toast.error(apiErrorMessage(error, 'Could not stop PBN batch.')),
    });
    const mediaMutation = useMutation({
        mutationFn: () => api.post(`/crm/pbn/batches/${selectedBatch.id}/media/retry`, { limit: 5 }).then((response) => response.data),
        onSuccess: (response) => {
            toast.success(response?.message || 'PBN media processing finished.');
            setSelectedBatch(response?.batch || selectedBatch);
            invalidateOperations();
        },
        onError: (error) => toast.error(apiErrorMessage(error, 'Could not process pending PBN media.')),
    });
    const revertMutation = useMutation({
        mutationFn: () => api.post(`/crm/pbn/batches/${selectedBatch.id}/revert`, { reason: revertReason }).then((response) => response.data),
        onSuccess: (response) => {
            toast.success(response?.message || 'PBN batch reverted.');
            setRevertOpen(false);
            setRevertReason('');
            setSelectedBatch(response?.batch || selectedBatch);
            invalidateOperations();
        },
        onError: (error) => toast.error(apiErrorMessage(error, 'Could not revert PBN batch.')),
    });

    const overview = overviewQuery.data || {};
    const batchRows = batchesQuery.data?.data || [];
    const itemRows = itemsQuery.data?.data || [];
    const eventRows = eventsQuery.data?.data || [];
    const batchDetail = batchDetailQuery.data?.batch || selectedBatch;
    const allBatchItems = batchItemsQuery.data?.data || [];
    const activePolicyFilter = itemPolicyFilters.find((filter) => filter.id === itemPolicyFilter) || itemPolicyFilters[0];
    const batchItems = activePolicyFilter.match ? allBatchItems.filter(activePolicyFilter.match) : allBatchItems;
    const batchPolicySummary = batchDetailQuery.data?.summary?.policy || null;
    const profileLinks = profileLinksQuery.data || null;
    const batchEvents = batchEventsQuery.data?.data || [];
    const revertPreview = batchDetailQuery.data?.revert_preview || {};
    const isBatchActive = ['queued', 'running'].includes(batchDetail?.status);
    const mediaSummary = batchDetailQuery.data?.media_summary || {};
    const pendingMediaCount = Number(mediaSummary.pending_count || allBatchItems.filter((item) => item.status === 'media_pending').length || 0);
    const mediaAttentionCount = Number(mediaSummary.attention_count || allBatchItems.filter((item) => item.status === 'media_pending' && item.failure_reason).length || 0);

    const batchColumns = [
        {
            key: 'site',
            label: 'Site',
            cellClassName: 'min-w-[210px] whitespace-normal',
            render: (row) => (
                <div>
                    <p className="font-semibold text-slate-900">{row.site?.name || 'PBN site'}</p>
                    <p className="text-xs text-slate-500">{row.site?.domain || 'Unknown domain'}</p>
                </div>
            ),
        },
        { key: 'status', label: 'Status', render: (row) => <StatusBadge status={row.status} label={statusLabel(row.status)} /> },
        { key: 'created', label: 'Created', render: (row) => `${formatNumber(row.created_count)}/${formatNumber(row.selected_count)}` },
        { key: 'failed', label: 'Failed', render: (row) => formatNumber(row.failed_count) },
        { key: 'reverted', label: 'Reverted', render: (row) => formatNumber(row.reverted_count) },
        { key: 'creator', label: 'Owner', render: (row) => row.creator?.name || 'System' },
        { key: 'created_at', label: 'Queued', render: (row) => formatDateTime(row.created_at) },
        {
            key: 'actions',
            label: '',
            render: (row) => (
                <div className="flex justify-end gap-2">
                    {row.failed_count > 0 ? (
                        <button type="button" className="crm-btn-secondary px-2 py-1 text-xs" onClick={(event) => { event.stopPropagation(); retryMutation.mutate(row.id); }}>
                            Retry
                        </button>
                    ) : null}
                    {['queued', 'running'].includes(row.status) ? (
                        <button type="button" className="crm-btn-danger px-2 py-1 text-xs" onClick={(event) => { event.stopPropagation(); setSelectedBatch(row); setCancelOpen(true); }}>
                            Stop
                        </button>
                    ) : null}
                    {canRevert && row.created_count > 0 ? (
                        <button type="button" className="crm-btn-danger px-2 py-1 text-xs" onClick={(event) => { event.stopPropagation(); setSelectedBatch(row); setRevertOpen(true); }}>
                            Revert
                        </button>
                    ) : null}
                </div>
            ),
        },
    ];

    const itemColumns = [
        {
            key: 'source_client',
            label: 'Profile',
            cellClassName: 'min-w-[240px] whitespace-normal',
            render: (row) => (
                <div className="flex items-center gap-3">
                    {row.source_client?.display_image_url ? (
                        <img src={row.source_client.display_image_url} alt="" className="h-10 w-10 rounded-lg object-cover" />
                    ) : (
                        <span className="flex h-10 w-10 items-center justify-center rounded-lg bg-slate-100 text-xs font-semibold text-slate-500">
                            {(row.source_client?.name || 'P').charAt(0)}
                        </span>
                    )}
                    <div className="min-w-0">
                        <p className="truncate font-semibold text-slate-900">{row.source_client?.name || `Client #${row.source_client_id}`}</p>
                        <p className="truncate text-xs text-slate-500">{row.source_platform_name || 'Source market'} • {row.source_client?.city || 'No city'}</p>
                    </div>
                </div>
            ),
        },
        { key: 'site_name', label: 'PBN Site', render: (row) => row.site_name || 'Unknown' },
        { key: 'status', label: 'Status', render: (row) => <StatusBadge status={row.status} label={statusLabel(row.status)} /> },
        { key: 'source_wp_post_id', label: 'Source WP', render: (row) => row.source_wp_post_id },
        { key: 'target_wp_post_id', label: 'Target WP', render: (row) => row.target_wp_post_id || 'Pending' },
        { key: 'quality_score', label: 'Score', render: (row) => row.quality_score ?? '-' },
        {
            key: 'updated_at',
            label: 'Updated',
            render: (row) => formatDateTime(row.updated_at),
        },
    ];

    return (
        <div className="space-y-4">
            <PageHeader
                title="PBN"
                subtitle="Seed, monitor, search, retry, and revert PBN WordPress profiles."
                actions={(
                    <>
                        <Link to="/settings?integrationArea=pbn" className="crm-btn-secondary px-3 py-2 text-sm">Settings</Link>
                        <button
                            type="button"
                            onClick={() => setSeedOpen(true)}
                            disabled={!selectedSite?.is_active || selectedSite?.status !== 'ready'}
                            className="crm-btn-primary px-3 py-2 text-sm disabled:cursor-not-allowed disabled:opacity-60"
                        >
                            Seed profiles
                        </button>
                    </>
                )}
            />

            <div className="grid gap-4 lg:grid-cols-[280px_minmax(0,1fr)]">
                <SiteSelector sites={sites} selectedSiteId={effectiveSiteId} onSelect={setSelectedSiteId} />

                <main className="min-w-0 space-y-4">
                    <section className="crm-surface overflow-hidden">
                        <div className="flex gap-2 overflow-x-auto border-b border-slate-100 p-3">
                            {tabDefs.map((tab) => (
                                <button
                                    key={tab.id}
                                    type="button"
                                    onClick={() => setActiveTab(tab.id)}
                                    className={`min-h-11 shrink-0 rounded-lg border px-3 py-2 text-sm font-semibold transition ${activeTab === tab.id ? 'border-teal-300 bg-teal-50 text-teal-800' : 'border-slate-200 bg-white text-slate-600 hover:border-slate-300'}`}
                                >
                                    {tab.label}
                                </button>
                            ))}
                        </div>

                        <div className="p-4">
                            {activeTab === 'overview' ? (
                                <div className="space-y-4">
                                    <div className="grid gap-3 sm:grid-cols-2 xl:grid-cols-4">
                                        <StatTile label="Ready Sites" value={overview.sites?.ready} accent="emerald" detail={`${formatNumber(overview.sites?.blocked)} blocked`} />
                                        <StatTile label="Active Batches" value={overview.batches?.active} accent="teal" detail={`${formatNumber(overview.batches?.partial)} partial`} />
                                        <StatTile label="Created Profiles" value={overview.items?.created} accent="sky" detail={`${formatNumber(overview.items?.created_last_7_days)} in 7 days`} />
                                        <StatTile label="Needs Attention" value={(overview.items?.failed || 0) + (overview.items?.media_pending || 0)} accent="amber" detail={`${formatNumber(overview.items?.failed)} failed · ${formatNumber(overview.items?.media_pending)} media pending`} />
                                    </div>

                                    <div className="grid gap-4 xl:grid-cols-2">
                                        <section className="rounded-lg border border-slate-200 bg-white">
                                            <div className="border-b border-slate-100 px-4 py-3">
                                                <p className="text-sm font-semibold text-slate-900">Recent Batches</p>
                                            </div>
                                            <div className="divide-y divide-slate-100">
                                                {(batchesQuery.data?.data || []).slice(0, 5).map((batch) => (
                                                    <button key={batch.id} type="button" onClick={() => setSelectedBatch(batch)} className="flex w-full items-center justify-between gap-3 px-4 py-3 text-left hover:bg-slate-50">
                                                        <div className="min-w-0">
                                                            <p className="truncate text-sm font-semibold text-slate-900">{batch.site?.name || 'PBN site'} · Batch #{batch.id}</p>
                                                            <p className="text-xs text-slate-500">{formatNumber(batch.created_count)}/{formatNumber(batch.selected_count)} created · {formatDateTime(batch.created_at)}</p>
                                                        </div>
                                                        <StatusBadge status={batch.status} label={statusLabel(batch.status)} />
                                                    </button>
                                                ))}
                                                {!batchesQuery.isLoading && (batchesQuery.data?.data || []).length === 0 ? (
                                                    <p className="px-4 py-8 text-center text-sm text-slate-500">No seed batches yet.</p>
                                                ) : null}
                                            </div>
                                        </section>

                                        <section className="rounded-lg border border-slate-200 bg-white">
                                            <div className="border-b border-slate-100 px-4 py-3">
                                                <p className="text-sm font-semibold text-slate-900">Recent Failures</p>
                                            </div>
                                            <div className="divide-y divide-slate-100">
                                                {(overview.recent_failures || []).map((item) => (
                                                    <div key={item.id} className="px-4 py-3">
                                                        <div className="flex items-start justify-between gap-3">
                                                            <div className="min-w-0">
                                                                <p className="truncate text-sm font-semibold text-slate-900">{item.source_client?.name || `Client #${item.source_client_id}`}</p>
                                                                <p className="truncate text-xs text-slate-500">{item.site_name} · Batch #{item.batch_id}</p>
                                                            </div>
                                                            <StatusBadge status="failed" />
                                                        </div>
                                                        <p className="mt-2 line-clamp-2 text-xs text-rose-700">{item.failure_reason || 'No failure reason recorded.'}</p>
                                                    </div>
                                                ))}
                                                {!overviewQuery.isLoading && (overview.recent_failures || []).length === 0 ? (
                                                    <p className="px-4 py-8 text-center text-sm text-slate-500">No recent failed seed items.</p>
                                                ) : null}
                                            </div>
                                        </section>
                                    </div>
                                </div>
                            ) : null}

                            {activeTab === 'seed' ? (
                                <div className="grid gap-4 xl:grid-cols-[minmax(0,1fr)_340px]">
                                    <section className="rounded-lg border border-slate-200 bg-white p-4">
                                        <div className="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
                                            <div>
                                                <h3 className="text-lg font-semibold text-slate-900">Seed Profiles</h3>
                                                <p className="mt-1 text-sm text-slate-500">{selectedSite ? `${selectedSite.name} · ${selectedSite.domain}` : 'Select a configured PBN site.'}</p>
                                            </div>
                                            <button
                                                type="button"
                                                onClick={() => setSeedOpen(true)}
                                                disabled={!selectedSite?.is_active || selectedSite?.status !== 'ready'}
                                                className="crm-btn-primary min-h-11 px-4 py-2 disabled:cursor-not-allowed disabled:opacity-60"
                                            >
                                                Open seed wizard
                                            </button>
                                        </div>
                                        {selectedSite && selectedSite.status !== 'ready' ? (
                                            <p className="mt-4 rounded-lg border border-amber-200 bg-amber-50 px-3 py-2 text-sm text-amber-800">
                                                Readiness is {statusLabel(selectedSite.status)}. Queueing opens after Settings readiness passes.
                                            </p>
                                        ) : null}
                                        {!selectedSite ? (
                                            <EmptyState title="No PBN site selected" body="Create and test a PBN destination before running seed batches." action={<Link to="/settings?integrationArea=pbn" className="crm-btn-secondary px-3 py-2 text-sm">Open settings</Link>} />
                                        ) : null}
                                    </section>

                                    <section className="rounded-lg border border-slate-200 bg-slate-50 p-4">
                                        <p className="text-sm font-semibold text-slate-900">Selected Site</p>
                                        {selectedSite ? (
                                            <div className="mt-3 space-y-3">
                                                <div className="flex items-center justify-between gap-2">
                                                    <span className="text-sm text-slate-600">Readiness</span>
                                                    <StatusBadge status={readyTone(selectedSite)} label={statusLabel(selectedSite.status)} />
                                                </div>
                                                <div className="flex items-center justify-between gap-2">
                                                    <span className="text-sm text-slate-600">Sources</span>
                                                    <span className="text-sm font-semibold text-slate-900">{formatNumber((selectedSite.source_platform_ids || []).length)}</span>
                                                </div>
                                                <div className="flex items-center justify-between gap-2">
                                                    <span className="text-sm text-slate-600">Last seed</span>
                                                    <span className="text-sm font-semibold text-slate-900">{selectedSite.latest_seed ? statusLabel(selectedSite.latest_seed.status) : 'None'}</span>
                                                </div>
                                            </div>
                                        ) : <p className="mt-2 text-sm text-slate-500">No configured site.</p>}
                                    </section>
                                </div>
                            ) : null}

                            {activeTab === 'batches' ? (
                                <div className="space-y-3">
                                    <div className="flex flex-col gap-3 rounded-lg border border-slate-200 bg-white p-3 md:flex-row md:items-end md:justify-between">
                                        <div className="flex flex-1 flex-col gap-3 md:flex-row md:items-end">
                                            <label className="flex flex-1 flex-col gap-1 text-xs font-semibold uppercase tracking-[0.1em] text-slate-500">
                                                Search
                                                <input value={batchFilters.q} onChange={(event) => setBatchFilters((current) => ({ ...current, q: event.target.value, page: 1 }))} className="crm-input text-sm font-normal normal-case tracking-normal" placeholder="Batch notes, site, creator" />
                                            </label>
                                            <FilterSelect value={batchFilters.status} onChange={(status) => setBatchFilters((current) => ({ ...current, status, page: 1 }))} options={batchStatuses} label="Status" />
                                        </div>
                                        <button type="button" className="crm-btn-secondary px-3 py-2 text-sm" onClick={() => batchesQuery.refetch()}>Refresh</button>
                                    </div>
                                    <DataTable
                                        columns={batchColumns}
                                        data={batchRows}
                                        pagination={batchesQuery.data?.meta}
                                        isLoading={batchesQuery.isLoading}
                                        emptyMessage="No PBN seed batches match these filters."
                                        onRowClick={(row) => setSelectedBatch(row)}
                                        onPageChange={(page) => setBatchFilters((current) => ({ ...current, page }))}
                                        perPage={batchFilters.per_page}
                                        onPerPageChange={(perPage) => setBatchFilters((current) => ({ ...current, per_page: perPage, page: 1 }))}
                                        compact
                                    />
                                </div>
                            ) : null}

                            {activeTab === 'items' ? (
                                <div className="space-y-3">
                                    <div className="flex flex-col gap-3 rounded-lg border border-slate-200 bg-white p-3 md:flex-row md:items-end md:justify-between">
                                        <div className="flex flex-1 flex-col gap-3 md:flex-row md:items-end">
                                            <label className="flex flex-1 flex-col gap-1 text-xs font-semibold uppercase tracking-[0.1em] text-slate-500">
                                                Search
                                                <input value={itemFilters.q} onChange={(event) => setItemFilters((current) => ({ ...current, q: event.target.value, page: 1 }))} className="crm-input text-sm font-normal normal-case tracking-normal" placeholder="Name, city, WP ID, failure" />
                                            </label>
                                            <FilterSelect value={itemFilters.status} onChange={(status) => setItemFilters((current) => ({ ...current, status, page: 1 }))} options={itemStatuses} label="Status" />
                                        </div>
                                        <button type="button" className="crm-btn-secondary px-3 py-2 text-sm" onClick={() => itemsQuery.refetch()}>Refresh</button>
                                    </div>
                                    <DataTable
                                        columns={itemColumns}
                                        data={itemRows}
                                        pagination={itemsQuery.data?.meta}
                                        isLoading={itemsQuery.isLoading}
                                        emptyMessage="No PBN seed items match these filters."
                                        onRowClick={(row) => setSelectedItem(row)}
                                        onPageChange={(page) => setItemFilters((current) => ({ ...current, page }))}
                                        perPage={itemFilters.per_page}
                                        onPerPageChange={(perPage) => setItemFilters((current) => ({ ...current, per_page: perPage, page: 1 }))}
                                        compact
                                    />
                                </div>
                            ) : null}

                            {activeTab === 'observability' ? (
                                <div className="space-y-3">
                                    <div className="flex flex-col gap-3 rounded-lg border border-slate-200 bg-white p-3 md:flex-row md:items-end md:justify-between">
                                        <FilterSelect value={eventFilters.level} onChange={(level) => setEventFilters((current) => ({ ...current, level, page: 1 }))} options={['all', 'info', 'warning', 'error']} label="Level" />
                                        <button type="button" className="crm-btn-secondary px-3 py-2 text-sm" onClick={() => eventsQuery.refetch()}>Refresh</button>
                                    </div>
                                    <section className="crm-surface overflow-hidden">
                                        <div className="divide-y divide-slate-100">
                                            {eventRows.map((event) => (
                                                <div key={event.id} className="grid gap-3 px-4 py-3 lg:grid-cols-[150px_1fr_auto] lg:items-center">
                                                    <div>
                                                        <StatusBadge status={eventTone(event.level)} label={event.level} />
                                                        <p className="mt-1 text-xs text-slate-500">{formatDateTime(event.created_at)}</p>
                                                    </div>
                                                    <div className="min-w-0">
                                                        <p className="text-sm font-semibold text-slate-900">{event.message}</p>
                                                        <p className="truncate text-xs text-slate-500">{event.site_name || 'PBN'} · {event.type} · {event.actor?.name || 'System'}</p>
                                                    </div>
                                                    <p className="text-xs font-semibold text-slate-500">Batch #{event.batch_id || '-'}</p>
                                                </div>
                                            ))}
                                            {eventsQuery.isLoading ? <p className="px-4 py-8 text-center text-sm text-slate-500">Loading event timeline...</p> : null}
                                            {!eventsQuery.isLoading && eventRows.length === 0 ? <p className="px-4 py-8 text-center text-sm text-slate-500">No PBN events recorded yet.</p> : null}
                                        </div>
                                        {eventsQuery.data?.meta?.total > 0 ? (
                                            <div className="flex items-center justify-between border-t border-slate-100 bg-slate-50 px-4 py-3">
                                                <button type="button" className="crm-btn-secondary px-3 py-1.5 text-xs disabled:opacity-50" disabled={(eventFilters.page || 1) <= 1} onClick={() => setEventFilters((current) => ({ ...current, page: Math.max(1, current.page - 1) }))}>Prev</button>
                                                <p className="text-xs text-slate-500">Page {eventsQuery.data.meta.current_page} of {eventsQuery.data.meta.last_page}</p>
                                                <button type="button" className="crm-btn-secondary px-3 py-1.5 text-xs disabled:opacity-50" disabled={eventsQuery.data.meta.current_page >= eventsQuery.data.meta.last_page} onClick={() => setEventFilters((current) => ({ ...current, page: current.page + 1 }))}>Next</button>
                                            </div>
                                        ) : null}
                                    </section>
                                </div>
                            ) : null}
                        </div>
                    </section>
                </main>
            </div>

            <PbnSeedWizard
                open={seedOpen}
                onClose={() => setSeedOpen(false)}
                site={selectedSite?.status === 'ready' ? selectedSite : null}
                platforms={platforms}
                defaultNotes="Manual PBN seed from operations workspace"
                onQueued={(data) => {
                    invalidateOperations();
                    if (data?.batch) {
                        setSelectedBatch(data.batch);
                        setActiveTab('batches');
                    }
                }}
            />

            {selectedBatch ? (
                <div className="fixed inset-0 z-50 flex justify-end bg-slate-900/40 p-0" role="dialog" aria-modal="true">
                    <aside className="flex h-full w-full max-w-3xl flex-col bg-white shadow-xl">
                        <header className="crm-panel-header">
                            <div>
                                <h3 className="crm-panel-title">Batch #{batchDetail?.id}</h3>
                                <p className="crm-panel-subtitle">{batchDetail?.site?.name || selectedBatch.site?.name || 'PBN site'} · {formatDateTime(batchDetail?.created_at)}</p>
                            </div>
                            <button type="button" className="crm-btn-secondary px-3 py-2 text-sm" onClick={() => setSelectedBatch(null)}>Close</button>
                        </header>
                        <div className="flex-1 space-y-4 overflow-y-auto p-4">
                            <div className="grid gap-3 sm:grid-cols-4">
                                <StatTile label="Selected" value={batchDetail?.selected_count} />
                                <StatTile label="Created" value={batchDetail?.created_count} accent="emerald" />
                                <StatTile label="Failed" value={batchDetail?.failed_count} accent="rose" />
                                <StatTile label="Reverted" value={batchDetail?.reverted_count} accent="amber" />
                            </div>
                            {batchDetail?.warnings?.length ? (
                                <div className="rounded-lg border border-amber-200 bg-amber-50 px-3 py-2 text-sm text-amber-800">
                                    {batchDetail.warnings.map((warning) => warning.message || warning.type).join(' ')}
                                </div>
                            ) : null}
                            <div className="flex flex-wrap items-center justify-between gap-2">
                                <StatusBadge status={batchDetail?.status} label={statusLabel(batchDetail?.status)} />
                                <div className="flex flex-wrap gap-2">
                                    {isBatchActive ? (
                                        <button
                                            type="button"
                                            className="crm-btn-danger px-3 py-2 text-sm disabled:cursor-not-allowed disabled:opacity-50"
                                            onClick={() => setCancelOpen(true)}
                                            disabled={cancelMutation.isPending}
                                        >
                                            Stop batch
                                        </button>
                                    ) : null}
                                    {batchDetail?.failed_count > 0 ? (
                                        <button type="button" className="crm-btn-secondary px-3 py-2 text-sm" onClick={() => retryMutation.mutate(batchDetail.id)} disabled={retryMutation.isPending}>
                                            Retry failed
                                        </button>
                                    ) : null}
                                    {pendingMediaCount > 0 ? (
                                        <button
                                            type="button"
                                            className="crm-btn-secondary px-3 py-2 text-sm disabled:cursor-not-allowed disabled:opacity-50"
                                            onClick={() => mediaMutation.mutate()}
                                            disabled={mediaMutation.isPending}
                                        >
                                            {mediaMutation.isPending ? 'Processing media...' : `Process media (${Math.min(5, pendingMediaCount)})`}
                                        </button>
                                    ) : null}
                                    {canRevert ? (
                                        <button
                                            type="button"
                                            className="crm-btn-danger px-3 py-2 text-sm disabled:cursor-not-allowed disabled:opacity-50"
                                            onClick={() => setRevertOpen(true)}
                                            disabled={!revertPreview.can_revert}
                                            title={revertPreview.message}
                                        >
                                            Revert batch
                                        </button>
                                    ) : null}
                                </div>
                            </div>
                            {batchDetailQuery.isError ? (
                                <div className="rounded-lg border border-rose-200 bg-rose-50 px-3 py-2 text-sm text-rose-800">
                                    <div className="flex flex-col gap-2 sm:flex-row sm:items-center sm:justify-between">
                                        <p>{apiErrorMessage(batchDetailQuery.error, 'Could not load batch details.')}</p>
                                        <button type="button" className="crm-btn-secondary px-3 py-1.5 text-xs" onClick={() => batchDetailQuery.refetch()}>
                                            Retry
                                        </button>
                                    </div>
                                </div>
                            ) : null}
                            {pendingMediaCount > 0 ? (
                                <section className="rounded-lg border border-amber-200 bg-amber-50 p-4 text-sm text-amber-900">
                                    <div className="flex flex-col gap-3 lg:flex-row lg:items-start lg:justify-between">
                                        <div className="min-w-0">
                                            <p className="font-semibold text-amber-950">Media copy pending</p>
                                            <p className="mt-1 text-amber-800">{mediaSummary.reason || 'Profiles were created; media is waiting for the copy pass.'}</p>
                                            <div className="mt-3 grid gap-2 sm:grid-cols-3">
                                                <div className="rounded-lg border border-amber-200 bg-white/70 px-3 py-2">
                                                    <p className="text-[11px] font-semibold uppercase tracking-[0.1em] text-amber-700">Pending</p>
                                                    <p className="mt-1 text-lg font-semibold text-amber-950">{formatNumber(pendingMediaCount)}</p>
                                                </div>
                                                <div className="rounded-lg border border-amber-200 bg-white/70 px-3 py-2">
                                                    <p className="text-[11px] font-semibold uppercase tracking-[0.1em] text-amber-700">Needs Check</p>
                                                    <p className="mt-1 text-lg font-semibold text-amber-950">{formatNumber(mediaAttentionCount)}</p>
                                                </div>
                                                <div className="rounded-lg border border-amber-200 bg-white/70 px-3 py-2">
                                                    <p className="text-[11px] font-semibold uppercase tracking-[0.1em] text-amber-700">Estimate</p>
                                                    <p className="mt-1 text-lg font-semibold text-amber-950">{mediaSummary.eta_label || 'About a few minutes'}</p>
                                                </div>
                                            </div>
                                            <p className="mt-3 text-xs text-amber-800">{mediaSummary.next_action || 'Process pending media, then inspect any rows that remain flagged.'}</p>
                                        </div>
                                        <button
                                            type="button"
                                            className="crm-btn-primary min-h-11 px-4 py-2 disabled:cursor-not-allowed disabled:opacity-60"
                                            onClick={() => mediaMutation.mutate()}
                                            disabled={mediaMutation.isPending}
                                        >
                                            {mediaMutation.isPending ? 'Processing...' : `Process next ${Math.min(5, pendingMediaCount)}`}
                                        </button>
                                    </div>
                                </section>
                            ) : null}
                            <section className="rounded-lg border border-slate-200 bg-white">
                                <div className="flex flex-col gap-2 px-4 py-3 sm:flex-row sm:items-center sm:justify-between">
                                    <div>
                                        <p className="text-sm font-semibold text-slate-900">Profile self-edit links</p>
                                        <p className="mt-0.5 text-xs text-slate-500">
                                            Checks the WordPress rows an owner needs to edit their profile and upload photos.
                                        </p>
                                    </div>
                                    <div className="flex flex-wrap gap-2">
                                        {!linkCheckOpen ? (
                                            <button type="button" className="crm-btn-secondary px-3 py-1.5 text-xs" onClick={() => setLinkCheckOpen(true)}>
                                                Run check
                                            </button>
                                        ) : (
                                            <button type="button" className="crm-btn-secondary px-3 py-1.5 text-xs" onClick={() => profileLinksQuery.refetch()} disabled={profileLinksQuery.isFetching}>
                                                {profileLinksQuery.isFetching ? 'Checking...' : 'Re-check'}
                                            </button>
                                        )}
                                        {canRevert && profileLinks?.needs_repair > 0 ? (
                                            <button
                                                type="button"
                                                className="crm-btn-primary px-3 py-1.5 text-xs"
                                                onClick={() => repairLinksMutation.mutate()}
                                                disabled={repairLinksMutation.isPending}
                                            >
                                                {repairLinksMutation.isPending ? 'Repairing...' : `Repair ${profileLinks.needs_repair} profile${profileLinks.needs_repair === 1 ? '' : 's'}`}
                                            </button>
                                        ) : null}
                                    </div>
                                </div>
                                {linkCheckOpen ? (
                                    <div className="border-t border-slate-100 px-4 py-3">
                                        {profileLinksQuery.isLoading ? (
                                            <p className="text-sm text-slate-500">Checking profiles on the destination site...</p>
                                        ) : null}
                                        {profileLinksQuery.isError ? (
                                            <div className="flex flex-col gap-2 rounded-md border border-rose-200 bg-rose-50 px-3 py-2 text-sm text-rose-800 sm:flex-row sm:items-center sm:justify-between">
                                                <p>{apiErrorMessage(profileLinksQuery.error, 'Could not check profile links.')}</p>
                                                <button type="button" className="crm-btn-secondary px-3 py-1.5 text-xs" onClick={() => profileLinksQuery.refetch()}>Retry</button>
                                            </div>
                                        ) : null}
                                        {profileLinks?.error ? (
                                            <p className="rounded-md border border-amber-200 bg-amber-50 px-3 py-2 text-sm text-amber-800">{profileLinks.error}</p>
                                        ) : null}
                                        {profileLinks && !profileLinks.error ? (
                                            <>
                                                <div className="grid gap-2 sm:grid-cols-3">
                                                    {[
                                                        ['Checked', profileLinks.checked, 'text-slate-900'],
                                                        ['Healthy', profileLinks.healthy + profileLinks.repaired, 'text-teal-700'],
                                                        ['Need repair', profileLinks.needs_repair, profileLinks.needs_repair > 0 ? 'text-amber-700' : 'text-slate-400'],
                                                    ].map(([label, value, tone]) => (
                                                        <div key={label} className="rounded-md border border-slate-200 px-3 py-2">
                                                            <p className="text-[11px] font-semibold uppercase tracking-[0.1em] text-slate-500">{label}</p>
                                                            <p className={`mt-0.5 text-xl font-semibold ${tone}`}>{value}</p>
                                                        </div>
                                                    ))}
                                                </div>
                                                {profileLinks.needs_repair > 0 ? (
                                                    <p className="mt-3 rounded-md border border-amber-200 bg-amber-50 px-3 py-2 text-xs text-amber-800">
                                                        These profiles cannot be edited or accept photo uploads on the destination site. Repairing writes the missing
                                                        WordPress rows in place — no profile is recreated and nothing published changes.
                                                    </p>
                                                ) : (
                                                    <p className="mt-3 rounded-md border border-teal-200 bg-teal-50 px-3 py-2 text-xs text-teal-800">
                                                        Every profile in this batch has the rows it needs to be edited and to accept uploads.
                                                    </p>
                                                )}
                                                {profileLinks.items.filter((row) => row.state !== 'ok' || row.repaired.length > 0).length > 0 ? (
                                                    <ul className="mt-3 max-h-48 space-y-1 overflow-auto">
                                                        {profileLinks.items.filter((row) => row.state !== 'ok' || row.repaired.length > 0).map((row) => (
                                                            <li key={row.item_id} className="flex flex-wrap items-center justify-between gap-2 rounded-md border border-slate-200 px-3 py-1.5 text-xs">
                                                                <span className="font-medium text-slate-800">{row.name} · WP {row.target_wp_post_id}</span>
                                                                <span className={row.repaired.length > 0 ? 'text-teal-700' : 'text-amber-700'}>
                                                                    {row.repaired.length > 0 ? `repaired: ${row.repaired.join(', ')}` : `missing: ${row.missing.join(', ')}`}
                                                                </span>
                                                            </li>
                                                        ))}
                                                    </ul>
                                                ) : null}
                                            </>
                                        ) : null}
                                    </div>
                                ) : null}
                            </section>

                            <section className="rounded-lg border border-slate-200 bg-white">
                                <div className="border-b border-slate-100 px-4 py-3">
                                    <div className="flex flex-col gap-2 sm:flex-row sm:items-center sm:justify-between">
                                        <div>
                                            <p className="text-sm font-semibold text-slate-900">Seed Items</p>
                                            {batchPolicySummary ? (
                                                <p className="mt-0.5 text-xs text-slate-500">
                                                    {batchPolicySummary.featured} VIP · {batchPolicySummary.premium} premium · {batchPolicySummary.verified} verified ·{' '}
                                                    {batchPolicySummary.bio_rewritten} bios rewritten
                                                    {batchPolicySummary.bio_template > 0 ? ` · ${batchPolicySummary.bio_template} from template` : ''}
                                                    {batchPolicySummary.bio_fallback > 0 ? ` · ${batchPolicySummary.bio_fallback} fell back` : ''}
                                                    {batchPolicySummary.bio_cost_usd > 0 ? ` · $${Number(batchPolicySummary.bio_cost_usd).toFixed(3)}` : ''}
                                                    {batchPolicySummary.awaiting_release > 0
                                                        ? ` · ${batchPolicySummary.awaiting_release} awaiting release${batchPolicySummary.next_release_at ? ` (next ${formatDateTime(batchPolicySummary.next_release_at)})` : ''}`
                                                        : ''}
                                                </p>
                                            ) : null}
                                        </div>
                                        <div className="flex gap-2">
                                            <button
                                                type="button"
                                                className="crm-btn-secondary px-3 py-1.5 text-xs"
                                                disabled={allBatchItems.length === 0}
                                                onClick={() => exportRowsToCsv(`pbn-batch-${batchDetail?.id}-items`, [
                                                    { label: 'Profile', value: (row) => row.source_client?.name || `Client #${row.source_client_id}` },
                                                    { label: 'Source market', value: (row) => row.source_platform_name },
                                                    { label: 'Source WP', value: (row) => row.source_wp_post_id },
                                                    { label: 'Status', value: (row) => row.status },
                                                    { label: 'Destination', value: (row) => row.destination_location },
                                                    { label: 'Target WP', value: (row) => row.target_wp_post_id },
                                                    { label: 'Profile URL', value: (row) => row.target_profile_url },
                                                    { label: 'Badge', value: (row) => badgeLabel[row.policy?.badge] || '' },
                                                    { label: 'Verified', value: (row) => (row.policy?.verified ? 'yes' : 'no') },
                                                    { label: 'Bio', value: (row) => bioOutcome(row.policy).label },
                                                    { label: 'Bio provider', value: (row) => row.policy?.bio_provider },
                                                    { label: 'Bio note', value: (row) => row.policy?.bio_note },
                                                    { label: 'Main image', value: (row) => imageOutcome(row.policy).label },
                                                    { label: 'Expires', value: (row) => row.policy?.expires_at },
                                                    { label: 'Release at', value: (row) => row.policy?.release_at },
                                                    { label: 'Failure', value: (row) => row.failure_reason || row.revert_failure_reason },
                                                ], allBatchItems)}
                                            >
                                                Export CSV
                                            </button>
                                            <button type="button" className="crm-btn-secondary px-3 py-1.5 text-xs" onClick={() => batchItemsQuery.refetch()} disabled={batchItemsQuery.isFetching}>
                                                {batchItemsQuery.isFetching ? 'Refreshing...' : 'Refresh'}
                                            </button>
                                        </div>
                                    </div>
                                    <div className="mt-3 flex flex-wrap gap-1.5">
                                        {itemPolicyFilters.map((filter) => {
                                            const count = filter.match ? allBatchItems.filter(filter.match).length : allBatchItems.length;

                                            return (
                                                <button
                                                    key={filter.id}
                                                    type="button"
                                                    onClick={() => setItemPolicyFilter(filter.id)}
                                                    className={`rounded-full border px-2.5 py-1 text-xs font-medium transition ${itemPolicyFilter === filter.id ? 'border-teal-300 bg-teal-50 text-teal-800' : 'border-slate-200 bg-white text-slate-600 hover:border-slate-300'}`}
                                                >
                                                    {filter.label} · {count}
                                                </button>
                                            );
                                        })}
                                    </div>
                                </div>
                                {batchItemsQuery.isError ? (
                                    <div className="m-3 rounded-lg border border-rose-200 bg-rose-50 px-3 py-2 text-sm text-rose-800">
                                        <div className="flex flex-col gap-2 sm:flex-row sm:items-center sm:justify-between">
                                            <p>{apiErrorMessage(batchItemsQuery.error, 'Could not load seed items for this batch.')}</p>
                                            <button type="button" className="crm-btn-secondary px-3 py-1.5 text-xs" onClick={() => batchItemsQuery.refetch()}>
                                                Retry
                                            </button>
                                        </div>
                                    </div>
                                ) : null}
                                <div className="max-h-[48vh] overflow-auto">
                                    <table className="min-w-full divide-y divide-slate-100">
                                        <thead className="bg-slate-50">
                                            <tr>
                                                <th className="px-3 py-2 text-left text-[11px] font-semibold uppercase tracking-[0.1em] text-slate-500">Profile</th>
                                                <th className="px-3 py-2 text-left text-[11px] font-semibold uppercase tracking-[0.1em] text-slate-500">Status</th>
                                                <th className="px-3 py-2 text-left text-[11px] font-semibold uppercase tracking-[0.1em] text-slate-500">Destination</th>
                                                <th className="px-3 py-2 text-left text-[11px] font-semibold uppercase tracking-[0.1em] text-slate-500">Badge</th>
                                                <th className="px-3 py-2 text-left text-[11px] font-semibold uppercase tracking-[0.1em] text-slate-500">Bio</th>
                                                <th className="px-3 py-2 text-left text-[11px] font-semibold uppercase tracking-[0.1em] text-slate-500">Main image</th>
                                                <th className="px-3 py-2 text-left text-[11px] font-semibold uppercase tracking-[0.1em] text-slate-500">Expires</th>
                                            </tr>
                                        </thead>
                                        <tbody className="divide-y divide-slate-100">
                                            {batchItemsQuery.isLoading ? (
                                                <tr><td colSpan={7} className="px-3 py-8 text-center text-sm text-slate-500">Loading seed items...</td></tr>
                                            ) : null}
                                            {batchItems.map((item) => (
                                                <tr key={item.id} onClick={() => setSelectedItem(item)} className="cursor-pointer transition-colors hover:bg-slate-50" title="Open profile actions">
                                                    <td className="px-3 py-2 text-sm">
                                                        <p className="font-semibold text-slate-900">{item.source_client?.name || `Client #${item.source_client_id}`}</p>
                                                        <p className="text-xs text-slate-500">{item.source_platform_name} · Source WP {item.source_wp_post_id}</p>
                                                        {(item.failure_reason || item.revert_failure_reason) && item.status !== 'media_pending' ? (
                                                            <p className={`mt-1 text-xs ${item.status === 'cancelled' ? 'text-slate-500' : 'text-rose-700'}`}>
                                                                {item.failure_reason || item.revert_failure_reason}
                                                            </p>
                                                        ) : null}
                                                        {item.media_status ? (
                                                            <p className="mt-1 text-xs text-amber-700">
                                                                {item.media_status.elapsed_label ? `${item.media_status.elapsed_label} pending · ` : ''}{item.media_status.reason}
                                                            </p>
                                                        ) : null}
                                                    </td>
                                                    <td className="px-3 py-2">
                                                        <StatusBadge status={item.status} label={statusLabel(item.status)} />
                                                        {item.policy?.awaiting_release ? (
                                                            <p className="mt-1 whitespace-nowrap text-[11px] text-slate-500">Releases {formatDateTime(item.policy.release_at)}</p>
                                                        ) : null}
                                                    </td>
                                                    <td className="px-3 py-2 text-sm">
                                                        <p className="text-slate-800">{item.destination_location || '—'}</p>
                                                        {item.target_profile_url ? (
                                                            <a
                                                                href={item.target_profile_url}
                                                                target="_blank"
                                                                rel="noreferrer"
                                                                onClick={(event) => event.stopPropagation()}
                                                                className="text-xs font-medium text-teal-700 hover:underline"
                                                            >
                                                                View on site ↗
                                                            </a>
                                                        ) : (
                                                            <p className="text-xs text-slate-400">WP {item.target_wp_post_id || '—'}</p>
                                                        )}
                                                    </td>
                                                    <td className="px-3 py-2">
                                                        {item.policy ? (
                                                            <div className="flex flex-wrap items-center gap-1">
                                                                <span className={`rounded-md border px-1.5 py-0.5 text-[11px] font-semibold ${badgeTone[item.policy.badge] || badgeTone.basic}`}>
                                                                    {badgeLabel[item.policy.badge] || 'Basic'}
                                                                </span>
                                                                {item.policy.verified ? (
                                                                    <span className="rounded-md border border-teal-200 bg-teal-50 px-1.5 py-0.5 text-[11px] font-semibold text-teal-800">Verified</span>
                                                                ) : null}
                                                            </div>
                                                        ) : <span className="text-sm text-slate-400">—</span>}
                                                    </td>
                                                    <td className="px-3 py-2 text-sm">
                                                        <p className={`font-medium ${bioOutcome(item.policy).tone}`}>{bioOutcome(item.policy).label}</p>
                                                        {bioOutcome(item.policy).hint ? (
                                                            <p className="max-w-[200px] truncate text-[11px] text-slate-500" title={bioOutcome(item.policy).hint}>{bioOutcome(item.policy).hint}</p>
                                                        ) : null}
                                                    </td>
                                                    <td className={`px-3 py-2 text-sm font-medium ${imageOutcome(item.policy).tone}`}>{imageOutcome(item.policy).label}</td>
                                                    <td className="px-3 py-2 text-sm text-slate-500">{item.policy?.expires_at || '—'}</td>
                                                </tr>
                                            ))}
                                            {!batchItemsQuery.isLoading && !batchItemsQuery.isError && batchItems.length === 0 ? (
                                                <tr><td colSpan={7} className="px-3 py-8 text-center text-sm text-slate-500">
                                                    {itemPolicyFilter === 'all' ? 'No items for this batch.' : 'No items match this filter.'}
                                                </td></tr>
                                            ) : null}
                                        </tbody>
                                    </table>
                                </div>
                            </section>
                            <section className="rounded-lg border border-slate-200 bg-white">
                                <div className="border-b border-slate-100 px-4 py-3">
                                    <div className="flex flex-col gap-2 sm:flex-row sm:items-center sm:justify-between">
                                        <p className="text-sm font-semibold text-slate-900">Event Timeline</p>
                                        <button type="button" className="crm-btn-secondary px-3 py-1.5 text-xs" onClick={() => batchEventsQuery.refetch()} disabled={batchEventsQuery.isFetching}>
                                            {batchEventsQuery.isFetching ? 'Refreshing...' : 'Refresh'}
                                        </button>
                                    </div>
                                </div>
                                {batchEventsQuery.isError ? (
                                    <div className="m-3 rounded-lg border border-rose-200 bg-rose-50 px-3 py-2 text-sm text-rose-800">
                                        <div className="flex flex-col gap-2 sm:flex-row sm:items-center sm:justify-between">
                                            <p>{apiErrorMessage(batchEventsQuery.error, 'Could not load event timeline for this batch.')}</p>
                                            <button type="button" className="crm-btn-secondary px-3 py-1.5 text-xs" onClick={() => batchEventsQuery.refetch()}>
                                                Retry
                                            </button>
                                        </div>
                                    </div>
                                ) : null}
                                <div className="divide-y divide-slate-100">
                                    {batchEvents.map((event) => (
                                        <div key={event.id} className="grid gap-2 px-4 py-3 sm:grid-cols-[130px_1fr] sm:items-start">
                                            <div>
                                                <StatusBadge status={eventTone(event.level)} label={event.level} />
                                                <p className="mt-1 text-xs text-slate-500">{formatDateTime(event.created_at)}</p>
                                            </div>
                                            <div className="min-w-0">
                                                <p className="text-sm font-semibold text-slate-900">{event.message}</p>
                                                <p className="mt-1 text-xs text-slate-500">{event.type} · Item #{event.item_id || '-'}</p>
                                                {event.context?.failure_reason ? <p className="mt-1 line-clamp-2 text-xs text-rose-700">{event.context.failure_reason}</p> : null}
                                            </div>
                                        </div>
                                    ))}
                                    {batchEventsQuery.isLoading ? <p className="px-4 py-8 text-center text-sm text-slate-500">Loading event timeline...</p> : null}
                                    {!batchEventsQuery.isLoading && !batchEventsQuery.isError && batchEvents.length === 0 ? <p className="px-4 py-8 text-center text-sm text-slate-500">No events for this batch.</p> : null}
                                </div>
                            </section>
                        </div>
                    </aside>
                </div>
            ) : null}

            <SeedItemActionDialog item={selectedItem} onClose={() => setSelectedItem(null)} />

            <ConfirmDialog
                open={cancelOpen && Boolean(selectedBatch)}
                title="Stop PBN batch"
                message="Queued destination profiles will be cancelled. A profile already provisioning may finish first."
                confirmLabel="Stop batch"
                tone="danger"
                onCancel={() => {
                    setCancelOpen(false);
                    setCancelReason('');
                }}
                onConfirm={() => cancelMutation.mutate()}
                isPending={cancelMutation.isPending}
            >
                <label className="block text-sm font-semibold text-slate-700">
                    Reason
                    <textarea
                        value={cancelReason}
                        onChange={(event) => setCancelReason(event.target.value)}
                        rows={3}
                        className="crm-input mt-2"
                        placeholder="Optional note for the audit timeline"
                    />
                </label>
            </ConfirmDialog>

            <ConfirmDialog
                open={revertOpen && Boolean(selectedBatch)}
                title="Revert PBN batch"
                message={revertPreview.message || 'Created destination profiles will be moved private.'}
                confirmLabel="Revert profiles"
                tone="danger"
                onCancel={() => setRevertOpen(false)}
                onConfirm={() => revertMutation.mutate()}
                isPending={revertMutation.isPending}
                confirmDisabled={revertReason.trim().length < 6 || !revertPreview.can_revert}
            >
                <label className="block text-sm font-semibold text-slate-700">
                    Reason
                    <textarea
                        value={revertReason}
                        onChange={(event) => setRevertReason(event.target.value)}
                        rows={3}
                        className="crm-input mt-2"
                        placeholder="Why are these destination profiles being reverted?"
                    />
                </label>
                <div className="grid gap-2 sm:grid-cols-3">
                    <StatTile label="Eligible" value={revertPreview.eligible_count} accent="rose" />
                    <StatTile label="Already Reverted" value={revertPreview.already_reverted_count} accent="amber" />
                    <StatTile label="Blocked" value={revertPreview.blocked_count} />
                </div>
            </ConfirmDialog>
        </div>
    );
}
