import React from 'react';
import { Link } from 'react-router-dom';

export const receiptTime = value => value ? `${new Intl.DateTimeFormat('en-GB', { timeZone: 'Africa/Dar_es_Salaam', day: '2-digit', month: 'short', year: 'numeric', hour: '2-digit', minute: '2-digit', hour12: false }).format(new Date(value))} EAT` : 'Time unavailable';
export function ProfileSubscription({ status }) {
    if (!status) return null;
    return <div className="mt-2 text-sm text-slate-600">{status.subscription ? <p><strong className="text-teal-800">{status.subscription.plan_type?.toUpperCase()} active</strong> · Expires {receiptTime(status.subscription.expires_at)}</p> : <p>No current active subscription found in CRM</p>}<p className="mt-1 text-xs text-slate-500">CRM record · {status.last_synced_at ? `Last site sync ${receiptTime(status.last_synced_at)}` : 'Site sync time unavailable'}</p></div>;
}
export default function CollectionReceiptEvidence({ usage, related = null, compact = false }) {
    if (related && (!related.complete || related.matches?.length)) return <section aria-label="Related payment records" className="space-y-3 rounded-lg border border-amber-200 bg-amber-50 p-4 text-sm text-amber-950">
        <h3 className="font-semibold">A related payment code is already recorded</h3>
        <p>Review the existing record before assigning this payment again. A numbered copy of a code does not confirm another payment was received.</p>
        {(related.matches || []).map(match => <div key={match.payment_id} className="border-t border-amber-200 pt-3">
            {match.client && <Link className="font-semibold text-teal-800 underline" to={`/clients/${match.client.id}?tab=deals`}>{match.client.name}</Link>}
            <p className="mt-1">{match.currency} {Number(match.amount).toLocaleString()} · {match.deal?.plan_type?.toUpperCase() || 'Payment recorded'}{match.deal?.expires_at ? ` · Expires ${receiptTime(match.deal.expires_at)}` : ''}</p>
            {match.recorded_by && <p className="mt-1 text-xs">Recorded by {match.recorded_by}</p>}
            <p className="mt-1 select-all font-mono text-xs">{match.reference}</p>
            {match.client && <Link className="mt-2 inline-flex min-h-11 items-center font-medium text-teal-800 underline" to={`/clients/${match.client.id}?tab=deals`}>View {match.client.name} subscription</Link>}
        </div>)}
        {!related.complete && <p>More possible matches need a manager’s review. Assignment stays on hold.</p>}
        <p className="text-xs leading-5">Removing the wrong activation can continue. Existing payment records stay unchanged for reconciliation.</p>
    </section>;
    if (!usage || ['unrecorded', 'owned'].includes(usage.state)) return null;
    const recorded = usage.state === 'already_recorded';
    return <section aria-label="Payment code status" className={`rounded-lg border p-4 ${recorded ? 'border-teal-200 bg-teal-50/60' : 'border-amber-200 bg-amber-50'}`}>
        <h3 className={`font-semibold ${recorded ? 'text-teal-900' : 'text-amber-950'}`}>{recorded ? 'This code is already recorded' : 'This payment code needs checking'}</h3>
        {!compact && <p className="mt-1 text-sm text-slate-600">{recorded ? 'Check the existing payment and subscription before taking further action.' : 'An existing payment or allocation uses this reference. Check it before recording another payment.'}</p>}
        {(usage.matches || []).map(match => <div key={match.payment_id} className="mt-3 text-sm">
            <p>{match.client && <Link className="font-semibold text-teal-800 underline" to={`/clients/${match.client.id}?tab=deals`}>{match.client.name}</Link>}<span className="ml-2 text-slate-600">{match.status === 'completed' ? 'Payment completed' : `Payment ${match.status}`}</span></p>
            {match.deal && <p className="mt-1">{match.deal.plan_type?.toUpperCase()} · {match.deal.status === 'active' && new Date(match.deal.expires_at).getTime() > Date.now() ? 'Active' : match.deal.status} · Expires {receiptTime(match.deal.expires_at)}</p>}
            {!compact && <><p className="mt-2 text-xs text-slate-600">{match.responsibility?.state === 'staff' ? `Activated by ${match.responsibility.actor?.name || 'CRM staff'} · ${receiptTime(match.responsibility.activated_at)}` : match.responsibility?.state === 'automatic' ? `Activated automatically · ${receiptTime(match.responsibility.activated_at)}` : 'Activation agent not recorded'}</p><p className="mt-1 text-xs text-slate-500">{match.destination === 'verified_account' ? 'Receiving account verified' : 'Receiving destination is not yet verified against this receipt'}</p>{match.client && <Link className="mt-3 inline-block font-medium text-teal-800 underline" to={`/clients/${match.client.id}?tab=deals`}>View subscription</Link>}</>}
        </div>)}
        {!usage.complete && <p className="mt-2 text-sm text-amber-900">More matches need review. Activation remains held.</p>}
        {!usage.matches?.length && <p className="mt-2 text-sm text-amber-900">An existing shared payment uses this code. Ask a manager to check its allocations.</p>}
    </section>;
}
