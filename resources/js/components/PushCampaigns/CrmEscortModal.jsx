import React, { useEffect, useMemo, useState } from 'react';
import { useMutation, useQuery } from '@tanstack/react-query';
import api from '../../services/api';
import { useToast } from '../ToastProvider';

export default function CrmEscortModal({ open, onClose, onCreated, platformOptions = [] }) {
    const toast = useToast();
    const [platformId, setPlatformId] = useState('');
    const [search, setSearch] = useState('');
    const [selectedIds, setSelectedIds] = useState([]);
    const [message, setMessage] = useState('');
    const [campaignName, setCampaignName] = useState('');
    const [scheduledAt, setScheduledAt] = useState('');
    const [page, setPage] = useState(1);

    useEffect(() => {
        if (!open) {
            setPlatformId('');
            setSearch('');
            setSelectedIds([]);
            setMessage('');
            setCampaignName('');
            setScheduledAt('');
            setPage(1);
        }
    }, [open]);

    const profilesQuery = useQuery({
        enabled: open && Boolean(platformId),
        queryKey: ['push-campaigns-crm-profiles', platformId, search, page],
        queryFn: () => api.get('/crm/push-campaigns/crm-profiles', {
            params: {
                platform_id: Number(platformId),
                ...(search.trim() ? { search: search.trim() } : {}),
                page,
                per_page: 25,
            },
        }).then((response) => response.data),
    });

    const createMutation = useMutation({
        mutationFn: () => api.post('/crm/push-campaigns/from-crm', {
            platform_id: Number(platformId),
            client_ids: selectedIds,
            message: message.trim(),
            campaign_name: campaignName.trim() || undefined,
            scheduled_at: scheduledAt || undefined,
            timezone: Intl.DateTimeFormat().resolvedOptions().timeZone || 'UTC',
        }).then((response) => response.data),
        onSuccess: (response) => {
            toast.success(`Created campaign with ${response?.created_items || 0} selected escorts.`);
            onCreated?.(response);
            onClose?.();
        },
        onError: (error) => {
            toast.error(error?.response?.data?.message || 'Failed to create CRM escort campaign.');
        },
    });

    const rows = profilesQuery.data?.data || [];
    const currentPage = Number(profilesQuery.data?.current_page || 1);
    const lastPage = Number(profilesQuery.data?.last_page || 1);

    const selectedSet = useMemo(() => new Set(selectedIds), [selectedIds]);
    const pageIds = rows.map((row) => Number(row.id)).filter((id) => id > 0);
    const allPageSelected = pageIds.length > 0 && pageIds.every((id) => selectedSet.has(id));

    const toggleRow = (id) => {
        const numericId = Number(id);
        if (!numericId) return;

        setSelectedIds((prev) => (
            prev.includes(numericId)
                ? prev.filter((value) => value !== numericId)
                : [...prev, numericId]
        ));
    };

    const toggleSelectPage = () => {
        if (allPageSelected) {
            setSelectedIds((prev) => prev.filter((id) => !pageIds.includes(id)));
            return;
        }

        setSelectedIds((prev) => Array.from(new Set([...prev, ...pageIds])));
    };

    if (!open) {
        return null;
    }

    const canSubmit = Boolean(platformId) && selectedIds.length > 0 && message.trim().length > 0;

    return (
        <div className="fixed inset-0 z-[95] flex items-center justify-center bg-slate-900/60 p-4">
            <div className="w-full max-w-6xl rounded-xl bg-white shadow-xl">
                <header className="flex items-center justify-between border-b border-slate-200 px-4 py-3">
                    <div>
                        <h3 className="text-lg font-semibold text-slate-900">Create Campaign From CRM Escorts</h3>
                        <p className="text-xs text-slate-500">Select escort profiles from CRM and create a draft push campaign.</p>
                    </div>
                    <button type="button" onClick={onClose} className="crm-btn-secondary px-3 py-1.5">Close</button>
                </header>

                <div className="space-y-3 p-4">
                    <section className="grid gap-2 md:grid-cols-4">
                        <select
                            value={platformId}
                            onChange={(event) => {
                                setPlatformId(event.target.value);
                                setPage(1);
                                setSelectedIds([]);
                            }}
                            className="crm-select"
                        >
                            <option value="">Select market</option>
                            {platformOptions.map((platform) => (
                                <option key={platform.platform_id} value={platform.platform_id}>
                                    {platform.platform_name}
                                </option>
                            ))}
                        </select>
                        <input
                            value={search}
                            onChange={(event) => {
                                setSearch(event.target.value);
                                setPage(1);
                            }}
                            className="crm-input md:col-span-2"
                            placeholder="Search by name, phone, email, city"
                        />
                        <div className="rounded-md border border-slate-200 bg-slate-50 px-3 py-2 text-xs text-slate-700">
                            Selected escorts: <span className="font-semibold">{selectedIds.length}</span>
                        </div>
                    </section>

                    <section className="rounded-lg border border-slate-200">
                        <div className="overflow-auto">
                            <table className="min-w-full text-xs">
                                <thead>
                                    <tr className="border-b border-slate-200 text-left text-slate-500">
                                        <th className="px-2 py-2">
                                            <input type="checkbox" checked={allPageSelected} onChange={toggleSelectPage} disabled={rows.length === 0} />
                                        </th>
                                        <th className="px-2 py-2 font-medium">Name</th>
                                        <th className="px-2 py-2 font-medium">Phone</th>
                                        <th className="px-2 py-2 font-medium">City</th>
                                        <th className="px-2 py-2 font-medium">Status</th>
                                        <th className="px-2 py-2 font-medium">Profile URL</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    {rows.map((row) => (
                                        <tr key={row.id} className="border-b border-slate-100">
                                            <td className="px-2 py-2">
                                                <input
                                                    type="checkbox"
                                                    checked={selectedSet.has(Number(row.id))}
                                                    onChange={() => toggleRow(row.id)}
                                                />
                                            </td>
                                            <td className="px-2 py-2 text-slate-700">{row.name || '—'}</td>
                                            <td className="px-2 py-2 text-slate-600">{row.phone_normalized || '—'}</td>
                                            <td className="px-2 py-2 text-slate-600">{row.city || '—'}</td>
                                            <td className="px-2 py-2 text-slate-600">{row.profile_status || '—'}</td>
                                            <td className="max-w-[280px] truncate px-2 py-2 text-slate-600">{row.wp_profile_url || '—'}</td>
                                        </tr>
                                    ))}
                                    {!profilesQuery.isLoading && rows.length === 0 ? (
                                        <tr>
                                            <td colSpan={6} className="px-2 py-4 text-center text-slate-500">
                                                {platformId ? 'No escorts found for this filter.' : 'Select a market to load escorts.'}
                                            </td>
                                        </tr>
                                    ) : null}
                                </tbody>
                            </table>
                        </div>

                        <div className="flex items-center justify-between border-t border-slate-200 px-3 py-2 text-xs text-slate-600">
                            <p>Page {currentPage} of {lastPage}</p>
                            <div className="flex items-center gap-2">
                                <button
                                    type="button"
                                    onClick={() => setPage((prev) => Math.max(1, prev - 1))}
                                    disabled={currentPage <= 1}
                                    className="crm-btn-secondary px-2 py-1 text-xs disabled:opacity-50"
                                >
                                    Prev
                                </button>
                                <button
                                    type="button"
                                    onClick={() => setPage((prev) => Math.min(lastPage, prev + 1))}
                                    disabled={currentPage >= lastPage}
                                    className="crm-btn-secondary px-2 py-1 text-xs disabled:opacity-50"
                                >
                                    Next
                                </button>
                            </div>
                        </div>
                    </section>

                    <section className="grid gap-2 md:grid-cols-3">
                        <input
                            value={campaignName}
                            onChange={(event) => setCampaignName(event.target.value)}
                            className="crm-input"
                            placeholder="Campaign name (optional)"
                        />
                        <input
                            type="datetime-local"
                            value={scheduledAt}
                            onChange={(event) => setScheduledAt(event.target.value)}
                            className="crm-input"
                        />
                        <input
                            value={message}
                            onChange={(event) => setMessage(event.target.value)}
                            className="crm-input"
                            placeholder="Push message (required)"
                            maxLength={255}
                        />
                    </section>
                </div>

                <footer className="flex items-center justify-between border-t border-slate-200 px-4 py-3">
                    <p className="text-xs text-slate-500">This creates a draft push campaign with selected escort profiles as pending items.</p>
                    <button
                        type="button"
                        onClick={() => createMutation.mutate()}
                        disabled={!canSubmit || createMutation.isPending}
                        className="crm-btn-primary disabled:cursor-not-allowed disabled:opacity-60"
                    >
                        {createMutation.isPending ? 'Creating...' : 'Create campaign'}
                    </button>
                </footer>
            </div>
        </div>
    );
}
