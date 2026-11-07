import React, { useState } from 'react';
import { useQuery } from '@tanstack/react-query';
import api from '../../services/api';
import { useAuth } from '../../hooks/useAuth';

export default function CollectionMessageCopy({ receiptId }) {
    const { user } = useAuth();
    const [open, setOpen] = useState(false);
    const [selected, setSelected] = useState('');
    const [status, setStatus] = useState('');
    const [fallback, setFallback] = useState(false);
    const result = useQuery({ queryKey: ['collection-messages', user?.id, receiptId], enabled: open,
        queryFn: async () => (await api.get(`/crm/collections/receipts/${receiptId}/messages`)).data,
        retry: false, staleTime: 0, gcTime: 0 });
    const messages = result.data?.messages || [];
    const message = messages.find(m => String(m.id) === selected) || messages[0];
    async function copy() {
        setStatus(''); setFallback(false);
        try { await navigator.clipboard.writeText(message.message); setStatus('Original SMS copied'); }
        catch { setFallback(true); }
    }
    return <section aria-label="Original receiving message" className="mt-3 text-sm">
        <button type="button" aria-expanded={open} className="font-medium text-teal-700 underline focus-visible:ring-2 focus-visible:ring-teal-600" onClick={() => { setOpen(!open); setStatus(''); setFallback(false); }}>{open ? 'Hide original SMS' : 'Copy original SMS'}</button>
        {open && <div className="mt-3 space-y-3 rounded-lg border border-slate-200 bg-slate-50 p-3">
            {result.isPending && <p role="status">Loading receiving messages…</p>}
            {result.isError && <p role="alert">Could not load the original SMS. <button type="button" className="underline" onClick={() => result.refetch()}>Try again</button></p>}
            {result.isSuccess && !messages.length && <p>No original receiving SMS is available for this receipt.</p>}
            {messages.length > 1 && <label className="block font-medium">Receiving message<select className="crm-input mt-1" value={String(message?.id || '')} onChange={e => { setSelected(e.target.value); setStatus(''); setFallback(false); }}>{messages.map((m, i) => <option value={String(m.id)} key={m.id}>Message {i + 1} · {m.sender}{m.received_at ? ` · ${new Date(m.received_at).toLocaleString()}` : ''}</option>)}</select></label>}
            {message && <><p className="text-xs text-slate-500">{message.sender}{message.received_at ? ` · ${new Date(message.received_at).toLocaleString()}` : ''}{message.copies > 1 ? ` · ${message.copies} identical copies` : ''}</p><label className="block"><span className={fallback ? 'font-medium' : 'sr-only'}>{fallback ? 'Clipboard unavailable. Select and copy this text.' : 'Original SMS'}</span><textarea readOnly rows={5} className="crm-input mt-1 w-full text-sm" value={message.message} onFocus={e => { if (fallback) e.target.select(); }} /></label><button type="button" className="rounded-lg border border-slate-300 bg-white px-3 py-2 font-medium text-slate-700 focus-visible:ring-2 focus-visible:ring-teal-600" onClick={copy}>Copy this SMS</button></>}
            {result.data && !result.data.complete && <p className="text-xs text-amber-900">Showing available messages from the latest 200 captured events. Coverage is incomplete.</p>}
            {status && <p role="status" className="text-teal-800">{status}</p>}
        </div>}
    </section>;
}
