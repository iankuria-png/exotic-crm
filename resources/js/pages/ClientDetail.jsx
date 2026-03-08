import React, { useEffect, useMemo, useState } from 'react';
import { useParams, useNavigate, useSearchParams } from 'react-router-dom';
import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query';
import api from '../services/api';
import StatusBadge from '../components/StatusBadge';
import Timeline from '../components/Timeline';
import ConfirmDialog from '../components/ConfirmDialog';
import CredentialDispatchDrawer from '../components/CredentialDispatchDrawer';
import { useToast } from '../components/ToastProvider';

function formatCurrency(value, currency = 'KES') {
    return `${currency} ${Number(value || 0).toLocaleString()}`;
}

function formatDateTime(value) {
    if (!value) return '—';
    const date = new Date(value);
    if (Number.isNaN(date.getTime())) return '—';
    return date.toLocaleString();
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

const DEFAULT_SUPPORT_CHAT_URL = 'https://chat.cloud.board.support/1369683147';
const FOREVER_PLAN_TOOLTIP = 'Reference: This profile is intentionally kept active to avoid zero-escort locations, which protects search ranking.';

const PROFILE_ENUM_CHOICES = {
    gender: [
        { code: '1', label: 'Female' },
        { code: '2', label: 'Male' },
        { code: '3', label: 'Couple' },
        { code: '4', label: 'Gay' },
        { code: '5', label: 'Transsexual' },
    ],
    ethnicity: [
        { code: '1', label: 'Latin' },
        { code: '2', label: 'Caucasian' },
        { code: '3', label: 'Black' },
        { code: '4', label: 'White' },
        { code: '5', label: 'MiddleEast' },
        { code: '6', label: 'Asian' },
        { code: '7', label: 'Indian' },
        { code: '8', label: 'Aborigine' },
        { code: '9', label: 'Native American' },
        { code: '10', label: 'Other' },
    ],
    build: [
        { code: '1', label: 'Skinny' },
        { code: '2', label: 'Slim' },
        { code: '3', label: 'Regular' },
        { code: '4', label: 'Curvy' },
        { code: '5', label: 'Fat' },
    ],
    services: [
        { code: '1', label: 'BDSM' },
        { code: '2', label: 'Couples' },
        { code: '3', label: 'Domination' },
        { code: '4', label: 'Escort' },
        { code: '5', label: 'Massage' },
        { code: '6', label: 'Fetish' },
        { code: '7', label: 'Mature' },
        { code: '8', label: 'GFE' },
    ],
};

const LEGACY_HEIGHT_CODE_TO_CM = {
    1: '128',
    2: '134',
    3: '140',
    4: '146',
    5: '152',
    6: '155',
    7: '158',
    8: '162',
    9: '165',
    10: '168',
    11: '171',
    12: '174',
    13: '177',
    14: '180',
    15: '183',
    16: '189',
    17: '195',
    18: '201',
    19: '207',
    20: '213',
};

const PROFILE_ENUM_OPTIONS = Object.fromEntries(
    Object.entries(PROFILE_ENUM_CHOICES).map(([field, options]) => [
        field,
        options.map((option) => ({
            value: option.code,
            plainLabel: option.label,
            label: `${option.label} (${option.code})`,
        })),
    ]),
);

const PROFILE_ENUM_LOOKUP = Object.fromEntries(
    Object.entries(PROFILE_ENUM_OPTIONS).map(([field, options]) => {
        const byCode = new Map();
        const byLabel = new Map();

        options.forEach((option) => {
            byCode.set(option.value, option.value);
            byLabel.set(normalizeLookupToken(option.plainLabel), option.value);
            byLabel.set(normalizeLookupToken(option.label), option.value);
            byLabel.set(normalizeLookupToken(`${option.value}`), option.value);
            byLabel.set(normalizeLookupToken(`${option.plainLabel} ${option.value}`), option.value);
            byLabel.set(normalizeLookupToken(`${option.value} ${option.plainLabel}`), option.value);
        });

        return [field, { byCode, byLabel }];
    }),
);

function normalizeLookupToken(value) {
    const normalized = String(value || '')
        .toLowerCase()
        .replace(/[^a-z0-9]+/g, ' ')
        .trim();

    return normalized;
}

function resolveProfileEnumValue(field, value) {
    const raw = String(value ?? '').trim();
    if (!raw) return '';

    const lookup = PROFILE_ENUM_LOOKUP[field];
    if (!lookup) return raw;

    if (lookup.byCode.has(raw)) {
        return lookup.byCode.get(raw);
    }

    const numericCode = raw.replace(/[^0-9]/g, '');
    if (numericCode) {
        const normalizedCode = String(Number.parseInt(numericCode, 10));
        if (lookup.byCode.has(normalizedCode)) {
            return normalizedCode;
        }
    }

    const token = normalizeLookupToken(raw);
    if (lookup.byLabel.has(token)) {
        return lookup.byLabel.get(token);
    }

    return raw;
}

function isKnownProfileEnumCode(field, value) {
    const raw = String(value ?? '').trim();
    if (!raw) return true;

    const lookup = PROFILE_ENUM_LOOKUP[field];
    if (!lookup) return true;

    return lookup.byCode.has(raw);
}

function parseProfileServices(value) {
    const tokens = Array.isArray(value)
        ? value
        : String(value ?? '')
            .split(',')
            .map((item) => item.trim());

    const normalized = [];
    tokens.forEach((token) => {
        const raw = String(token ?? '').trim();
        if (!raw) return;

        const resolved = resolveProfileEnumValue('services', raw);
        if (!normalized.includes(resolved)) {
            normalized.push(resolved);
        }
    });

    return normalized;
}

function toDateInputValue(year, month, day) {
    const y = Number.parseInt(year, 10);
    const m = Number.parseInt(month, 10);
    const d = Number.parseInt(day, 10);
    if (!Number.isInteger(y) || !Number.isInteger(m) || !Number.isInteger(d)) return '';
    if (m < 1 || m > 12 || d < 1 || d > 31) return '';

    const paddedMonth = String(m).padStart(2, '0');
    const paddedDay = String(d).padStart(2, '0');
    return `${y}-${paddedMonth}-${paddedDay}`;
}

function normalizeBirthdayForEditor(value) {
    const raw = String(value ?? '').trim();
    if (!raw) return '';

    const ymdMatch = raw.match(/^(\d{4})[-/](\d{1,2})[-/](\d{1,2})$/);
    if (ymdMatch) {
        return toDateInputValue(ymdMatch[1], ymdMatch[2], ymdMatch[3]);
    }

    const dmyMatch = raw.match(/^(\d{1,2})[-/](\d{1,2})[-/](\d{4})$/);
    if (dmyMatch) {
        // Stored legacy format may be dd/mm/yyyy or mm/dd/yyyy; date parser resolves valid local date.
        const parsed = new Date(raw);
        if (!Number.isNaN(parsed.getTime())) {
            return toDateInputValue(parsed.getFullYear(), parsed.getMonth() + 1, parsed.getDate());
        }

        return toDateInputValue(dmyMatch[3], dmyMatch[2], dmyMatch[1]);
    }

    if (/^\d{10,13}$/.test(raw)) {
        const numeric = Number.parseInt(raw, 10);
        const millis = raw.length === 13 ? numeric : numeric * 1000;
        const parsed = new Date(millis);
        if (!Number.isNaN(parsed.getTime())) {
            return toDateInputValue(parsed.getFullYear(), parsed.getMonth() + 1, parsed.getDate());
        }
    }

    const parsed = new Date(raw);
    if (Number.isNaN(parsed.getTime())) {
        return '';
    }

    return toDateInputValue(parsed.getFullYear(), parsed.getMonth() + 1, parsed.getDate());
}

function normalizeBirthdayForSave(value) {
    const normalized = normalizeBirthdayForEditor(value);
    return normalized || null;
}

function normalizeHeightForEditor(value) {
    const raw = String(value ?? '').trim();
    if (!raw) return '';
    if (LEGACY_HEIGHT_CODE_TO_CM[raw]) return LEGACY_HEIGHT_CODE_TO_CM[raw];

    const cmInParens = raw.match(/\((\d+(?:\.\d+)?)\)/);
    if (cmInParens) {
        return String(Math.round(Number.parseFloat(cmInParens[1])));
    }

    const explicitCm = raw.match(/(\d+(?:\.\d+)?)\s*cm/i);
    if (explicitCm) {
        return String(Math.round(Number.parseFloat(explicitCm[1])));
    }

    const feetInches = raw.match(/(\d+)\s*(?:ft|')\s*(\d+)?/i);
    if (feetInches) {
        const feet = Number.parseInt(feetInches[1], 10);
        const inches = Number.parseInt(feetInches[2] || '0', 10);
        if (Number.isFinite(feet) && Number.isFinite(inches)) {
            return String(Math.round((feet * 12 + inches) * 2.54));
        }
    }

    const numeric = raw.match(/^\d+(?:\.\d+)?$/);
    if (numeric) {
        return String(Math.round(Number.parseFloat(raw)));
    }

    return raw;
}

function normalizeHeightForSave(value) {
    const normalized = normalizeHeightForEditor(value);
    if (!normalized) return null;
    return normalized;
}

function ProfileInfoCard({ title, children }) {
    return (
        <section className="crm-surface p-5">
            <h3 className="text-xs font-semibold uppercase tracking-[0.12em] text-slate-500">{title}</h3>
            <div className="mt-3">{children}</div>
        </section>
    );
}

function DefinitionRow({ label, value, mono = false }) {
    return (
        <div className="flex items-start justify-between gap-3 text-sm">
            <dt className="text-slate-500">{label}</dt>
            <dd className={`text-right font-medium text-slate-900 ${mono ? 'crm-mono text-xs' : ''}`}>{value}</dd>
        </div>
    );
}

export default function ClientDetail() {
    const { id } = useParams();
    const navigate = useNavigate();
    const [searchParams, setSearchParams] = useSearchParams();
    const queryClient = useQueryClient();
    const toast = useToast();
    const requestedTab = (searchParams.get('tab') || '').toLowerCase();
    const initialTab = ['overview', 'deals', 'notes', 'timeline', 'wallet', 'payments', 'edit_profile', 'profile_health']
        .includes(requestedTab)
        ? requestedTab
        : 'overview';
    const [activeTab, setActiveTab] = useState(initialTab);
    const [noteForm, setNoteForm] = useState({ note_type: 'internal', content: '', follow_up_at: '' });
    const [showDealModal, setShowDealModal] = useState(false);
    const [activationDialog, setActivationDialog] = useState({
        open: false,
        dealId: null,
        dealLabel: '',
    });
    const [activationReason, setActivationReason] = useState('Activation initiated from client profile');
    const [activationPaymentMethod, setActivationPaymentMethod] = useState('manual');
    const [activationPaymentReference, setActivationPaymentReference] = useState('');
    const [activationApprovedBy, setActivationApprovedBy] = useState('');
    const [showSyncConfirm, setShowSyncConfirm] = useState(false);
    const [profileSection, setProfileSection] = useState('personal');
    const [profileForm, setProfileForm] = useState(null);
    const [profileReason, setProfileReason] = useState('Profile edited from CRM');
    const [profileForce, setProfileForce] = useState(false);
    const [profileConflict, setProfileConflict] = useState(null);
    const [mediaUploadFile, setMediaUploadFile] = useState(null);
    const [mediaUploadSetMain, setMediaUploadSetMain] = useState(false);
    const [healthAction, setHealthAction] = useState('keep_primary');
    const [healthReason, setHealthReason] = useState('Duplicate resolution from CRM');
    const [selectedDuplicateIds, setSelectedDuplicateIds] = useState([]);
    const [updatePhoneTargetId, setUpdatePhoneTargetId] = useState('');
    const [updatePhoneValue, setUpdatePhoneValue] = useState('');
    const [showCredentialDrawer, setShowCredentialDrawer] = useState(false);
    const [walletTopupForm, setWalletTopupForm] = useState({
        amount: '',
        pin: '',
        reason: 'Manual wallet top-up from client profile',
    });
    const [walletAdjustmentForm, setWalletAdjustmentForm] = useState({
        type: 'debit',
        amount: '',
        pin: '',
        reason: 'Wallet adjustment from client profile',
    });

    const { data: client, isLoading } = useQuery({
        queryKey: ['client', id],
        queryFn: () => api.get(`/crm/clients/${id}`).then((r) => r.data),
    });
    const platformPhonePrefix = client?.platform?.phone_prefix || '254';
    const clientPlatformId = Number(client?.platform_id || client?.platform?.id || 0);

    const { data: meData } = useQuery({
        queryKey: ['me'],
        queryFn: () => api.get('/crm/me').then((response) => response.data),
    });
    const currentUser = meData?.user || null;
    const isReadOnly = currentUser?.role === 'marketing';
    const canManageWallet = ['admin', 'sub_admin', 'sales'].includes(String(currentUser?.role || ''));

    const { data: timelineData } = useQuery({
        queryKey: ['client-timeline', id],
        queryFn: () => api.get(`/crm/clients/${id}/timeline`).then((r) => r.data),
        enabled: activeTab === 'timeline',
    });

    const { data: products } = useQuery({
        queryKey: ['products', clientPlatformId],
        queryFn: () => api.get('/crm/products', { params: { platform_id: clientPlatformId } }).then((r) => r.data),
        enabled: clientPlatformId > 0,
    });

    const { data: wpProfileData } = useQuery({
        queryKey: ['client-wp-profile', id],
        queryFn: () => api.get(`/crm/clients/${id}/wp-profile`).then((r) => r.data),
        enabled: activeTab === 'edit_profile' && Number(client?.wp_post_id || 0) > 0,
    });

    const { data: mediaData, isLoading: mediaLoading } = useQuery({
        queryKey: ['client-media', id],
        queryFn: () => api.get(`/crm/clients/${id}/media`).then((r) => r.data),
        enabled: activeTab === 'edit_profile' && profileSection === 'media' && Number(client?.wp_post_id || 0) > 0,
    });

    const { data: healthData, isLoading: healthLoading } = useQuery({
        queryKey: ['client-health', id],
        queryFn: () => api.get(`/crm/clients/${id}/health`).then((r) => r.data),
        enabled: activeTab === 'profile_health',
    });

    const {
        data: walletData,
        isLoading: walletLoading,
        refetch: refetchWallet,
        isFetching: walletFetching,
    } = useQuery({
        queryKey: ['client-wallet', id],
        queryFn: () => api.get(`/crm/clients/${id}/wallet`).then((r) => r.data),
        enabled: activeTab === 'wallet',
    });

    const addNoteMutation = useMutation({
        mutationFn: (note) =>
            api.post(`/crm/clients/${id}/notes`, {
                ...note,
                follow_up_at: note.follow_up_at || null,
            }).then((r) => r.data),
        onSuccess: () => {
            queryClient.invalidateQueries({ queryKey: ['client', id] });
            queryClient.invalidateQueries({ queryKey: ['client-timeline', id] });
            setNoteForm({ note_type: 'internal', content: '', follow_up_at: '' });
            toast.success('Note added.');
        },
        onError: (error) => {
            toast.error(error?.response?.data?.message || 'Failed to add note.');
        },
    });

    const createDealMutation = useMutation({
        mutationFn: (deal) =>
            api.post('/crm/deals', {
                ...deal,
                product_id: Number(deal.product_id),
                product_price_id: deal.product_price_id ? Number(deal.product_price_id) : undefined,
            }).then((r) => r.data),
        onSuccess: () => {
            queryClient.invalidateQueries({ queryKey: ['client', id] });
            queryClient.invalidateQueries({ queryKey: ['client-timeline', id] });
            setShowDealModal(false);
            toast.success('Subscription created for client.');
        },
        onError: (error) => {
            toast.error(error?.response?.data?.message || 'Subscription creation failed.');
        },
    });

    const activateDealMutation = useMutation({
        mutationFn: ({ dealId, reason, paymentMethod, paymentReference, approvedBy }) =>
            api.post(`/crm/deals/${dealId}/activate`, {
                reason,
                payment_method: paymentMethod,
                ...(paymentMethod === 'manual' ? { payment_reference: paymentReference } : {}),
                ...(paymentMethod === 'free_trial' ? { approved_by: approvedBy } : {}),
            }).then((r) => r.data),
        onSuccess: (payload) => {
            queryClient.invalidateQueries({ queryKey: ['client', id] });
            queryClient.invalidateQueries({ queryKey: ['client-timeline', id] });
            setActivationDialog({ open: false, dealId: null, dealLabel: '' });
            setActivationReason('Activation initiated from client profile');
            setActivationPaymentMethod('manual');
            setActivationPaymentReference('');
            setActivationApprovedBy('');
            toast.success(payload?.message || 'Subscription activation request submitted.');
        },
        onError: (error) => {
            toast.error(error?.response?.data?.message || 'Subscription activation failed.');
        },
    });

    const syncMutation = useMutation({
        mutationFn: () => api.post(`/crm/clients/${id}/sync`).then((r) => r.data),
        onSuccess: () => {
            queryClient.invalidateQueries({ queryKey: ['client', id] });
            queryClient.invalidateQueries({ queryKey: ['client-wp-profile', id] });
            queryClient.invalidateQueries({ queryKey: ['client-media', id] });
            toast.success('Client profile synced from WordPress.');
            setShowSyncConfirm(false);
        },
        onError: (error) => {
            toast.error(error?.response?.data?.message || 'WordPress sync failed.');
            setShowSyncConfirm(false);
        },
    });

    const updateProfileMutation = useMutation({
        mutationFn: ({ fields, force }) =>
            api.patch(`/crm/clients/${id}/wp-profile`, {
                fields,
                force,
                reason: profileReason,
            }).then((response) => response.data),
        onSuccess: () => {
            queryClient.invalidateQueries({ queryKey: ['client', id] });
            queryClient.invalidateQueries({ queryKey: ['client-wp-profile', id] });
            setProfileConflict(null);
            setProfileForce(false);
            toast.success('Profile synced to WordPress successfully.');
        },
        onError: (error) => {
            if (error?.response?.status === 409) {
                setProfileConflict(error.response.data?.conflict || null);
                toast.warning('WordPress profile changed since last sync. Review conflict and force save if needed.');
                return;
            }
            toast.error(error?.response?.data?.message || 'Profile update failed.');
        },
    });

    const uploadMediaMutation = useMutation({
        mutationFn: ({ file, setMain }) => {
            const formData = new FormData();
            formData.append('file', file);
            formData.append('set_main', setMain ? '1' : '0');
            formData.append('reason', 'Uploaded media from client detail');
            return api.post(`/crm/clients/${id}/media`, formData, {
                headers: {
                    'Content-Type': 'multipart/form-data',
                },
            }).then((response) => response.data);
        },
        onSuccess: () => {
            queryClient.invalidateQueries({ queryKey: ['client', id] });
            queryClient.invalidateQueries({ queryKey: ['client-media', id] });
            setMediaUploadFile(null);
            setMediaUploadSetMain(false);
            toast.success('Image uploaded to WordPress.');
        },
        onError: (error) => {
            toast.error(error?.response?.data?.message || 'Media upload failed.');
        },
    });

    const deleteMediaMutation = useMutation({
        mutationFn: (attachmentId) =>
            api.delete(`/crm/clients/${id}/media/${attachmentId}`, {
                data: { reason: 'Deleted media from client detail' },
            }).then((response) => response.data),
        onSuccess: () => {
            queryClient.invalidateQueries({ queryKey: ['client', id] });
            queryClient.invalidateQueries({ queryKey: ['client-media', id] });
            toast.success('Image deleted from WordPress.');
        },
        onError: (error) => {
            toast.error(error?.response?.data?.message || 'Media delete failed.');
        },
    });

    const setMainMediaMutation = useMutation({
        mutationFn: (attachmentId) =>
            api.patch(`/crm/clients/${id}/media/${attachmentId}/set-main`, {
                reason: 'Set main image from client detail',
            }).then((response) => response.data),
        onSuccess: () => {
            queryClient.invalidateQueries({ queryKey: ['client', id] });
            queryClient.invalidateQueries({ queryKey: ['client-media', id] });
            toast.success('Main image updated.');
        },
        onError: (error) => {
            toast.error(error?.response?.data?.message || 'Setting main image failed.');
        },
    });

    const resolveHealthMutation = useMutation({
        mutationFn: (payload) => api.post(`/crm/clients/${id}/health/resolve`, payload).then((response) => response.data),
        onSuccess: () => {
            queryClient.invalidateQueries({ queryKey: ['client', id] });
            queryClient.invalidateQueries({ queryKey: ['client-health', id] });
            setSelectedDuplicateIds([]);
            setUpdatePhoneTargetId('');
            setUpdatePhoneValue('');
            toast.success('Profile health resolution applied.');
        },
        onError: (error) => {
            toast.error(error?.response?.data?.message || 'Profile health resolution failed.');
        },
    });

    const walletTopupMutation = useMutation({
        mutationFn: (payload) => api.post(`/crm/clients/${id}/wallet/topup`, payload).then((response) => response.data),
        onSuccess: () => {
            queryClient.invalidateQueries({ queryKey: ['client-wallet', id] });
            queryClient.invalidateQueries({ queryKey: ['client', id] });
            setWalletTopupForm({
                amount: '',
                pin: '',
                reason: 'Manual wallet top-up from client profile',
            });
            toast.success('Wallet top-up recorded.');
        },
        onError: (error) => {
            toast.error(error?.response?.data?.message || 'Wallet top-up failed.');
        },
    });

    const walletAdjustmentMutation = useMutation({
        mutationFn: (payload) => api.post(`/crm/clients/${id}/wallet/adjustment`, payload).then((response) => response.data),
        onSuccess: () => {
            queryClient.invalidateQueries({ queryKey: ['client-wallet', id] });
            queryClient.invalidateQueries({ queryKey: ['client', id] });
            setWalletAdjustmentForm((current) => ({
                ...current,
                amount: '',
                pin: '',
                reason: 'Wallet adjustment from client profile',
            }));
            toast.success('Wallet adjustment recorded.');
        },
        onError: (error) => {
            toast.error(error?.response?.data?.message || 'Wallet adjustment failed.');
        },
    });

    const tabLinks = useMemo(() => {
        const links = [
            { key: 'overview', label: 'Overview' },
            { key: 'deals', label: `Subscriptions (${client?.deals?.length || 0})` },
            { key: 'notes', label: `Notes (${client?.notes?.length || 0})` },
            { key: 'timeline', label: 'Timeline' },
            { key: 'wallet', label: 'Wallet' },
            { key: 'payments', label: `Payments (${client?.payments?.length || 0})` },
            { key: 'edit_profile', label: 'Edit Profile' },
            { key: 'profile_health', label: `Profile Health (${healthData?.summary?.duplicate_count || 0})` },
        ];

        if (!isReadOnly) {
            return links;
        }

        return links.filter((tab) => !['edit_profile', 'profile_health'].includes(tab.key));
    }, [client, healthData?.summary?.duplicate_count, isReadOnly]);

    useEffect(() => {
        const allowedTabs = tabLinks.map((tab) => tab.key);
        const nextTab = allowedTabs.includes(requestedTab) ? requestedTab : 'overview';
        if (nextTab !== activeTab) {
            setActiveTab(nextTab);
        }
    }, [activeTab, requestedTab, tabLinks]);

    useEffect(() => {
        if (isReadOnly) {
            return;
        }

        const requestedAction = (searchParams.get('action') || '').toLowerCase();
        if (requestedAction !== 'new_subscription') {
            return;
        }

        setActiveTab('deals');
        setShowDealModal(true);

        const next = new URLSearchParams(searchParams);
        next.set('tab', 'deals');
        next.delete('action');
        setSearchParams(next, { replace: true });
    }, [isReadOnly, searchParams, setSearchParams]);

    useEffect(() => {
        if (!wpProfileData?.wp_profile) {
            return;
        }

        const profile = wpProfileData.wp_profile;
        const meta = profile.meta || {};
        const cityName = profile?.taxonomies?.city?.name || profile.city || '';

        setProfileForm({
            name: profile.name || profile?.post?.title || '',
            phone: meta.phone || profile.phone || client?.phone_normalized || '',
            email: profile.email || client?.email || '',
            city: cityName || client?.city || '',
            birthday: normalizeBirthdayForEditor(meta.birthday),
            gender: resolveProfileEnumValue('gender', meta.gender),
            ethnicity: resolveProfileEnumValue('ethnicity', meta.ethnicity),
            height: normalizeHeightForEditor(meta.height),
            build: resolveProfileEnumValue('build', meta.build || meta.body_type),
            services: parseProfileServices(meta.services),
            rates_incall: meta.incall || meta.rate_incall || '',
            rates_outcall: meta.outcall || meta.rate_outcall || '',
            whatsapp: meta.whatsapp || meta.whatsapp_number || '',
            instagram: meta.instagram || meta.instagram_url || '',
            twitter: meta.twitter || meta.twitter_url || '',
            telegram: meta.telegram || '',
            website: meta.website || meta.website_url || '',
            bio: profile?.post?.content || meta.bio || '',
        });
    }, [wpProfileData?.wp_profile, client?.city, client?.email, client?.phone_normalized]);

    const profileSections = [
        { key: 'personal', label: 'Personal Info' },
        { key: 'services', label: 'Services & Rates' },
        { key: 'contact', label: 'Social & Contact' },
        { key: 'subscription', label: 'Subscription & Status' },
        { key: 'media', label: 'Media' },
    ];

    const serviceOptions = useMemo(() => {
        const selectedServices = Array.isArray(profileForm?.services) ? profileForm.services : [];
        const unknownOptions = selectedServices
            .map((code) => String(code || '').trim())
            .filter((code) => code && !isKnownProfileEnumCode('services', code))
            .map((code) => ({
                value: code,
                plainLabel: /^\d+$/.test(code) ? `Legacy service code` : 'Unknown service value',
                label: /^\d+$/.test(code) ? `Legacy service code (${code})` : `Unknown service value (${code})`,
            }));

        return [...PROFILE_ENUM_OPTIONS.services, ...unknownOptions];
    }, [profileForm?.services]);
    const selectedServiceCodes = Array.isArray(profileForm?.services)
        ? profileForm.services.map((value) => String(value || '').trim()).filter(Boolean)
        : [];

    if (isLoading) {
        return (
            <div className="flex h-64 items-center justify-center">
                <div className="h-8 w-8 animate-spin rounded-full border-4 border-teal-600 border-t-transparent" />
            </div>
        );
    }

    if (!client) {
        return <p className="py-12 text-center text-sm text-slate-500">Client not found.</p>;
    }

    const isExpired = client.escort_expire ? new Date(client.escort_expire * 1000) < new Date() : false;
    const isUntrackedForeverPlan = client.profile_status === 'publish'
        && Number(client.deals?.length || 0) === 0
        && !client.escort_expire
        && !client.premium_expire
        && !client.featured_expire;
    const activeSubscriptionLabel = client.active_deal
        ? (client.active_deal.product?.name || client.active_deal.plan_type)
        : (isUntrackedForeverPlan ? 'Forever plan' : 'None');
    const subscriptionExpiryLabel = client.escort_expire
        ? new Date(client.escort_expire * 1000).toLocaleDateString()
        : (isUntrackedForeverPlan ? 'Forever' : '—');
    const subscriptionExpiryDetailLabel = client.escort_expire
        ? new Date(client.escort_expire * 1000).toLocaleString()
        : (isUntrackedForeverPlan ? 'Forever' : '—');

    const canSyncFromWp = Number(client.wp_post_id || 0) > 0;
    const canDispatchCredentials = Number(client.wp_post_id || 0) > 0;
    const supportChatUrl = client?.platform?.support_chat_url || DEFAULT_SUPPORT_CHAT_URL;
    const mediaItems = mediaData?.data || [];
    const healthDuplicates = healthData?.duplicates || [];
    const walletSummary = walletData?.wallet || null;
    const walletTransactions = walletSummary?.transactions || [];
    const activationRequiresReference = activationPaymentMethod === 'manual';
    const activationRequiresApprovedBy = activationPaymentMethod === 'free_trial';
    const activationTargetPhone = client?.phone_normalized || '';

    const openActivationDialog = (deal) => {
        const dealLabel = deal?.product?.name || deal?.plan_type || 'Subscription';
        setActivationDialog({
            open: true,
            dealId: deal.id,
            dealLabel,
        });
        setActivationReason('Activation initiated from client profile');
        setActivationPaymentMethod('manual');
        setActivationPaymentReference('');
        setActivationApprovedBy(currentUser?.name || '');
    };

    const closeActivationDialog = () => {
        setActivationDialog({ open: false, dealId: null, dealLabel: '' });
        setActivationReason('Activation initiated from client profile');
        setActivationPaymentMethod('manual');
        setActivationPaymentReference('');
        setActivationApprovedBy('');
    };

    const submitActivation = () => {
        if (!activationDialog.dealId) {
            return;
        }

        if (activationRequiresReference && !activationPaymentReference.trim()) {
            toast.error('Transaction reference is required for manual activation.');
            return;
        }

        if (activationRequiresApprovedBy && !activationApprovedBy.trim()) {
            toast.error('Approver name is required for free trial activation.');
            return;
        }

        activateDealMutation.mutate({
            dealId: activationDialog.dealId,
            reason: activationReason.trim() || 'Activation initiated from client profile',
            paymentMethod: activationPaymentMethod,
            paymentReference: activationPaymentReference.trim(),
            approvedBy: activationApprovedBy.trim(),
        });
    };

    const activationSubmitDisabled = activateDealMutation.isPending
        || !activationDialog.dealId
        || (activationRequiresReference && !activationPaymentReference.trim())
        || (activationRequiresApprovedBy && !activationApprovedBy.trim());

    const submitProfileUpdate = () => {
        if (!profileForm) {
            return;
        }

        const normalizedGender = resolveProfileEnumValue('gender', profileForm.gender);
        const normalizedEthnicity = resolveProfileEnumValue('ethnicity', profileForm.ethnicity);
        const normalizedBuild = resolveProfileEnumValue('build', profileForm.build);
        const normalizedServices = parseProfileServices(profileForm.services)
            .map((value) => String(value || '').trim())
            .filter(Boolean);
        const invalidServiceValues = normalizedServices.filter((value) => !/^\d+$/.test(value));

        if (normalizedGender && !isKnownProfileEnumCode('gender', normalizedGender)) {
            toast.error('Gender must be selected from the dropdown list (label + code).');
            return;
        }

        if (normalizedEthnicity && !isKnownProfileEnumCode('ethnicity', normalizedEthnicity)) {
            toast.error('Ethnicity must be selected from the dropdown list (label + code).');
            return;
        }

        if (normalizedBuild && !isKnownProfileEnumCode('build', normalizedBuild)) {
            toast.error('Build must be selected from the dropdown list (label + code).');
            return;
        }

        if (invalidServiceValues.length > 0) {
            toast.error('Services include unknown text values. Re-select using listed service codes before saving.');
            return;
        }

        const fields = {
            name: profileForm.name?.trim() || '',
            phone: profileForm.phone?.trim() || null,
            email: profileForm.email?.trim() || null,
            city: profileForm.city?.trim() || null,
            birthday: normalizeBirthdayForSave(profileForm.birthday),
            gender: normalizedGender || null,
            ethnicity: normalizedEthnicity || null,
            height: normalizeHeightForSave(profileForm.height),
            build: normalizedBuild || null,
            services: normalizedServices.length ? normalizedServices : null,
            incall: profileForm.rates_incall?.trim() || null,
            outcall: profileForm.rates_outcall?.trim() || null,
            whatsapp: profileForm.whatsapp?.trim() || null,
            instagram: profileForm.instagram?.trim() || null,
            twitter: profileForm.twitter?.trim() || null,
            telegram: profileForm.telegram?.trim() || null,
            website: profileForm.website?.trim() || null,
            content: profileForm.bio || '',
        };

        updateProfileMutation.mutate({ fields, force: profileForce });
    };

    const applyHealthResolution = () => {
        if (!healthReason.trim()) {
            return;
        }

        if (healthAction === 'update_phone') {
            if (!updatePhoneTargetId || !updatePhoneValue.trim()) {
                return;
            }

            resolveHealthMutation.mutate({
                action: 'update_phone',
                duplicate_id: Number(updatePhoneTargetId),
                new_phone_normalized: updatePhoneValue.trim(),
                reason: healthReason.trim(),
            });
            return;
        }

        if (!selectedDuplicateIds.length) {
            return;
        }

        resolveHealthMutation.mutate({
            action: healthAction,
            duplicate_ids: selectedDuplicateIds.map((duplicateId) => Number(duplicateId)),
            reason: healthReason.trim(),
        });
    };

    return (
        <div className="space-y-4">
            <button
                onClick={() => navigate('/clients')}
                className="inline-flex items-center gap-1 text-sm font-medium text-teal-700 transition hover:text-teal-800"
            >
                <svg className="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
                    <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M15 19l-7-7 7-7" />
                </svg>
                Back to Clients
            </button>

            <section className="crm-surface px-5 py-5">
                <div className="flex flex-col gap-4 lg:flex-row lg:items-start lg:justify-between">
                    <div className="flex items-start gap-4">
                        {client.main_image_url ? (
                            <img src={client.main_image_url} alt="" className="h-16 w-16 rounded-full object-cover ring-1 ring-slate-200" />
                        ) : (
                            <div className="flex h-16 w-16 items-center justify-center rounded-full bg-slate-100 text-xl font-semibold text-slate-600 ring-1 ring-slate-200">
                                {client.name?.charAt(0) || '?'}
                            </div>
                        )}

                        <div>
                            <h2 className="crm-page-title">{client.name || 'Unnamed'}</h2>
                            <div className="mt-2 flex flex-wrap items-center gap-2">
                                <StatusBadge status={client.profile_status} />
                                {client.premium ? <span className="inline-flex items-center rounded-md bg-teal-50 px-2.5 py-0.5 text-xs font-medium text-teal-700 ring-1 ring-inset ring-teal-200">Premium</span> : null}
                                {client.featured ? <span className="inline-flex items-center rounded-md bg-amber-50 px-2.5 py-0.5 text-xs font-medium text-amber-700 ring-1 ring-inset ring-amber-200">Featured</span> : null}
                                {client.verified ? <span className="inline-flex items-center rounded-md bg-emerald-50 px-2.5 py-0.5 text-xs font-medium text-emerald-700 ring-1 ring-inset ring-emerald-200">Verified</span> : null}
                                {isUntrackedForeverPlan ? (
                                    <span
                                        className="inline-flex cursor-help items-center rounded-md bg-slate-50 px-2.5 py-0.5 text-xs font-medium text-slate-600 ring-1 ring-inset ring-slate-200"
                                        title={FOREVER_PLAN_TOOLTIP}
                                    >
                                        Forever plan
                                    </span>
                                ) : null}
                            </div>
                        </div>
                    </div>

                    <div className="flex flex-wrap gap-2">
                        {!isReadOnly ? (
                            <>
                                <button
                                    onClick={() => setShowSyncConfirm(true)}
                                    disabled={!canSyncFromWp || syncMutation.isPending}
                                    className="crm-btn-secondary disabled:cursor-not-allowed disabled:opacity-50"
                                    title={!canSyncFromWp ? 'Sync unavailable for manual CRM-only records' : undefined}
                                >
                                    {syncMutation.isPending ? 'Syncing...' : 'Sync latest from WP'}
                                </button>
                                <button
                                    type="button"
                                    onClick={() => setShowCredentialDrawer(true)}
                                    disabled={!canDispatchCredentials}
                                    className="crm-btn-secondary disabled:cursor-not-allowed disabled:opacity-50"
                                    title={!canDispatchCredentials ? 'Credential send is available for WP-linked client profiles.' : undefined}
                                >
                                    Send credentials
                                </button>
                            </>
                        ) : null}
                        <a
                            href={supportChatUrl}
                            target="_blank"
                            rel="noreferrer"
                            className="crm-btn-secondary"
                        >
                            Support chat
                        </a>
                        {!isReadOnly ? (
                            <button
                                onClick={() => setShowDealModal(true)}
                                className="crm-btn-primary"
                            >
                                New subscription
                            </button>
                        ) : null}
                    </div>
                </div>
            </section>

            <section className="grid gap-4 lg:grid-cols-3">
                <ProfileInfoCard title="Contact Info">
                    <dl className="space-y-2.5">
                        <DefinitionRow label="Phone" value={client.phone_normalized || '—'} mono />
                        <DefinitionRow label="Email" value={client.email || '—'} />
                        <DefinitionRow label="City" value={client.city || '—'} />
                        <DefinitionRow label="Market" value={client.platform?.name || '—'} />
                    </dl>
                </ProfileInfoCard>

                <ProfileInfoCard title="Subscription">
                    <dl className="space-y-2.5">
                        <DefinitionRow
                            label="Active Subscription"
                            value={isUntrackedForeverPlan && !client.active_deal ? (
                                <span className="inline-flex items-center gap-1">
                                    <span>Forever plan</span>
                                    <span
                                        className="inline-flex h-4 w-4 cursor-help items-center justify-center rounded-full border border-slate-200 text-[10px] font-semibold text-slate-400"
                                        title={FOREVER_PLAN_TOOLTIP}
                                    >
                                        ?
                                    </span>
                                </span>
                            ) : activeSubscriptionLabel}
                        />
                        <DefinitionRow
                            label="Expires"
                            value={client.escort_expire ? (
                                <span className={isExpired ? 'text-rose-700' : 'text-slate-900'}>{subscriptionExpiryLabel}</span>
                            ) : subscriptionExpiryLabel}
                        />
                        <DefinitionRow label="WP Post ID" value={client.wp_post_id || '—'} mono />
                        <DefinitionRow label="WP User ID" value={client.wp_user_id || '—'} mono />
                        <DefinitionRow
                            label="Profile URL"
                            value={client.wp_profile_url ? (
                                <a href={client.wp_profile_url} target="_blank" rel="noreferrer" className="text-teal-700 underline decoration-teal-200 underline-offset-2">
                                    Open profile
                                </a>
                            ) : 'Not available'}
                        />
                        <DefinitionRow
                            label="Support Chat"
                            value={supportChatUrl ? (
                                <a href={supportChatUrl} target="_blank" rel="noreferrer" className="text-teal-700 underline decoration-teal-200 underline-offset-2">
                                    Open support board
                                </a>
                            ) : 'Not configured'}
                        />
                        <DefinitionRow
                            label="Last Online"
                            value={client.last_online_at ? (
                                <span>
                                    {new Date(client.last_online_at * 1000).toLocaleString()}
                                    <span className="ml-1 text-xs text-slate-500">({formatRelativeFromUnix(client.last_online_at)})</span>
                                </span>
                            ) : '—'}
                        />
                        <DefinitionRow label="Last Synced" value={formatDateTime(client.last_synced_at)} />
                    </dl>
                </ProfileInfoCard>

                <ProfileInfoCard title="Summary">
                    <dl className="space-y-2.5">
                        <DefinitionRow label="Total Subscriptions" value={client.deals?.length || 0} />
                        <DefinitionRow label="Total Payments" value={client.payments?.length || 0} />
                        <DefinitionRow label="Notes" value={client.notes?.length || 0} />
                        <DefinitionRow label="Agent" value={client.assigned_agent?.name || 'Unassigned'} />
                    </dl>
                </ProfileInfoCard>
            </section>

            <section className="crm-surface p-2">
                <nav className="flex flex-wrap gap-1">
                    {tabLinks.map((tab) => (
                        <button
                            key={tab.key}
                            onClick={() => {
                                setActiveTab(tab.key);
                                const next = new URLSearchParams(searchParams);
                                if (tab.key === 'overview') {
                                    next.delete('tab');
                                } else {
                                    next.set('tab', tab.key);
                                }
                                setSearchParams(next, { replace: true });
                            }}
                            className={`rounded-md px-3 py-2 text-sm font-medium transition ${activeTab === tab.key ? 'bg-white text-slate-900 ring-1 ring-slate-200' : 'text-slate-500 hover:bg-slate-100 hover:text-slate-700'}`}
                        >
                            {tab.label}
                        </button>
                    ))}
                </nav>
            </section>

            {activeTab === 'overview' ? (
                <section className="crm-surface">
                    <header className="crm-panel-header">
                        <div>
                            <h3 className="crm-panel-title">Recent Activity</h3>
                            <p className="crm-panel-subtitle">Most recent subscriptions for this client. New subscriptions remain pending until activated.</p>
                        </div>
                    </header>
                    <div className="p-4">
                        {client.deals?.length > 0 ? (
                            <div className="space-y-2">
                                {client.deals.slice(0, 5).map((deal) => (
                                    <div key={deal.id} className="flex items-center justify-between rounded-md border border-slate-200 px-3 py-2.5">
                                        <div>
                                            <div className="flex items-center gap-1.5">
                                                <p className="text-sm font-semibold text-slate-900">{deal.product?.name || deal.plan_type} - {deal.duration}</p>
                                                {deal.origin === 'mpesa_import' && (
                                                    <span className="inline-flex items-center rounded-sm bg-teal-50 px-1 text-[10px] font-bold uppercase tracking-wider text-teal-700 ring-1 ring-inset ring-teal-600/20">MPESA</span>
                                                )}
                                            </div>
                                            <p className="text-xs text-slate-500">
                                                {formatCurrency(deal.amount, deal.currency || 'KES')}
                                                {deal.activated_at ? ` • Paid ${new Date(deal.activated_at).toLocaleDateString()}` : ''}
                                                {deal.payment_reference ? ` • Ref: ${deal.payment_reference}` : ' • Activation enables subscription access.'}
                                            </p>
                                        </div>
                                        <StatusBadge status={deal.status} />
                                    </div>
                                ))}
                            </div>
                        ) : (
                            <div className="rounded-md border border-dashed border-slate-300 bg-slate-50 px-4 py-4">
                                <p className="text-sm text-slate-600">No subscription records yet for this client.</p>
                                {!isReadOnly ? (
                                    <button
                                        type="button"
                                        onClick={() => setShowDealModal(true)}
                                        className="mt-3 crm-btn-primary"
                                    >
                                        Add subscription
                                    </button>
                                ) : null}
                            </div>
                        )}
                    </div>
                </section>
            ) : null}

            {activeTab === 'deals' ? (
                <div className="space-y-3">
                    {client.deals?.length > 0 ? client.deals.map((deal) => (
                        <section key={deal.id} className="crm-surface p-5">
                            <div className="flex items-start justify-between gap-4">
                                <div>
                                    <div className="flex flex-wrap items-center gap-2">
                                        <h4 className="text-sm font-semibold text-slate-900">{deal.product?.name || deal.plan_type}</h4>
                                        <StatusBadge status={deal.status} />
                                        {deal.origin === 'mpesa_import' && (
                                            <span className="inline-flex items-center rounded-sm bg-teal-50 px-1 text-[10px] font-bold uppercase tracking-wider text-teal-700 ring-1 ring-inset ring-teal-600/20">MPESA Import</span>
                                        )}
                                    </div>
                                    <p className="mt-1 text-sm text-slate-500">
                                        {formatCurrency(deal.amount, deal.currency || 'KES')} - {deal.duration}
                                        {deal.activated_at ? ` - Paid ${new Date(deal.activated_at).toLocaleDateString()}` : ''}
                                        {deal.expires_at ? ` - Expires ${new Date(deal.expires_at).toLocaleDateString()}` : ''}
                                        {deal.payment_reference ? ` - Ref: ${deal.payment_reference}` : ''}
                                    </p>
                                </div>

                                {!isReadOnly && deal.status === 'pending' ? (
                                    <button
                                        onClick={() => openActivationDialog(deal)}
                                        disabled={activateDealMutation.isPending}
                                        className="crm-btn-primary px-3 py-1.5 disabled:cursor-not-allowed disabled:opacity-50"
                                    >
                                        {activateDealMutation.isPending ? 'Submitting...' : 'Activate'}
                                    </button>
                                ) : null}
                            </div>
                        </section>
                    )) : (
                        <section className="crm-surface p-8 text-center">
                            <p className="text-sm text-slate-600">No subscriptions yet for this client.</p>
                            {!isReadOnly ? (
                                <button
                                    type="button"
                                    onClick={() => setShowDealModal(true)}
                                    className="mt-4 crm-btn-primary"
                                >
                                    Add subscription
                                </button>
                            ) : null}
                        </section>
                    )}
                </div>
            ) : null}

            {activeTab === 'notes' ? (
                <div className="space-y-3">
                    {!isReadOnly ? (
                        <section className="crm-surface p-4">
                            <h3 className="crm-panel-title">Add Note</h3>
                            <div className="mt-3 space-y-3">
                                <div className="flex flex-wrap gap-2">
                                    <select
                                        value={noteForm.note_type}
                                        onChange={(e) => setNoteForm({ ...noteForm, note_type: e.target.value })}
                                        className="crm-select"
                                    >
                                        <option value="internal">Internal</option>
                                        <option value="call">Call</option>
                                        <option value="sms">SMS</option>
                                        <option value="email">Email</option>
                                    </select>
                                    <input
                                        type="datetime-local"
                                        value={noteForm.follow_up_at}
                                        onChange={(e) => setNoteForm({ ...noteForm, follow_up_at: e.target.value })}
                                        className="crm-input max-w-[260px]"
                                        placeholder="Follow-up date"
                                    />
                                </div>

                                <textarea
                                    value={noteForm.content}
                                    onChange={(e) => setNoteForm({ ...noteForm, content: e.target.value })}
                                    placeholder="Write a note..."
                                    rows={3}
                                    className="crm-input"
                                />

                                <div className="flex items-center gap-2">
                                    <button
                                        onClick={() => addNoteMutation.mutate(noteForm)}
                                        disabled={!noteForm.content.trim() || addNoteMutation.isPending}
                                        className="crm-btn-primary disabled:cursor-not-allowed disabled:opacity-50"
                                    >
                                        {addNoteMutation.isPending ? 'Saving...' : 'Add note'}
                                    </button>
                                </div>
                            </div>
                        </section>
                    ) : null}

                    {client.notes?.length > 0 ? client.notes.map((note) => (
                        <section key={note.id} className="crm-surface p-4">
                            <div className="mb-2 flex flex-wrap items-center justify-between gap-2">
                                <div className="flex items-center gap-2">
                                    <span className="inline-flex items-center rounded-md bg-slate-100 px-2.5 py-0.5 text-xs font-medium capitalize text-slate-600 ring-1 ring-inset ring-slate-200">
                                        {note.note_type}
                                    </span>
                                    <span className="text-xs text-slate-500">by {note.author?.name || 'Unknown'}</span>
                                </div>
                                <span className="text-xs text-slate-400">{formatDateTime(note.created_at)}</span>
                            </div>
                            <p className="whitespace-pre-wrap text-sm text-slate-700">{note.content}</p>
                            {note.follow_up_at ? <p className="mt-2 text-xs text-teal-700">Follow-up: {formatDateTime(note.follow_up_at)}</p> : null}
                        </section>
                    )) : (
                        <section className="crm-surface p-8 text-center text-sm text-slate-500">No notes yet.</section>
                    )}
                </div>
            ) : null}

            {activeTab === 'timeline' ? (
                <section className="crm-surface p-5">
                    <Timeline events={timelineData?.data} isLoading={!timelineData} />
                </section>
            ) : null}

            {activeTab === 'wallet' ? (
                <div className="space-y-4">
                    <section className="crm-surface p-4">
                        <div className="flex flex-col gap-3 md:flex-row md:items-start md:justify-between">
                            <div>
                                <h3 className="crm-panel-title">Client Wallet</h3>
                                <p className="crm-panel-subtitle">Balance, recent wallet activity, and manual wallet actions for this escort profile.</p>
                            </div>
                            <button
                                type="button"
                                onClick={() => refetchWallet()}
                                disabled={walletFetching}
                                className="crm-btn-secondary self-start disabled:cursor-not-allowed disabled:opacity-50"
                            >
                                {walletFetching ? 'Refreshing...' : 'Refresh wallet'}
                            </button>
                        </div>

                        {walletLoading ? (
                            <p className="mt-4 text-sm text-slate-500">Loading wallet...</p>
                        ) : walletSummary ? (
                            <div className="mt-4 grid gap-3 md:grid-cols-3">
                                <div className="rounded-md border border-slate-200 bg-slate-50 p-3">
                                    <p className="text-xs font-semibold uppercase tracking-[0.08em] text-slate-500">Balance</p>
                                    <p className="mt-2 text-2xl font-semibold text-slate-900">{formatCurrency(walletSummary.balance, walletSummary.currency || 'KES')}</p>
                                </div>
                                <div className="rounded-md border border-slate-200 bg-slate-50 p-3">
                                    <p className="text-xs font-semibold uppercase tracking-[0.08em] text-slate-500">Last Top-up</p>
                                    <p className="mt-2 text-sm font-semibold text-slate-900">
                                        {walletSummary.last_topup
                                            ? formatCurrency(walletSummary.last_topup.amount, walletSummary.last_topup.currency || walletSummary.currency || 'KES')
                                            : 'No top-ups yet'}
                                    </p>
                                    <p className="mt-1 text-xs text-slate-500">{formatDateTime(walletSummary.last_topup?.created_at)}</p>
                                </div>
                                <div className="rounded-md border border-slate-200 bg-slate-50 p-3">
                                    <p className="text-xs font-semibold uppercase tracking-[0.08em] text-slate-500">Synced</p>
                                    <p className="mt-2 text-sm font-semibold text-slate-900">{formatDateTime(walletSummary.wallet_last_synced_at)}</p>
                                    <p className="mt-1 text-xs text-slate-500">Refreshed {formatDateTime(walletSummary.refreshed_at)}</p>
                                </div>
                            </div>
                        ) : (
                            <p className="mt-4 text-sm text-slate-500">Wallet data is not available for this client yet.</p>
                        )}
                    </section>

                    {canManageWallet ? (
                        <section className="grid gap-4 lg:grid-cols-2">
                            <div className="crm-surface p-4">
                                <h4 className="text-sm font-semibold text-slate-900">Manual Top-up</h4>
                                <p className="mt-1 text-xs text-slate-500">Use this for verified offline credits or support-side balance corrections.</p>
                                <div className="mt-4 space-y-3">
                                    <input
                                        type="number"
                                        min="0.01"
                                        step="0.01"
                                        value={walletTopupForm.amount}
                                        onChange={(event) => setWalletTopupForm((current) => ({ ...current, amount: event.target.value }))}
                                        className="crm-input"
                                        placeholder="Top-up amount"
                                    />
                                    <input
                                        type="password"
                                        inputMode="numeric"
                                        maxLength={6}
                                        value={walletTopupForm.pin}
                                        onChange={(event) => setWalletTopupForm((current) => ({ ...current, pin: event.target.value.replace(/\D/g, '').slice(0, 6) }))}
                                        className="crm-input"
                                        placeholder="Wallet PIN"
                                    />
                                    <textarea
                                        rows={3}
                                        value={walletTopupForm.reason}
                                        onChange={(event) => setWalletTopupForm((current) => ({ ...current, reason: event.target.value }))}
                                        className="crm-input"
                                        placeholder="Reason for wallet top-up"
                                    />
                                    <div className="flex justify-end">
                                        <button
                                            type="button"
                                            onClick={() => walletTopupMutation.mutate({
                                                amount: walletTopupForm.amount,
                                                pin: walletTopupForm.pin,
                                                reason: walletTopupForm.reason.trim(),
                                            })}
                                            disabled={!walletTopupForm.amount || !walletTopupForm.pin.trim() || !walletTopupForm.reason.trim() || walletTopupMutation.isPending}
                                            className="crm-btn-primary disabled:cursor-not-allowed disabled:opacity-50"
                                        >
                                            {walletTopupMutation.isPending ? 'Recording...' : 'Record top-up'}
                                        </button>
                                    </div>
                                </div>
                            </div>

                            <div className="crm-surface p-4">
                                <h4 className="text-sm font-semibold text-slate-900">Balance Adjustment</h4>
                                <p className="mt-1 text-xs text-slate-500">Debit or credit the wallet directly when support needs to correct a balance.</p>
                                <div className="mt-4 space-y-3">
                                    <select
                                        value={walletAdjustmentForm.type}
                                        onChange={(event) => setWalletAdjustmentForm((current) => ({ ...current, type: event.target.value }))}
                                        className="crm-select"
                                    >
                                        <option value="debit">Debit wallet</option>
                                        <option value="credit">Credit wallet</option>
                                    </select>
                                    <input
                                        type="number"
                                        min="0.01"
                                        step="0.01"
                                        value={walletAdjustmentForm.amount}
                                        onChange={(event) => setWalletAdjustmentForm((current) => ({ ...current, amount: event.target.value }))}
                                        className="crm-input"
                                        placeholder="Adjustment amount"
                                    />
                                    <input
                                        type="password"
                                        inputMode="numeric"
                                        maxLength={6}
                                        value={walletAdjustmentForm.pin}
                                        onChange={(event) => setWalletAdjustmentForm((current) => ({ ...current, pin: event.target.value.replace(/\D/g, '').slice(0, 6) }))}
                                        className="crm-input"
                                        placeholder="Wallet PIN"
                                    />
                                    <textarea
                                        rows={3}
                                        value={walletAdjustmentForm.reason}
                                        onChange={(event) => setWalletAdjustmentForm((current) => ({ ...current, reason: event.target.value }))}
                                        className="crm-input"
                                        placeholder="Reason for wallet adjustment"
                                    />
                                    <div className="flex justify-end">
                                        <button
                                            type="button"
                                            onClick={() => walletAdjustmentMutation.mutate({
                                                type: walletAdjustmentForm.type,
                                                amount: walletAdjustmentForm.amount,
                                                pin: walletAdjustmentForm.pin,
                                                reason: walletAdjustmentForm.reason.trim(),
                                            })}
                                            disabled={!walletAdjustmentForm.amount || !walletAdjustmentForm.pin.trim() || !walletAdjustmentForm.reason.trim() || walletAdjustmentMutation.isPending}
                                            className="crm-btn-primary disabled:cursor-not-allowed disabled:opacity-50"
                                        >
                                            {walletAdjustmentMutation.isPending ? 'Recording...' : 'Record adjustment'}
                                        </button>
                                    </div>
                                </div>
                            </div>
                        </section>
                    ) : null}

                    <section className="crm-surface">
                        <header className="crm-panel-header">
                            <div>
                                <h3 className="crm-panel-title">Recent Wallet Transactions</h3>
                                <p className="crm-panel-subtitle">Latest balance changes for this escort wallet.</p>
                            </div>
                        </header>
                        <div className="p-4">
                            {walletLoading ? (
                                <p className="text-sm text-slate-500">Loading wallet transactions...</p>
                            ) : walletTransactions.length > 0 ? (
                                <div className="space-y-2">
                                    {walletTransactions.map((transaction) => (
                                        <div key={transaction.id} className="flex flex-col gap-2 rounded-md border border-slate-200 px-3 py-3 md:flex-row md:items-start md:justify-between">
                                            <div>
                                                <div className="flex flex-wrap items-center gap-2">
                                                    <span className={`inline-flex items-center rounded-md px-2 py-0.5 text-xs font-medium ring-1 ring-inset ${
                                                        transaction.type === 'credit'
                                                            ? 'bg-emerald-50 text-emerald-700 ring-emerald-200'
                                                            : 'bg-rose-50 text-rose-700 ring-rose-200'
                                                    }`}>
                                                        {transaction.type}
                                                    </span>
                                                    <p className="text-sm font-semibold text-slate-900">{formatCurrency(transaction.amount, transaction.currency || walletSummary?.currency || 'KES')}</p>
                                                </div>
                                                <p className="mt-1 text-sm text-slate-600">{transaction.description || 'Wallet transaction'}</p>
                                                <p className="mt-1 text-xs text-slate-500">
                                                    Ref: {transaction.reference_type || '—'}
                                                    {transaction.reference_id ? ` #${transaction.reference_id}` : ''}
                                                    {transaction.payment_id ? ` • Payment #${transaction.payment_id}` : ''}
                                                    {transaction.deal_id ? ` • Deal #${transaction.deal_id}` : ''}
                                                </p>
                                            </div>
                                            <div className="text-left md:text-right">
                                                <p className="text-xs font-semibold uppercase tracking-[0.08em] text-slate-500">Balance After</p>
                                                <p className="mt-1 text-sm font-semibold text-slate-900">{formatCurrency(transaction.balance_after, transaction.currency || walletSummary?.currency || 'KES')}</p>
                                                <p className="mt-1 text-xs text-slate-500">{formatDateTime(transaction.created_at)}</p>
                                            </div>
                                        </div>
                                    ))}
                                </div>
                            ) : (
                                <p className="rounded-md border border-dashed border-slate-300 bg-slate-50 px-3 py-6 text-center text-sm text-slate-500">
                                    No wallet transactions recorded for this client yet.
                                </p>
                            )}
                        </div>
                    </section>
                </div>
            ) : null}

            {activeTab === 'payments' ? (
                <div className="space-y-3">
                    {client.payments?.length > 0 ? client.payments.map((payment) => (
                        <section key={payment.id} className="crm-surface flex items-start justify-between gap-3 p-4">
                            <div>
                                <p className="text-sm font-semibold text-slate-900">
                                    {formatCurrency(payment.amount, payment.currency || 'KES')}
                                    {payment.product ? <span className="font-normal text-slate-500"> - {payment.product.name}</span> : null}
                                </p>
                                <p className="text-xs text-slate-500">
                                    {payment.phone || 'No phone'}
                                    {payment.transaction_reference ? ` | Ref: ${payment.transaction_reference}` : ''}
                                </p>
                            </div>
                            <div className="text-right">
                                <StatusBadge status={payment.status} />
                                <p className="mt-1 text-xs text-slate-400">{formatDateTime(payment.created_at)}</p>
                            </div>
                        </section>
                    )) : (
                        <section className="crm-surface p-8 text-center text-sm text-slate-500">No payments recorded.</section>
                    )}
                </div>
            ) : null}

            {activeTab === 'edit_profile' && !isReadOnly ? (
                <section className="crm-surface p-4">
                    {!canSyncFromWp ? (
                        <p className="text-sm text-slate-500">This is a CRM-only client record and does not support WordPress profile editing.</p>
                    ) : (
                        <div className="space-y-4">
                            <div className="flex flex-wrap items-center gap-2">
                                {profileSections.map((section) => (
                                    <button
                                        key={section.key}
                                        type="button"
                                        onClick={() => setProfileSection(section.key)}
                                        className={`rounded-md px-3 py-1.5 text-xs font-semibold transition ${
                                            profileSection === section.key
                                                ? 'bg-teal-700 text-white'
                                                : 'bg-slate-100 text-slate-600 hover:bg-slate-200'
                                        }`}
                                    >
                                        {section.label}
                                    </button>
                                ))}
                            </div>

                            {profileConflict ? (
                                <div className="rounded-md border border-amber-200 bg-amber-50 p-3 text-xs text-amber-800">
                                    <p className="font-semibold">WordPress has newer changes than CRM cache.</p>
                                    <p className="mt-1">WP modified: {formatDateTime(profileConflict.wp_modified_at)} • CRM synced: {formatDateTime(profileConflict.crm_last_synced_at)}</p>
                                    <div className="mt-2 space-y-1">
                                        {Object.entries(profileConflict.diff || {}).map(([field, values]) => (
                                            <p key={field}>
                                                <span className="font-semibold">{field}:</span> CRM "{String(values.crm_value ?? '')}" vs WP "{String(values.wp_value ?? '')}"
                                            </p>
                                        ))}
                                    </div>
                                    <label className="mt-2 flex items-center gap-2">
                                        <input
                                            type="checkbox"
                                            checked={profileForce}
                                            onChange={(event) => setProfileForce(event.target.checked)}
                                            className="h-4 w-4 rounded border-amber-300 text-amber-700 focus:ring-amber-200"
                                        />
                                        Force overwrite WordPress values
                                    </label>
                                </div>
                            ) : null}

                            {profileSection === 'personal' ? (
                                <div className="space-y-3">
                                    <p className="text-xs text-slate-500">Use the dropdown options with visible codes. CRM saves the WordPress code value automatically.</p>
                                    <div className="grid gap-3 md:grid-cols-2">
                                        <label className="space-y-1">
                                            <span className="text-xs font-semibold uppercase tracking-[0.08em] text-slate-500">Display Name</span>
                                            <input value={profileForm?.name || ''} onChange={(event) => setProfileForm((current) => ({ ...current, name: event.target.value }))} className="crm-input" placeholder="e.g. Majesty" />
                                        </label>
                                        <label className="space-y-1">
                                            <span className="text-xs font-semibold uppercase tracking-[0.08em] text-slate-500">Birthday</span>
                                            <input type="date" value={profileForm?.birthday || ''} onChange={(event) => setProfileForm((current) => ({ ...current, birthday: event.target.value }))} className="crm-input" />
                                        </label>
                                        <label className="space-y-1">
                                            <span className="text-xs font-semibold uppercase tracking-[0.08em] text-slate-500">Gender (Code)</span>
                                            <select value={profileForm?.gender || ''} onChange={(event) => setProfileForm((current) => ({ ...current, gender: event.target.value }))} className="crm-input">
                                                <option value="">Select gender</option>
                                                {PROFILE_ENUM_OPTIONS.gender.map((option) => (
                                                    <option key={option.value} value={option.value}>{option.label}</option>
                                                ))}
                                            </select>
                                            {profileForm?.gender && !isKnownProfileEnumCode('gender', profileForm.gender) ? (
                                                <p className="text-xs text-rose-600">Unknown current value: {String(profileForm.gender)}</p>
                                            ) : null}
                                        </label>
                                        <label className="space-y-1">
                                            <span className="text-xs font-semibold uppercase tracking-[0.08em] text-slate-500">Ethnicity (Code)</span>
                                            <select value={profileForm?.ethnicity || ''} onChange={(event) => setProfileForm((current) => ({ ...current, ethnicity: event.target.value }))} className="crm-input">
                                                <option value="">Select ethnicity</option>
                                                {PROFILE_ENUM_OPTIONS.ethnicity.map((option) => (
                                                    <option key={option.value} value={option.value}>{option.label}</option>
                                                ))}
                                            </select>
                                            {profileForm?.ethnicity && !isKnownProfileEnumCode('ethnicity', profileForm.ethnicity) ? (
                                                <p className="text-xs text-rose-600">Unknown current value: {String(profileForm.ethnicity)}</p>
                                            ) : null}
                                        </label>
                                        <label className="space-y-1">
                                            <span className="text-xs font-semibold uppercase tracking-[0.08em] text-slate-500">Height (cm)</span>
                                            <input type="text" value={profileForm?.height || ''} onChange={(event) => setProfileForm((current) => ({ ...current, height: event.target.value }))} className="crm-input" placeholder={`e.g. 167 or 5'6" (167.64)`} />
                                            <p className="text-xs text-slate-500">You can enter cm or legacy formats. CRM auto-converts to centimeter value on save.</p>
                                        </label>
                                        <label className="space-y-1">
                                            <span className="text-xs font-semibold uppercase tracking-[0.08em] text-slate-500">Build (Code)</span>
                                            <select value={profileForm?.build || ''} onChange={(event) => setProfileForm((current) => ({ ...current, build: event.target.value }))} className="crm-input">
                                                <option value="">Select build</option>
                                                {PROFILE_ENUM_OPTIONS.build.map((option) => (
                                                    <option key={option.value} value={option.value}>{option.label}</option>
                                                ))}
                                            </select>
                                            {profileForm?.build && !isKnownProfileEnumCode('build', profileForm.build) ? (
                                                <p className="text-xs text-rose-600">Unknown current value: {String(profileForm.build)}</p>
                                            ) : null}
                                        </label>
                                        <label className="space-y-1 md:col-span-2">
                                            <span className="text-xs font-semibold uppercase tracking-[0.08em] text-slate-500">Profile Bio</span>
                                            <textarea
                                                value={profileForm?.bio || ''}
                                                onChange={(event) => setProfileForm((current) => ({ ...current, bio: event.target.value }))}
                                                className="crm-input"
                                                rows={4}
                                                placeholder="Public profile description"
                                            />
                                        </label>
                                    </div>
                                </div>
                            ) : null}

                            {profileSection === 'services' ? (
                                <div className="space-y-3">
                                    <p className="text-xs text-slate-500">Services are saved as WordPress service codes. Select one or more options with visible code values.</p>
                                    <div className="grid gap-3 md:grid-cols-2">
                                        <label className="space-y-1 md:col-span-2">
                                            <span className="text-xs font-semibold uppercase tracking-[0.08em] text-slate-500">Services (Code)</span>
                                            <div className="rounded-md border border-slate-200 bg-white p-3">
                                                <div className="flex flex-wrap gap-2">
                                                    {serviceOptions.map((option) => {
                                                        const isSelected = selectedServiceCodes.includes(option.value);
                                                        const isUnknown = !isKnownProfileEnumCode('services', option.value);

                                                        return (
                                                            <button
                                                                key={`${option.value}-${option.label}`}
                                                                type="button"
                                                                onClick={() => {
                                                                    setProfileForm((current) => {
                                                                        const currentValues = Array.isArray(current?.services)
                                                                            ? current.services.map((value) => String(value || '').trim()).filter(Boolean)
                                                                            : [];

                                                                        const nextValues = currentValues.includes(option.value)
                                                                            ? currentValues.filter((value) => value !== option.value)
                                                                            : [...currentValues, option.value];

                                                                        return {
                                                                            ...current,
                                                                            services: nextValues,
                                                                        };
                                                                    });
                                                                }}
                                                                aria-pressed={isSelected}
                                                                className={`rounded-full border px-3 py-1.5 text-sm transition ${
                                                                    isSelected
                                                                        ? 'border-teal-600 bg-teal-50 text-teal-700'
                                                                        : isUnknown
                                                                            ? 'border-amber-300 bg-amber-50 text-amber-700'
                                                                            : 'border-slate-300 bg-white text-slate-700 hover:border-teal-400 hover:text-teal-700'
                                                                }`}
                                                            >
                                                                {option.label}
                                                            </button>
                                                        );
                                                    })}
                                                </div>
                                            </div>
                                            <p className="text-xs text-slate-500">Click a service chip to add or remove it. Selected: {selectedServiceCodes.length}</p>
                                        </label>
                                        <label className="space-y-1">
                                            <span className="text-xs font-semibold uppercase tracking-[0.08em] text-slate-500">Incall Rate</span>
                                            <input value={profileForm?.rates_incall || ''} onChange={(event) => setProfileForm((current) => ({ ...current, rates_incall: event.target.value }))} className="crm-input" placeholder="e.g. 1500" />
                                        </label>
                                        <label className="space-y-1">
                                            <span className="text-xs font-semibold uppercase tracking-[0.08em] text-slate-500">Outcall Rate</span>
                                            <input value={profileForm?.rates_outcall || ''} onChange={(event) => setProfileForm((current) => ({ ...current, rates_outcall: event.target.value }))} className="crm-input" placeholder="e.g. 2000" />
                                        </label>
                                    </div>
                                </div>
                            ) : null}

                            {profileSection === 'contact' ? (
                                <div className="grid gap-3 md:grid-cols-2">
                                    <input value={profileForm?.phone || ''} onChange={(event) => setProfileForm((current) => ({ ...current, phone: event.target.value }))} className="crm-input" placeholder="Phone" />
                                    <input value={profileForm?.email || ''} onChange={(event) => setProfileForm((current) => ({ ...current, email: event.target.value }))} className="crm-input" placeholder="Email" />
                                    <input value={profileForm?.city || ''} onChange={(event) => setProfileForm((current) => ({ ...current, city: event.target.value }))} className="crm-input" placeholder="City" />
                                    <input value={profileForm?.whatsapp || ''} onChange={(event) => setProfileForm((current) => ({ ...current, whatsapp: event.target.value }))} className="crm-input" placeholder="WhatsApp" />
                                    <input value={profileForm?.instagram || ''} onChange={(event) => setProfileForm((current) => ({ ...current, instagram: event.target.value }))} className="crm-input" placeholder="Instagram URL" />
                                    <input value={profileForm?.twitter || ''} onChange={(event) => setProfileForm((current) => ({ ...current, twitter: event.target.value }))} className="crm-input" placeholder="Twitter URL" />
                                    <input value={profileForm?.telegram || ''} onChange={(event) => setProfileForm((current) => ({ ...current, telegram: event.target.value }))} className="crm-input" placeholder="Telegram" />
                                    <input value={profileForm?.website || ''} onChange={(event) => setProfileForm((current) => ({ ...current, website: event.target.value }))} className="crm-input" placeholder="Website" />
                                </div>
                            ) : null}

                            {profileSection === 'subscription' ? (
                                <div className="space-y-3 rounded-md border border-slate-200 bg-slate-50 p-3">
                                    <p className="text-sm text-slate-700">Subscription fields are read-only in profile editor. Manage activation, extension, and deactivation from subscriptions workflows.</p>
                                    <div className="grid gap-2 md:grid-cols-2">
                                        <p className="text-xs text-slate-600">Status: <span className="font-semibold text-slate-900">{client.profile_status}</span></p>
                                        <p className="text-xs text-slate-600">Plan: <span className="font-semibold text-slate-900">{client.plan_label || 'Basic'}</span></p>
                                        <p className="text-xs text-slate-600">Expires: <span className="font-semibold text-slate-900">{subscriptionExpiryDetailLabel}</span></p>
                                        <p className="text-xs text-slate-600">
                                            Active subscription: <span className="font-semibold text-slate-900">{activeSubscriptionLabel}</span>
                                            {isUntrackedForeverPlan && !client.active_deal ? (
                                                <span
                                                    className="ml-1 inline-flex h-4 w-4 cursor-help items-center justify-center rounded-full border border-slate-200 text-[10px] font-semibold text-slate-400"
                                                    title={FOREVER_PLAN_TOOLTIP}
                                                >
                                                    ?
                                                </span>
                                            ) : null}
                                        </p>
                                    </div>
                                    <button
                                        type="button"
                                        onClick={() => {
                                            const next = new URLSearchParams(searchParams);
                                            next.set('tab', 'deals');
                                            setSearchParams(next, { replace: true });
                                            setActiveTab('deals');
                                        }}
                                        className="crm-btn-secondary"
                                    >
                                        Open subscriptions tab
                                    </button>
                                </div>
                            ) : null}

                            {profileSection === 'media' ? (
                                <div className="space-y-3">
                                    <div className="rounded-md border border-slate-200 bg-slate-50 p-3">
                                        <div className="grid gap-2 md:grid-cols-2">
                                            <input
                                                type="file"
                                                accept="image/jpeg,image/png,image/webp"
                                                onChange={(event) => setMediaUploadFile(event.target.files?.[0] || null)}
                                                className="crm-input"
                                            />
                                            <label className="flex items-center gap-2 text-sm text-slate-700">
                                                <input
                                                    type="checkbox"
                                                    checked={mediaUploadSetMain}
                                                    onChange={(event) => setMediaUploadSetMain(event.target.checked)}
                                                    className="h-4 w-4 rounded border-slate-300 text-teal-700 focus:ring-teal-200"
                                                />
                                                Set uploaded image as main
                                            </label>
                                        </div>
                                        <div className="mt-2 flex justify-end">
                                            <button
                                                type="button"
                                                onClick={() => {
                                                    if (!mediaUploadFile) return;
                                                    uploadMediaMutation.mutate({
                                                        file: mediaUploadFile,
                                                        setMain: mediaUploadSetMain,
                                                    });
                                                }}
                                                disabled={!mediaUploadFile || uploadMediaMutation.isPending}
                                                className="crm-btn-primary disabled:cursor-not-allowed disabled:opacity-50"
                                            >
                                                {uploadMediaMutation.isPending ? 'Uploading...' : 'Upload image'}
                                            </button>
                                        </div>
                                    </div>

                                    {mediaLoading ? (
                                        <p className="text-sm text-slate-500">Loading media...</p>
                                    ) : mediaItems.length > 0 ? (
                                        <div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
                                            {mediaItems.map((media) => (
                                                <div
                                                    key={media.id}
                                                    className={`rounded-md border bg-white p-2 ${media.is_main ? 'border-amber-300 ring-1 ring-amber-200' : 'border-slate-200'}`}
                                                >
                                                    <img src={media.url} alt="" className="h-40 w-full rounded object-cover" />
                                                    <p className="mt-2 truncate text-xs text-slate-600">{media.filename}</p>
                                                    <div className="mt-2 flex flex-wrap items-center gap-1.5">
                                                        {!media.is_main ? (
                                                            <button
                                                                type="button"
                                                                onClick={() => setMainMediaMutation.mutate(media.id)}
                                                                disabled={setMainMediaMutation.isPending}
                                                                className="rounded-md border border-teal-200 bg-teal-50 px-2 py-1 text-[11px] font-semibold text-teal-700 transition hover:bg-teal-100 disabled:cursor-not-allowed disabled:opacity-50"
                                                            >
                                                                Set main
                                                            </button>
                                                        ) : (
                                                            <span className="rounded-md bg-amber-50 px-2 py-1 text-[11px] font-semibold text-amber-700">Main image</span>
                                                        )}
                                                        <button
                                                            type="button"
                                                            onClick={() => deleteMediaMutation.mutate(media.id)}
                                                            disabled={deleteMediaMutation.isPending}
                                                            className="rounded-md border border-rose-200 bg-rose-50 px-2 py-1 text-[11px] font-semibold text-rose-700 transition hover:bg-rose-100 disabled:cursor-not-allowed disabled:opacity-50"
                                                        >
                                                            Delete
                                                        </button>
                                                    </div>
                                                </div>
                                            ))}
                                        </div>
                                    ) : (
                                        <p className="rounded-md border border-dashed border-slate-300 bg-slate-50 px-3 py-6 text-center text-sm text-slate-500">
                                            No images uploaded. Drag and drop or click to upload.
                                        </p>
                                    )}
                                </div>
                            ) : null}

                            {profileSection !== 'media' ? (
                                <div className="rounded-md border border-slate-200 bg-slate-50 p-3">
                                    <label className="mb-1 block text-sm font-medium text-slate-700">Reason</label>
                                    <textarea
                                        rows={2}
                                        value={profileReason}
                                        onChange={(event) => setProfileReason(event.target.value)}
                                        className="crm-input"
                                    />
                                    <div className="mt-2 flex justify-end">
                                        <button
                                            type="button"
                                            onClick={submitProfileUpdate}
                                            disabled={!profileForm?.name?.trim() || !profileReason.trim() || updateProfileMutation.isPending || (profileConflict && !profileForce)}
                                            className="crm-btn-primary disabled:cursor-not-allowed disabled:opacity-50"
                                        >
                                            {updateProfileMutation.isPending ? 'Syncing to WordPress...' : 'Save profile changes'}
                                        </button>
                                    </div>
                                </div>
                            ) : null}
                        </div>
                    )}
                </section>
            ) : null}

            {activeTab === 'profile_health' && !isReadOnly ? (
                <section className="crm-surface p-4">
                    {healthLoading ? (
                        <p className="text-sm text-slate-500">Loading profile health...</p>
                    ) : (
                        <div className="space-y-4">
                            <div className="rounded-md border border-slate-200 bg-slate-50 p-3 text-sm text-slate-700">
                                <p>Phone: <span className="crm-mono font-semibold text-slate-900">{healthData?.summary?.phone_normalized || '—'}</span></p>
                                <p className="mt-1">Duplicates: <span className="font-semibold text-slate-900">{healthData?.summary?.duplicate_count || 0}</span> • Lead matches: <span className="font-semibold text-slate-900">{healthData?.summary?.lead_matches || 0}</span></p>
                            </div>

                            {healthDuplicates.length > 0 ? (
                                <div className="space-y-2">
                                    {healthDuplicates.map((duplicate) => (
                                        <label key={duplicate.id} className="flex items-start gap-3 rounded-md border border-slate-200 bg-white p-3">
                                            <input
                                                type="checkbox"
                                                checked={selectedDuplicateIds.includes(String(duplicate.id))}
                                                onChange={(event) => {
                                                    setSelectedDuplicateIds((current) => {
                                                        if (event.target.checked) {
                                                            return [...current, String(duplicate.id)];
                                                        }
                                                        return current.filter((value) => value !== String(duplicate.id));
                                                    });
                                                }}
                                                className="mt-1 h-4 w-4 rounded border-slate-300 text-teal-700 focus:ring-teal-200"
                                            />
                                            <div className="min-w-0 flex-1">
                                                <p className="text-sm font-semibold text-slate-900">{duplicate.name || `Client #${duplicate.id}`}</p>
                                                <p className="text-xs text-slate-500">
                                                    CRM #{duplicate.id} • WP #{duplicate.wp_post_id || '—'} • status {duplicate.profile_status} • active deals {duplicate.active_deals_count}
                                                </p>
                                                <p className="text-xs text-slate-500">Last payment: {formatDateTime(duplicate.last_payment_at)}</p>
                                            </div>
                                        </label>
                                    ))}
                                </div>
                            ) : (
                                <p className="rounded-md border border-dashed border-slate-300 bg-slate-50 px-3 py-6 text-center text-sm text-slate-500">
                                    No duplicate profiles detected for this phone number.
                                </p>
                            )}

                            <div className="grid gap-3 md:grid-cols-2">
                                <div>
                                    <label className="mb-1 block text-sm font-medium text-slate-700">Resolution action</label>
                                    <select value={healthAction} onChange={(event) => setHealthAction(event.target.value)} className="crm-select">
                                        <option value="keep_primary">Keep primary (move deals/payments)</option>
                                        <option value="merge_into_primary">Merge into primary</option>
                                        <option value="archive_duplicate">Archive selected duplicates</option>
                                        <option value="update_phone">Update duplicate phone</option>
                                    </select>
                                </div>
                                {healthAction === 'update_phone' ? (
                                    <>
                                        <div>
                                            <label className="mb-1 block text-sm font-medium text-slate-700">Duplicate profile</label>
                                            <select value={updatePhoneTargetId} onChange={(event) => setUpdatePhoneTargetId(event.target.value)} className="crm-select">
                                                <option value="">Select duplicate</option>
                                                {healthDuplicates.map((duplicate) => (
                                                    <option key={duplicate.id} value={duplicate.id}>
                                                        {duplicate.name || `Client #${duplicate.id}`} (CRM #{duplicate.id})
                                                    </option>
                                                ))}
                                            </select>
                                        </div>
                                        <div>
                                            <label className="mb-1 block text-sm font-medium text-slate-700">New phone</label>
                                            <input
                                                value={updatePhoneValue}
                                                onChange={(event) => setUpdatePhoneValue(event.target.value)}
                                                className="crm-input"
                                                placeholder={`e.g. ${platformPhonePrefix}712345678`}
                                            />
                                        </div>
                                    </>
                                ) : null}
                                <div className="md:col-span-2">
                                    <label className="mb-1 block text-sm font-medium text-slate-700">Resolution note</label>
                                    <textarea
                                        rows={3}
                                        value={healthReason}
                                        onChange={(event) => setHealthReason(event.target.value)}
                                        className="crm-input"
                                    />
                                </div>
                            </div>

                            <div className="flex justify-end">
                                <button
                                    type="button"
                                    onClick={applyHealthResolution}
                                    disabled={
                                        !healthReason.trim()
                                        || resolveHealthMutation.isPending
                                        || (
                                            healthAction === 'update_phone'
                                                ? (!updatePhoneTargetId || !updatePhoneValue.trim())
                                                : !selectedDuplicateIds.length
                                        )
                                    }
                                    className="crm-btn-primary disabled:cursor-not-allowed disabled:opacity-50"
                                >
                                    {resolveHealthMutation.isPending ? 'Applying...' : 'Apply resolution'}
                                </button>
                            </div>
                        </div>
                    )}
                </section>
            ) : null}

            {activationDialog.open ? (
                <div className="fixed inset-0 z-50 flex items-center justify-center bg-slate-900/45 p-4" onClick={closeActivationDialog}>
                    <div className="w-full max-w-lg rounded-lg border border-slate-200 bg-white shadow-xl" onClick={(event) => event.stopPropagation()}>
                        <header className="crm-panel-header">
                            <div>
                                <h3 className="crm-panel-title">Activate Subscription</h3>
                                <p className="crm-panel-subtitle">{client.name} • {activationDialog.dealLabel}</p>
                            </div>
                        </header>
                        <div className="space-y-4 p-4">
                            <div className="space-y-3 rounded-md border border-slate-200 bg-slate-50 p-3">
                                <p className="text-sm font-semibold text-slate-800">Payment Method</p>
                                <div className="grid gap-2 sm:grid-cols-2">
                                    {['manual', 'stk', 'link', 'free_trial'].map((method) => (
                                        <button
                                            key={method}
                                            type="button"
                                            onClick={() => setActivationPaymentMethod(method)}
                                            className={`rounded-md border px-3 py-2 text-xs font-semibold uppercase tracking-wide transition ${
                                                activationPaymentMethod === method
                                                    ? 'border-teal-300 bg-teal-50 text-teal-700'
                                                    : 'border-slate-200 bg-white text-slate-600 hover:border-slate-300'
                                            }`}
                                        >
                                            {method === 'manual'
                                                ? 'Manual Payment'
                                                : method === 'stk'
                                                    ? 'STK Push'
                                                    : method === 'link'
                                                        ? 'Payment Link'
                                                        : 'Free Trial'}
                                        </button>
                                    ))}
                                </div>

                                {activationPaymentMethod === 'manual' ? (
                                    <div>
                                        <label htmlFor="client-detail-payment-reference" className="mb-1 block text-sm font-medium text-slate-700">
                                            MPESA / Transaction Reference
                                        </label>
                                        <input
                                            id="client-detail-payment-reference"
                                            type="text"
                                            value={activationPaymentReference}
                                            onChange={(event) => setActivationPaymentReference(event.target.value)}
                                            className="crm-input"
                                            placeholder="e.g. MPESA123ABC"
                                        />
                                    </div>
                                ) : null}

                                {activationPaymentMethod === 'free_trial' ? (
                                    <div>
                                        <label htmlFor="client-detail-approved-by" className="mb-1 block text-sm font-medium text-slate-700">
                                            Approved By
                                        </label>
                                        <input
                                            id="client-detail-approved-by"
                                            type="text"
                                            value={activationApprovedBy}
                                            onChange={(event) => setActivationApprovedBy(event.target.value)}
                                            className="crm-input"
                                            placeholder="Admin or sub-admin approver"
                                        />
                                    </div>
                                ) : null}

                                {(activationPaymentMethod === 'stk' || activationPaymentMethod === 'link') ? (
                                    <div className="rounded-md border border-slate-200 bg-white px-3 py-2 text-xs text-slate-600">
                                        {activationPaymentMethod === 'stk'
                                            ? 'An STK push will be sent to the client phone. Subscription activates after payment confirmation.'
                                            : 'A payment link will be sent to the client phone. Subscription activates after payment confirmation.'}
                                        <span className="mt-1 block crm-mono text-[11px] text-slate-500">
                                            Target phone: {activationTargetPhone || 'Unavailable'}
                                        </span>
                                    </div>
                                ) : null}

                            </div>

                            <div>
                                <label htmlFor="client-detail-activation-reason" className="mb-1 block text-sm font-medium text-slate-700">
                                    Reason
                                </label>
                                <textarea
                                    id="client-detail-activation-reason"
                                    rows={3}
                                    value={activationReason}
                                    onChange={(event) => setActivationReason(event.target.value)}
                                    className="crm-input"
                                />
                            </div>
                        </div>
                        <footer className="flex items-center justify-end gap-2 border-t border-slate-100 px-4 py-3">
                            <button
                                type="button"
                                onClick={closeActivationDialog}
                                className="crm-btn-secondary"
                                disabled={activateDealMutation.isPending}
                            >
                                Cancel
                            </button>
                            <button
                                type="button"
                                onClick={submitActivation}
                                disabled={activationSubmitDisabled}
                                className="crm-btn-primary disabled:cursor-not-allowed disabled:opacity-50"
                            >
                                {activateDealMutation.isPending
                                    ? 'Submitting...'
                                    : (activationPaymentMethod === 'stk' || activationPaymentMethod === 'link')
                                        ? 'Initiate payment'
                                        : 'Activate subscription'}
                            </button>
                        </footer>
                    </div>
                </div>
            ) : null}

            {!isReadOnly && showDealModal ? (
                <DealModal
                    client={client}
                    products={products}
                    onClose={() => setShowDealModal(false)}
                    onSubmit={(deal) => createDealMutation.mutate(deal)}
                    isPending={createDealMutation.isPending}
                    error={createDealMutation.error}
                />
            ) : null}

            {!isReadOnly ? (
                <ConfirmDialog
                    open={showSyncConfirm}
                    title="Sync Client from WordPress"
                    message="This refreshes client profile fields from WordPress and may overwrite CRM-side contact data for synced fields."
                    confirmLabel="Sync now"
                    onCancel={() => setShowSyncConfirm(false)}
                    onConfirm={() => syncMutation.mutate()}
                    confirmDisabled={syncMutation.isPending}
                    isPending={syncMutation.isPending}
                />
            ) : null}

            {!isReadOnly ? (
                <CredentialDispatchDrawer
                    open={showCredentialDrawer}
                    client={client}
                    defaultSource="client_detail"
                    defaultReason="Credential dispatch from client detail"
                    onClose={() => setShowCredentialDrawer(false)}
                    onSuccess={() => {
                        queryClient.invalidateQueries({ queryKey: ['client-timeline', id] });
                        queryClient.invalidateQueries({ queryKey: ['client', id] });
                    }}
                />
            ) : null}
        </div>
    );
}

function DealModal({ client, products, onClose, onSubmit, isPending, error }) {
    const platformCurrency = client?.platform?.currency_code || 'KES';
    const [form, setForm] = useState({
        client_id: client.id,
        product_id: '',
        product_price_id: '',
    });

    const selectedProduct = products?.find((p) => String(p.id) === String(form.product_id));
    const availablePrices = selectedProduct?.active_prices || [];
    const selectedPrice = availablePrices.find((p) => String(p.id) === String(form.product_price_id));

    const handleProductChange = (e) => {
        const productId = e.target.value;
        const product = products?.find((p) => String(p.id) === String(productId));
        const prices = product?.active_prices || [];
        setForm({
            ...form,
            product_id: productId,
            product_price_id: prices.length === 1 ? String(prices[0].id) : '',
        });
    };

    const handleSubmit = (e) => {
        e.preventDefault();
        onSubmit(form);
    };

    const canSubmit = form.product_id && form.product_price_id && !isPending;

    return (
        <div className="fixed inset-0 z-50 flex items-center justify-center bg-slate-900/45 p-4" onClick={onClose}>
            <div className="w-full max-w-lg rounded-lg border border-slate-200 bg-white shadow-xl" onClick={(e) => e.stopPropagation()}>
                <header className="crm-panel-header">
                    <div>
                        <h3 className="crm-panel-title">Create New Subscription</h3>
                        <p className="crm-panel-subtitle">{client.name}</p>
                    </div>
                </header>

                <form onSubmit={handleSubmit} className="space-y-4 p-4">
                    <div>
                        <label className="mb-1 block text-sm font-medium text-slate-700">Package</label>
                        <select
                            value={form.product_id}
                            onChange={handleProductChange}
                            required
                            className="crm-select w-full"
                        >
                            <option value="">Select a package...</option>
                            {products?.map((product) => (
                                <option key={product.id} value={product.id}>
                                    {product.display_name || product.name}
                                </option>
                            ))}
                        </select>
                    </div>

                    {form.product_id && availablePrices.length > 0 ? (
                        <div>
                            <label className="mb-1 block text-sm font-medium text-slate-700">Duration &amp; Price</label>
                            <select
                                value={form.product_price_id}
                                onChange={(e) => setForm({ ...form, product_price_id: e.target.value })}
                                required
                                className="crm-select w-full"
                            >
                                <option value="">Select a duration...</option>
                                {availablePrices.map((price) => (
                                    <option key={price.id} value={price.id}>
                                        {price.duration_label} — {formatCurrency(price.price, price.currency || platformCurrency)}
                                    </option>
                                ))}
                            </select>
                        </div>
                    ) : form.product_id ? (
                        <p className="text-sm text-amber-600">No active pricing options for this package.</p>
                    ) : null}

                    {selectedPrice ? (
                        <div className="rounded-md bg-slate-50 px-3 py-2 text-sm text-slate-600">
                            <span className="font-medium">{selectedProduct?.display_name || selectedProduct?.name}</span>
                            {' · '}
                            {selectedPrice.duration_label}
                            {' · '}
                            <span className="font-semibold text-slate-900">{formatCurrency(selectedPrice.price, selectedPrice.currency || platformCurrency)}</span>
                            {selectedPrice.duration_days ? <span className="text-slate-400"> ({selectedPrice.duration_days} days)</span> : null}
                        </div>
                    ) : null}

                    {error ? <p className="text-sm text-rose-700">Failed to create subscription. {error.response?.data?.message || 'Please try again.'}</p> : null}

                    <div className="flex items-center justify-end gap-2 border-t border-slate-100 pt-3">
                        <button type="button" onClick={onClose} className="crm-btn-secondary">Cancel</button>
                        <button type="submit" disabled={!canSubmit} className="crm-btn-primary disabled:cursor-not-allowed disabled:opacity-50">
                            {isPending ? 'Creating...' : 'Create subscription'}
                        </button>
                    </div>
                </form>
            </div>
        </div>
    );
}
