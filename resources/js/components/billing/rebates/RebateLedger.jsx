import React, { useEffect, useRef, useState } from 'react';
import { useQuery, useQueryClient } from '@tanstack/react-query';
import api from '../../../services/api';
import { channelLabels, errorMessage, money } from '../RebatesTab';

export default function RebateLedger({ marketId, currency, editable }) {
    const [filters, setFilters] = useState({ status: '', trigger: '', channel: '', period: '', q: '', page: 1 });
    const [search, setSearch] = useState('');
    const [row, setRow] = useState(null);
    const [reason, setReason] = useState('');
    const [busy, setBusy] = useState(false);
    const [failure, setFailure] = useState('');
    const dialog = useRef(null);
    const cache = useQueryClient();
    useEffect(() => { const timer = setTimeout(() => setFilters(f => ({ ...f, q: search, page: 1 })), 300); return () => clearTimeout(timer); }, [search]);
    useEffect(() => { if (row) dialog.current.showModal(); else if (dialog.current?.open) dialog.current.close(); }, [row]);
    const q = useQuery({ queryKey: ['rebates-ledger', marketId, filters], queryFn: () => api.get(`/crm/settings/billing/rebates/${marketId}/ledger`, { params: Object.fromEntries(Object.entries(filters).filter(([, v]) => v !== '')) }).then(r => r.data) });
    const set = (key, value) => setFilters(f => ({ ...f, [key]: value, page: 1 }));
    const reverse = async () => {
        setBusy(true); setFailure('');
        try { await api.post(`/crm/rebates/${row.id}/reverse`, { reason }); setRow(null); cache.invalidateQueries({ queryKey: ['rebates-ledger', marketId] }); cache.invalidateQueries({ queryKey: ['rebates-performance', marketId] }); }
        catch (e) { setFailure(errorMessage(e)); }
        finally { setBusy(false); }
    };
    const exportPage = () => {
        const headers = ['ID', 'Payment', 'Companion', 'Channel', 'Status', 'Amount', 'Currency', 'Revision', 'Reason'];
        const rows = q.data?.data.map(r => [r.id, r.source_payment_id, r.client?.name, r.channel, r.status, r.amount, r.currency, r.program_revision, r.reason]) || [];
        const csv = [headers, ...rows].map(line => line.map(v => `"${String(v ?? '').replace(/^[=+@-]/, "'").replaceAll('"', '""')}"`).join(',')).join('\n');
        const a = document.createElement('a'); a.href = URL.createObjectURL(new Blob([csv], { type: 'text/csv' })); a.download = `rebate-ledger-${marketId}-page-${filters.page}.csv`; a.click(); URL.revokeObjectURL(a.href);
    };
    return <div className="rbt-ledger"><div className="rbt-performance-heading"><div><h5>Every rebate, explained.</h5><p>Issued credits, sandbox quotes, skipped payments and failures stay traceable.</p></div><button disabled={!q.data?.data.length} onClick={exportPage}>Export this page</button></div><div className="rbt-filters"><label className="rbt-field">Search<input type="search" value={search} onChange={e => setSearch(e.target.value)} placeholder="Name, phone or payment ID" /></label><label className="rbt-field">Status<select value={filters.status} onChange={e => set('status', e.target.value)}><option value="">All statuses</option>{['credited', 'capped', 'skipped', 'simulated', 'failed', 'reversed'].map(s => <option key={s}>{s}</option>)}</select></label><label className="rbt-field">Channel<select value={filters.channel} onChange={e => set('channel', e.target.value)}><option value="">All channels</option>{Object.entries(channelLabels).map(([k, v]) => <option value={k} key={k}>{v}</option>)}</select></label><label className="rbt-field">Trigger<select value={filters.trigger} onChange={e => set('trigger', e.target.value)}><option value="">All triggers</option>{['topup', 'self_service', 'auto_renew'].map(t => <option key={t}>{t}</option>)}</select></label><label className="rbt-field">Month<input type="month" value={filters.period} onChange={e => set('period', e.target.value)} /></label></div>
        {q.isLoading ? <p role="status" className="rbt-empty">Loading rebate ledger…</p> : q.isError ? <p role="alert" className="rbt-error">{errorMessage(q.error)} <button onClick={() => q.refetch()}>Retry</button></p> : <><div className="rbt-table-wrap"><table><thead><tr><th>Companion / payment</th><th>Channel</th><th>Rebate</th><th>Status</th><th>Revision</th><th>Details</th></tr></thead><tbody>{q.data.data.map(r => <tr key={r.id}><td><b>{r.client?.name || 'Companion'}</b><small>{r.client?.phone_masked} · payment #{r.source_payment_id}</small><small>{new Date(r.created_at).toLocaleString()}</small></td><td>{channelLabels[r.channel]}<small>{r.rate_percent}% on {money(r.base_amount, currency)}</small></td><td><b>{money(r.amount, r.currency)}</b><small>{r.metadata?.quote?.bonus > 0 ? `+${money(r.metadata.quote.bonus, currency)} booster included` : ''}</small></td><td><span className={`rbt-status is-${r.status}`}>{r.status}</span><small>{r.reason?.replaceAll('_', ' ')}</small></td><td>{r.program_revision}</td><td><details><summary>Trace</summary><div className="rbt-trace"><span>Payment #{r.source_payment_id}</span><span>Wallet transaction {r.wallet_transaction_id ? `#${r.wallet_transaction_id}` : '—'}</span><span>Period {r.period_key}</span>{r.status === 'reversed' && <><span>Shortfall {money(r.reversal_shortfall, r.currency)}</span><span>{r.metadata?.reversal?.reason}</span></>}{r.status === 'failed' && <span>{r.metadata?.error}</span>}</div></details>{editable && ['credited', 'capped'].includes(r.status) && <button className="rbt-link" onClick={() => { setFailure(''); setReason(''); setRow(r); }}>Reverse</button>}</td></tr>)}</tbody></table></div>{!q.data.data.length && <div className="rbt-empty"><h5>No matching rebates yet.</h5><p>Completed qualifying payments create a decision here, including skipped and sandbox results.</p></div>}<div className="rbt-pagination"><span>{q.data.total} decisions · page {q.data.current_page} of {q.data.last_page}</span><button disabled={filters.page <= 1} onClick={() => setFilters(f => ({ ...f, page: f.page - 1 }))}>Previous</button><button disabled={filters.page >= q.data.last_page} onClick={() => setFilters(f => ({ ...f, page: f.page + 1 }))}>Next</button></div></>}
        <dialog className="rbt-dialog" ref={dialog} onCancel={e => { if (busy) e.preventDefault(); else setRow(null); }} onClose={() => { if (!busy) setRow(null); }}><form onSubmit={e => { e.preventDefault(); reverse(); }}><h4>Reverse {money(row?.amount, currency)}?</h4><p>Recover the available wallet credit. Any spent amount becomes a recorded shortfall. This cannot be repeated or undone.</p><label className="rbt-field">Reason<textarea autoFocus value={reason} onChange={e => setReason(e.target.value)} required minLength={5} maxLength={1000} /></label>{failure && <p className="rbt-error" role="alert">{failure}</p>}<div className="rbt-actions"><button type="button" disabled={busy} onClick={() => setRow(null)}>Cancel</button><button className="rbt-primary" disabled={busy || reason.trim().length < 5}>{busy ? 'Reversing…' : 'Reverse rebate'}</button></div></form></dialog>
    </div>;
}
