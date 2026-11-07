import React from 'react';
import { useQuery } from '@tanstack/react-query';
import { Link } from 'react-router-dom';
import api from '../../services/api';
import { useAuth } from '../../hooks/useAuth';
import { useCollectionCapabilities } from './CollectionWorkspace';

function useClientSales(clientId) {
    const { user } = useAuth();
    const capabilities = useCollectionCapabilities();
    return useQuery({ queryKey: ['collection-client-sales', user?.id, clientId], enabled: !!clientId && !!capabilities.data?.sales_storage_ready,
        queryFn: async () => (await api.get(`/crm/collections/clients/${clientId}/sales`)).data, staleTime: 15000, retry: false });
}
export function CollectionSaleLink({ clientId, dealId }) {
    const result = useClientSales(clientId);
    const sale = result.data?.items?.find(i => i.deal_id === dealId);
    if (result.isError) return <Link className="text-sm font-medium text-teal-700 underline" to="/payments?workspace=collections">Find received payment</Link>;
    return sale ? <Link className="rounded-lg border border-teal-700 bg-teal-700 px-3 py-2 text-sm font-medium text-white" to={`/payments?workspace=collections&receipt=${sale.receipt_id}`}>Review received payment</Link> : <span role="status" className="text-xs text-slate-500">Loading received payment…</span>;
}
export default function CollectionClientSales({ clientId }) {
    const result = useClientSales(clientId);
    const items = result.data?.items?.filter(i => i.state !== 'confirmed') || [];
    if (!items.length) return null;
    return <section aria-label="Received payments awaiting activation" className="rounded-lg border border-teal-200 bg-teal-50/50 p-4"><h3 className="text-sm font-semibold text-teal-900">Continue from the received payment</h3><p className="mt-1 text-xs text-slate-600">The payment and service choice are saved. Review activation in the original receipt.</p><ul className="mt-3 space-y-2">{items.map(i => <li key={i.receipt_id} className="flex flex-wrap items-center justify-between gap-2 text-sm"><span>{i.tier.toUpperCase()} · {i.duration_days} days · {i.state === 'unknown' ? 'Needs checking' : 'Not activated yet'}</span><Link className="font-medium text-teal-700 underline" to={`/payments?workspace=collections&receipt=${i.receipt_id}`}>Review received payment</Link></li>)}</ul></section>;
}
