import React, { useEffect, useRef, useState } from 'react';
import { useQuery } from '@tanstack/react-query';
import api from '../../services/api';

export default function SubsidiaryClientSearchPopover({ open, platformId, onClose, onSelect }) {
    const [search, setSearch] = useState('');
    const [activeIndex, setActiveIndex] = useState(0);
    const inputRef = useRef(null);

    useEffect(() => {
        if (open) {
            setSearch('');
            setActiveIndex(0);
            setTimeout(() => inputRef.current?.focus(), 0);
        }
    }, [open]);

    const query = useQuery({
        queryKey: ['subsidiary-client-search', platformId, search],
        queryFn: () => api.get('/crm/clients', {
            params: { platform_id: Number(platformId), search, per_page: 8 },
        }).then((response) => response.data),
        enabled: open && Boolean(platformId) && search.trim().length >= 2,
        staleTime: 10_000,
    });

    if (!open) return null;

    const clients = query.data?.data || [];

    const handleKeyDown = (event) => {
        if (event.key === 'Escape') {
            onClose?.();
            return;
        }
        if (event.key === 'ArrowDown') {
            event.preventDefault();
            setActiveIndex((current) => Math.min(clients.length - 1, current + 1));
            return;
        }
        if (event.key === 'ArrowUp') {
            event.preventDefault();
            setActiveIndex((current) => Math.max(0, current - 1));
            return;
        }
        if (event.key === 'Enter' && clients[activeIndex]) {
            event.preventDefault();
            onSelect?.(clients[activeIndex]);
        }
    };

    return (
        <div className="absolute z-20 mt-2 w-full rounded-md border border-slate-200 bg-white p-2 shadow-lg">
            <input
                ref={inputRef}
                value={search}
                onChange={(event) => setSearch(event.target.value)}
                onKeyDown={handleKeyDown}
                className="crm-input text-sm"
                placeholder="Search by name or phone"
            />
            <div className="mt-2 max-h-52 overflow-y-auto">
                {query.isFetching ? (
                    <p className="px-2 py-2 text-xs text-slate-500">Searching...</p>
                ) : clients.length > 0 ? clients.map((client, index) => (
                    <button
                        key={client.id}
                        type="button"
                        onClick={() => onSelect?.(client)}
                        className={`flex w-full items-center justify-between rounded px-2 py-2 text-left ${index === activeIndex ? 'bg-teal-50' : 'hover:bg-slate-50'}`}
                    >
                        <span className="min-w-0">
                            <span className="block truncate text-sm font-semibold text-slate-900">{client.name || 'Client'}</span>
                            <span className="block truncate text-xs text-slate-500">{client.phone_normalized || 'No phone'}</span>
                        </span>
                    </button>
                )) : search.trim().length >= 2 ? (
                    <p className="px-2 py-2 text-xs text-slate-500">No clients found.</p>
                ) : (
                    <p className="px-2 py-2 text-xs text-slate-500">Type at least 2 characters.</p>
                )}
            </div>
        </div>
    );
}
