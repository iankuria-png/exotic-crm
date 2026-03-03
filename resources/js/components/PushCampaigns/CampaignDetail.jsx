import React, { useMemo, useState } from 'react';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import api from '../../services/api';
import { useToast } from '../ToastProvider';

function prettyStatus(status) {
    return (status || 'unknown').replaceAll('_', ' ');
}

export default function CampaignDetail({ campaignId, onClose, onChanged }) {
    const toast = useToast();
    const queryClient = useQueryClient();
    const [itemPage, setItemPage] = useState(1);
    const [itemStatus, setItemStatus] = useState('');
    const [scheduleAt, setScheduleAt] = useState('');
    const [analytics, setAnalytics] = useState(null);

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

    const campaign = data?.campaign || null;
    const items = data?.items?.data || [];
    const pagination = data?.items || null;

    const canDelete = useMemo(() => {
        const status = campaign?.status;
        return status === 'draft' || status === 'failed';
    }, [campaign]);

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
                            <p className="mt-1 text-sm font-semibold text-slate-900">{campaign?.scheduled_at || 'Not scheduled'}</p>
                        </article>
                        <article className="rounded-lg border border-slate-200 bg-slate-50 p-3 text-xs text-slate-600">
                            <p className="font-semibold text-slate-800">Confirmed</p>
                            <p className="mt-1 text-sm font-semibold text-slate-900">{campaign?.confirmed_at || 'No'}</p>
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
                                        </tr>
                                    </thead>
                                    <tbody>
                                        {items.map((item) => (
                                            <tr key={item.id} className="border-t border-slate-100">
                                                <td className="px-2 py-1">{item.scheduled_at || item.date_label || '--'}</td>
                                                <td className="px-2 py-1">
                                                    <p className="font-medium text-slate-700">{item.profile_name || 'Unknown'}</p>
                                                    <p className="max-w-[220px] truncate text-slate-500">{item.profile_url}</p>
                                                </td>
                                                <td className="max-w-[320px] truncate px-2 py-1">{item.custom_message || '--'}</td>
                                                <td className="px-2 py-1">{item.status}</td>
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
