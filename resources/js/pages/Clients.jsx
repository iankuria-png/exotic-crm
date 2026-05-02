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
import { deriveClientProfileState, isClientPubliclyActive } from '../utils/clientProfileState';
import { normalizePhone } from '../utils/phone';
import { useAuth } from '../hooks/useAuth';
import { RETENTION_BEHAVIOR_TAGS, RETENTION_BANDS, retentionBandClasses, retentionBandTone } from '../utils/retention';
import { proxyImageUrl } from '../utils/imageProxy';

const CSV_ERROR_PREVIEW_LIMIT = 8;
const DASHBOARD_MARKET_STORAGE_KEY = 'exoticcrm.dashboard.market_filter';
const SMART_DELETE_DAY_OPTIONS = [30, 60, 90, 180, 365];
const DEFAULT_SORT_OPTION = 'updated_desc';
const ONLINE_STATUS_WINDOW_MINUTES = 30;
const PLAN_SORT_ORDER = {
    vvip: 0,
    vip: 1,
    premium: 2,
    basic: 3,
    featured: 4,
};

function createBulkDeleteDialogState(platformId = '') {
    return {
        open: false,
        mode: 'selected',
        selectedClients: [],
        preview: null,
        confirmText: '',
        reason: 'Bulk client deletion from clients page',
        filters: {
            platform_id: platformId,
            inactive_days: '90',
            has_no_chat: true,
            has_no_subscription_or_payment: true,
        },
    };
}

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

function normalizeDateInputValue(value) {
    const raw = String(value ?? '').trim();
    return /^\d{4}-\d{2}-\d{2}$/.test(raw) ? raw : '';
}

function formatDateInputValue(date) {
    if (!(date instanceof Date) || Number.isNaN(date.getTime())) {
        return '';
    }

    const year = date.getFullYear();
    const month = String(date.getMonth() + 1).padStart(2, '0');
    const day = String(date.getDate()).padStart(2, '0');

    return `${year}-${month}-${day}`;
}

function slugifyPlanKey(value) {
    return String(value ?? '')
        .trim()
        .toLowerCase()
        .replace(/[^a-z0-9]+/g, '-')
        .replace(/^-+|-+$/g, '');
}

function normalizeKnownPlanKey(value) {
    const normalized = String(value ?? '')
        .trim()
        .toLowerCase()
        .replace(/[_-]+/g, ' ')
        .replace(/\s+/g, ' ');

    if (!normalized || normalized === 'custom') {
        return '';
    }

    if (normalized.includes('vvip')) return 'vvip';
    if (normalized === 'vip') return 'vip';
    if (normalized.includes('premium')) return 'premium';
    if (normalized.includes('featured')) return 'featured';
    if (normalized.includes('basic')) return 'basic';

    return '';
}

function ClientAvatar({ client }) {
    const rawImageUrl = client?.display_image_url || client?.main_image_url || '';
    const imageUrl = proxyImageUrl(rawImageUrl);
    const [failedUrl, setFailedUrl] = useState('');
    const showImage = imageUrl && failedUrl !== imageUrl;
    const initial = client?.name?.charAt(0) || '?';

    useEffect(() => {
        setFailedUrl('');
    }, [imageUrl]);

    if (showImage) {
        return (
            <img
                src={imageUrl}
                alt=""
                loading="lazy"
                className="h-9 w-9 rounded-full object-cover ring-1 ring-slate-200"
                onError={() => setFailedUrl(imageUrl)}
            />
        );
    }

    return (
        <div className="flex h-9 w-9 items-center justify-center rounded-full bg-slate-100 text-xs font-semibold text-slate-600 ring-1 ring-slate-200">
            {initial}
        </div>
    );
}

function formatPlanLabel(value) {
    const knownKey = normalizeKnownPlanKey(value);
    if (knownKey === 'vvip') return 'VVIP';
    if (knownKey === 'vip') return 'VIP';
    if (knownKey === 'premium') return 'Premium';
    if (knownKey === 'featured') return 'Featured';
    if (knownKey === 'basic') return 'Basic';

    return String(value ?? '')
        .trim()
        .replace(/[_-]+/g, ' ')
        .split(/\s+/)
        .filter(Boolean)
        .map((word) => {
            const lower = word.toLowerCase();
            if (lower === 'vip' || lower === 'vvip') {
                return lower.toUpperCase();
            }

            return lower.charAt(0).toUpperCase() + lower.slice(1);
        })
        .join(' ');
}

function planBadgeClasses(planKey) {
    switch (planKey) {
        case 'vvip':
            return 'bg-fuchsia-50 text-fuchsia-700 ring-fuchsia-200';
        case 'vip':
            return 'bg-indigo-50 text-indigo-700 ring-indigo-200';
        case 'premium':
            return 'bg-teal-50 text-teal-700 ring-teal-200';
        case 'featured':
            return 'bg-amber-50 text-amber-700 ring-amber-200';
        case 'basic':
            return 'bg-slate-100 text-slate-600 ring-slate-200';
        default:
            return 'bg-sky-50 text-sky-700 ring-sky-200';
    }
}

function getPackagePlanOption(pkg) {
    if (!pkg || pkg.is_archived || !pkg.is_active) {
        return null;
    }

    const labelSource = String(pkg.display_name || pkg.name || pkg.tier || pkg.slug || '').trim();
    if (!labelSource) {
        return null;
    }

    const knownKey = normalizeKnownPlanKey(pkg.tier) || normalizeKnownPlanKey(labelSource) || normalizeKnownPlanKey(pkg.slug);
    const value = knownKey || slugifyPlanKey(pkg.slug || labelSource);
    if (!value) {
        return null;
    }

    return {
        value,
        label: knownKey ? formatPlanLabel(knownKey) : formatPlanLabel(labelSource),
        sortOrder: Number(pkg.sort_order || 0),
    };
}

function resolveInitialSortOption(searchParams) {
    const sortBy = String(searchParams.get('sort_by') || '').trim();
    const sortDirection = String(searchParams.get('sort_direction') || '').trim().toLowerCase();

    if (sortBy === 'name' && sortDirection === 'asc') return 'name_asc';
    if (sortBy === 'name' && sortDirection === 'desc') return 'name_desc';
    if (sortBy === 'created_at' && sortDirection === 'asc') return 'created_asc';
    if (sortBy === 'created_at' && sortDirection === 'desc') return 'created_desc';

    return DEFAULT_SORT_OPTION;
}

function getSortParams(sortOption) {
    switch (sortOption) {
        case 'name_asc':
            return { sort_by: 'name', sort_direction: 'asc' };
        case 'name_desc':
            return { sort_by: 'name', sort_direction: 'desc' };
        case 'created_asc':
            return { sort_by: 'created_at', sort_direction: 'asc' };
        case 'created_desc':
            return { sort_by: 'created_at', sort_direction: 'desc' };
        case DEFAULT_SORT_OPTION:
        default:
            return { sort_by: 'updated_at', sort_direction: 'desc' };
    }
}

function resolveCreatedRange(newUsersFilter, createdFrom, createdTo) {
    if (newUsersFilter === 'custom') {
        if (createdFrom && createdTo && createdFrom > createdTo) {
            return { createdFrom: createdTo, createdTo: createdFrom };
        }

        return { createdFrom, createdTo };
    }

    if (!newUsersFilter) {
        return { createdFrom: '', createdTo: '' };
    }

    const today = new Date();
    today.setHours(0, 0, 0, 0);
    const endDate = formatDateInputValue(today);
    const startDate = new Date(today);

    if (newUsersFilter === 'today') {
        return { createdFrom: endDate, createdTo: endDate };
    }

    if (newUsersFilter === '7d') {
        startDate.setDate(startDate.getDate() - 6);
        return { createdFrom: formatDateInputValue(startDate), createdTo: endDate };
    }

    if (newUsersFilter === '30d') {
        startDate.setDate(startDate.getDate() - 29);
        return { createdFrom: formatDateInputValue(startDate), createdTo: endDate };
    }

    return { createdFrom: '', createdTo: '' };
}

function formatRelativeFromUnix(unixTs) {
    const ts = Number(unixTs || 0);
    if (!ts) return '—';

    const diffSeconds = Math.floor(Date.now() / 1000) - ts;
    if (diffSeconds < 60) return 'just now';

    const minutes = Math.floor(diffSeconds / 60);
    if (minutes < 60) return `${minutes}m ago`;

    const hours = Math.floor(minutes / 60);
    if (hours < 24) return `${hours}h ago`;

    const days = Math.floor(hours / 24);
    if (days < 30) return `${days}d ago`;

    const months = Math.floor(days / 30);
    if (months < 12) return `${months}mo ago`;

    const years = Math.floor(months / 12);
    return `${years}y ago`;
}

function isClientOnline(lastOnlineAt) {
    const ts = Number(lastOnlineAt || 0);
    if (!ts) {
        return false;
    }

    return ts >= Math.floor(Date.now() / 1000) - (ONLINE_STATUS_WINDOW_MINUTES * 60);
}

function formatSeenTimestamp(unixTs) {
    const ts = Number(unixTs || 0);
    if (!ts) return '';

    const parsed = new Date(ts * 1000);
    if (Number.isNaN(parsed.getTime())) return '';

    return parsed.toLocaleString(undefined, {
        day: '2-digit',
        month: 'short',
        hour: '2-digit',
        minute: '2-digit',
    });
}

function formatLastSeenMeta(unixTs) {
    const ts = Number(unixTs || 0);
    if (!ts) {
        return 'Never seen';
    }

    const relative = formatRelativeFromUnix(ts);
    if (isClientOnline(ts)) {
        return `Seen ${relative}`;
    }

    const exact = formatSeenTimestamp(ts);
    return exact ? `Seen ${relative} • ${exact}` : `Seen ${relative}`;
}

export default function Clients() {
    const allowedStatuses = new Set(['publish', 'private', 'draft', 'pending']);
    const allowedVerifiedFilters = new Set(['1', '0']);
    const allowedHasChatFilters = new Set(['1', '0']);
    const allowedHighRiskFilters = new Set(['1']);
    const allowedOnlineFilters = new Set(['5', '15', '30', '60', '360', '1440', '10080']);
    const allowedSignupSources = new Set(['fast_signup', 'full_registration', 'crm_manual', 'crm_provisioned']);
    const allowedRetentionBands = new Set([...RETENTION_BANDS, 'watch']);
    const allowedBehaviorTags = new Set(RETENTION_BEHAVIOR_TAGS);
    const allowedNewUsersFilters = new Set(['today', '7d', '30d', 'custom']);
    const navigate = useNavigate();
    const queryClient = useQueryClient();
    const toast = useToast();
    const { user } = useAuth();
    const isReadOnly = user?.role === 'marketing';
    const canBulkRefreshThumbnails = ['admin', 'sub_admin', 'sales'].includes(String(user?.role || ''));
    const canDeleteClients = ['admin', 'sub_admin'].includes(String(user?.role || ''));
    const canSelectClients = canBulkRefreshThumbnails || canDeleteClients;
    const [searchParams] = useSearchParams();

    const [page, setPage] = useState(1);
    const [perPage, setPerPage] = useState(50);
    const [search, setSearch] = useState('');
    const [searchInput, setSearchInput] = useState('');
    const [statusFilter, setStatusFilter] = useState(() => {
        const requested = (searchParams.get('status') || '').trim();
        return allowedStatuses.has(requested) ? requested : '';
    });
    const [planFilter, setPlanFilter] = useState(() => (searchParams.get('plan') || '').trim());
    const [verifiedFilter, setVerifiedFilter] = useState(() => {
        const requested = (searchParams.get('verified') || '').trim();
        return allowedVerifiedFilters.has(requested) ? requested : '';
    });
    const [highRiskFilter, setHighRiskFilter] = useState(() => {
        const requested = (searchParams.get('high_risk') || '').trim();
        return allowedHighRiskFilters.has(requested) ? requested : '';
    });
    const [hasChatFilter, setHasChatFilter] = useState(() => {
        const requested = (searchParams.get('has_chat') || '').trim();
        return allowedHasChatFilters.has(requested) ? requested : '';
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
    const [signupSourceFilter, setSignupSourceFilter] = useState(() => {
        const requested = (searchParams.get('signup_source') || '').trim();
        return allowedSignupSources.has(requested) ? requested : '';
    });
    const [retentionBandFilter, setRetentionBandFilter] = useState(() => {
        const requested = (searchParams.get('retention_band') || '').trim();
        return allowedRetentionBands.has(requested) ? requested : '';
    });
    const [behaviorTagFilter, setBehaviorTagFilter] = useState(() => {
        const requested = (searchParams.get('behavior_tag') || '').trim();
        return allowedBehaviorTags.has(requested) ? requested : '';
    });
    const [newUsersFilter, setNewUsersFilter] = useState(() => {
        const requested = (searchParams.get('new_users') || '').trim();
        if (allowedNewUsersFilters.has(requested)) {
            return requested;
        }

        return searchParams.get('created_from') || searchParams.get('created_to') ? 'custom' : '';
    });
    const [createdFrom, setCreatedFrom] = useState(() => normalizeDateInputValue(searchParams.get('created_from')));
    const [createdTo, setCreatedTo] = useState(() => normalizeDateInputValue(searchParams.get('created_to')));
    const [sortOption, setSortOption] = useState(() => resolveInitialSortOption(searchParams));
    const [cityFilter, setCityFilter] = useState(() => (searchParams.get('city') || '').trim());

    const [showCreateModal, setShowCreateModal] = useState(false);
    const [showCsvModal, setShowCsvModal] = useState(false);
    const [showCsvConfirm, setShowCsvConfirm] = useState(false);
    const [csvResult, setCsvResult] = useState(null);
    const [clearSelectionKey, setClearSelectionKey] = useState(0);
    const [bulkThumbnailRefreshSelection, setBulkThumbnailRefreshSelection] = useState([]);
    const [showBulkThumbnailRefreshConfirm, setShowBulkThumbnailRefreshConfirm] = useState(false);
    const [bulkDeleteDialog, setBulkDeleteDialog] = useState(() => createBulkDeleteDialogState(''));
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
    const resolvedCreatedRange = useMemo(
        () => resolveCreatedRange(newUsersFilter, createdFrom, createdTo),
        [newUsersFilter, createdFrom, createdTo],
    );
    const sortParams = useMemo(() => getSortParams(sortOption), [sortOption]);

    const { data: citiesData } = useQuery({
        queryKey: ['client-cities', platformFilter],
        queryFn: () =>
            api.get('/crm/clients/cities', {
                params: platformFilter ? { platform_id: Number(platformFilter) } : {},
            }).then((response) => response.data),
    });
    const availableCities = citiesData?.cities || [];

    useEffect(() => {
        setCityFilter('');
    }, [platformFilter]);

    const { data, isLoading } = useQuery({
        queryKey: [
            'clients',
            page,
            perPage,
            search,
            statusFilter,
            planFilter,
            verifiedFilter,
            highRiskFilter,
            hasChatFilter,
            onlineFilter,
            platformFilter,
            cityFilter,
            signupSourceFilter,
            retentionBandFilter,
            behaviorTagFilter,
            resolvedCreatedRange.createdFrom,
            resolvedCreatedRange.createdTo,
            sortParams.sort_by,
            sortParams.sort_direction,
        ],
        queryFn: () =>
            api.get('/crm/clients', {
                params: {
                    page,
                    per_page: perPage,
                    ...(search && { search }),
                    ...(statusFilter && { status: statusFilter }),
                    ...(planFilter && { plan: planFilter }),
                    ...(verifiedFilter !== '' && { verified: verifiedFilter }),
                    ...(highRiskFilter === '1' && { high_risk: 1 }),
                    ...(hasChatFilter !== '' && { has_chat: hasChatFilter }),
                    ...(onlineFilter && { online_within: Number(onlineFilter) }),
                    ...(platformFilter && { platform_id: Number(platformFilter) }),
                    ...(cityFilter && { city: cityFilter }),
                    ...(signupSourceFilter && { signup_source: signupSourceFilter }),
                    ...(retentionBandFilter && { retention_band: retentionBandFilter }),
                    ...(behaviorTagFilter && { behavior_tag: behaviorTagFilter }),
                    ...(resolvedCreatedRange.createdFrom && { created_from: resolvedCreatedRange.createdFrom }),
                    ...(resolvedCreatedRange.createdTo && { created_to: resolvedCreatedRange.createdTo }),
                    ...sortParams,
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

    const { data: createModalCitiesData } = useQuery({
        queryKey: ['client-cities', createForm.platform_id],
        queryFn: () =>
            api.get('/crm/clients/cities', {
                params: createForm.platform_id ? { platform_id: Number(createForm.platform_id) } : {},
            }).then((response) => response.data),
        enabled: showCreateModal && !!createForm.platform_id,
    });
    const createModalCities = createModalCitiesData?.cities || [];

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

    const buildBulkDeletePreviewPayload = (dialogState) => {
        if (dialogState.mode === 'selected') {
            return {
                client_ids: dialogState.selectedClients.map((client) => Number(client.id)).filter((clientId) => clientId > 0),
            };
        }

        const filters = {};
        if (dialogState.filters.platform_id) {
            filters.platform_id = Number(dialogState.filters.platform_id);
        }
        if (dialogState.filters.inactive_days) {
            filters.inactive_days = Number(dialogState.filters.inactive_days);
        }
        if (dialogState.filters.has_no_chat) {
            filters.has_no_chat = true;
        }
        if (dialogState.filters.has_no_subscription_or_payment) {
            filters.has_no_subscription_or_payment = true;
        }

        return { filters };
    };

    const bulkDeletePreviewMutation = useMutation({
        mutationFn: (payload) => api.post('/crm/clients/bulk-delete/preview', payload).then((response) => response.data),
        onSuccess: (payload) => {
            setBulkDeleteDialog((current) => ({
                ...current,
                preview: payload,
            }));
        },
        onError: (error) => {
            toast.error(error?.response?.data?.message || 'Delete preview could not be loaded.');
        },
    });

    const bulkDeleteMutation = useMutation({
        mutationFn: ({ clientIds, reason }) => api.post('/crm/clients/bulk-delete', {
            client_ids: clientIds,
            confirm: 'DELETE',
            reason,
        }).then((response) => response.data),
        onSuccess: (payload) => {
            queryClient.invalidateQueries({ queryKey: ['clients'] });
            queryClient.invalidateQueries({ queryKey: ['dashboard'] });
            setClearSelectionKey((current) => current + 1);
            setBulkDeleteDialog(createBulkDeleteDialogState(platformFilter));
            const deletedCount = Number(payload?.deleted_count || 0);
            toast.success(`Deleted ${deletedCount.toLocaleString()} client${deletedCount === 1 ? '' : 's'}.`);
            setPage(1);
        },
        onError: (error) => {
            toast.error(error?.response?.data?.message || 'Bulk client deletion failed.');
        },
    });

    const bulkThumbnailRefreshMutation = useMutation({
        mutationFn: (clientIds) => api.post('/crm/clients/bulk-refresh-display-images', {
            client_ids: clientIds,
        }).then((response) => response.data),
        onSuccess: (payload) => {
            queryClient.invalidateQueries({ queryKey: ['clients'] });
            setClearSelectionKey((current) => current + 1);
            setBulkThumbnailRefreshSelection([]);
            setShowBulkThumbnailRefreshConfirm(false);

            const refreshedCount = Number(payload?.refreshed_count || 0);
            const clearedCount = Number(payload?.cleared_count || 0);
            const skippedCount = Number(payload?.skipped_count || 0);
            const failedCount = Number(payload?.failed_count || 0);

            const summary = [
                `${refreshedCount} refreshed`,
                clearedCount ? `${clearedCount} cleared` : null,
                skippedCount ? `${skippedCount} skipped` : null,
                failedCount ? `${failedCount} failed` : null,
            ].filter(Boolean).join(' • ');

            if (failedCount > 0) {
                toast.warning(`Thumbnail refresh finished with partial failures: ${summary}.`);
                return;
            }

            toast.success(`Thumbnail refresh complete: ${summary}.`);
        },
        onError: (error) => {
            toast.error(error?.response?.data?.message || 'Bulk thumbnail refresh failed.');
        },
    });

    const closeBulkDeleteDialog = () => {
        if (bulkDeletePreviewMutation.isPending || bulkDeleteMutation.isPending) {
            return;
        }

        setBulkDeleteDialog(createBulkDeleteDialogState(platformFilter));
    };

    const previewBulkDelete = (dialogState) => {
        setBulkDeleteDialog((current) => ({
            ...current,
            preview: null,
        }));
        bulkDeletePreviewMutation.mutate(buildBulkDeletePreviewPayload(dialogState));
    };

    const openSelectedDeleteDialog = (rowsSelection) => {
        const nextDialog = {
            ...createBulkDeleteDialogState(platformFilter),
            open: true,
            mode: 'selected',
            selectedClients: rowsSelection,
        };

        setBulkDeleteDialog(nextDialog);
        previewBulkDelete(nextDialog);
    };

    const openSmartDeleteDialog = () => {
        setBulkDeleteDialog({
            ...createBulkDeleteDialogState(platformFilter),
            open: true,
            mode: 'smart',
            reason: 'Smart client deletion from clients page',
        });
    };

    const handleSearch = (event) => {
        event.preventDefault();
        setSearch(searchInput.trim());
        setPage(1);
    };

    const bulkActions = [
        ...(canBulkRefreshThumbnails ? [{
            key: 'bulk-refresh-client-thumbnails',
            label: 'Refresh thumbnails',
            onClick: (rowsSelection) => {
                setBulkThumbnailRefreshSelection(rowsSelection);
                setShowBulkThumbnailRefreshConfirm(true);
            },
            isDisabled: (rowsSelection) => rowsSelection.some((row) => Number(row.wp_post_id || 0) <= 0),
            getDisabledReason: (rowsSelection) => (
                rowsSelection.some((row) => Number(row.wp_post_id || 0) <= 0)
                    ? 'Only WordPress-linked clients can refresh thumbnails.'
                    : undefined
            ),
        }] : []),
        ...(canDeleteClients ? [{
            key: 'bulk-delete-clients',
            label: 'Delete selected',
            variant: 'danger',
            onClick: (rowsSelection) => {
                openSelectedDeleteDialog(rowsSelection);
            },
        }] : []),
    ];

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
    const scopedPlanOptions = useMemo(() => {
        const scopedPlatforms = platformFilter
            ? platformOptions.filter((platform) => String(platform.platform_id) === String(platformFilter))
            : platformOptions;

        const collectedOptions = scopedPlatforms.flatMap((platform) => (
            Array.isArray(platform.packages) ? platform.packages.map(getPackagePlanOption).filter(Boolean) : []
        ));
        const optionMap = new Map();

        const orderedOptions = [...collectedOptions].sort((left, right) => {
            if (platformFilter) {
                return left.sortOrder - right.sortOrder
                    || (PLAN_SORT_ORDER[left.value] ?? 99) - (PLAN_SORT_ORDER[right.value] ?? 99)
                    || left.label.localeCompare(right.label);
            }

            return (PLAN_SORT_ORDER[left.value] ?? 99) - (PLAN_SORT_ORDER[right.value] ?? 99)
                || left.label.localeCompare(right.label);
        });

        orderedOptions.forEach((option) => {
            if (!optionMap.has(option.value)) {
                optionMap.set(option.value, {
                    value: option.value,
                    label: option.label,
                });
            }
        });

        const featuredExistsInScope = planFilter === 'featured'
            || rows.some((row) => slugifyPlanKey(row.plan_key || row.plan_label) === 'featured');

        if (featuredExistsInScope && !optionMap.has('featured')) {
            optionMap.set('featured', { value: 'featured', label: 'Featured' });
        }

        return [
            { value: '', label: 'All plans' },
            ...Array.from(optionMap.values()),
        ];
    }, [planFilter, platformFilter, platformOptions, rows]);

    useEffect(() => {
        if (!planFilter) {
            return;
        }

        const hasSelectedPlanOption = scopedPlanOptions.some((option) => option.value === planFilter);
        if (!hasSelectedPlanOption) {
            setPlanFilter('');
            setPage(1);
        }
    }, [planFilter, scopedPlanOptions]);

    const stats = useMemo(() => {
        if (data?.stats) {
            return {
                active: Number(data.stats.active || 0),
                new_users: Number(data.stats.new_users || 0),
                verified: Number(data.stats.verified || 0),
                with_chat: Number(data.stats.with_chat || 0),
                retention_watch: Number(data.stats.retention_watch || 0),
                total: Number(data.stats.total || 0),
            };
        }

        const sevenDayThreshold = Date.now() - (7 * 24 * 60 * 60 * 1000);

        return {
            active: rows.filter((row) => isClientPubliclyActive(row)).length,
            new_users: rows.filter((row) => {
                const createdAt = row.created_at ? new Date(row.created_at) : null;
                return createdAt && !Number.isNaN(createdAt.getTime()) && createdAt.getTime() >= sevenDayThreshold;
            }).length,
            verified: rows.filter((row) => row.verified).length,
            with_chat: rows.filter((row) => Number(row.sb_user_id || 0) > 0).length,
            retention_watch: rows.filter((row) => ['Watchlist', 'Needs Attention', 'Critical'].includes(String(row.retention_insight?.band || row.retentionInsight?.band || ''))).length,
            total: Number(data?.total || rows.length),
        };
    }, [data?.stats, data?.total, rows]);

    const metricShare = useMemo(() => ({
        active: percentage(stats.active, stats.total),
        new_users: percentage(stats.new_users, stats.total),
        verified: percentage(stats.verified, stats.total),
        retention_watch: percentage(stats.retention_watch, stats.total),
    }), [stats]);

    const activeMetric = useMemo(() => {
        if (
            statusFilter === 'publish'
            && planFilter === ''
            && verifiedFilter === ''
            && onlineFilter === ''
            && newUsersFilter === ''
        ) return 'active';

        if (
            newUsersFilter === '7d'
            && statusFilter === ''
            && planFilter === ''
            && verifiedFilter === ''
            && onlineFilter === ''
            && signupSourceFilter === ''
            && retentionBandFilter === ''
            && behaviorTagFilter === ''
            && hasChatFilter === ''
        ) return 'new_users';

        if (
            verifiedFilter === '1'
            && statusFilter === ''
            && planFilter === ''
            && onlineFilter === ''
            && newUsersFilter === ''
        ) return 'verified';

        if (
            retentionBandFilter === 'watch'
            && statusFilter === ''
            && planFilter === ''
            && verifiedFilter === ''
            && onlineFilter === ''
            && newUsersFilter === ''
            && behaviorTagFilter === ''
        ) return 'retention_watch';

        return '';
    }, [
        behaviorTagFilter,
        hasChatFilter,
        newUsersFilter,
        onlineFilter,
        planFilter,
        retentionBandFilter,
        signupSourceFilter,
        statusFilter,
        verifiedFilter,
    ]);

    const applyMetricFilter = (metricKey) => {
        if (activeMetric === metricKey) {
            setStatusFilter('');
            setPlanFilter('');
            setVerifiedFilter('');
            setOnlineFilter('');
            setNewUsersFilter('');
            setCreatedFrom('');
            setCreatedTo('');
            setSignupSourceFilter('');
            setRetentionBandFilter('');
            setBehaviorTagFilter('');
            setPage(1);
            return;
        }

        if (metricKey === 'active') {
            setStatusFilter('publish');
            setPlanFilter('');
            setVerifiedFilter('');
            setNewUsersFilter('');
            setCreatedFrom('');
            setCreatedTo('');
        } else if (metricKey === 'new_users') {
            setStatusFilter('');
            setPlanFilter('');
            setVerifiedFilter('');
            setNewUsersFilter('7d');
            setCreatedFrom('');
            setCreatedTo('');
        } else if (metricKey === 'verified') {
            setStatusFilter('');
            setPlanFilter('');
            setVerifiedFilter('1');
            setNewUsersFilter('');
            setCreatedFrom('');
            setCreatedTo('');
            setSignupSourceFilter('');
            setRetentionBandFilter('');
            setBehaviorTagFilter('');
        } else if (metricKey === 'retention_watch') {
            setStatusFilter('');
            setPlanFilter('');
            setVerifiedFilter('');
            setNewUsersFilter('');
            setCreatedFrom('');
            setCreatedTo('');
            setSignupSourceFilter('');
            setRetentionBandFilter('watch');
            setBehaviorTagFilter('');
        }

        setOnlineFilter('');
        setPage(1);
    };

    const hasActiveFilters = Boolean(
        search
        || statusFilter
        || planFilter
        || verifiedFilter !== ''
        || highRiskFilter !== ''
        || hasChatFilter !== ''
        || onlineFilter
        || platformFilter
        || cityFilter
        || signupSourceFilter
        || retentionBandFilter
        || behaviorTagFilter
        || newUsersFilter
        || createdFrom
        || createdTo
        || sortOption !== DEFAULT_SORT_OPTION
    );
    const searchResolutionNotice = useMemo(() => {
        const resolution = data?.search_resolution;
        if (!search || !resolution?.mode) {
            return null;
        }

        const wpPostId = resolution.resolved_wp_post_id ? `WP #${resolution.resolved_wp_post_id}` : null;

        if (resolution.mode === 'exact') {
            return {
                tone: 'success',
                title: 'Exact profile URL match found',
                message: wpPostId
                    ? `Matched the pasted URL directly to ${wpPostId}.`
                    : 'Matched the pasted URL directly to one CRM profile.',
            };
        }

        if (resolution.mode === 'exact_missing') {
            return {
                tone: 'warning',
                title: 'Profile URL resolved, but no synced CRM client was found',
                message: wpPostId
                    ? `The public site resolved this URL to ${wpPostId}, but that profile is not currently in this CRM scope.`
                    : 'The public site resolved this URL, but the profile is not currently in this CRM scope.',
            };
        }

        if (resolution.mode === 'fallback') {
            return {
                tone: 'info',
                title: 'No exact URL match found',
                message: 'Showing similar profiles from the URL slug so the team can still continue.',
            };
        }

        return null;
    }, [data?.search_resolution, search]);

    const columns = [
        {
            key: 'name',
            label: 'Client',
            width: '280px',
            cellClassName: 'w-[280px] max-w-[280px]',
            render: (row) => (
                <div className="flex min-w-0 max-w-[248px] items-center gap-3">
                    <ClientAvatar client={row} />
                    <div className="min-w-0 flex-1">
                        <div className="flex items-center gap-2">
                            <p className="truncate text-sm font-semibold text-slate-900" title={row.name || 'Unnamed'}>
                                {row.name || 'Unnamed'}
                            </p>
                            {row.is_high_risk ? (
                                <span className="inline-flex shrink-0 items-center rounded-md bg-rose-50 px-2 py-0.5 text-[11px] font-semibold text-rose-700 ring-1 ring-inset ring-rose-200">
                                    High Risk
                                </span>
                            ) : null}
                            {row.sb_user_id ? (
                                <span className="inline-flex shrink-0 items-center rounded-md bg-sky-50 px-2 py-0.5 text-[11px] font-semibold text-sky-700 ring-1 ring-inset ring-sky-200">
                                    Chat
                                </span>
                            ) : null}
                        </div>
                        <p className="truncate text-xs text-slate-500" title={row.city || 'City not set'}>
                            {row.city || 'City not set'}
                        </p>
                    </div>
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
            render: (row) => {
                const profileState = deriveClientProfileState(row);

                return (
                    <StatusBadge
                        status={profileState.status}
                        tone={profileState.tone}
                        label={profileState.label}
                    />
                );
            },
        },
        {
            key: 'plan',
            label: 'Plan',
            render: (row) => (
                <div className="flex flex-wrap items-center gap-1.5">
                    <span className={`inline-flex items-center rounded-md px-2.5 py-0.5 text-xs font-medium ring-1 ring-inset ${planBadgeClasses(slugifyPlanKey(row.plan_key || row.plan_label) || 'basic')}`}>
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
            key: 'online',
            label: 'Online',
            render: (row) => {
                const online = isClientOnline(row.last_online_at);

                return (
                    <div className="space-y-1">
                        <span className={`inline-flex items-center rounded-md px-2.5 py-0.5 text-xs font-medium ring-1 ring-inset ${
                            online
                                ? 'bg-emerald-50 text-emerald-700 ring-emerald-200'
                                : 'bg-slate-100 text-slate-600 ring-slate-200'
                        }`}>
                            {online ? 'Online' : 'Offline'}
                        </span>
                        <p className="text-[11px] text-slate-500">{formatLastSeenMeta(row.last_online_at)}</p>
                    </div>
                );
            },
        },
        {
            key: 'signup_source',
            label: 'Source',
            render: (row) => {
                const sourceMap = {
                    fast_signup: { label: 'Fast', classes: 'bg-blue-50 text-blue-700 ring-blue-200' },
                    full_registration: { label: 'Full', classes: 'bg-slate-50 text-slate-600 ring-slate-200' },
                    crm_manual: { label: 'Manual', classes: 'bg-purple-50 text-purple-700 ring-purple-200' },
                    crm_provisioned: { label: 'Provisioned', classes: 'bg-green-50 text-green-700 ring-green-200' },
                };
                const source = sourceMap[row.signup_source];
                if (!source) {
                    return <span className="text-xs text-slate-400">&mdash;</span>;
                }
                return (
                    <span className={`inline-flex items-center rounded-md px-2.5 py-0.5 text-xs font-medium ring-1 ring-inset ${source.classes}`}>
                        {source.label}
                    </span>
                );
            },
        },
        {
            key: 'retention',
            label: 'Retention',
            render: (row) => {
                const insight = row.retentionInsight || row.retention_insight;
                if (!insight?.band) {
                    return <span className="text-xs text-slate-400">Computing...</span>;
                }

                return (
                    <div className="space-y-1">
                        <span className={`inline-flex items-center rounded-md px-2.5 py-0.5 text-xs font-medium ring-1 ring-inset ${retentionBandClasses(insight.band)}`}>
                            {insight.band}
                        </span>
                        <p className="text-[11px] text-slate-500">{insight.primary_tag || 'No behavior tag yet'}</p>
                    </div>
                );
            },
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
        <div className="space-y-4" data-tour="clients-root">
            <PageHeader
                title="Clients"
                subtitle={stats.total
                    ? `${stats.total.toLocaleString()} clients in scope • ${stats.active.toLocaleString()} active • ${stats.verified.toLocaleString()} verified`
                    : 'Manage client records and subscription status.'}
                actions={!isReadOnly ? (
                    <>
                        {canDeleteClients ? (
                            <button
                                type="button"
                                onClick={openSmartDeleteDialog}
                                className="crm-btn-danger"
                                data-tour="clients-smart-delete"
                            >
                                Smart delete
                            </button>
                        ) : null}
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

            <section className="grid gap-4 md:grid-cols-2 xl:grid-cols-4">
                <MetricCard
                    label="Active Clients"
                    value={stats.active.toLocaleString()}
                    meta={`${metricShare.active}% of current scope in publish status`}
                    tone="success"
                    onClick={() => applyMetricFilter('active')}
                    active={activeMetric === 'active'}
                />
                <MetricCard
                    label="New Users"
                    value={stats.new_users.toLocaleString()}
                    meta={`${metricShare.new_users}% of current scope created in the last 7 days`}
                    tone="accent"
                    onClick={() => applyMetricFilter('new_users')}
                    active={activeMetric === 'new_users'}
                />
                <MetricCard
                    label="Verified Profiles"
                    value={stats.verified.toLocaleString()}
                    meta={`${metricShare.verified}% of current scope identity verified`}
                    tone="default"
                    onClick={() => applyMetricFilter('verified')}
                    active={activeMetric === 'verified'}
                />
                <MetricCard
                    label="Retention Watch"
                    value={stats.retention_watch.toLocaleString()}
                    meta="Clients showing churn or disengagement signals in current scope"
                    tone={retentionBandTone('Needs Attention')}
                    onClick={() => applyMetricFilter('retention_watch')}
                    active={activeMetric === 'retention_watch'}
                />
            </section>

            <p className="px-1 text-xs text-slate-500">Click a metric card to segment the table. Click the same card again to clear.</p>

            <section className="crm-filter-row space-y-4" data-tour="clients-filters">
                <div className="grid gap-3 xl:grid-cols-6">
                    <form onSubmit={handleSearch} className="min-w-0 xl:col-span-2">
                        <div className="flex flex-col gap-1">
                            <span className="text-[10px] font-semibold uppercase tracking-[0.1em] text-slate-400">Search</span>
                            <div className="relative">
                                <input
                                    type="text"
                                    value={searchInput}
                                    onChange={(event) => setSearchInput(event.target.value)}
                                    placeholder="Name, phone, email, or profile URL..."
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
                        label="City"
                        value={cityFilter}
                        onChange={(event) => { setCityFilter(event.target.value); setPage(1); }}
                        options={[
                            { value: '', label: 'All locations' },
                            ...availableCities.map((city) => ({ value: city, label: city })),
                        ]}
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
                        options={scopedPlanOptions}
                    />

                    <FilterSelect
                        label="New Users"
                        value={newUsersFilter}
                        onChange={(event) => {
                            const nextValue = event.target.value;
                            setNewUsersFilter(nextValue);
                            if (nextValue !== 'custom') {
                                setCreatedFrom('');
                                setCreatedTo('');
                            }
                            setPage(1);
                        }}
                        options={[
                            { value: '', label: 'All clients' },
                            { value: 'today', label: 'Today' },
                            { value: '7d', label: 'Last 7 days' },
                            { value: '30d', label: 'Last 30 days' },
                            { value: 'custom', label: 'Custom range' },
                        ]}
                    />

                    <FilterSelect
                        label="Sort"
                        value={sortOption}
                        onChange={(event) => { setSortOption(event.target.value); setPage(1); }}
                        options={[
                            { value: DEFAULT_SORT_OPTION, label: 'Recently updated' },
                            { value: 'name_asc', label: 'Client name A-Z' },
                            { value: 'name_desc', label: 'Client name Z-A' },
                            { value: 'created_desc', label: 'Newest signups' },
                            { value: 'created_asc', label: 'Oldest signups' },
                        ]}
                    />
                </div>

                {newUsersFilter === 'custom' ? (
                    <div className="grid gap-3 sm:grid-cols-2 lg:max-w-[22rem]">
                        <label className="flex flex-col gap-1">
                            <span className="text-[10px] font-semibold uppercase tracking-[0.1em] text-slate-400">From</span>
                            <input
                                type="date"
                                value={createdFrom}
                                onChange={(event) => {
                                    setCreatedFrom(event.target.value);
                                    setPage(1);
                                }}
                                className="crm-input"
                            />
                        </label>
                        <label className="flex flex-col gap-1">
                            <span className="text-[10px] font-semibold uppercase tracking-[0.1em] text-slate-400">To</span>
                            <input
                                type="date"
                                value={createdTo}
                                onChange={(event) => {
                                    setCreatedTo(event.target.value);
                                    setPage(1);
                                }}
                                className="crm-input"
                            />
                        </label>
                    </div>
                ) : null}

                <div className="flex flex-wrap items-end gap-3">
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
                        label="Risk"
                        value={highRiskFilter}
                        onChange={(event) => { setHighRiskFilter(event.target.value); setPage(1); }}
                        options={[
                            { value: '', label: 'All clients' },
                            { value: '1', label: 'High risk only' },
                        ]}
                    />

                    <FilterSelect
                        label="Chat"
                        value={hasChatFilter}
                        onChange={(event) => { setHasChatFilter(event.target.value); setPage(1); }}
                        options={[
                            { value: '', label: 'All clients' },
                            { value: '1', label: 'Matched to chat' },
                            { value: '0', label: 'No chat match' },
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

                    <FilterSelect
                        label="Source"
                        value={signupSourceFilter}
                        onChange={(event) => { setSignupSourceFilter(event.target.value); setPage(1); }}
                        options={[
                            { value: '', label: 'All sources' },
                            { value: 'fast_signup', label: 'Fast Signup' },
                            { value: 'full_registration', label: 'Full Registration' },
                            { value: 'crm_manual', label: 'CRM Manual' },
                            { value: 'crm_provisioned', label: 'CRM Provisioned' },
                        ]}
                    />

                    <FilterSelect
                        label="Retention Band"
                        value={retentionBandFilter}
                        onChange={(event) => { setRetentionBandFilter(event.target.value); setPage(1); }}
                        options={[
                            { value: '', label: 'All bands' },
                            ...RETENTION_BANDS.map((band) => ({ value: band, label: band })),
                        ]}
                    />

                    <FilterSelect
                        label="Behavior Tag"
                        value={behaviorTagFilter}
                        onChange={(event) => { setBehaviorTagFilter(event.target.value); setPage(1); }}
                        options={[
                            { value: '', label: 'All behaviors' },
                            ...RETENTION_BEHAVIOR_TAGS.map((tag) => ({ value: tag, label: tag })),
                        ]}
                    />

                    {hasActiveFilters ? (
                        <button
                            type="button"
                            onClick={() => {
                                setSearch('');
                                setSearchInput('');
                                setStatusFilter('');
                                setPlanFilter('');
                                setVerifiedFilter('');
                                setHighRiskFilter('');
                                setHasChatFilter('');
                                setOnlineFilter('');
                                setPlatformFilter('');
                                setCityFilter('');
                                setSignupSourceFilter('');
                                setRetentionBandFilter('');
                                setBehaviorTagFilter('');
                                setNewUsersFilter('');
                                setCreatedFrom('');
                                setCreatedTo('');
                                setSortOption(DEFAULT_SORT_OPTION);
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

            {searchResolutionNotice ? (
                <section className={`rounded-lg border px-4 py-3 text-sm ${
                    searchResolutionNotice.tone === 'success'
                        ? 'border-emerald-200 bg-emerald-50 text-emerald-800'
                        : searchResolutionNotice.tone === 'warning'
                            ? 'border-amber-200 bg-amber-50 text-amber-800'
                            : 'border-sky-200 bg-sky-50 text-sky-800'
                }`}>
                    <p className="font-semibold">{searchResolutionNotice.title}</p>
                    <p className="mt-1 text-xs opacity-80">{searchResolutionNotice.message}</p>
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
                selectable={canSelectClients}
                bulkActions={bulkActions}
                clearSelectionKey={clearSelectionKey}
                perPage={perPage}
                onPerPageChange={(n) => { setPerPage(n); setPage(1); }}
            />

            {canDeleteClients ? (
                <BulkDeleteClientsDialog
                    open={bulkDeleteDialog.open}
                    mode={bulkDeleteDialog.mode}
                    platformOptions={platformOptions}
                    selectedCount={bulkDeleteDialog.selectedClients.length}
                    filters={bulkDeleteDialog.filters}
                    preview={bulkDeleteDialog.preview}
                    confirmText={bulkDeleteDialog.confirmText}
                    reason={bulkDeleteDialog.reason}
                    previewPending={bulkDeletePreviewMutation.isPending}
                    deletePending={bulkDeleteMutation.isPending}
                    onCancel={closeBulkDeleteDialog}
                    onFiltersChange={(updater) => {
                        setBulkDeleteDialog((current) => ({
                            ...current,
                            filters: typeof updater === 'function' ? updater(current.filters) : updater,
                            preview: null,
                        }));
                    }}
                    onConfirmTextChange={(value) => {
                        setBulkDeleteDialog((current) => ({ ...current, confirmText: value }));
                    }}
                    onReasonChange={(value) => {
                        setBulkDeleteDialog((current) => ({ ...current, reason: value }));
                    }}
                    onPreview={() => previewBulkDelete(bulkDeleteDialog)}
                    onConfirm={() => {
                        const clientIds = (bulkDeleteDialog.preview?.clients || [])
                            .map((clientRow) => Number(clientRow.client_id))
                            .filter((clientId) => clientId > 0);

                        bulkDeleteMutation.mutate({
                            clientIds,
                            reason: bulkDeleteDialog.reason.trim() || 'Bulk client deletion from clients page',
                        });
                    }}
                />
            ) : null}

            <ConfirmDialog
                open={showBulkThumbnailRefreshConfirm}
                title="Refresh Client Thumbnails"
                message="This refreshes the cached CRM thumbnail from WordPress media for the selected profiles. It does not overwrite the rest of the client profile."
                confirmLabel={`Refresh ${bulkThumbnailRefreshSelection.length || 0} thumbnail${bulkThumbnailRefreshSelection.length === 1 ? '' : 's'}`}
                onCancel={() => {
                    if (bulkThumbnailRefreshMutation.isPending) {
                        return;
                    }

                    setShowBulkThumbnailRefreshConfirm(false);
                    setBulkThumbnailRefreshSelection([]);
                }}
                onConfirm={() => {
                    const clientIds = bulkThumbnailRefreshSelection
                        .map((client) => Number(client.id))
                        .filter((clientId) => clientId > 0);

                    bulkThumbnailRefreshMutation.mutate(clientIds);
                }}
                confirmDisabled={!bulkThumbnailRefreshSelection.length || bulkThumbnailRefreshMutation.isPending}
                isPending={bulkThumbnailRefreshMutation.isPending}
            >
                <div className="rounded-md border border-slate-200 bg-slate-50 px-3 py-2 text-sm text-slate-600">
                    {bulkThumbnailRefreshSelection.length.toLocaleString()} selected client{bulkThumbnailRefreshSelection.length === 1 ? '' : 's'} will have their cached thumbnails refreshed.
                </div>
            </ConfirmDialog>

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
                    <div className="flex w-full max-w-2xl flex-col rounded-lg border border-slate-200 bg-white shadow-xl max-h-[90vh]" onClick={(event) => event.stopPropagation()}>
                        <header className="crm-panel-header shrink-0">
                            <div>
                                <h3 className="crm-panel-title">Add Client</h3>
                                <p className="crm-panel-subtitle">
                                    {createForm.onboarding_mode === 'wp_provision'
                                        ? 'Provision a real WordPress profile and link it to CRM in one flow.'
                                        : 'Create a manual CRM client record for outreach and deal tracking.'}
                                </p>
                            </div>
                        </header>

                        <div className="min-h-0 flex-1 overflow-y-auto">
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
                                <select
                                    id="client-city"
                                    value={createForm.city}
                                    onChange={(event) => setCreateForm((current) => ({ ...current, city: event.target.value }))}
                                    className="crm-input"
                                >
                                    <option value="">Select city</option>
                                    {createModalCities.map((city) => (
                                        <option key={city} value={city}>{city}</option>
                                    ))}
                                </select>
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
                        </div>

                        <footer className="flex shrink-0 items-center justify-end gap-2 border-t border-slate-100 p-4">
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

function BulkDeleteClientsDialog({
    open,
    mode,
    platformOptions,
    selectedCount,
    filters,
    preview,
    confirmText,
    reason,
    previewPending,
    deletePending,
    onCancel,
    onFiltersChange,
    onConfirmTextChange,
    onReasonChange,
    onPreview,
    onConfirm,
}) {
    if (!open) {
        return null;
    }

    const confirmDisabled = previewPending
        || deletePending
        || !preview
        || Number(preview.total_count || 0) === 0
        || Boolean(preview.capped)
        || confirmText.trim() !== 'DELETE'
        || !reason.trim();

    return (
        <ConfirmDialog
            open={open}
            title={mode === 'smart' ? 'Smart Delete Clients' : 'Delete Selected Clients'}
            message={mode === 'smart'
                ? 'Preview a filtered deletion batch first. Deletion is disabled if the preview is capped above 500 matches.'
                : 'Review the deletion impact for the selected clients before removing them from CRM.'}
            confirmLabel={deletePending ? 'Deleting...' : 'Delete clients'}
            tone="danger"
            onCancel={onCancel}
            onConfirm={onConfirm}
            confirmDisabled={confirmDisabled}
            isPending={deletePending}
        >
            <div className="space-y-4">
                {mode === 'smart' ? (
                    <div className="grid gap-3 md:grid-cols-2">
                        <label className="block">
                            <span className="mb-1 block text-xs font-semibold uppercase tracking-[0.08em] text-slate-500">
                                Market
                            </span>
                            <select
                                value={filters.platform_id}
                                onChange={(event) => onFiltersChange((current) => ({
                                    ...current,
                                    platform_id: event.target.value,
                                }))}
                                className="crm-select w-full"
                            >
                                <option value="">All accessible markets</option>
                                {platformOptions.map((platform) => (
                                    <option key={platform.platform_id} value={platform.platform_id}>
                                        {platform.platform_name}
                                    </option>
                                ))}
                            </select>
                        </label>

                        <label className="block">
                            <span className="mb-1 block text-xs font-semibold uppercase tracking-[0.08em] text-slate-500">
                                Inactive for
                            </span>
                            <select
                                value={filters.inactive_days}
                                onChange={(event) => onFiltersChange((current) => ({
                                    ...current,
                                    inactive_days: event.target.value,
                                }))}
                                className="crm-select w-full"
                            >
                                {SMART_DELETE_DAY_OPTIONS.map((days) => (
                                    <option key={days} value={days}>{days} days or longer</option>
                                ))}
                            </select>
                        </label>

                        <label className="flex items-start gap-2 rounded-md border border-slate-200 bg-slate-50 px-3 py-2 text-sm text-slate-700">
                            <input
                                type="checkbox"
                                checked={filters.has_no_chat}
                                onChange={(event) => onFiltersChange((current) => ({
                                    ...current,
                                    has_no_chat: event.target.checked,
                                }))}
                                className="mt-0.5 h-4 w-4 rounded border-slate-300 text-teal-700 focus:ring-teal-200"
                            />
                            <span>No support chat match</span>
                        </label>

                        <label className="flex items-start gap-2 rounded-md border border-slate-200 bg-slate-50 px-3 py-2 text-sm text-slate-700">
                            <input
                                type="checkbox"
                                checked={filters.has_no_subscription_or_payment}
                                onChange={(event) => onFiltersChange((current) => ({
                                    ...current,
                                    has_no_subscription_or_payment: event.target.checked,
                                }))}
                                className="mt-0.5 h-4 w-4 rounded border-slate-300 text-teal-700 focus:ring-teal-200"
                            />
                            <span>No subscriptions and no payments</span>
                        </label>
                    </div>
                ) : (
                    <div className="rounded-md border border-slate-200 bg-slate-50 px-3 py-2 text-sm text-slate-700">
                        {selectedCount.toLocaleString()} selected client{selectedCount === 1 ? '' : 's'} will be previewed for deletion.
                    </div>
                )}

                <div className="flex items-center justify-between gap-3 rounded-md border border-slate-200 bg-white px-3 py-2">
                    <div>
                        <p className="text-xs font-semibold uppercase tracking-[0.08em] text-slate-500">Preview</p>
                        <p className="text-sm text-slate-700">
                            {preview
                                ? `${Number(preview.total_count || 0).toLocaleString()} matching client${Number(preview.total_count || 0) === 1 ? '' : 's'}`
                                : 'Run preview to load the deletion impact.'}
                        </p>
                    </div>
                    <button
                        type="button"
                        onClick={onPreview}
                        disabled={previewPending}
                        className="crm-btn-secondary disabled:cursor-not-allowed disabled:opacity-50"
                    >
                        {previewPending ? 'Loading preview...' : 'Preview matches'}
                    </button>
                </div>

                {preview?.capped ? (
                    <div className="rounded-md border border-amber-200 bg-amber-50 px-3 py-2 text-xs text-amber-800">
                        More than 500 clients matched this preview. Narrow the filters before deletion can continue.
                    </div>
                ) : null}

                {preview ? (
                    <div className="space-y-3">
                        <div className="grid gap-2 sm:grid-cols-4">
                            <ImpactPill label="Clients" value={Number(preview.total_count || 0).toLocaleString()} />
                            <ImpactPill label="Active deals" value={(preview.clients || []).filter((clientRow) => clientRow.has_active_deal).length.toLocaleString()} />
                            <ImpactPill label="Payments" value={(preview.clients || []).reduce((sum, clientRow) => sum + Number(clientRow.payments_count || 0), 0).toLocaleString()} />
                            <ImpactPill label="Notes" value={(preview.clients || []).reduce((sum, clientRow) => sum + Number(clientRow.notes_count || 0), 0).toLocaleString()} />
                        </div>

                        <div className="max-h-56 space-y-2 overflow-auto rounded-md border border-slate-200 bg-slate-50 p-3">
                            {(preview.clients || []).length ? (
                                preview.clients.map((clientRow) => (
                                    <div key={clientRow.client_id} className="rounded-md border border-slate-200 bg-white px-3 py-2">
                                        <div className="flex items-start justify-between gap-3">
                                            <div>
                                                <p className="text-sm font-semibold text-slate-900">{clientRow.name || `Client #${clientRow.client_id}`}</p>
                                                <p className="text-xs text-slate-500">
                                                    CRM #{clientRow.client_id} • {clientRow.platform_name || 'Unknown market'}
                                                </p>
                                            </div>
                                            {clientRow.has_active_deal ? (
                                                <span className="inline-flex items-center rounded-md bg-amber-50 px-2 py-0.5 text-[11px] font-semibold text-amber-800 ring-1 ring-inset ring-amber-200">
                                                    Active deal
                                                </span>
                                            ) : null}
                                        </div>
                                        <p className="mt-2 text-[11px] text-slate-600">
                                            Deals {clientRow.deals_count} • Payments {clientRow.payments_count} • Notes {clientRow.notes_count} • Leads {clientRow.leads_count}
                                        </p>
                                    </div>
                                ))
                            ) : (
                                <p className="text-sm text-slate-500">No clients matched this preview.</p>
                            )}
                        </div>
                    </div>
                ) : null}

                <label className="block">
                    <span className="mb-1 block text-xs font-semibold uppercase tracking-[0.08em] text-slate-500">
                        Reason
                    </span>
                    <textarea
                        value={reason}
                        onChange={(event) => onReasonChange(event.target.value)}
                        rows={3}
                        className="crm-input min-h-[96px] w-full"
                        placeholder="Why are these clients being deleted?"
                    />
                </label>

                <label className="block">
                    <span className="mb-1 block text-xs font-semibold uppercase tracking-[0.08em] text-slate-500">
                        Type DELETE to confirm
                    </span>
                    <input
                        type="text"
                        value={confirmText}
                        onChange={(event) => onConfirmTextChange(event.target.value)}
                        className="crm-input"
                        placeholder="DELETE"
                    />
                </label>
            </div>
        </ConfirmDialog>
    );
}

function ImpactPill({ label, value }) {
    return (
        <div className="rounded-md border border-slate-200 bg-slate-50 px-3 py-2">
            <p className="text-[11px] font-semibold uppercase tracking-[0.08em] text-slate-500">{label}</p>
            <p className="mt-1 text-sm font-semibold text-slate-900">{value}</p>
        </div>
    );
}
