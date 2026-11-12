import React, { useEffect, useRef, useState } from 'react';
import { Link } from 'react-router-dom';
import api from '../../services/api';
import { receiptTime } from './CollectionReceiptEvidence';
import { Icon, primaryButton, secondaryButton } from './collectionUi';

const messages = {
    subscription_link_preview_stale: 'The payment or subscription changed. Refresh the receipt and review the link again.',
    finish_saved_removal_before_linking: 'Finish the saved removal before linking this subscription.',
    subscription_link_candidates_require_review: 'More than one record may use this payment. A manager needs to review the records before linking.',
    subscription_link_original_payment_requires_review: 'The original payment is still assigned or has other evidence to review. Refresh its saved result first.',
    subscription_link_cash_requires_review: 'The amount, receiving evidence or payment review needs a manager’s check before these records can be linked.',
    subscription_link_existing_service_required: 'This record needs a matching active subscription in the same market. Open the subscription to check its payment and package.',
    subscription_link_site_requires_review: 'The website subscription differs from this record, or another correction is in progress. Refresh the subscription before linking.',
    subscription_link_request_conflict: 'This link already has a saved result. Refresh the receipt to see it.',
    linked_receipt_payment_is_managed: 'This payment is already linked to a received code. Refresh the receipt to see the saved result.',
};
const explain = failure => messages[failure.response?.data?.reason] || 'The link could not be confirmed. Your subscription stays unchanged. Refresh the receipt and try again.';
const money = (minor, currency) => `${currency} ${Number(minor / 100).toLocaleString()}`;

export default function CollectionSubscriptionLink({ receipt, onUpdated }) {
    const related = receipt.related_payments;
    const [preview, setPreview] = useState(null);
    const [selected, setSelected] = useState(null);
    const [busy, setBusy] = useState(false);
    const [error, setError] = useState('');
    const [submitted, setSubmitted] = useState(false);
    const key = useRef(crypto.randomUUID());
    const heading = useRef(null);
    const linked = receipt.receipt_link || receipt.purchase?.receipt_link || (preview?.state === 'verified' ? preview.preview.receipt_link : null);
    useEffect(() => { if (preview) heading.current?.focus(); }, [preview?.id]);

    async function prepare(candidate) {
        if (busy) return;
        setSelected(candidate); setError(''); setBusy(true); setSubmitted(false); key.current = crypto.randomUUID();
        try {
            setPreview((await api.post(`/crm/collections/receipts/${receipt.id}/subscription-link-preview`, {
                payment_id: candidate.payment_id, expected_version: receipt.version, expected_allocation_version: receipt.allocation.version,
            })).data);
        } catch (failure) { setError(explain(failure)); }
        finally { setBusy(false); }
    }

    async function confirm() {
        if (busy || !preview?.preview.can_execute) return;
        setBusy(true); setError(''); setSubmitted(true);
        try {
            const result = (await api.post(`/crm/collections/subscription-links/${preview.id}/execute`, {
                preview_version: preview.version, idempotency_key: key.current,
            })).data;
            setPreview(result);
            await onUpdated();
        } catch (failure) {
            setError(explain(failure));
            try { await onUpdated(); } catch { /* Keep the same saved link/key available for checking. */ }
        } finally { setBusy(false); }
    }

    if (linked) return <section aria-label="Linked payment" className="space-y-5 rounded-xl border border-teal-200 bg-white p-5">
        <div className="flex items-start gap-3"><span className="flex h-9 w-9 shrink-0 items-center justify-center rounded-full bg-teal-50 text-teal-700"><Icon name="check" className="h-5 w-5" /></span><div><h3 className="font-semibold text-slate-900">Payment linked</h3><p className="mt-1 text-sm text-slate-600">One received payment, matched to the existing subscription.</p></div></div>
        <div><Link className="font-semibold text-teal-800 underline decoration-teal-300 underline-offset-4" to={`/clients/${linked.client.id}?tab=deals`}>{linked.client.name}</Link><p className="mt-2 text-sm text-slate-700">{linked.deal.tier.toUpperCase()} · {linked.deal.duration_days} days · {money(linked.amount_minor, linked.currency)}</p><p className="mt-1 text-sm text-slate-600">Expires {receiptTime(linked.deal.expires_at)} · unchanged</p></div>
        <dl className="grid gap-4 border-y border-slate-100 py-4 text-sm sm:grid-cols-2"><div><dt className="text-xs text-slate-500">Received payment code</dt><dd className="mt-1 select-all break-all font-mono font-semibold text-slate-900">{linked.original_reference}</dd></div><div><dt className="text-xs text-slate-500">Previous temporary code</dt><dd className="mt-1 select-all break-all font-mono text-slate-600">{linked.temporary_reference}</dd></div></dl>
        <p className="text-xs leading-5 text-slate-500">Linked by {linked.linked_by.name} · {receiptTime(linked.linked_at)}. The previous record and code are kept in payment history.</p>
        <Link className={`${secondaryButton} min-h-11`} to={`/clients/${linked.client.id}?tab=deals`}>View {linked.client.name} subscription</Link>
    </section>;

    if (!related) return null;
    const p = preview?.preview;
    const candidate = p?.client ? { client: p.client, reference: p.temporary_reference } : selected;
    const name = candidate?.client?.name || 'this profile';
    return <section aria-label="Link existing subscription" className="space-y-5 rounded-xl border border-slate-200 bg-white p-5">
        <div><h3 ref={heading} tabIndex={-1} className="break-words font-semibold text-slate-900 outline-none">{preview ? `Link ${name}’s subscription` : 'Link an existing subscription'}</h3><p className="mt-2 text-sm leading-6 text-slate-600">{preview ? 'Use the received code for this subscription. Its current expiry stays the same.' : 'We found an existing subscription recorded with this payment code or a temporary copy. Link it here to finish matching the payment.'}</p></div>
        {!preview && <div className="divide-y divide-slate-100">{(related.matches || []).map(match => <div key={match.payment_id} className="space-y-3 py-4 first:pt-0 last:pb-0">
            <div className="flex flex-wrap items-start justify-between gap-2">{match.client ? <Link className="min-w-0 break-words font-semibold text-teal-800 underline decoration-teal-300 underline-offset-4" to={`/clients/${match.client.id}?tab=deals`}>{match.client.name}</Link> : <p className="font-medium text-slate-800">Payment record needs review</p>}<span className="text-sm font-medium tabular-nums text-slate-900">{match.currency} {Number(match.amount).toLocaleString()}</span></div>
            <p className="text-sm text-slate-600">{match.deal?.plan_type?.toUpperCase() || 'Subscription'}{match.deal?.expires_at ? ` · Expires ${receiptTime(match.deal.expires_at)}` : ' · Subscription needs checking'}</p>
            {match.recorded_by && <p className="text-xs text-slate-500">Recorded by {match.recorded_by}</p>}
            <dl className="grid gap-3 rounded-lg bg-slate-50 p-3 text-sm sm:grid-cols-2"><div><dt className="text-xs text-slate-500">Recorded code</dt><dd className="mt-1 select-all break-all font-mono text-slate-700">{match.reference}</dd></div><div><dt className="text-xs text-slate-500">Received code</dt><dd className="mt-1 select-all break-all font-mono font-medium text-slate-900">{receipt.reference}</dd></div></dl>
            <button type="button" className={`${primaryButton} min-h-11 max-w-full whitespace-normal`} disabled={busy || !related.complete || !match.client} onClick={() => prepare(match)}>{busy && selected?.payment_id === match.payment_id ? 'Checking subscription…' : `Link ${match.client?.name || 'this profile'}’s subscription`}</button>
            {!match.client && <p className="text-sm text-slate-600">A manager needs to check the profile and subscription on this record.</p>}
        </div>)}</div>}
        {!related.complete && <p role="alert" className="rounded-lg bg-amber-50 p-3 text-sm text-amber-950">More payment records need a manager’s review before a subscription can be linked.</p>}
        {preview && <>
            {p.client && <><p className="text-sm font-medium text-slate-800">{money(p.amount_minor, p.currency)} · {p.deal.tier.toUpperCase()} · {p.deal.duration_days} days</p>
                <dl className="space-y-4 rounded-lg bg-slate-50 p-4 text-sm"><div className="flex flex-wrap justify-between gap-2"><dt className="text-slate-600">Temporary code</dt><dd className="select-all break-all font-mono text-slate-700">{p.temporary_reference}</dd></div><div className="flex flex-wrap justify-between gap-2"><dt className="font-medium text-slate-800">Use received code</dt><dd className="select-all break-all font-mono font-semibold text-teal-800">{p.original_reference}</dd></div><div className="border-t border-slate-200 pt-3"><dt className="text-slate-600">Subscription expires</dt><dd className="mt-1 font-medium text-slate-900">{receiptTime(p.deal.expires_at)} · unchanged</dd></div></dl>
                <p className="text-sm leading-6 text-slate-600">No extra days or additional payment. The unassigned duplicate stays in history and no longer counts as a separate successful payment.</p></>}
            {!!p.reasons?.length && <div role="alert" className="rounded-lg bg-amber-50 p-3 text-sm leading-6 text-amber-950">{p.reasons.map(code => <p key={code}>{messages[code] || 'This record needs a manager’s review before it can be linked.'}</p>)}</div>}
            <div className="flex flex-wrap gap-3">{p.can_execute && <button type="button" className={`${primaryButton} min-h-11`} disabled={busy} onClick={confirm}>{busy ? 'Confirming link…' : submitted ? 'Check link result' : 'Confirm link'}</button>}
                <button type="button" className={`${secondaryButton} min-h-11`} disabled={busy} onClick={() => { setPreview(null); setError(''); }}>{submitted ? 'Close' : 'Back'}</button></div>
        </>}
        {busy && <p role="status" className="text-sm text-slate-600">{submitted ? 'Checking the saved link. The subscription stays unchanged.' : 'Checking the current subscription with the website…'}</p>}
        {error && <div role="alert" className="space-y-2 rounded-lg bg-rose-50 p-3 text-sm leading-6 text-rose-900"><p>{error}</p><button type="button" className="min-h-11 font-medium underline" disabled={busy} onClick={() => onUpdated()}>Refresh receipt</button></div>}
    </section>;
}
