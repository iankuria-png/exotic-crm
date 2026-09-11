import React, { useEffect, useMemo, useState } from 'react';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { useSearchParams } from 'react-router-dom';
import api from '../services/api';
import ConfirmDialog from '../components/ConfirmDialog';
import DataTable from '../components/DataTable';
import MetricCard from '../components/MetricCard';
import PageHeader from '../components/PageHeader';
import { useToast } from '../components/ToastProvider';

const DASHBOARD_MARKET_STORAGE_KEY = 'exoticcrm.dashboard.market_filter';

const STATUS_OPTIONS = [
    { value: '', label: 'All statuses' },
    { value: 'active', label: 'Active' },
    { value: 'scheduled', label: 'Scheduled' },
    { value: 'paused', label: 'Paused' },
    { value: 'expired', label: 'Expired' },
];

const DEFAULT_FORM = {
    post_title: '',
    _campaign_format: 'card',
    _campaign_badge_text: '',
    _campaign_description: '',
    _campaign_icon_class: 'fa fa-bullhorn',
    _campaign_color_primary: '#AB1C2F',
    _campaign_color_secondary: '',
    _campaign_image_id: '',
    _campaign_image_url: '',
    _campaign_image_alt: '',
    _campaign_cta_text: '',
    _campaign_cta_url: '',
    _campaign_cta_visible: true,
    _campaign_status: 'scheduled',
    _campaign_priority: 10,
    _campaign_start_date: '',
    _campaign_end_date: '',
};

function normalizePlatformId(value) {
    const raw = String(value ?? '').trim();
    return /^\d+$/.test(raw) ? raw : '';
}

function number(value) {
    return Number(value || 0).toLocaleString();
}

function percent(value) {
    return `${Number(value || 0).toFixed(2)}%`;
}

function statusLabel(status) {
    return String(status || 'scheduled').replaceAll('_', ' ');
}

function statusTone(status) {
    if (status === 'active') return 'bg-emerald-50 text-emerald-700 ring-emerald-200';
    if (status === 'scheduled') return 'bg-blue-50 text-blue-700 ring-blue-200';
    if (status === 'paused') return 'bg-amber-50 text-amber-700 ring-amber-200';
    if (status === 'expired') return 'bg-slate-100 text-slate-600 ring-slate-200';
    return 'bg-slate-100 text-slate-700 ring-slate-200';
}

function toInputDate(value) {
    if (!value) return '';
    return String(value).replace(' ', 'T').slice(0, 16);
}

function toApiDate(value) {
    if (!value) return '';
    const normalized = String(value).replace('T', ' ');
    return normalized.length === 16 ? `${normalized}:00` : normalized;
}

function fromCampaign(item) {
    return {
        post_title: item.title || '',
        _campaign_format: item.format || 'card',
        _campaign_badge_text: item.badge_text || '',
        _campaign_description: item.description || '',
        _campaign_icon_class: item.icon_class || 'fa fa-bullhorn',
        _campaign_color_primary: item.color_primary || '#AB1C2F',
        _campaign_color_secondary: item.color_secondary || '',
        _campaign_image_id: item.image_id ? String(item.image_id) : '',
        _campaign_image_url: item.image_url || '',
        _campaign_image_alt: item.image_alt || '',
        _campaign_cta_text: item.cta_text || '',
        _campaign_cta_url: item.cta_url || '',
        _campaign_cta_visible: Boolean(item.cta_visible),
        _campaign_status: item.status || 'scheduled',
        _campaign_priority: Number(item.priority || 10),
        _campaign_start_date: toInputDate(item.start_date),
        _campaign_end_date: toInputDate(item.end_date),
    };
}

function toPayload(form) {
    const payload = {
        post_title: form.post_title.trim(),
        _campaign_format: form._campaign_format,
        _campaign_badge_text: form._campaign_badge_text.trim(),
        _campaign_description: form._campaign_description.trim(),
        _campaign_icon_class: form._campaign_icon_class.trim(),
        _campaign_color_primary: form._campaign_color_primary,
        _campaign_color_secondary: form._campaign_color_secondary,
        _campaign_cta_text: form._campaign_cta_text.trim(),
        _campaign_cta_url: form._campaign_cta_url.trim(),
        _campaign_cta_visible: Boolean(form._campaign_cta_visible),
        _campaign_status: form._campaign_status,
        _campaign_priority: Number(form._campaign_priority || 10),
        _campaign_start_date: toApiDate(form._campaign_start_date),
        _campaign_end_date: toApiDate(form._campaign_end_date),
    };

    if (form._campaign_format === 'image') {
        payload._campaign_image_id = Number(form._campaign_image_id || 0);
        payload._campaign_image_alt = form._campaign_image_alt.trim();
    }

    return payload;
}

function IconButton({ label, onClick, children, disabled = false, tone = 'default' }) {
    const toneClass = tone === 'danger'
        ? 'text-rose-700 hover:bg-rose-50 disabled:text-rose-300'
        : tone === 'warning'
            ? 'text-amber-700 hover:bg-amber-50 disabled:text-amber-300'
            : 'text-slate-600 hover:bg-slate-100 disabled:text-slate-300';

    return (
        <button
            type="button"
            aria-label={label}
            title={label}
            onClick={onClick}
            disabled={disabled}
            className={`inline-flex h-8 w-8 items-center justify-center rounded-md transition disabled:cursor-not-allowed ${toneClass}`}
        >
            {children}
        </button>
    );
}

function SvgIcon({ path }) {
    return (
        <svg className="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
            <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d={path} />
        </svg>
    );
}

function Field({ label, children, hint }) {
    return (
        <label className="block">
            <span className="text-xs font-semibold uppercase tracking-[0.10em] text-slate-500">{label}</span>
            <div className="mt-1.5">{children}</div>
            {hint ? <span className="mt-1 block text-xs text-slate-500">{hint}</span> : null}
        </label>
    );
}

function BannerAdFormModal({
    open,
    title,
    platformId,
    initial,
    onClose,
    onSubmit,
    isPending,
}) {
    const toast = useToast();
    const [form, setForm] = useState(DEFAULT_FORM);
    const [mediaSearch, setMediaSearch] = useState('');
    const [uploadFile, setUploadFile] = useState(null);

    useEffect(() => {
        if (open) {
            setForm(initial ? fromCampaign(initial) : DEFAULT_FORM);
            setMediaSearch('');
            setUploadFile(null);
        }
    }, [initial, open]);

    const mediaQuery = useQuery({
        queryKey: ['banner-ad-media', platformId, mediaSearch],
        queryFn: () => api.get('/crm/banner-ads/media', {
            params: {
                platform_id: platformId,
                search: mediaSearch || undefined,
                per_page: 18,
            },
        }).then((response) => response.data),
        enabled: open && form._campaign_format === 'image' && Boolean(platformId),
    });

    const uploadMutation = useMutation({
        mutationFn: () => {
            const data = new FormData();
            data.append('platform_id', platformId);
            data.append('file', uploadFile);
            if (form._campaign_image_alt) {
                data.append('alt_text', form._campaign_image_alt);
            }

            return api.post('/crm/banner-ads/media', data, {
                headers: { 'Content-Type': 'multipart/form-data' },
            }).then((response) => response.data);
        },
        onSuccess: (response) => {
            const media = response.item;
            setForm((current) => ({
                ...current,
                _campaign_image_id: String(media.id || ''),
                _campaign_image_url: media.url || media.thumbnail_url || '',
                _campaign_image_alt: media.alt_text || current._campaign_image_alt,
            }));
            setUploadFile(null);
            mediaQuery.refetch();
            toast.success(response?.message || 'Image uploaded.');
        },
        onError: (error) => {
            toast.error(error?.response?.data?.message || 'Image upload failed.');
        },
    });

    if (!open) return null;

    const update = (key, value) => setForm((current) => ({ ...current, [key]: value }));
    const selectedImage = form._campaign_image_url;

    return (
        <div className="fixed inset-0 z-[100] flex items-center justify-center bg-slate-900/45 p-3 sm:p-6" onMouseDown={onClose}>
            <div
                role="dialog"
                aria-modal="true"
                aria-labelledby="banner-ad-form-title"
                className="flex max-h-[92vh] w-full max-w-5xl flex-col overflow-hidden rounded-lg border border-slate-200 bg-white shadow-2xl"
                onMouseDown={(event) => event.stopPropagation()}
            >
                <header className="crm-panel-header">
                    <div>
                        <h3 id="banner-ad-form-title" className="crm-panel-title">{title}</h3>
                        <p className="crm-panel-subtitle">Changes are written to the selected WordPress market.</p>
                    </div>
                    <button type="button" onClick={onClose} className="rounded-md p-2 text-slate-400 transition hover:bg-slate-100 hover:text-slate-700" aria-label="Close">
                        <SvgIcon path="M6 18 18 6M6 6l12 12" />
                    </button>
                </header>

                <form
                    className="overflow-y-auto p-4 sm:p-5"
                    onSubmit={(event) => {
                        event.preventDefault();
                        onSubmit(toPayload(form));
                    }}
                >
                    <div className="grid gap-5 lg:grid-cols-[minmax(0,1fr)_320px]">
                        <div className="space-y-5">
                            <section className="grid gap-4 sm:grid-cols-2">
                                <Field label="Title">
                                    <input required maxLength={100} className="crm-input" value={form.post_title} onChange={(event) => update('post_title', event.target.value)} />
                                </Field>
                                <Field label="Format">
                                    <div className="grid grid-cols-2 gap-2">
                                        {['card', 'image'].map((format) => (
                                            <button
                                                key={format}
                                                type="button"
                                                onClick={() => update('_campaign_format', format)}
                                                className={`rounded-md border px-3 py-2 text-sm font-semibold capitalize transition ${form._campaign_format === format ? 'border-teal-300 bg-teal-50 text-teal-800' : 'border-slate-200 text-slate-600 hover:bg-slate-50'}`}
                                            >
                                                {format}
                                            </button>
                                        ))}
                                    </div>
                                </Field>
                            </section>

                            {form._campaign_format === 'card' ? (
                                <section className="grid gap-4 sm:grid-cols-2">
                                    <Field label="Badge">
                                        <input maxLength={20} className="crm-input" value={form._campaign_badge_text} onChange={(event) => update('_campaign_badge_text', event.target.value)} />
                                    </Field>
                                    <Field label="Icon class">
                                        <input maxLength={60} className="crm-input" value={form._campaign_icon_class} onChange={(event) => update('_campaign_icon_class', event.target.value)} placeholder="fa fa-bullhorn" />
                                    </Field>
                                    <Field label="Primary color">
                                        <input type="color" className="h-10 w-full rounded-md border border-slate-200 bg-white p-1" value={form._campaign_color_primary} onChange={(event) => update('_campaign_color_primary', event.target.value)} />
                                    </Field>
                                    <Field label="Secondary color">
                                        <input type="color" className="h-10 w-full rounded-md border border-slate-200 bg-white p-1" value={form._campaign_color_secondary || '#AB1C2F'} onChange={(event) => update('_campaign_color_secondary', event.target.value)} />
                                    </Field>
                                    <div className="sm:col-span-2">
                                        <Field label="Description">
                                            <textarea maxLength={200} rows={3} className="crm-input resize-y" value={form._campaign_description} onChange={(event) => update('_campaign_description', event.target.value)} />
                                        </Field>
                                    </div>
                                </section>
                            ) : (
                                <section className="space-y-4">
                                    <div className="grid gap-4 lg:grid-cols-[220px_minmax(0,1fr)]">
                                        <div className="overflow-hidden rounded-lg border border-slate-200 bg-slate-50">
                                            {selectedImage ? (
                                                <img src={selectedImage} alt="" className="aspect-[4/3] w-full object-cover" />
                                            ) : (
                                                <div className="flex aspect-[4/3] items-center justify-center text-sm font-medium text-slate-400">No image selected</div>
                                            )}
                                            <div className="border-t border-slate-200 p-3 text-xs text-slate-500">
                                                Attachment ID: {form._campaign_image_id || 'None'}
                                            </div>
                                        </div>
                                        <div className="space-y-3">
                                            <Field label="Image alt text">
                                                <input maxLength={125} className="crm-input" value={form._campaign_image_alt} onChange={(event) => update('_campaign_image_alt', event.target.value)} />
                                            </Field>
                                            <div className="grid gap-2 sm:grid-cols-[minmax(0,1fr)_auto]">
                                                <input type="file" accept="image/*" onChange={(event) => setUploadFile(event.target.files?.[0] || null)} className="block w-full text-sm text-slate-600 file:mr-3 file:rounded-md file:border-0 file:bg-slate-100 file:px-3 file:py-2 file:text-sm file:font-semibold file:text-slate-700 hover:file:bg-slate-200" />
                                                <button type="button" disabled={!uploadFile || uploadMutation.isPending} onClick={() => uploadMutation.mutate()} className="crm-btn-primary px-3 py-2 text-sm disabled:cursor-not-allowed disabled:opacity-50">
                                                    {uploadMutation.isPending ? 'Uploading...' : 'Upload'}
                                                </button>
                                            </div>
                                            <Field label="Search media library">
                                                <input className="crm-input" value={mediaSearch} onChange={(event) => setMediaSearch(event.target.value)} placeholder="Search images" />
                                            </Field>
                                        </div>
                                    </div>
                                    <div className="grid max-h-64 grid-cols-2 gap-2 overflow-y-auto rounded-lg border border-slate-200 p-2 sm:grid-cols-3 lg:grid-cols-6">
                                        {mediaQuery.isLoading ? (
                                            <p className="col-span-full p-3 text-sm text-slate-500">Loading images...</p>
                                        ) : (mediaQuery.data?.items || []).length ? (
                                            mediaQuery.data.items.map((media) => (
                                                <button
                                                    key={media.id}
                                                    type="button"
                                                    onClick={() => setForm((current) => ({
                                                        ...current,
                                                        _campaign_image_id: String(media.id),
                                                        _campaign_image_url: media.url || media.thumbnail_url || '',
                                                        _campaign_image_alt: current._campaign_image_alt || media.alt_text || '',
                                                    }))}
                                                    className={`overflow-hidden rounded-md border text-left transition ${Number(form._campaign_image_id) === Number(media.id) ? 'border-teal-400 ring-2 ring-teal-100' : 'border-slate-200 hover:border-slate-300'}`}
                                                    title={media.title || `Image ${media.id}`}
                                                >
                                                    <img src={media.thumbnail_url || media.url} alt="" className="aspect-square w-full object-cover" />
                                                </button>
                                            ))
                                        ) : (
                                            <p className="col-span-full p-3 text-sm text-slate-500">No images found.</p>
                                        )}
                                    </div>
                                </section>
                            )}

                            <section className="grid gap-4 sm:grid-cols-2">
                                <Field label="CTA text">
                                    <input maxLength={30} className="crm-input" value={form._campaign_cta_text} onChange={(event) => update('_campaign_cta_text', event.target.value)} />
                                </Field>
                                <Field label="CTA URL">
                                    <input type="url" className="crm-input" value={form._campaign_cta_url} onChange={(event) => update('_campaign_cta_url', event.target.value)} placeholder="https://example.com" />
                                </Field>
                                {form._campaign_format === 'image' ? (
                                    <label className="flex items-center gap-2 text-sm font-medium text-slate-700">
                                        <input type="checkbox" checked={form._campaign_cta_visible} onChange={(event) => update('_campaign_cta_visible', event.target.checked)} className="h-4 w-4 rounded border-slate-300 text-teal-600 focus:ring-teal-500" />
                                        Show CTA overlay
                                    </label>
                                ) : null}
                            </section>
                        </div>

                        <aside className="space-y-4 rounded-lg border border-slate-200 bg-slate-50 p-4">
                            <Field label="Status">
                                <select className="crm-input" value={form._campaign_status} onChange={(event) => update('_campaign_status', event.target.value)}>
                                    {STATUS_OPTIONS.filter((option) => option.value).map((option) => (
                                        <option key={option.value} value={option.value}>{option.label}</option>
                                    ))}
                                </select>
                            </Field>
                            <Field label="Priority" hint="Lower numbers display first when shuffle is off.">
                                <input type="number" min="1" className="crm-input" value={form._campaign_priority} onChange={(event) => update('_campaign_priority', event.target.value)} />
                            </Field>
                            <Field label="Start date">
                                <input type="datetime-local" required={form._campaign_status === 'scheduled'} className="crm-input" value={form._campaign_start_date} onChange={(event) => update('_campaign_start_date', event.target.value)} />
                            </Field>
                            <Field label="End date">
                                <input type="datetime-local" className="crm-input" value={form._campaign_end_date} onChange={(event) => update('_campaign_end_date', event.target.value)} />
                            </Field>
                        </aside>
                    </div>

                    <footer className="mt-5 flex flex-wrap items-center justify-end gap-2 border-t border-slate-100 pt-4">
                        <button type="button" onClick={onClose} disabled={isPending} className="crm-btn-secondary disabled:cursor-not-allowed disabled:opacity-50">
                            Cancel
                        </button>
                        <button type="submit" disabled={isPending} className="crm-btn-primary disabled:cursor-not-allowed disabled:opacity-50">
                            {isPending ? 'Saving...' : 'Save Banner Ad'}
                        </button>
                    </footer>
                </form>
            </div>
        </div>
    );
}

function ScheduleDialog({ open, row, platformId, onCancel, onConfirm, isPending }) {
    const [startDate, setStartDate] = useState('');
    const [endDate, setEndDate] = useState('');

    useEffect(() => {
        if (open) {
            setStartDate(toInputDate(row?.start_date));
            setEndDate(toInputDate(row?.end_date));
        }
    }, [open, row]);

    return (
        <ConfirmDialog
            open={open}
            title="Schedule Banner Ad"
            message={row?.title || 'Choose when this banner ad should become active.'}
            confirmLabel="Schedule"
            tone="warning"
            confirmDisabled={!platformId || !startDate || isPending}
            isPending={isPending}
            onCancel={onCancel}
            onConfirm={() => onConfirm({
                status: 'scheduled',
                start_date: toApiDate(startDate),
                end_date: toApiDate(endDate),
            })}
        >
            <Field label="Start date">
                <input type="datetime-local" className="crm-input" value={startDate} onChange={(event) => setStartDate(event.target.value)} />
            </Field>
            <Field label="End date">
                <input type="datetime-local" className="crm-input" value={endDate} onChange={(event) => setEndDate(event.target.value)} />
            </Field>
        </ConfirmDialog>
    );
}

function MetricsDialog({ row, onClose }) {
    return (
        <ConfirmDialog
            open={Boolean(row)}
            title="Banner Ad Metrics"
            message={row?.title || ''}
            confirmLabel="Close"
            onCancel={onClose}
            onConfirm={onClose}
        >
            <div className="grid gap-3 sm:grid-cols-3">
                <MetricCard label="Impressions" value={number(row?.impressions)} tone="accent" />
                <MetricCard label="Clicks" value={number(row?.clicks)} tone="success" />
                <MetricCard label="CTR" value={percent(row?.ctr)} tone="slate" />
            </div>
            <dl className="grid gap-2 rounded-lg bg-slate-50 p-3 text-sm sm:grid-cols-2">
                <div>
                    <dt className="text-xs font-semibold uppercase tracking-[0.10em] text-slate-500">Status</dt>
                    <dd className="mt-1 font-medium capitalize text-slate-800">{statusLabel(row?.status)}</dd>
                </div>
                <div>
                    <dt className="text-xs font-semibold uppercase tracking-[0.10em] text-slate-500">Priority</dt>
                    <dd className="mt-1 font-medium text-slate-800">{row?.priority || '-'}</dd>
                </div>
                <div>
                    <dt className="text-xs font-semibold uppercase tracking-[0.10em] text-slate-500">Starts</dt>
                    <dd className="mt-1 font-medium text-slate-800">{row?.start_date || '-'}</dd>
                </div>
                <div>
                    <dt className="text-xs font-semibold uppercase tracking-[0.10em] text-slate-500">Ends</dt>
                    <dd className="mt-1 font-medium text-slate-800">{row?.end_date || '-'}</dd>
                </div>
            </dl>
        </ConfirmDialog>
    );
}

export default function BannerAds() {
    const toast = useToast();
    const queryClient = useQueryClient();
    const [searchParams, setSearchParams] = useSearchParams();
    const [platformId, setPlatformId] = useState(() => {
        const requested = normalizePlatformId(searchParams.get('platform_id'));
        if (requested) {
            return requested;
        }

        if (typeof window === 'undefined') {
            return '';
        }

        return normalizePlatformId(window.localStorage.getItem(DASHBOARD_MARKET_STORAGE_KEY));
    });
    const [statusFilter, setStatusFilter] = useState('');
    const [page, setPage] = useState(1);
    const [perPage, setPerPage] = useState(50);
    const [formState, setFormState] = useState({ open: false, row: null });
    const [deleteRow, setDeleteRow] = useState(null);
    const [scheduleRow, setScheduleRow] = useState(null);
    const [metricsRow, setMetricsRow] = useState(null);

    const marketsQuery = useQuery({
        queryKey: ['banner-ad-markets'],
        queryFn: () => api.get('/crm/banner-ads/markets').then((response) => response.data),
    });

    const markets = marketsQuery.data?.data || [];
    const selectedMarket = markets.find((market) => String(market.id) === String(platformId));
    const canLoadAds = Boolean(platformId && selectedMarket?.banner_ads_ready);

    useEffect(() => {
        if (typeof window !== 'undefined') {
            if (platformId) {
                window.localStorage.setItem(DASHBOARD_MARKET_STORAGE_KEY, platformId);
            } else {
                window.localStorage.removeItem(DASHBOARD_MARKET_STORAGE_KEY);
            }
        }

        const current = normalizePlatformId(searchParams.get('platform_id'));
        if (platformId && current !== platformId) {
            const params = new URLSearchParams(searchParams);
            params.set('platform_id', platformId);
            setSearchParams(params, { replace: true });
        } else if (!platformId && current) {
            const params = new URLSearchParams(searchParams);
            params.delete('platform_id');
            setSearchParams(params, { replace: true });
        }
    }, [platformId, searchParams, setSearchParams]);

    const selectPlatform = (value, { resetPage = true } = {}) => {
        const nextPlatformId = normalizePlatformId(value);
        if (nextPlatformId === platformId) {
            return;
        }

        setPlatformId(nextPlatformId);
        if (resetPage) {
            setPage(1);
        }
    };

    useEffect(() => {
        if (markets.length === 0) {
            return;
        }

        const selectedStillAccessible = platformId && markets.some((market) => String(market.id) === String(platformId));
        if (selectedStillAccessible) {
            return;
        }

        const ready = markets.find((market) => market.banner_ads_ready);
        selectPlatform(String((ready || markets[0]).id));
    }, [markets, platformId]);

    const listQuery = useQuery({
        queryKey: ['banner-ads', platformId, statusFilter, page, perPage],
        queryFn: () => api.get('/crm/banner-ads', {
            params: {
                platform_id: platformId,
                status: statusFilter || undefined,
                page,
                per_page: perPage,
            },
        }).then((response) => response.data),
        enabled: canLoadAds,
    });

    const invalidateAds = () => {
        queryClient.invalidateQueries({ queryKey: ['banner-ads'] });
        queryClient.invalidateQueries({ queryKey: ['banner-ad-markets'] });
    };

    const saveMutation = useMutation({
        mutationFn: ({ row, payload }) => {
            const data = { platform_id: Number(platformId), ...payload };
            if (row?.id) {
                return api.patch(`/crm/banner-ads/${row.id}`, data).then((response) => response.data);
            }
            return api.post('/crm/banner-ads', data).then((response) => response.data);
        },
        onSuccess: (response) => {
            toast.success(response?.message || 'Banner ad saved.');
            setFormState({ open: false, row: null });
            invalidateAds();
        },
        onError: (error) => {
            toast.error(error?.response?.data?.message || 'Failed to save banner ad.');
        },
    });

    const statusMutation = useMutation({
        mutationFn: ({ row, payload }) => api.post(`/crm/banner-ads/${row.id}/status`, {
            platform_id: Number(platformId),
            ...payload,
        }).then((response) => response.data),
        onSuccess: (response) => {
            toast.success(response?.message || 'Banner ad status updated.');
            setScheduleRow(null);
            invalidateAds();
        },
        onError: (error) => {
            toast.error(error?.response?.data?.message || 'Failed to update banner ad status.');
        },
    });

    const deleteMutation = useMutation({
        mutationFn: (row) => api.delete(`/crm/banner-ads/${row.id}`, {
            data: {
                platform_id: Number(platformId),
                reason: 'Deleted from CRM Banner Ads.',
            },
        }).then((response) => response.data),
        onSuccess: () => {
            toast.success('Banner ad deleted.');
            setDeleteRow(null);
            invalidateAds();
        },
        onError: (error) => {
            toast.error(error?.response?.data?.message || 'Failed to delete banner ad.');
        },
    });

    const settingsMutation = useMutation({
        mutationFn: (shuffleMode) => api.patch('/crm/banner-ads/settings', {
            platform_id: Number(platformId),
            shuffle_mode: Boolean(shuffleMode),
        }).then((response) => response.data),
        onSuccess: (response) => {
            toast.success(response?.message || 'Settings updated.');
            invalidateAds();
        },
        onError: (error) => {
            toast.error(error?.response?.data?.message || 'Failed to update shuffle mode.');
        },
    });

    const data = listQuery.data || {};
    const rows = data.items || [];
    const summary = data.summary || {};
    const settings = data.settings || {};

    const columns = useMemo(() => [
        {
            key: 'title',
            label: 'Ad',
            render: (row) => (
                <div className="flex min-w-[220px] items-center gap-3">
                    {row.format === 'image' && row.image_url ? (
                        <img src={row.image_url} alt="" className="h-12 w-12 rounded-md object-cover ring-1 ring-slate-200" />
                    ) : (
                        <span className="flex h-12 w-12 items-center justify-center rounded-md text-sm font-bold text-white" style={{ backgroundColor: row.color_primary || '#AB1C2F' }}>
                            {(row.title || 'B').slice(0, 1).toUpperCase()}
                        </span>
                    )}
                    <div className="min-w-0">
                        <p className="truncate font-semibold text-slate-900">{row.title}</p>
                        <p className="truncate text-xs text-slate-500">
                            {row.format === 'image' ? `Image ID ${row.image_id || '-'}` : (row.badge_text || row.description || 'Card ad')}
                        </p>
                    </div>
                </div>
            ),
        },
        {
            key: 'format',
            label: 'Format',
            render: (row) => <span className="capitalize text-slate-700">{row.format}</span>,
        },
        {
            key: 'status',
            label: 'Status',
            render: (row) => (
                <span className={`inline-flex rounded-full px-2 py-0.5 text-xs font-semibold capitalize ring-1 ring-inset ${statusTone(row.status)}`}>
                    {statusLabel(row.status)}
                </span>
            ),
        },
        { key: 'priority', label: 'Priority' },
        { key: 'impressions', label: 'Impr.', render: (row) => number(row.impressions) },
        { key: 'clicks', label: 'Clicks', render: (row) => number(row.clicks) },
        { key: 'ctr', label: 'CTR', render: (row) => percent(row.ctr) },
        {
            key: 'actions',
            label: '',
            render: (row) => (
                <div className="flex items-center justify-end gap-1">
                    <IconButton label="View metrics" onClick={() => setMetricsRow(row)}>
                        <SvgIcon path="M3.75 19.5h16.5M6.75 16.5v-5.25m5.25 5.25V8.25m5.25 8.25v-3.75" />
                    </IconButton>
                    <IconButton label="Edit" onClick={() => setFormState({ open: true, row })}>
                        <SvgIcon path="m16.862 4.487 1.687-1.688a1.875 1.875 0 1 1 2.652 2.652L7.5 19.152 3.75 20.25l1.098-3.75L16.862 4.487Z" />
                    </IconButton>
                    <IconButton label="Schedule" tone="warning" onClick={() => setScheduleRow(row)}>
                        <SvgIcon path="M12 6v6l3.75 2.25M21 12a9 9 0 1 1-18 0 9 9 0 0 1 18 0Z" />
                    </IconButton>
                    <IconButton label="Activate" disabled={row.status === 'active'} onClick={() => statusMutation.mutate({ row, payload: { status: 'active' } })}>
                        <SvgIcon path="M4.5 12.75 9 17.25 19.5 6.75" />
                    </IconButton>
                    <IconButton label="Pause" disabled={row.status === 'paused'} tone="warning" onClick={() => statusMutation.mutate({ row, payload: { status: 'paused' } })}>
                        <SvgIcon path="M7.5 5.25v13.5m9-13.5v13.5" />
                    </IconButton>
                    <IconButton label="Expire" disabled={row.status === 'expired'} onClick={() => statusMutation.mutate({ row, payload: { status: 'expired' } })}>
                        <SvgIcon path="M6 18 18 6M6 6l12 12" />
                    </IconButton>
                    <IconButton label="Delete permanently" tone="danger" onClick={() => setDeleteRow(row)}>
                        <SvgIcon path="m14.74 9-.346 9m-4.788 0L9.26 9m9.968-3.21c.342.052.682.107 1.022.166M18.16 19.673A2.25 2.25 0 0 1 15.916 21H8.084a2.25 2.25 0 0 1-2.244-2.077L4.772 5.79m14.456 0a48.108 48.108 0 0 0-3.478-.397m-12 .562c.34-.059.68-.114 1.022-.165m0 0a48.11 48.11 0 0 1 3.478-.397m7.5 0v-.916c0-1.18-.91-2.164-2.09-2.201a51.964 51.964 0 0 0-3.32 0c-1.18.037-2.09 1.022-2.09 2.201v.916m7.5 0a49.16 49.16 0 0 0-7.5 0" />
                    </IconButton>
                </div>
            ),
        },
    ], [statusMutation]);

    const pagination = {
        current_page: page,
        last_page: data.pages || 1,
        total: data.total || 0,
        per_page: perPage,
    };

    return (
        <div className="space-y-6">
            <PageHeader
                title="Banner Ads"
                subtitle="Manage WordPress banner ad campaigns by market."
                actions={(
                    <button
                        type="button"
                        onClick={() => setFormState({ open: true, row: null })}
                        disabled={!canLoadAds}
                        className="crm-btn-primary disabled:cursor-not-allowed disabled:opacity-50"
                    >
                        Add Ad
                    </button>
                )}
            />

            <section className="crm-surface p-4">
                <div className="grid gap-3 lg:grid-cols-[minmax(220px,320px)_180px_1fr_auto]">
                    <label className="block">
                        <span className="text-xs font-semibold uppercase tracking-[0.10em] text-slate-500">Market</span>
                        <select className="crm-input mt-1.5" value={platformId} onChange={(event) => selectPlatform(event.target.value)}>
                            {platformId && !selectedMarket ? (
                                <option value={platformId}>Selected market #{platformId}</option>
                            ) : null}
                            {!platformId ? (
                                <option value="">{marketsQuery.isLoading ? 'Loading markets...' : 'Select market'}</option>
                            ) : null}
                            {markets.map((market) => (
                                <option key={market.id} value={market.id}>
                                    {market.name}{market.banner_ads_ready ? '' : ' - not ready'}
                                </option>
                            ))}
                        </select>
                    </label>
                    <label className="block">
                        <span className="text-xs font-semibold uppercase tracking-[0.10em] text-slate-500">Status</span>
                        <select className="crm-input mt-1.5" value={statusFilter} onChange={(event) => { setStatusFilter(event.target.value); setPage(1); }}>
                            {STATUS_OPTIONS.map((option) => <option key={option.value} value={option.value}>{option.label}</option>)}
                        </select>
                    </label>
                    <div className="flex items-end">
                        {selectedMarket?.readiness_message ? (
                            <p className="rounded-md bg-amber-50 px-3 py-2 text-sm font-medium text-amber-800 ring-1 ring-amber-200">
                                {selectedMarket.readiness_message}
                            </p>
                        ) : (
                            <p className="rounded-md bg-emerald-50 px-3 py-2 text-sm font-medium text-emerald-800 ring-1 ring-emerald-200">
                                WordPress Banner Ads ready for {selectedMarket?.name || 'selected market'}.
                            </p>
                        )}
                    </div>
                    <label className="flex items-end justify-start gap-2 pb-2 text-sm font-semibold text-slate-700 lg:justify-end">
                        <input
                            type="checkbox"
                            checked={Boolean(settings.shuffle_mode)}
                            disabled={!canLoadAds || settingsMutation.isPending}
                            onChange={(event) => settingsMutation.mutate(event.target.checked)}
                            className="h-4 w-4 rounded border-slate-300 text-teal-600 focus:ring-teal-500 disabled:opacity-50"
                        />
                        Shuffle mode
                    </label>
                </div>
            </section>

            <div className="grid gap-3 sm:grid-cols-2 xl:grid-cols-5">
                <MetricCard label="Active" value={number(summary.banner_ads_active)} tone="success" isLoading={listQuery.isLoading} />
                <MetricCard label="Scheduled" value={number(summary.banner_ads_scheduled)} tone="accent" isLoading={listQuery.isLoading} />
                <MetricCard label="Paused" value={number(summary.banner_ads_paused)} tone="warning" isLoading={listQuery.isLoading} />
                <MetricCard label="Impressions" value={number(summary.impressions_total)} tone="slate" isLoading={listQuery.isLoading} />
                <MetricCard label="Avg CTR" value={percent(summary.avg_ctr)} tone="default" isLoading={listQuery.isLoading} />
            </div>

            {listQuery.isError ? (
                <section className="crm-surface border-rose-200 bg-rose-50 p-4 text-sm font-medium text-rose-800">
                    {listQuery.error?.response?.data?.message || 'WordPress Banner Ads could not be loaded.'}
                </section>
            ) : null}

            <DataTable
                columns={columns}
                data={canLoadAds ? rows : []}
                pagination={pagination}
                onPageChange={setPage}
                perPage={perPage}
                onPerPageChange={(value) => { setPerPage(value); setPage(1); }}
                isLoading={marketsQuery.isLoading || (canLoadAds && listQuery.isLoading)}
                emptyMessage={canLoadAds ? 'No banner ads found.' : 'Select a ready WordPress market to manage banner ads.'}
                compact
            />

            <BannerAdFormModal
                open={formState.open}
                title={formState.row ? 'Edit Banner Ad' : 'Add Banner Ad'}
                platformId={platformId}
                initial={formState.row}
                onClose={() => setFormState({ open: false, row: null })}
                isPending={saveMutation.isPending}
                onSubmit={(payload) => saveMutation.mutate({ row: formState.row, payload })}
            />

            <ScheduleDialog
                open={Boolean(scheduleRow)}
                row={scheduleRow}
                platformId={platformId}
                isPending={statusMutation.isPending}
                onCancel={() => setScheduleRow(null)}
                onConfirm={(payload) => statusMutation.mutate({ row: scheduleRow, payload })}
            />

            <MetricsDialog row={metricsRow} onClose={() => setMetricsRow(null)} />

            <ConfirmDialog
                open={Boolean(deleteRow)}
                title="Delete Banner Ad Permanently"
                message={deleteRow ? `${deleteRow.title} will be permanently deleted from WordPress.` : ''}
                confirmLabel="Delete"
                tone="danger"
                isPending={deleteMutation.isPending}
                onCancel={() => setDeleteRow(null)}
                onConfirm={() => deleteMutation.mutate(deleteRow)}
            />
        </div>
    );
}
