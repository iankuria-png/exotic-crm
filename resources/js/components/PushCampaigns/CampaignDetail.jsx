import React, { useEffect, useMemo, useState } from 'react';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import api from '../../services/api';
import { useToast } from '../ToastProvider';

function prettyStatus(status) {
    return (status || 'unknown').replaceAll('_', ' ');
}

function formatDateTime(value, fallback = '--') {
    if (!value) {
        return fallback;
    }

    const date = new Date(value);
    if (Number.isNaN(date.getTime())) {
        return String(value);
    }

    return new Intl.DateTimeFormat(undefined, {
        year: 'numeric',
        month: 'short',
        day: '2-digit',
        hour: '2-digit',
        minute: '2-digit',
    }).format(date);
}

function toDateTimeLocal(value) {
    if (!value) {
        return '';
    }

    const parsed = new Date(value);
    if (Number.isNaN(parsed.getTime())) {
        return '';
    }

    const tzOffsetMs = parsed.getTimezoneOffset() * 60 * 1000;
    return new Date(parsed.getTime() - tzOffsetMs).toISOString().slice(0, 16);
}

function canMutateItem(item) {
    return String(item?.status || '') !== 'sent';
}

function extractionReason(item) {
    const message = String(item?.error_message || '').trim();
    if (!message) {
        return null;
    }

    const [code, ...rest] = message.split(':');
    if (!rest.length) {
        return {
            code: 'issue',
            message,
        };
    }

    return {
        code: code.trim().replaceAll('_', ' '),
        message: rest.join(':').trim(),
    };
}

function timingStateMeta(timingState) {
    const state = String(timingState || '').toLowerCase();
    if (state === 'overdue') {
        return {
            label: 'Overdue',
            className: 'bg-rose-100 text-rose-700',
        };
    }

    if (state === 'send_now') {
        return {
            label: 'Sends now',
            className: 'bg-amber-100 text-amber-700',
        };
    }

    if (state === 'future_delayed') {
        return {
            label: 'Scheduled later',
            className: 'bg-teal-100 text-teal-700',
        };
    }

    if (state === 'outside_window') {
        return {
            label: 'Outside 24h queue',
            className: 'bg-slate-100 text-slate-700',
        };
    }

    return null;
}

const EMPTY_EDIT_FORM = {
    profile_url: '',
    profile_name: '',
    profile_phone: '',
    profile_image_url: '',
    profile_age: '',
    custom_message: '',
    scheduled_at: '',
};

function itemToEditForm(item) {
    return {
        profile_url: item?.profile_url || '',
        profile_name: item?.profile_name || '',
        profile_phone: item?.profile_phone || '',
        profile_image_url: item?.profile_image_url || '',
        profile_age: item?.profile_age || '',
        custom_message: item?.custom_message || '',
        scheduled_at: toDateTimeLocal(item?.scheduled_at),
    };
}

function mergeHydratedItemIntoForm(form, item) {
    const next = { ...form };
    const hydrated = itemToEditForm(item);

    ['profile_url', 'profile_name', 'profile_phone', 'profile_image_url', 'profile_age', 'scheduled_at'].forEach((field) => {
        if (!next[field] && hydrated[field]) {
            next[field] = hydrated[field];
        }
    });

    return next;
}

export default function CampaignDetail({ campaignId, onClose, onChanged }) {
    const toast = useToast();
    const queryClient = useQueryClient();
    const [itemPage, setItemPage] = useState(1);
    const [itemStatus, setItemStatus] = useState('');
    const [scheduleAt, setScheduleAt] = useState('');
    const [analytics, setAnalytics] = useState(null);
    const [previewDevice, setPreviewDevice] = useState('mobile');
    const [previewItemId, setPreviewItemId] = useState(null);
    const [editingItemId, setEditingItemId] = useState(null);
    const [editForm, setEditForm] = useState(EMPTY_EDIT_FORM);
    const [matchingItemId, setMatchingItemId] = useState(null);
    const [matchSearchInput, setMatchSearchInput] = useState('');
    const [matchSearch, setMatchSearch] = useState('');
    const [matchPage, setMatchPage] = useState(1);
    const [matchHydrateWp, setMatchHydrateWp] = useState(true);
    const [mediaUploadFile, setMediaUploadFile] = useState(null);
    const [hydratingItem, setHydratingItem] = useState(null);
    const [hydrationSources, setHydrationSources] = useState({});
    const [executeModalOpen, setExecuteModalOpen] = useState(false);
    const [executeReadiness, setExecuteReadiness] = useState(null);

    const detailQueryKey = ['push-campaign-detail', campaignId, itemPage, itemStatus];

    const { data, isLoading } = useQuery({
        queryKey: detailQueryKey,
        enabled: Boolean(campaignId),
        queryFn: () => api.get(`/crm/push-campaigns/${campaignId}`, {
            params: {
                page: itemPage,
                per_page: 50,
                ...(itemStatus ? { status: itemStatus } : {}),
            },
        }).then((response) => response.data),
    });

    useEffect(() => {
        if (!matchingItemId) {
            return undefined;
        }

        const handle = window.setTimeout(() => {
            setMatchSearch(matchSearchInput.trim());
            setMatchPage(1);
        }, 250);

        return () => window.clearTimeout(handle);
    }, [matchingItemId, matchSearchInput]);

    const matchCandidatesQuery = useQuery({
        queryKey: ['push-campaign-item-match-candidates', campaignId, matchingItemId, matchSearch, matchPage],
        enabled: Boolean(campaignId && matchingItemId),
        queryFn: () => api.get(`/crm/push-campaigns/${campaignId}/items/${matchingItemId}/match-candidates`, {
            params: {
                ...(matchSearch ? { search: matchSearch } : {}),
                page: matchPage,
                per_page: 10,
            },
        }).then((response) => response.data),
    });

    const mergeItemIntoCurrentDetail = (updatedItem) => {
        if (!updatedItem?.id) {
            return;
        }

        queryClient.setQueryData(detailQueryKey, (current) => {
            if (!current?.items?.data) {
                return current;
            }

            return {
                ...current,
                items: {
                    ...current.items,
                    data: current.items.data.map((row) => (row.id === updatedItem.id ? { ...row, ...updatedItem } : row)),
                },
            };
        });
    };

    const hydrateItemMutation = useMutation({
        mutationFn: ({ itemId, force }) => api.post(`/crm/push-campaigns/${campaignId}/items/${itemId}/hydrate-profile`, {
            force: Boolean(force),
        }).then((response) => response.data),
        onSuccess: (response, variables) => {
            const hydratedItem = response?.item;
            if (hydratedItem) {
                mergeItemIntoCurrentDetail(hydratedItem);
                setHydrationSources((prev) => ({
                    ...prev,
                    [hydratedItem.id]: response?.sources || null,
                }));

                if (editingItemId === hydratedItem.id) {
                    setEditForm((prev) => mergeHydratedItemIntoForm(prev, hydratedItem));
                }
            }

            queryClient.invalidateQueries({ queryKey: ['push-campaign-detail', campaignId] });
            if (variables?.itemId) {
                queryClient.invalidateQueries({ queryKey: ['push-campaign-item-media', campaignId, variables.itemId] });
            }
        },
        onError: (error, variables) => {
            if (variables?.silent) {
                return;
            }

            toast.error(error?.response?.data?.message || 'Failed to refresh profile details.');
        },
        onSettled: (_, __, variables) => {
            setHydratingItem((prev) => (prev?.itemId === variables?.itemId ? null : prev));
        },
    });

    const itemMediaQuery = useQuery({
        queryKey: ['push-campaign-item-media', campaignId, editingItemId],
        enabled: Boolean(campaignId && editingItemId),
        retry: false,
        queryFn: () => api.get(`/crm/push-campaigns/${campaignId}/items/${editingItemId}/media`).then((response) => response.data),
    });

    const selectItemMediaMutation = useMutation({
        mutationFn: ({ itemId, attachmentId }) => api.post(`/crm/push-campaigns/${campaignId}/items/${itemId}/media/select`, {
            attachment_id: attachmentId,
        }).then((response) => response.data),
        onSuccess: (response, variables) => {
            if (response?.item) {
                mergeItemIntoCurrentDetail(response.item);
                if (editingItemId === response.item.id) {
                    setEditForm((prev) => ({
                        ...prev,
                        profile_image_url: response.item.profile_image_url || prev.profile_image_url,
                    }));
                }
            }

            queryClient.invalidateQueries({ queryKey: ['push-campaign-detail', campaignId] });
            queryClient.invalidateQueries({ queryKey: ['push-campaign-item-media', campaignId, variables.itemId] });
            toast.success('Profile image applied to this campaign item.');
        },
        onError: (error) => {
            toast.error(error?.response?.data?.message || 'Failed to apply selected media.');
        },
    });

    const uploadItemMediaMutation = useMutation({
        mutationFn: ({ itemId, file, applyToItem }) => {
            const formData = new FormData();
            formData.append('file', file);
            formData.append('apply_to_item', applyToItem ? '1' : '0');

            return api.post(`/crm/push-campaigns/${campaignId}/items/${itemId}/media/upload`, formData, {
                headers: {
                    'Content-Type': 'multipart/form-data',
                },
            }).then((response) => response.data);
        },
        onSuccess: (response, variables) => {
            if (response?.item) {
                mergeItemIntoCurrentDetail(response.item);
                if (editingItemId === response.item.id) {
                    setEditForm((prev) => ({
                        ...prev,
                        profile_image_url: response.item.profile_image_url || prev.profile_image_url,
                    }));
                }
            }

            setMediaUploadFile(null);
            queryClient.invalidateQueries({ queryKey: ['push-campaign-detail', campaignId] });
            queryClient.invalidateQueries({ queryKey: ['push-campaign-item-media', campaignId, variables.itemId] });
            toast.success('Image uploaded and applied to this campaign item.');
        },
        onError: (error) => {
            toast.error(error?.response?.data?.message || 'Media upload failed.');
        },
    });

    const executeReadinessMutation = useMutation({
        mutationFn: () => api.get(`/crm/push-campaigns/${campaignId}/dispatch-readiness`, {
            params: {
                mode: 'execute_now',
            },
        }).then((response) => response.data),
        onSuccess: (response) => {
            setExecuteReadiness(response || null);
            setExecuteModalOpen(true);
        },
        onError: (error) => {
            toast.error(error?.response?.data?.message || 'Failed to check execution readiness.');
        },
    });

    const executeMutation = useMutation({
        mutationFn: () => api.post(`/crm/push-campaigns/${campaignId}/execute`, {}).then((response) => response.data),
        onSuccess: (response) => {
            queryClient.invalidateQueries({ queryKey: ['push-campaigns-list'] });
            queryClient.invalidateQueries({ queryKey: ['push-campaign-detail', campaignId] });
            onChanged?.();
            setExecuteModalOpen(false);
            setExecuteReadiness(null);
            toast.success(response?.dispatch_plan?.message || 'Campaign execution started.');
        },
        onError: (error) => {
            const readinessPayload = error?.response?.data;
            if (readinessPayload && typeof readinessPayload === 'object' && Object.prototype.hasOwnProperty.call(readinessPayload, 'can_activate')) {
                setExecuteReadiness(readinessPayload);
                setExecuteModalOpen(true);
                toast.warning(readinessPayload?.message || 'Campaign execution is blocked.');
                return;
            }

            toast.error(error?.response?.data?.message || 'Failed to execute campaign.');
        },
    });

    const scheduleMutation = useMutation({
        mutationFn: () => api.post(`/crm/push-campaigns/${campaignId}/schedule`, {
            scheduled_at: scheduleAt,
        }).then((response) => response.data),
        onSuccess: () => {
            queryClient.invalidateQueries({ queryKey: ['push-campaigns-list'] });
            queryClient.invalidateQueries({ queryKey: ['push-campaign-detail', campaignId] });
            onChanged?.();
            toast.success('Campaign scheduled successfully.');
        },
        onError: (error) => {
            toast.error(error?.response?.data?.message || 'Failed to schedule campaign.');
        },
    });

    const refreshAnalyticsMutation = useMutation({
        mutationFn: () => api.get(`/crm/push-campaigns/${campaignId}/analytics`).then((response) => response.data),
        onSuccess: (response) => {
            setAnalytics(response?.analytics || null);
            queryClient.invalidateQueries({ queryKey: ['push-campaign-detail', campaignId] });
            toast.success('Analytics refreshed.');
        },
        onError: (error) => {
            toast.error(error?.response?.data?.message || 'Failed to refresh analytics.');
        },
    });

    const deleteMutation = useMutation({
        mutationFn: () => api.delete(`/crm/push-campaigns/${campaignId}`).then((response) => response.data),
        onSuccess: () => {
            queryClient.invalidateQueries({ queryKey: ['push-campaigns-list'] });
            onChanged?.();
            toast.success('Campaign deleted.');
            onClose?.();
        },
        onError: (error) => {
            toast.error(error?.response?.data?.message || 'Failed to delete campaign.');
        },
    });

    const updateItemMutation = useMutation({
        mutationFn: ({ itemId, payload }) => api.patch(`/crm/push-campaigns/${campaignId}/items/${itemId}`, payload).then((response) => response.data),
        onSuccess: (response) => {
            if (response?.item) {
                mergeItemIntoCurrentDetail(response.item);
            }
            queryClient.invalidateQueries({ queryKey: ['push-campaign-detail', campaignId] });
            queryClient.invalidateQueries({ queryKey: ['push-campaigns-list'] });
            onChanged?.();
            toast.success('Campaign item updated.');
            setEditingItemId(null);
            setEditForm(EMPTY_EDIT_FORM);
            setMediaUploadFile(null);
        },
        onError: (error) => {
            toast.error(error?.response?.data?.message || 'Failed to update campaign item.');
        },
    });

    const matchCrmMutation = useMutation({
        mutationFn: ({ itemId, clientId }) => api.post(`/crm/push-campaigns/${campaignId}/items/${itemId}/match-crm`, {
            client_id: clientId,
            replace_profile_url: true,
            hydrate_wp_details: matchHydrateWp,
        }).then((response) => response.data),
        onSuccess: (response) => {
            if (response?.item) {
                mergeItemIntoCurrentDetail(response.item);
            }
            queryClient.invalidateQueries({ queryKey: ['push-campaign-detail', campaignId] });
            queryClient.invalidateQueries({ queryKey: ['push-campaigns-list'] });
            onChanged?.();
            toast.success('CRM profile matched to item.');
            setMatchingItemId(null);
            setMatchSearchInput('');
            setMatchSearch('');
            setMatchPage(1);
        },
        onError: (error) => {
            toast.error(error?.response?.data?.message || 'Failed to match CRM profile.');
        },
    });

    const removeItemMutation = useMutation({
        mutationFn: (itemId) => api.delete(`/crm/push-campaigns/${campaignId}/items/${itemId}`).then((response) => response.data),
        onSuccess: (_, itemId) => {
            queryClient.invalidateQueries({ queryKey: ['push-campaign-detail', campaignId] });
            queryClient.invalidateQueries({ queryKey: ['push-campaigns-list'] });
            onChanged?.();
            toast.success('Item removed from active campaign.');
            if (editingItemId === itemId) {
                setEditingItemId(null);
                setEditForm(EMPTY_EDIT_FORM);
                setMediaUploadFile(null);
            }
            if (matchingItemId === itemId) {
                setMatchingItemId(null);
            }
        },
        onError: (error) => {
            toast.error(error?.response?.data?.message || 'Failed to remove campaign item.');
        },
    });

    const campaign = data?.campaign || null;
    const items = data?.items?.data || [];
    const pagination = data?.items || null;

    useEffect(() => {
        if (!items.length) {
            setPreviewItemId(null);
            return;
        }

        if (!items.some((item) => item.id === previewItemId)) {
            setPreviewItemId(items[0].id);
        }
    }, [items, previewItemId]);

    const canDelete = useMemo(() => {
        const status = campaign?.status;
        return status === 'draft' || status === 'failed';
    }, [campaign]);

    const previewItem = useMemo(() => (
        items.find((item) => item.id === previewItemId) || items[0] || null
    ), [items, previewItemId]);

    const activeEditItem = useMemo(() => (
        items.find((item) => item.id === editingItemId) || null
    ), [items, editingItemId]);

    const activeMatchItem = useMemo(() => (
        items.find((item) => item.id === matchingItemId) || null
    ), [items, matchingItemId]);

    const activeEditHydrationSources = activeEditItem ? (hydrationSources[activeEditItem.id] || null) : null;
    const activeMatchHydrationSources = activeMatchItem ? (hydrationSources[activeMatchItem.id] || null) : null;
    const itemMedia = itemMediaQuery.data?.data || [];
    const selectedMediaUrl = itemMediaQuery.data?.selected_url || editForm.profile_image_url || activeEditItem?.profile_image_url || '';
    const recommendedMediaUrl = itemMediaQuery.data?.recommended_url || '';
    const mediaErrorMessage = itemMediaQuery.error?.response?.data?.message || itemMediaQuery.error?.message || '';
    const executeCounts = executeReadiness?.counts || null;
    const executeSampleOverdue = executeReadiness?.sample_overdue_items || [];

    if (!campaignId) {
        return null;
    }

    const hydrateItemProfile = ({ itemId, force = false, context = 'edit', silent = true }) => {
        if (!itemId) {
            return;
        }

        setHydratingItem({ itemId, context });
        hydrateItemMutation.mutate({ itemId, force, context, silent });
    };

    const openExecuteConfirmation = () => {
        executeReadinessMutation.mutate();
    };

    const startEditing = (item) => {
        setEditingItemId(item.id);
        setMatchingItemId(null);
        setEditForm(itemToEditForm(item));
        setMediaUploadFile(null);
        hydrateItemProfile({
            itemId: item.id,
            force: false,
            context: 'edit',
            silent: true,
        });
    };

    const startMatching = (item) => {
        setMatchingItemId(item.id);
        setEditingItemId(null);
        setMatchSearchInput('');
        setMatchSearch('');
        setMatchPage(1);
        setMediaUploadFile(null);
        hydrateItemProfile({
            itemId: item.id,
            force: false,
            context: 'match',
            silent: true,
        });
    };

    const saveEditedItem = () => {
        if (!activeEditItem) {
            return;
        }

        if (!editForm.custom_message.trim()) {
            toast.warning('Message is required.');
            return;
        }

        const timezone = Intl.DateTimeFormat().resolvedOptions().timeZone || 'UTC';

        updateItemMutation.mutate({
            itemId: activeEditItem.id,
            payload: {
                profile_url: editForm.profile_url.trim(),
                profile_name: editForm.profile_name.trim() || null,
                profile_phone: editForm.profile_phone.trim() || null,
                profile_image_url: editForm.profile_image_url.trim() || null,
                profile_age: editForm.profile_age.trim() || null,
                custom_message: editForm.custom_message.trim(),
                scheduled_at: editForm.scheduled_at || null,
                timezone,
            },
        });
    };

    return (
        <div className="fixed inset-0 z-[95] flex items-start justify-end bg-slate-900/60 p-0">
            <div className="h-full w-full max-w-5xl overflow-y-auto bg-white shadow-xl">
                <header className="sticky top-0 z-10 border-b border-slate-200 bg-white px-4 py-3">
                    <div className="flex flex-wrap items-center justify-between gap-2">
                        <div>
                            <h3 className="text-lg font-semibold text-slate-900">{campaign?.name || 'Campaign detail'}</h3>
                            <p className="text-xs text-slate-500">
                                {campaign?.platform?.name || 'Market'} • status: {prettyStatus(campaign?.status)} • items: {campaign?.total_items || 0}
                            </p>
                        </div>
                        <button type="button" onClick={onClose} className="crm-btn-secondary px-3 py-1.5">Close</button>
                    </div>
                </header>

                <div className="space-y-4 p-4">
                    <section className="grid gap-3 md:grid-cols-4">
                        <article className="rounded-lg border border-slate-200 bg-slate-50 p-3 text-xs text-slate-600">
                            <p className="font-semibold text-slate-800">Sent</p>
                            <p className="mt-1 text-lg font-semibold text-slate-900">{campaign?.sent_count || 0}</p>
                        </article>
                        <article className="rounded-lg border border-slate-200 bg-slate-50 p-3 text-xs text-slate-600">
                            <p className="font-semibold text-slate-800">Failed</p>
                            <p className="mt-1 text-lg font-semibold text-slate-900">{campaign?.failed_count || 0}</p>
                        </article>
                        <article className="rounded-lg border border-slate-200 bg-slate-50 p-3 text-xs text-slate-600">
                            <p className="font-semibold text-slate-800">Scheduled at</p>
                            <p className="mt-1 text-sm font-semibold text-slate-900">{formatDateTime(campaign?.scheduled_at, 'Not scheduled')}</p>
                        </article>
                        <article className="rounded-lg border border-slate-200 bg-slate-50 p-3 text-xs text-slate-600">
                            <p className="font-semibold text-slate-800">Confirmed</p>
                            <p className="mt-1 text-sm font-semibold text-slate-900">{formatDateTime(campaign?.confirmed_at, 'No')}</p>
                        </article>
                    </section>

                    {analytics ? (
                        <section className="rounded-lg border border-slate-200 bg-slate-50 p-3 text-xs text-slate-700">
                            <p className="font-semibold text-slate-900">Analytics</p>
                            <p className="mt-1">sent: {analytics.total_sent || 0} • delivered: {analytics.delivered || 0} • clicked: {analytics.clicked || 0} • failed: {analytics.failed || 0}</p>
                        </section>
                    ) : null}

                    <section className="rounded-lg border border-slate-200 bg-white p-3">
                        <div className="flex flex-wrap items-end gap-2">
                            <button
                                type="button"
                                onClick={openExecuteConfirmation}
                                disabled={executeReadinessMutation.isPending || executeMutation.isPending || campaign?.status === 'processing'}
                                className="crm-btn-primary disabled:cursor-not-allowed disabled:opacity-60"
                            >
                                {executeReadinessMutation.isPending
                                    ? 'Checking...'
                                    : (executeMutation.isPending ? 'Executing...' : 'Execute now')}
                            </button>

                            <div className="flex items-center gap-2">
                                <input
                                    type="datetime-local"
                                    value={scheduleAt}
                                    onChange={(event) => setScheduleAt(event.target.value)}
                                    className="crm-input"
                                />
                                <button
                                    type="button"
                                    onClick={() => scheduleMutation.mutate()}
                                    disabled={scheduleMutation.isPending || !scheduleAt}
                                    className="crm-btn-secondary disabled:cursor-not-allowed disabled:opacity-60"
                                >
                                    {scheduleMutation.isPending ? 'Scheduling...' : 'Schedule'}
                                </button>
                            </div>

                            <button
                                type="button"
                                onClick={() => refreshAnalyticsMutation.mutate()}
                                disabled={refreshAnalyticsMutation.isPending}
                                className="crm-btn-secondary disabled:cursor-not-allowed disabled:opacity-60"
                            >
                                {refreshAnalyticsMutation.isPending ? 'Refreshing...' : 'Refresh analytics'}
                            </button>

                            <button
                                type="button"
                                onClick={() => deleteMutation.mutate()}
                                disabled={deleteMutation.isPending || !canDelete}
                                className="crm-btn-danger disabled:cursor-not-allowed disabled:opacity-60"
                            >
                                {deleteMutation.isPending ? 'Deleting...' : 'Delete campaign'}
                            </button>
                        </div>
                        <p className="mt-2 text-[11px] text-slate-500">
                            Campaign schedule sets activation time only. Each item still sends at its own date/time.
                        </p>
                    </section>

                    <section className="rounded-lg border border-slate-200 bg-white p-3">
                        <div className="mb-3 flex flex-wrap items-center justify-between gap-2">
                            <h4 className="text-sm font-semibold text-slate-900">Push Preview</h4>
                            <div className="flex items-center gap-2">
                                <button
                                    type="button"
                                    onClick={() => setPreviewDevice('mobile')}
                                    className={`crm-btn-secondary px-2 py-1 text-xs ${previewDevice === 'mobile' ? 'border-teal-500 text-teal-700' : ''}`}
                                >
                                    Mobile
                                </button>
                                <button
                                    type="button"
                                    onClick={() => setPreviewDevice('desktop')}
                                    className={`crm-btn-secondary px-2 py-1 text-xs ${previewDevice === 'desktop' ? 'border-teal-500 text-teal-700' : ''}`}
                                >
                                    Desktop
                                </button>
                            </div>
                        </div>

                        <div className="flex justify-center">
                            <div className={`rounded-xl border border-slate-200 bg-slate-50 p-2 ${previewDevice === 'mobile' ? 'w-[300px]' : 'w-[500px]'}`}>
                                <div className="rounded-lg border border-slate-200 bg-white p-3 shadow-sm">
                                    <p className="text-[11px] font-semibold uppercase tracking-wide text-slate-500">Exotic Push</p>
                                    <div className="mt-2 flex items-start gap-3">
                                        {previewItem?.profile_image_url ? (
                                            <img
                                                src={previewItem.profile_image_url}
                                                alt={previewItem.profile_name || 'Profile'}
                                                className="h-11 w-11 rounded-full object-cover"
                                            />
                                        ) : (
                                            <div className="flex h-11 w-11 items-center justify-center rounded-full bg-slate-200 text-sm font-semibold text-slate-700">
                                                {(previewItem?.profile_name || 'E').charAt(0).toUpperCase()}
                                            </div>
                                        )}
                                        <div className="min-w-0 flex-1">
                                            <p className="truncate text-sm font-semibold text-slate-900">
                                                {previewItem?.profile_name || 'Escort Profile'}
                                            </p>
                                            <div className="mt-1 flex flex-wrap items-center gap-1 text-[11px]">
                                                <span className="rounded-md bg-slate-100 px-1.5 py-0.5 text-slate-700">{previewItem?.profile_phone || 'phone n/a'}</span>
                                                <span className="rounded-md bg-slate-100 px-1.5 py-0.5 text-slate-700">Age {previewItem?.profile_age || 'n/a'}</span>
                                                <span className="rounded-md bg-slate-100 px-1.5 py-0.5 text-slate-700">{prettyStatus(previewItem?.status)}</span>
                                            </div>
                                            <p className="mt-2 text-xs leading-5 text-slate-700">
                                                {previewItem?.custom_message || 'Select an item to preview its message.'}
                                            </p>
                                            <p className="mt-1 truncate text-[11px] text-slate-500">
                                                {previewItem?.profile_url || campaign?.platform?.domain || '--'}
                                            </p>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </section>

                    <section className="rounded-lg border border-slate-200 bg-white p-3">
                        <div className="mb-2 flex flex-wrap items-center justify-between gap-2">
                            <h4 className="text-sm font-semibold text-slate-900">Items</h4>
                            <select
                                value={itemStatus}
                                onChange={(event) => {
                                    setItemStatus(event.target.value);
                                    setItemPage(1);
                                }}
                                className="crm-select w-44"
                            >
                                <option value="">All statuses</option>
                                <option value="pending_extraction">Pending extraction</option>
                                <option value="needs_preset">Needs preset</option>
                                <option value="pending">Pending</option>
                                <option value="scheduled">Scheduled</option>
                                <option value="sent">Sent</option>
                                <option value="failed">Failed</option>
                                <option value="skipped">Skipped</option>
                            </select>
                        </div>

                        {isLoading ? (
                            <p className="text-sm text-slate-500">Loading campaign items...</p>
                        ) : (
                            <div className="overflow-auto">
                                <table className="min-w-full text-xs">
                                    <thead>
                                        <tr className="text-left text-slate-500">
                                            <th className="px-2 py-1 font-medium">Date</th>
                                            <th className="px-2 py-1 font-medium">Profile</th>
                                            <th className="px-2 py-1 font-medium">Message</th>
                                            <th className="px-2 py-1 font-medium">Status</th>
                                            <th className="px-2 py-1 font-medium">Actions</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        {items.map((item) => {
                                            const reason = extractionReason(item);
                                            const timingMeta = timingStateMeta(item.timing_state);

                                            return (
                                                <tr
                                                    key={item.id}
                                                    className={`border-t border-slate-100 ${previewItem?.id === item.id ? 'bg-teal-50/60' : ''}`}
                                                    onClick={() => setPreviewItemId(item.id)}
                                                >
                                                    <td className="whitespace-nowrap px-2 py-1">{formatDateTime(item.scheduled_at, item.date_label || '--')}</td>
                                                    <td className="px-2 py-1">
                                                        <p className="font-medium text-slate-700">{item.profile_name || 'Unknown'}</p>
                                                        <p className="max-w-[250px] truncate text-slate-500">{item.profile_url}</p>
                                                        <p className="text-[11px] text-slate-500">{item.profile_phone || 'phone n/a'} • age {item.profile_age || 'n/a'}</p>
                                                        {timingMeta ? (
                                                            <span className={`mr-1 inline-flex max-w-[250px] truncate rounded-md px-1.5 py-0.5 text-[10px] font-medium uppercase ${timingMeta.className}`}>
                                                                {timingMeta.label}
                                                            </span>
                                                        ) : null}
                                                        {reason ? (
                                                            <span className="inline-flex max-w-[250px] truncate rounded-md bg-amber-100 px-1.5 py-0.5 text-[10px] font-medium uppercase text-amber-700" title={reason.message}>
                                                                {reason.code}
                                                            </span>
                                                        ) : null}
                                                    </td>
                                                    <td className="px-2 py-1">
                                                        <p className="max-w-[320px] truncate">{item.custom_message || '--'}</p>
                                                    </td>
                                                    <td className="px-2 py-1">{prettyStatus(item.status)}</td>
                                                    <td className="px-2 py-1">
                                                        <div className="flex items-center gap-1">
                                                            <button
                                                                type="button"
                                                                onClick={(event) => {
                                                                    event.stopPropagation();
                                                                    startEditing(item);
                                                                }}
                                                                disabled={!canMutateItem(item)}
                                                                className="crm-btn-secondary px-2 py-1 text-xs disabled:opacity-50"
                                                            >
                                                                Edit item
                                                            </button>
                                                            <button
                                                                type="button"
                                                                onClick={(event) => {
                                                                    event.stopPropagation();
                                                                    startMatching(item);
                                                                }}
                                                                disabled={!canMutateItem(item)}
                                                                className="crm-btn-secondary px-2 py-1 text-xs disabled:opacity-50"
                                                            >
                                                                Match CRM
                                                            </button>
                                                            <button
                                                                type="button"
                                                                onClick={(event) => {
                                                                    event.stopPropagation();
                                                                    if (!window.confirm('Remove this item from the active send list?')) {
                                                                        return;
                                                                    }
                                                                    removeItemMutation.mutate(item.id);
                                                                }}
                                                                disabled={!canMutateItem(item) || removeItemMutation.isPending}
                                                                className="crm-btn-danger px-2 py-1 text-xs disabled:opacity-50"
                                                            >
                                                                Remove
                                                            </button>
                                                        </div>
                                                    </td>
                                                </tr>
                                            );
                                        })}
                                    </tbody>
                                </table>
                            </div>
                        )}

                        {pagination?.last_page > 1 ? (
                            <div className="mt-2 flex justify-end gap-1">
                                <button
                                    type="button"
                                    onClick={() => setItemPage((page) => Math.max(1, page - 1))}
                                    disabled={itemPage <= 1}
                                    className="crm-btn-secondary px-2 py-1 text-xs disabled:cursor-not-allowed disabled:opacity-60"
                                >
                                    Prev
                                </button>
                                <span className="px-2 py-1 text-xs text-slate-600">Page {pagination.current_page} of {pagination.last_page}</span>
                                <button
                                    type="button"
                                    onClick={() => setItemPage((page) => Math.min(pagination.last_page, page + 1))}
                                    disabled={itemPage >= pagination.last_page}
                                    className="crm-btn-secondary px-2 py-1 text-xs disabled:cursor-not-allowed disabled:opacity-60"
                                >
                                    Next
                                </button>
                            </div>
                        ) : null}
                    </section>

                    {activeEditItem ? (
                        <section className="rounded-lg border border-teal-200 bg-teal-50/40 p-3">
                            <div className="mb-2 flex flex-wrap items-center justify-between gap-2">
                                <h4 className="text-sm font-semibold text-slate-900">Edit item #{activeEditItem.id}</h4>
                                <div className="flex items-center gap-1">
                                    <button
                                        type="button"
                                        onClick={() => hydrateItemProfile({
                                            itemId: activeEditItem.id,
                                            force: true,
                                            context: 'edit',
                                            silent: false,
                                        })}
                                        disabled={hydrateItemMutation.isPending}
                                        className="crm-btn-secondary px-2 py-1 text-xs disabled:opacity-60"
                                    >
                                        {hydrateItemMutation.isPending && hydratingItem?.itemId === activeEditItem.id ? 'Refreshing...' : 'Refresh profile data'}
                                    </button>
                                    <button
                                        type="button"
                                        onClick={() => {
                                            setEditingItemId(null);
                                            setEditForm(EMPTY_EDIT_FORM);
                                            setMediaUploadFile(null);
                                        }}
                                        className="crm-btn-secondary px-2 py-1 text-xs"
                                    >
                                        Close
                                    </button>
                                </div>
                            </div>

                            {hydratingItem?.itemId === activeEditItem.id && hydratingItem?.context === 'edit' ? (
                                <p className="mb-2 rounded-md border border-teal-200 bg-teal-50 px-2 py-1 text-xs text-teal-800">
                                    Refreshing profile data...
                                </p>
                            ) : null}

                            {activeEditHydrationSources ? (
                                <p className="mb-2 text-[11px] text-slate-600">
                                    Age source: <span className="font-medium text-slate-800">{activeEditHydrationSources.age_source || 'n/a'}</span>
                                    {' • '}
                                    Image source: <span className="font-medium text-slate-800">{activeEditHydrationSources.image_source || 'n/a'}</span>
                                </p>
                            ) : null}

                            <div className="mb-3 rounded-md border border-slate-200 bg-white p-2">
                                <div className="flex items-center gap-3">
                                    {selectedMediaUrl ? (
                                        <img
                                            src={selectedMediaUrl}
                                            alt={editForm.profile_name || 'Profile'}
                                            className="h-16 w-16 rounded-md object-cover"
                                        />
                                    ) : (
                                        <div className="flex h-16 w-16 items-center justify-center rounded-md bg-slate-200 text-lg font-semibold text-slate-700">
                                            {(editForm.profile_name || 'E').charAt(0).toUpperCase()}
                                        </div>
                                    )}
                                    <div className="min-w-0">
                                        <p className="truncate text-sm font-semibold text-slate-900">{editForm.profile_name || 'Unknown profile'}</p>
                                        <p className="text-xs text-slate-600">{editForm.profile_phone || 'phone n/a'} • age {editForm.profile_age || 'n/a'}</p>
                                        <p className="truncate text-[11px] text-slate-500">{editForm.profile_url || '--'}</p>
                                    </div>
                                </div>
                            </div>

                            <div className="mb-3 rounded-md border border-slate-200 bg-white p-2">
                                <div className="mb-2 flex items-center justify-between gap-2">
                                    <p className="text-xs font-semibold uppercase tracking-wide text-slate-600">Profile media</p>
                                    <p className="text-[11px] text-slate-500">Selecting an image updates this campaign item only.</p>
                                </div>

                                {itemMediaQuery.isLoading ? (
                                    <p className="rounded-md border border-slate-200 bg-slate-50 px-2 py-2 text-xs text-slate-600">Loading profile media...</p>
                                ) : null}

                                {!itemMediaQuery.isLoading && mediaErrorMessage ? (
                                    <p className="rounded-md border border-amber-200 bg-amber-50 px-2 py-2 text-xs text-amber-800">{mediaErrorMessage}</p>
                                ) : null}

                                {!itemMediaQuery.isLoading && !mediaErrorMessage && itemMedia.length > 0 ? (
                                    <div className="grid gap-2 sm:grid-cols-2 lg:grid-cols-4">
                                        {itemMedia.map((media) => {
                                            const isSelected = selectedMediaUrl && media.url === selectedMediaUrl;
                                            const isRecommended = recommendedMediaUrl && media.url === recommendedMediaUrl;

                                            return (
                                                <div key={media.id} className={`rounded-md border p-2 ${isSelected ? 'border-teal-300 bg-teal-50/50' : 'border-slate-200 bg-white'}`}>
                                                    <img src={media.url} alt={media.filename || 'Media'} className="h-24 w-full rounded object-cover" />
                                                    <p className="mt-1 truncate text-[11px] text-slate-600">{media.filename || `media #${media.id}`}</p>
                                                    <div className="mt-1 flex flex-wrap items-center gap-1">
                                                        {media.is_main ? (
                                                            <span className="rounded bg-amber-50 px-1.5 py-0.5 text-[10px] font-medium text-amber-700">Main image</span>
                                                        ) : null}
                                                        {isRecommended && !media.is_main ? (
                                                            <span className="rounded bg-slate-100 px-1.5 py-0.5 text-[10px] font-medium text-slate-700">Recommended</span>
                                                        ) : null}
                                                    </div>
                                                    <button
                                                        type="button"
                                                        onClick={() => selectItemMediaMutation.mutate({ itemId: activeEditItem.id, attachmentId: media.id })}
                                                        disabled={selectItemMediaMutation.isPending || isSelected}
                                                        className="mt-2 w-full rounded-md border border-teal-200 bg-teal-50 px-2 py-1 text-[11px] font-semibold text-teal-700 transition hover:bg-teal-100 disabled:cursor-not-allowed disabled:opacity-50"
                                                    >
                                                        {isSelected ? 'Selected' : (selectItemMediaMutation.isPending ? 'Applying...' : 'Use image')}
                                                    </button>
                                                </div>
                                            );
                                        })}
                                    </div>
                                ) : null}

                                {!itemMediaQuery.isLoading && !mediaErrorMessage && itemMedia.length === 0 ? (
                                    <p className="rounded-md border border-dashed border-slate-300 bg-slate-50 px-2 py-4 text-center text-xs text-slate-500">
                                        No profile media found for this item yet.
                                    </p>
                                ) : null}

                                <div className="mt-2 grid gap-2 md:grid-cols-[1fr_auto]">
                                    <input
                                        type="file"
                                        accept="image/jpeg,image/png,image/webp"
                                        onChange={(event) => setMediaUploadFile(event.target.files?.[0] || null)}
                                        className="crm-input"
                                    />
                                    <button
                                        type="button"
                                        onClick={() => {
                                            if (!mediaUploadFile) return;
                                            uploadItemMediaMutation.mutate({
                                                itemId: activeEditItem.id,
                                                file: mediaUploadFile,
                                                applyToItem: true,
                                            });
                                        }}
                                        disabled={!mediaUploadFile || uploadItemMediaMutation.isPending}
                                        className="crm-btn-primary px-3 py-2 text-xs disabled:opacity-60"
                                    >
                                        {uploadItemMediaMutation.isPending ? 'Uploading...' : 'Upload image'}
                                    </button>
                                </div>
                            </div>

                            <div className="grid gap-2 md:grid-cols-2">
                                <input
                                    value={editForm.profile_url}
                                    onChange={(event) => setEditForm((prev) => ({ ...prev, profile_url: event.target.value }))}
                                    className="crm-input md:col-span-2"
                                    placeholder="Profile URL"
                                />
                                <input
                                    value={editForm.profile_name}
                                    onChange={(event) => setEditForm((prev) => ({ ...prev, profile_name: event.target.value }))}
                                    className="crm-input"
                                    placeholder="Profile name"
                                />
                                <input
                                    value={editForm.profile_phone}
                                    onChange={(event) => setEditForm((prev) => ({ ...prev, profile_phone: event.target.value }))}
                                    className="crm-input"
                                    placeholder="Phone"
                                />
                                <input
                                    value={editForm.profile_age}
                                    onChange={(event) => setEditForm((prev) => ({ ...prev, profile_age: event.target.value }))}
                                    className="crm-input"
                                    placeholder="Age"
                                />
                                <input
                                    value={editForm.profile_image_url}
                                    onChange={(event) => setEditForm((prev) => ({ ...prev, profile_image_url: event.target.value }))}
                                    className="crm-input"
                                    placeholder="Image URL"
                                />
                                <input
                                    type="datetime-local"
                                    value={editForm.scheduled_at}
                                    onChange={(event) => setEditForm((prev) => ({ ...prev, scheduled_at: event.target.value }))}
                                    className="crm-input"
                                />
                                <textarea
                                    value={editForm.custom_message}
                                    onChange={(event) => setEditForm((prev) => ({ ...prev, custom_message: event.target.value }))}
                                    className="crm-input min-h-[84px] md:col-span-2"
                                    maxLength={255}
                                    placeholder="Message"
                                />
                            </div>
                            <div className="mt-3 flex items-center justify-end gap-2">
                                <button
                                    type="button"
                                    onClick={saveEditedItem}
                                    disabled={updateItemMutation.isPending}
                                    className="crm-btn-primary px-3 py-1.5 text-xs disabled:opacity-60"
                                >
                                    {updateItemMutation.isPending ? 'Saving...' : 'Save item'}
                                </button>
                            </div>
                        </section>
                    ) : null}

                    {activeMatchItem ? (
                        <section className="rounded-lg border border-slate-200 bg-slate-50 p-3">
                            <div className="mb-2 flex flex-wrap items-center justify-between gap-2">
                                <h4 className="text-sm font-semibold text-slate-900">Match CRM profile for item #{activeMatchItem.id}</h4>
                                <div className="flex items-center gap-1">
                                    <button
                                        type="button"
                                        onClick={() => hydrateItemProfile({
                                            itemId: activeMatchItem.id,
                                            force: true,
                                            context: 'match',
                                            silent: false,
                                        })}
                                        disabled={hydrateItemMutation.isPending}
                                        className="crm-btn-secondary px-2 py-1 text-xs disabled:opacity-60"
                                    >
                                        {hydrateItemMutation.isPending && hydratingItem?.itemId === activeMatchItem.id ? 'Refreshing...' : 'Refresh profile data'}
                                    </button>
                                    <button
                                        type="button"
                                        onClick={() => {
                                            setMatchingItemId(null);
                                            setHydratingItem(null);
                                        }}
                                        className="crm-btn-secondary px-2 py-1 text-xs"
                                    >
                                        Close
                                    </button>
                                </div>
                            </div>

                            {hydratingItem?.itemId === activeMatchItem.id && hydratingItem?.context === 'match' ? (
                                <p className="mb-2 rounded-md border border-teal-200 bg-teal-50 px-2 py-1 text-xs text-teal-800">
                                    Refreshing profile data...
                                </p>
                            ) : null}

                            {activeMatchHydrationSources ? (
                                <p className="mb-2 text-[11px] text-slate-600">
                                    Age source: <span className="font-medium text-slate-800">{activeMatchHydrationSources.age_source || 'n/a'}</span>
                                    {' • '}
                                    Image source: <span className="font-medium text-slate-800">{activeMatchHydrationSources.image_source || 'n/a'}</span>
                                </p>
                            ) : null}

                            <div className="mb-2 grid gap-2 md:grid-cols-[1fr_auto]">
                                <input
                                    value={matchSearchInput}
                                    onChange={(event) => setMatchSearchInput(event.target.value)}
                                    className="crm-input"
                                    placeholder="Search CRM by name, phone, email, WP ID"
                                />
                                <label className="flex items-center gap-2 rounded-md border border-slate-200 bg-white px-3 py-2 text-xs text-slate-700">
                                    <input
                                        type="checkbox"
                                        checked={matchHydrateWp}
                                        onChange={(event) => setMatchHydrateWp(event.target.checked)}
                                    />
                                    Use CRM/WP details now
                                </label>
                            </div>

                            <div className="overflow-auto rounded-lg border border-slate-200 bg-white">
                                <table className="min-w-full text-xs">
                                    <thead>
                                        <tr className="border-b border-slate-200 text-left text-slate-500">
                                            <th className="px-2 py-2 font-medium">Profile</th>
                                            <th className="px-2 py-2 font-medium">Contact</th>
                                            <th className="px-2 py-2 font-medium">Confidence</th>
                                            <th className="px-2 py-2 font-medium">Action</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        {(matchCandidatesQuery.data?.data || []).map((candidate) => (
                                            <tr key={candidate.id} className="border-b border-slate-100">
                                                <td className="px-2 py-2">
                                                    <div className="flex items-center gap-2">
                                                        {candidate.main_image_url ? (
                                                            <img src={candidate.main_image_url} alt={candidate.name || 'Profile'} className="h-8 w-8 rounded-full object-cover" />
                                                        ) : (
                                                            <div className="flex h-8 w-8 items-center justify-center rounded-full bg-slate-200 text-[11px] font-semibold text-slate-700">
                                                                {(candidate.name || 'E').charAt(0).toUpperCase()}
                                                            </div>
                                                        )}
                                                        <div>
                                                            <p className="font-medium text-slate-700">{candidate.name || 'Unknown'}</p>
                                                            <p className="max-w-[220px] truncate text-slate-500">{candidate.wp_profile_url || 'No WP URL'}</p>
                                                        </div>
                                                    </div>
                                                </td>
                                                <td className="px-2 py-2 text-slate-600">
                                                    <p>{candidate.phone_normalized || '—'}</p>
                                                    <p>{candidate.email || '—'}</p>
                                                </td>
                                                <td className="px-2 py-2 text-slate-600">
                                                    <p className="font-medium">{candidate.score || 0}</p>
                                                    <p className="max-w-[220px] truncate" title={candidate.score_reason || ''}>{candidate.score_reason || '—'}</p>
                                                </td>
                                                <td className="px-2 py-2">
                                                    <button
                                                        type="button"
                                                        onClick={() => matchCrmMutation.mutate({ itemId: activeMatchItem.id, clientId: candidate.id })}
                                                        disabled={matchCrmMutation.isPending}
                                                        className="crm-btn-primary px-2 py-1 text-xs disabled:opacity-60"
                                                    >
                                                        {matchCrmMutation.isPending ? 'Applying...' : 'Apply match'}
                                                    </button>
                                                </td>
                                            </tr>
                                        ))}
                                        {!matchCandidatesQuery.isLoading && (matchCandidatesQuery.data?.data || []).length === 0 ? (
                                            <tr>
                                                <td colSpan={4} className="px-2 py-4 text-center text-slate-500">No candidate profiles found for this item.</td>
                                            </tr>
                                        ) : null}
                                    </tbody>
                                </table>
                            </div>

                            <div className="mt-2 flex items-center justify-end gap-1">
                                <button
                                    type="button"
                                    onClick={() => setMatchPage((page) => Math.max(1, page - 1))}
                                    disabled={(matchCandidatesQuery.data?.current_page || 1) <= 1}
                                    className="crm-btn-secondary px-2 py-1 text-xs disabled:opacity-60"
                                >
                                    Prev
                                </button>
                                <span className="px-2 py-1 text-xs text-slate-600">
                                    Page {matchCandidatesQuery.data?.current_page || 1} of {matchCandidatesQuery.data?.last_page || 1}
                                </span>
                                <button
                                    type="button"
                                    onClick={() => setMatchPage((page) => Math.min(matchCandidatesQuery.data?.last_page || 1, page + 1))}
                                    disabled={(matchCandidatesQuery.data?.current_page || 1) >= (matchCandidatesQuery.data?.last_page || 1)}
                                    className="crm-btn-secondary px-2 py-1 text-xs disabled:opacity-60"
                                >
                                    Next
                                </button>
                            </div>
                        </section>
                    ) : null}
                </div>
            </div>

            {executeModalOpen ? (
                <div className="fixed inset-0 z-[110] flex items-center justify-center bg-slate-900/50 p-4" onClick={() => setExecuteModalOpen(false)}>
                    <div
                        className="w-full max-w-2xl rounded-lg border border-slate-200 bg-white shadow-xl"
                        onClick={(event) => event.stopPropagation()}
                    >
                        <header className="border-b border-slate-200 px-4 py-3">
                            <h4 className="text-base font-semibold text-slate-900">Confirm Execute Now</h4>
                            <p className="mt-1 text-xs text-slate-600">
                                Campaign schedule sets activation only. Item-level date/time still controls delivery timing.
                            </p>
                        </header>

                        <div className="space-y-3 p-4">
                            <div className="grid gap-2 sm:grid-cols-2 lg:grid-cols-4">
                                <article className="rounded-md border border-slate-200 bg-slate-50 p-2 text-xs text-slate-600">
                                    <p className="font-semibold text-slate-800">Overdue</p>
                                    <p className="mt-1 text-lg font-semibold text-slate-900">{executeCounts?.overdue || 0}</p>
                                </article>
                                <article className="rounded-md border border-slate-200 bg-slate-50 p-2 text-xs text-slate-600">
                                    <p className="font-semibold text-slate-800">Send immediately</p>
                                    <p className="mt-1 text-lg font-semibold text-slate-900">{executeCounts?.send_immediately || 0}</p>
                                </article>
                                <article className="rounded-md border border-slate-200 bg-slate-50 p-2 text-xs text-slate-600">
                                    <p className="font-semibold text-slate-800">Queue with delay</p>
                                    <p className="mt-1 text-lg font-semibold text-slate-900">{executeCounts?.queue_with_delay || 0}</p>
                                </article>
                                <article className="rounded-md border border-slate-200 bg-slate-50 p-2 text-xs text-slate-600">
                                    <p className="font-semibold text-slate-800">Outside 24h queue</p>
                                    <p className="mt-1 text-lg font-semibold text-slate-900">{executeCounts?.outside_dispatch_window || 0}</p>
                                </article>
                            </div>

                            <p className={`rounded-md border px-3 py-2 text-sm ${
                                executeReadiness?.can_activate
                                    ? 'border-teal-200 bg-teal-50 text-teal-800'
                                    : 'border-rose-200 bg-rose-50 text-rose-800'
                            }`}>
                                {executeReadiness?.message || 'Run readiness check to understand what execute now will do.'}
                            </p>

                            {!executeReadiness?.can_activate && executeSampleOverdue.length > 0 ? (
                                <div className="rounded-md border border-rose-200 bg-rose-50 p-3">
                                    <p className="text-xs font-semibold uppercase tracking-wide text-rose-700">Overdue items need reschedule</p>
                                    <div className="mt-2 space-y-1">
                                        {executeSampleOverdue.map((item) => (
                                            <p key={item.id} className="text-xs text-rose-800">
                                                #{item.id} {item.profile_name || 'Unknown'} • {formatDateTime(item.scheduled_at_local || item.scheduled_at, '--')}
                                            </p>
                                        ))}
                                    </div>
                                    <p className="mt-2 text-[11px] text-rose-700">
                                        Use the Items table `Edit item` action to change overdue times, then run execute again.
                                    </p>
                                </div>
                            ) : null}
                        </div>

                        <footer className="flex items-center justify-end gap-2 border-t border-slate-200 px-4 py-3">
                            <button
                                type="button"
                                onClick={() => executeReadinessMutation.mutate()}
                                disabled={executeReadinessMutation.isPending}
                                className="crm-btn-secondary px-3 py-1.5 text-xs disabled:opacity-60"
                            >
                                {executeReadinessMutation.isPending ? 'Rechecking...' : 'Recheck'}
                            </button>
                            <button
                                type="button"
                                onClick={() => setExecuteModalOpen(false)}
                                className="crm-btn-secondary px-3 py-1.5 text-xs"
                            >
                                Close
                            </button>
                            <button
                                type="button"
                                onClick={() => executeMutation.mutate()}
                                disabled={executeMutation.isPending || !executeReadiness?.can_activate}
                                className="crm-btn-primary px-3 py-1.5 text-xs disabled:opacity-60"
                            >
                                {executeMutation.isPending ? 'Executing...' : 'Confirm execute'}
                            </button>
                        </footer>
                    </div>
                </div>
            ) : null}
        </div>
    );
}
