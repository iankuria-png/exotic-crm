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

function canEditItem(item) {
    return ['pending_extraction', 'needs_preset', 'pending', 'scheduled'].includes(String(item?.status || ''));
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
    const [editingMessage, setEditingMessage] = useState('');

    const { data, isLoading } = useQuery({
        queryKey: ['push-campaign-detail', campaignId, itemPage, itemStatus],
        enabled: Boolean(campaignId),
        queryFn: () => api.get(`/crm/push-campaigns/${campaignId}`, {
            params: {
                page: itemPage,
                per_page: 50,
                ...(itemStatus ? { status: itemStatus } : {}),
            },
        }).then((response) => response.data),
    });

    const executeMutation = useMutation({
        mutationFn: () => api.post(`/crm/push-campaigns/${campaignId}/execute`, {}).then((response) => response.data),
        onSuccess: () => {
            queryClient.invalidateQueries({ queryKey: ['push-campaigns-list'] });
            queryClient.invalidateQueries({ queryKey: ['push-campaign-detail', campaignId] });
            onChanged?.();
            toast.success('Campaign execution started.');
        },
        onError: (error) => {
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
        onSuccess: () => {
            queryClient.invalidateQueries({ queryKey: ['push-campaign-detail', campaignId] });
            queryClient.invalidateQueries({ queryKey: ['push-campaigns-list'] });
            onChanged?.();
            toast.success('Campaign item updated.');
            setEditingItemId(null);
            setEditingMessage('');
        },
        onError: (error) => {
            toast.error(error?.response?.data?.message || 'Failed to update campaign item.');
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
    const previewMessage = previewItem && editingItemId === previewItem.id
        ? editingMessage
        : previewItem?.custom_message;

    if (!campaignId) {
        return null;
    }

    return (
        <div className="fixed inset-0 z-[95] flex items-start justify-end bg-slate-900/60 p-0">
            <div className="h-full w-full max-w-4xl overflow-y-auto bg-white shadow-xl">
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
                                onClick={() => executeMutation.mutate()}
                                disabled={executeMutation.isPending || campaign?.status === 'processing'}
                                className="crm-btn-primary disabled:cursor-not-allowed disabled:opacity-60"
                            >
                                {executeMutation.isPending ? 'Executing...' : 'Execute now'}
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
                            <div className={`rounded-xl border border-slate-200 bg-slate-50 p-2 ${previewDevice === 'mobile' ? 'w-[290px]' : 'w-[460px]'}`}>
                                <div className="rounded-lg border border-slate-200 bg-white p-3 shadow-sm">
                                    <p className="text-[11px] font-semibold uppercase tracking-wide text-slate-500">Exotic Push</p>
                                    <div className="mt-2 flex items-start gap-3">
                                        {previewItem?.profile_image_url ? (
                                            <img
                                                src={previewItem.profile_image_url}
                                                alt={previewItem.profile_name || 'Profile'}
                                                className="h-10 w-10 rounded-full object-cover"
                                            />
                                        ) : (
                                            <div className="flex h-10 w-10 items-center justify-center rounded-full bg-slate-200 text-sm font-semibold text-slate-700">
                                                {(previewItem?.profile_name || 'E').charAt(0).toUpperCase()}
                                            </div>
                                        )}
                                        <div className="min-w-0 flex-1">
                                            <p className="truncate text-sm font-semibold text-slate-900">
                                                {previewItem?.profile_name || 'Escort Profile'}
                                            </p>
                                            <p className="mt-1 text-xs leading-5 text-slate-700">
                                                {previewMessage || 'Select an item to preview its message.'}
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
                                        {items.map((item) => (
                                            <tr
                                                key={item.id}
                                                className={`border-t border-slate-100 ${previewItem?.id === item.id ? 'bg-teal-50/60' : ''}`}
                                                onClick={() => setPreviewItemId(item.id)}
                                            >
                                                <td className="px-2 py-1">{formatDateTime(item.scheduled_at, item.date_label || '--')}</td>
                                                <td className="px-2 py-1">
                                                    <p className="font-medium text-slate-700">{item.profile_name || 'Unknown'}</p>
                                                    <p className="max-w-[220px] truncate text-slate-500">{item.profile_url}</p>
                                                </td>
                                                <td className="px-2 py-1">
                                                    {editingItemId === item.id ? (
                                                        <textarea
                                                            value={editingMessage}
                                                            onChange={(event) => setEditingMessage(event.target.value)}
                                                            className="crm-input min-h-[72px] w-full text-xs"
                                                            maxLength={255}
                                                            onClick={(event) => event.stopPropagation()}
                                                        />
                                                    ) : (
                                                        <p className="max-w-[320px] truncate">{item.custom_message || '--'}</p>
                                                    )}
                                                </td>
                                                <td className="px-2 py-1">{prettyStatus(item.status)}</td>
                                                <td className="px-2 py-1">
                                                    <div className="flex items-center gap-1">
                                                        {editingItemId === item.id ? (
                                                            <>
                                                                <button
                                                                    type="button"
                                                                    onClick={(event) => {
                                                                        event.stopPropagation();
                                                                        updateItemMutation.mutate({
                                                                            itemId: item.id,
                                                                            payload: { custom_message: editingMessage.trim() },
                                                                        });
                                                                    }}
                                                                    disabled={updateItemMutation.isPending || editingMessage.trim().length === 0}
                                                                    className="crm-btn-primary px-2 py-1 text-xs disabled:opacity-60"
                                                                >
                                                                    Save
                                                                </button>
                                                                <button
                                                                    type="button"
                                                                    onClick={(event) => {
                                                                        event.stopPropagation();
                                                                        setEditingItemId(null);
                                                                        setEditingMessage('');
                                                                    }}
                                                                    className="crm-btn-secondary px-2 py-1 text-xs"
                                                                >
                                                                    Cancel
                                                                </button>
                                                            </>
                                                        ) : (
                                                            <button
                                                                type="button"
                                                                onClick={(event) => {
                                                                    event.stopPropagation();
                                                                    setEditingItemId(item.id);
                                                                    setEditingMessage(item.custom_message || '');
                                                                }}
                                                                disabled={!canEditItem(item)}
                                                                className="crm-btn-secondary px-2 py-1 text-xs disabled:opacity-50"
                                                            >
                                                                Edit message
                                                            </button>
                                                        )}
                                                    </div>
                                                </td>
                                            </tr>
                                        ))}
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
                </div>
            </div>
        </div>
    );
}
