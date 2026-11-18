import React, { useMemo, useState } from 'react';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import api from '../../services/api';
import { useAuth } from '../../hooks/useAuth';
import { Icon, StatusPill, eat, quietButton, relative, secondaryButton } from './collectionUi';

// Ghana-style accounts: customers submit the MoMo transaction ID themselves and activate at once.
// These checks only FLAG claims (Ian, 18 Nov 2026); nothing here holds or changes a subscription.
const labels = {
    no_receipt: { label: 'No matching receipt', tone: 'action', help: 'The receiving phone has no payment with this transaction ID.' },
    short_paid: { label: 'Paid less than claimed', tone: 'action', help: 'The phone received less money than this subscription records.' },
    receipt_reused: { label: 'Receipt used twice', tone: 'action', help: 'One payment was used for more than one activation and does not cover them all.' },
    reversed: { label: 'Payment reversed', tone: 'action', help: 'The provider withheld or reversed this payment.' },
    verified: { label: 'Receipt confirmed', tone: 'live', help: '' },
    pending: { label: 'Checking', tone: 'busy', help: '' },
};
const reasonText = { transaction_id_one_digit_off: 'ID one digit off', payer_name_typed: 'Payer name typed instead of ID', same_amount: 'Same amount' };
const money = (minor, currency) => `${currency} ${(minor / 100).toLocaleString(undefined, { minimumFractionDigits: 0, maximumFractionDigits: 2 })}`;
const csvCell = value => `"${String(value ?? '').replace(/"/g, '""')}"`;

function ClaimRow({ claim, currency, onLink, linking }) {
    const meta = labels[claim.status] || labels.no_receipt;
    const s = claim.suggestion;
    return <li className="grid gap-3 border-l-4 border-l-amber-400 px-4 py-3 sm:grid-cols-[minmax(0,1fr)_minmax(0,1.2fr)_11rem] sm:items-start">
        <div className="min-w-0">
            <p className="font-semibold tabular-nums text-slate-900">{claim.currency} {Number(claim.amount).toLocaleString()}</p>
            <p className="text-xs text-slate-500">Claimed {eat(claim.claimed_at)} · {relative(claim.claimed_at)}</p>
            <p className="mt-1 truncate text-sm text-slate-700">{claim.client ? claim.client.name : 'No profile'}{claim.deal?.plan_type ? ` · ${claim.deal.plan_type}` : ''}{claim.deal?.status ? ` · ${claim.deal.status}` : ''}</p>
            <p className="mt-1 text-xs text-slate-500">Typed reference <code className="rounded bg-slate-100 px-1.5 py-0.5 text-slate-800">{claim.typed_reference || '—'}</code> · payment #{claim.payment_id}</p>
        </div>
        <div className="min-w-0 text-sm">
            <StatusPill tone={meta.tone}>{meta.label}</StatusPill>
            <p className="mt-1 text-xs text-slate-600">{meta.help}</p>
            {claim.receipt && <p className="mt-1 text-xs text-slate-600">Phone received {money(claim.receipt.principal_minor, claim.receipt.currency)} on {claim.receipt.reference}{claim.receipt.claim_count > 1 ? ` · claimed ${claim.receipt.claim_count}× for ${money(claim.receipt.claimed_total_minor, claim.receipt.currency)}` : ''}</p>}
            {s && <div className="mt-2 rounded-lg border border-teal-200 bg-teal-50 p-2 text-xs text-teal-950"><p className="font-medium">Likely receipt {s.receipt.reference} · {money(s.receipt.principal_minor, s.receipt.currency)}{s.receipt.payer_name ? ` from ${s.receipt.payer_name}` : ''}</p><p className="mt-0.5">{s.reasons.map(r => reasonText[r] || r).join(' · ')} · paid {eat(s.receipt.paid_at)}</p></div>}
            {claim.status === 'no_receipt' && !s && <p className="mt-1 text-xs text-slate-500">No likely receipt was found within 48 hours of this claim.</p>}
        </div>
        <div className="flex flex-wrap gap-2 sm:justify-end">
            {s && claim.typed_reference && <button type="button" className={secondaryButton} disabled={linking} onClick={() => onLink(claim, s.receipt)}>{linking ? 'Linking…' : 'Link to this receipt'}</button>}
        </div>
    </li>;
}

export default function CollectionClaimChecks() {
    const { user } = useAuth();
    const qc = useQueryClient();
    const [open, setOpen] = useState(false);
    const [days, setDays] = useState(14);
    const [status, setStatus] = useState('');
    const [message, setMessage] = useState(null);
    const query = useQuery({ queryKey: ['collection-claim-checks', user?.id, days], queryFn: async () => (await api.get('/crm/collections/claim-checks', { params: { days } })).data,
        refetchInterval: () => document.visibilityState === 'visible' ? 60000 : false, retry: 1 });
    const link = useMutation({
        mutationFn: ({ claim, receipt }) => api.post(`/crm/collections/receipts/${receipt.id}/aliases`, { reference: claim.typed_reference, evidence_reference: `Claim check: payment #${claim.payment_id}` }),
        onSuccess: (_, { claim, receipt }) => { setMessage({ tone: 'ok', text: `Linked payment #${claim.payment_id} to receipt ${receipt.reference}.` }); qc.invalidateQueries({ queryKey: ['collection-claim-checks'] }); qc.invalidateQueries({ queryKey: ['collection-receipts'] }); },
        onError: error => setMessage({ tone: 'error', text: error?.response?.status === 403 ? 'Only a manager can link a receipt.' : (error?.response?.data?.message || 'The receipt could not be linked. Try again.') }),
    });
    const accounts = query.data?.accounts || [];
    const flagged = useMemo(() => accounts.reduce((n, a) => n + (a.claims?.length || 0), 0), [accounts]);
    if (query.isPending || (query.isSuccess && accounts.length === 0) || query.error?.response?.status === 404) return null;

    const exportCsv = account => {
        const rows = [['status', 'payment_id', 'claimed_at', 'amount', 'currency', 'typed_reference', 'profile', 'deal_status', 'receipt_reference', 'receipt_amount', 'suggested_receipt', 'suggested_amount', 'suggested_payer', 'suggestion_reasons'],
            ...account.claims.map(c => [c.status, c.payment_id, c.claimed_at, c.amount, c.currency, c.typed_reference, c.client?.name, c.deal?.status, c.receipt?.reference, c.receipt ? c.receipt.principal_minor / 100 : '',
                c.suggestion?.receipt.reference, c.suggestion ? c.suggestion.receipt.principal_minor / 100 : '', c.suggestion?.receipt.payer_name, c.suggestion?.reasons.join(' ')])];
        const url = URL.createObjectURL(new Blob([rows.map(r => r.map(csvCell).join(',')).join('\n')], { type: 'text/csv' }));
        Object.assign(document.createElement('a'), { href: url, download: `claim-checks-${account.label.replace(/\W+/g, '-').toLowerCase()}.csv` }).click();
        URL.revokeObjectURL(url);
    };

    return <section aria-label="Customer payment claims to check" className={`mb-4 rounded-lg border ${flagged ? 'border-amber-200 bg-amber-50/60' : 'border-slate-200 bg-slate-50'}`}>
        <div className="flex flex-wrap items-center justify-between gap-3 px-4 py-3 text-sm">
            <div><span className="font-medium text-slate-800">Customer claims · </span>
                <span className={flagged ? 'text-amber-950' : 'text-slate-600'}>{query.isError ? 'Checks unavailable' : flagged ? `${flagged} to check against the receiving phone` : 'Every recent claim matches a phone receipt'}</span>
                <p className="mt-0.5 text-xs text-slate-500">Flag only — subscriptions stay active. Claims are checked {query.data?.accounts?.[0]?.grace_minutes ?? 30} minutes after submission.</p></div>
            <button type="button" className="py-2 font-medium text-teal-800 underline" aria-expanded={open} onClick={() => setOpen(v => !v)}>{open ? 'Hide claims' : 'Review claims'}</button>
        </div>
        {query.isError && <p role="alert" className="mx-4 mb-3 rounded-lg bg-rose-50 p-3 text-sm text-rose-800">Claim checks could not be loaded. <button type="button" className="underline" onClick={() => query.refetch()}>Retry</button></p>}
        {open && accounts.map(account => {
            const shown = (account.claims || []).filter(c => !status || c.status === status);
            return <div key={account.account_id} className="border-t border-amber-200/70 bg-white">
                <div className="flex flex-wrap items-end justify-between gap-3 px-4 py-3">
                    <div><h3 className="text-sm font-semibold text-slate-900">{account.label}</h3>
                        <p className="text-xs text-slate-500">Since {eat(account.checked_from)} · {account.counts?.verified ?? 0} confirmed · {account.counts?.pending ?? 0} checking{account.complete ? '' : ' · showing the most recent window only'}</p></div>
                    <div className="flex flex-wrap items-end gap-2">
                        <label className="text-xs text-slate-600">Problem<select className="crm-input mt-1 block" value={status} onChange={e => setStatus(e.target.value)}><option value="">All problems</option>{['no_receipt', 'short_paid', 'receipt_reused', 'reversed'].map(k => <option key={k} value={k}>{labels[k].label} ({account.counts?.[k] ?? 0})</option>)}</select></label>
                        <label className="text-xs text-slate-600">Window<select className="crm-input mt-1 block" value={days} onChange={e => setDays(Number(e.target.value))}>{[3, 7, 14, 30, 60].map(d => <option key={d} value={d}>Last {d} days</option>)}</select></label>
                        <button type="button" className={quietButton} onClick={() => exportCsv(account)} disabled={!account.claims?.length}><Icon name="download" />Export</button>
                    </div>
                </div>
                {message && <p role="status" className={`mx-4 mb-3 rounded-lg p-2 text-sm ${message.tone === 'ok' ? 'bg-teal-50 text-teal-900' : 'bg-rose-50 text-rose-800'}`}>{message.text}</p>}
                {shown.length === 0 ? <div className="px-4 pb-6 pt-2 text-center"><p className="font-medium text-slate-900">{status ? 'No claims with this problem' : 'Nothing to check'}</p><p className="mt-1 text-sm text-slate-500">{status ? 'Choose another problem or widen the window.' : 'Every claim in this window matches a receipt on the receiving phone.'}</p></div>
                    : <ul className="divide-y divide-slate-100">{shown.map(c => <ClaimRow key={c.payment_id} claim={c} currency={account.currency} linking={link.isPending && link.variables?.claim?.payment_id === c.payment_id} onLink={(claim, receipt) => { setMessage(null); link.mutate({ claim, receipt }); }} />)}</ul>}
            </div>;
        })}
    </section>;
}
