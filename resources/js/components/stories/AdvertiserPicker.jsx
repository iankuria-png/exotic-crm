import React, { useEffect, useState } from 'react';
import { useQuery } from '@tanstack/react-query';
import api from '../../services/api';

// Choose which advertiser a support-posted story is for, within one market.
export default function AdvertiserPicker({ open, platformId, marketName, onClose, onPick }) {
    const [search, setSearch] = useState('');
    const [debounced, setDebounced] = useState('');

    useEffect(() => {
        if (open) { setSearch(''); setDebounced(''); }
    }, [open]);

    useEffect(() => {
        const timer = setTimeout(() => setDebounced(search.trim()), 250);
        return () => clearTimeout(timer);
    }, [search]);

    useEffect(() => {
        if (!open) return undefined;
        const onKey = (event) => event.key === 'Escape' && onClose();
        window.addEventListener('keydown', onKey);
        return () => window.removeEventListener('keydown', onKey);
    }, [open, onClose]);

    const query = useQuery({
        queryKey: ['stories-advertiser-search', platformId, debounced],
        queryFn: () => api.get('/crm/clients', { params: { platform_id: platformId, search: debounced, per_page: 8, client_type: 'escort' } }).then((r) => r.data),
        enabled: open && debounced.length >= 2,
        staleTime: 30_000,
    });
    const results = (query.data?.data || []).filter((client) => Number(client.wp_post_id || 0) > 0);

    if (!open) return null;

    return (
        <div className="fixed inset-0 z-50 flex items-start justify-center bg-slate-900/50 p-4 pt-[12vh]" role="presentation" onMouseDown={(event) => event.target === event.currentTarget && onClose()}>
            <div role="dialog" aria-modal="true" aria-labelledby="advertiser-picker-title" className="w-full max-w-lg overflow-hidden rounded-xl bg-white shadow-xl">
                <div className="border-b border-slate-200 p-4">
                    <h2 id="advertiser-picker-title" className="text-sm font-semibold text-slate-900">Post a story for an advertiser{marketName ? ` in ${marketName}` : ''}</h2>
                    <input
                        autoFocus
                        type="search"
                        className="crm-input mt-3 w-full"
                        placeholder="Search by name, phone or email"
                        value={search}
                        onChange={(event) => setSearch(event.target.value)}
                        aria-label="Search advertisers"
                    />
                </div>
                <div className="max-h-[50vh] overflow-y-auto">
                    {debounced.length < 2 ? (
                        <p className="p-6 text-center text-sm text-slate-500">Type at least two characters.</p>
                    ) : query.isLoading ? (
                        <p className="p-6 text-center text-sm text-slate-500">Searching…</p>
                    ) : query.isError ? (
                        <p className="p-6 text-center text-sm text-rose-700">{query.error?.response?.data?.message || 'Search failed.'}</p>
                    ) : results.length === 0 ? (
                        <p className="p-6 text-center text-sm text-slate-500">No advertisers with a WordPress profile match “{debounced}”.</p>
                    ) : (
                        <ul className="divide-y divide-slate-100">
                            {results.map((client) => (
                                <li key={client.id}>
                                    <button type="button" className="flex w-full items-center gap-3 px-4 py-3 text-left hover:bg-slate-50 focus:bg-slate-50 focus:outline-none" onClick={() => onPick(client)}>
                                        {client.display_image_url || client.main_image_url
                                            ? <img src={client.display_image_url || client.main_image_url} alt="" className="h-9 w-9 rounded-full object-cover" loading="lazy" />
                                            : <span className="flex h-9 w-9 items-center justify-center rounded-full bg-slate-200 text-xs font-semibold">{(client.name || '?').charAt(0)}</span>}
                                        <span className="min-w-0 flex-1">
                                            <span className="block truncate text-sm font-semibold text-slate-900">{client.name}</span>
                                            <span className="block truncate text-xs text-slate-500">{[client.city, client.phone_normalized].filter(Boolean).join(' · ')}</span>
                                        </span>
                                        <span className="text-xs font-semibold text-teal-700">Choose →</span>
                                    </button>
                                </li>
                            ))}
                        </ul>
                    )}
                </div>
            </div>
        </div>
    );
}
