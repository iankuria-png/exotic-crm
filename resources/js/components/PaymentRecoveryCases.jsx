import React, { useState } from 'react';
import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query';
import api from '../services/api';

const labels = { open: 'Needs review', awaiting_identity: 'Finding customer', awaiting_fulfillment: 'Awaiting activation', settlement_review: 'Review payment evidence', effect_unknown: 'Checking activation', closed: 'Resolved' };

export default function PaymentRecoveryCases({ platformId, canManage, staffId }) {
    const [state, setState] = useState('');
    const [page, setPage] = useState(1);
    const [error, setError] = useState('');
    const [selected, setSelected] = useState(null);
    const [disposition, setDisposition] = useState('fulfilled');
    const [reason, setReason] = useState('');
    const [evidenceId, setEvidenceId] = useState('');
    const client = useQueryClient();
    const params = { platform_id: platformId || undefined, state: state || undefined, page, per_page: 25 };
    const query = useQuery({ queryKey: ['payment-recovery-cases', params], queryFn: async () => (await api.get('/crm/payment-recovery-cases', { params })).data });
    const update = useMutation({
        mutationFn: async ({ row, action, requestKey, ...evidence }) => api.patch(`/crm/payment-recovery-cases/${row.id}`, { request_key: requestKey, version: row.version, action, assigned_to: action === 'assign' ? staffId : undefined, reason: action === 'assign' ? 'Claimed for payment recovery review.' : 'Support reopened this receipt for investigation.', ...evidence }),
        onSuccess: () => { setError(''); setSelected(null); setReason(''); setEvidenceId(''); client.invalidateQueries({ queryKey: ['payment-recovery-cases'] }); },
        onError: e => setError(e.response?.data?.message || 'Could not update the case. Refresh and try again.'),
    });
    async function exportRows() {
        try {
            const result = await api.get('/crm/payment-recovery-cases', { params: { ...params, export: 1 }, responseType: 'blob' });
            const url = URL.createObjectURL(result.data); const a = document.createElement('a'); a.href = url; a.download = 'payment-recovery.csv'; a.click(); URL.revokeObjectURL(url);
        } catch { setError('Could not export recovery cases. Try again.'); }
    }
    return <section className="rounded-xl border border-slate-200 bg-white p-5" aria-label="Payment service recovery">
        <div className="flex flex-wrap items-start justify-between gap-4">
            <div><h2 className="font-semibold text-slate-900">Paid customer recovery</h2><p className="mt-1 max-w-2xl text-sm text-slate-500">Track receipts awaiting verified service, including customers already linked to a profile. Evidence review stays visible until a benefit or resolution is recorded.</p></div>
            <button type="button" onClick={exportRows} className="rounded-lg border border-slate-300 px-3 py-2 text-sm">Export cases</button>
        </div>
        {!!query.data?.summary?.length && <div className="mt-5 flex flex-wrap gap-x-8 gap-y-3 border-y border-slate-100 py-4">{[
            ['Accepted cash awaiting service', ['accepted_cash']],
            ['Cash held for settlement review', ['settlement_review']],
            ['Evidence awaiting verification', ['settlement_unverified','proof_pending']],
        ].map(([label, reasons]) => { const rows = query.data.summary.filter(x => reasons.includes(x.reason_code)); const total = rows.reduce((n,x) => n + Number(x.receipts), 0); return <div key={label}><p className="text-xs text-slate-500">{label}</p><p className="mt-1 font-semibold text-slate-900">{total} {total === 1 ? 'receipt' : 'receipts'}</p>{rows.map(x => <p key={x.reason_code + x.currency} className="text-xs text-slate-500">{x.currency} {Number(x.amount).toLocaleString()}</p>)}</div>; })}</div>}
        {selected && <form className="mt-5 rounded-lg border border-teal-200 bg-teal-50/40 p-4" onSubmit={event => { event.preventDefault(); update.mutate({ row: selected, requestKey: crypto.randomUUID(), action: disposition === 'verify_receipt' ? 'verify_receipt' : 'record_disposition', disposition, reason, deal_id: disposition === 'compensating_benefit_reconciled' ? Number(evidenceId) : undefined, audit_id: ['refunded','verify_receipt'].includes(disposition) ? Number(evidenceId) : undefined }); }}>
            <h3 className="text-sm font-semibold">Review payment #{selected.payment_id}</h3>
            <p className="mt-1 text-xs text-slate-600">Record an existing verified outcome. This does not activate a profile or issue a refund.</p>
            <label className="mt-3 block text-sm">Outcome<select className="crm-input mt-1" value={disposition} onChange={e => setDisposition(e.target.value)}>{[['fulfilled','Existing paid benefit'],['compensating_benefit_reconciled','Existing compensating trial'],['refunded','Verified refund'],['reversed','Recorded reversal'],['invalid_receipt','Invalid receipt'],['no_cash_trial','Zero-value trial'],['verify_receipt','Verify receiving-account audit evidence']].map(([value,label]) => <option key={value} value={value}>{label}</option>)}</select></label>
            {['compensating_benefit_reconciled','refunded','verify_receipt'].includes(disposition) && <label className="mt-3 block text-sm">{disposition === 'compensating_benefit_reconciled' ? 'Existing deal ID' : 'Verified audit ID'}<input className="crm-input mt-1" type="number" min="1" required value={evidenceId} onChange={e => setEvidenceId(e.target.value)} /></label>}
            <label className="mt-3 block text-sm">Reason<textarea className="crm-input mt-1" required minLength={3} maxLength={500} value={reason} onChange={e => setReason(e.target.value)} /></label>
            <div className="mt-3 flex gap-4"><button className="rounded-lg bg-teal-700 px-3 py-2 text-sm text-white disabled:opacity-50" disabled={update.isPending}>Save verified outcome</button><button type="button" onClick={() => setSelected(null)} className="text-sm text-slate-600">Cancel</button></div>
        </form>}
        <label className="mt-5 block text-sm text-slate-600">Recovery state<select className="ml-3 rounded-lg border border-slate-300 bg-white px-3 py-2" value={state} onChange={e => { setState(e.target.value); setPage(1); }}><option value="">All states</option>{Object.entries(labels).map(([k,v]) => <option key={k} value={k}>{v}</option>)}</select></label>
        {(error || query.isError) && <p role="alert" className="mt-4 rounded-lg bg-rose-50 p-3 text-sm text-rose-800">{error || 'Recovery cases could not be loaded.'} <button type="button" onClick={() => query.refetch()} className="underline">Retry</button></p>}
        {query.isPending ? <p role="status" className="py-10 text-sm text-slate-500">Loading recovery cases…</p> : !query.isError && !query.data?.data?.length ? <p className="py-10 text-sm text-slate-500">No recovery cases match this view.</p> : <div className="mt-4 overflow-x-auto"><table className="w-full text-left text-sm"><thead className="border-b text-xs uppercase tracking-wide text-slate-500"><tr>{['Receipt','Amount','Recovery','Owner','Waiting since','Actions'].map(x => <th key={x} className="px-3 py-3">{x}</th>)}</tr></thead><tbody>{query.data?.data?.map(row => <tr key={row.id} className="border-b border-slate-100 align-top">
            <td className="px-3 py-4"><a href={`/payments?search=${row.payment_id}`} className="font-medium text-teal-700">Payment #{row.payment_id}</a><details className="mt-1 text-xs text-slate-500"><summary className="cursor-pointer">Case reference</summary><span className="select-all">{row.public_reference}</span></details></td>
            <td className="whitespace-nowrap px-3 py-4">{row.payment?.currency} {Number(row.payment?.amount || 0).toLocaleString()}</td>
            <td className="px-3 py-4"><span className="font-medium text-slate-800">{labels[row.state] || row.state}</span><p className="mt-1 text-xs text-slate-500">{row.reason_code.replaceAll('_',' ')}</p>{row.escalated_at && row.state !== 'closed' && <span className="mt-1 block text-xs text-amber-700">{row.alerted_at ? 'Overdue: support action needed' : 'Support attention required'}</span>}</td>
            <td className="px-3 py-4">{row.assigned_to ? `Staff #${row.assigned_to}` : `Market ${row.platform_id} support`}</td>
            <td className="whitespace-nowrap px-3 py-4 text-slate-500">{new Date(row.opened_at).toLocaleString()}</td>
            <td className="px-3 py-4">{canManage && <button type="button" disabled={update.isPending} onClick={() => update.mutate({ row, requestKey: crypto.randomUUID(), action: row.state === 'closed' ? 'reopen' : 'assign' })} className="rounded-lg border border-slate-300 px-3 py-2 text-xs disabled:opacity-50">{row.state === 'closed' ? 'Reopen case' : 'Assign to me'}</button>}{canManage && row.state !== 'closed' && <button type="button" onClick={() => { setSelected(row); setError(''); }} className="mt-2 block text-xs text-teal-700 underline">Review resolution</button>}</td>
        </tr>)}</tbody></table></div>}
        <div className="mt-4 flex items-center justify-between text-sm text-slate-600"><span>{query.data?.total ?? 0} {query.data?.total === 1 ? 'case' : 'cases'}</span><div className="flex gap-3"><button type="button" disabled={page <= 1 || query.isFetching} onClick={() => setPage(page-1)} className="disabled:opacity-40">Previous</button><span>Page {page}</span><button type="button" disabled={!query.data?.next_page_url || query.isFetching} onClick={() => setPage(page+1)} className="disabled:opacity-40">Next</button></div></div>
    </section>;
}
