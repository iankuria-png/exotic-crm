import React, { useEffect, useRef, useState } from 'react';
import { useQuery } from '@tanstack/react-query';
import api from '../../services/api';
import { primaryButton, secondaryButton } from './collectionUi';

const explanations = {
    purchase_authority_stale_or_incompatible: 'The selected package or profile is no longer eligible for this payment. Check the package price and profile details.',
    purchase_terms_incompatible: 'This package is no longer available. Choose a current package.',
    cross_market_correction_requires_recovery: 'Choose a profile in the payment’s original market.',
    target_purchase_required: 'Choose the intended profile and package.',
    correction_preview_stale_or_held: 'The payment changed. Refresh the receipt before trying again.',
    correction_target_state_changed: 'The selected profile changed. Refresh its details before trying again.',
    activation_already_removed: 'This activation is already removed. You can assign the payment to a profile.',
    wordpress_compensation_v2_required: 'This website needs its correction support checked before this can continue.',
    original_compensation_preimage_required: 'The original activation cannot be reversed automatically. A manager needs to check its saved site result.',
    untracked_entitlement_change_requires_recovery: 'Other service changed on this profile. A manager needs to check it before moving this activation.',
    later_paid_coverage_requires_recovery: 'A later purchase needs to be preserved. A manager must review this correction.',
    correction_duration_policy_required: 'The market’s correction period needs approval before this can continue.',
    consumed_or_paid_benefit_requires_review: 'A claimed benefit or paid commission needs a manager’s review.',
    correction_cash_allocation_requires_finance_review: 'The package price differs from the allocated payment. Choose the purchased price or ask Finance to review it.',
    receipt_amount_or_currency_mismatch: 'This package does not match the money received. Choose the purchased price.',
    subsidiary_sale_correction_requires_owned_benefit_review: 'The paired subscription needs to be checked with this activation before it can move.',
    correction_target_conflicts_with_sibling_or_market: 'Choose another profile in this market that is not already using this payment.',
    related_payment_requires_review: 'Another payment record uses this code or a numbered copy. Review that record before assigning this payment again.',
    finish_or_recover_existing_operation_first: 'Finish the saved correction before starting another change.',
    operation_result_unavailable: 'The website has no confirmed result for this step. Check the saved correction; if it stays here, ask a manager to review it.',
    site_confirmation_required: 'The website result could not be confirmed. Check the saved correction before making another change.',
    wordpress_correction_owner_conflict: 'Another correction is protecting this profile. A manager needs to check it before continuing.',
};
const explain = code => explanations[code] || 'This correction needs checking before it can continue.';
const money = (minor, currency) => currency + ' ' + Number(minor / 100).toLocaleString();
const finished = state => ['verified', 'confirmed'].includes(state);

export function CorrectionProgress({ receipt, correction }) {
    const removing = correction.action === 'undo_effect';
    const effects = (receipt.allocation?.effects || []).filter(e => e.correction_id === correction.id);
    return <section aria-label="Saved payment correction" className="space-y-3 rounded-lg border border-amber-200 bg-amber-50/70 p-4 text-sm text-amber-950">
        <h4 className="font-semibold">{correction.state === 'verified_release_pending' ? 'Finishing correction' : removing ? 'Removal needs checking' : 'Correction needs checking'}</h4>
        <p>{removing ? 'The removal is saved. Finish it before starting another change.' : 'The change is saved. Check its result before starting another activation.'}</p>
        {!!effects.length && <ul className="space-y-2">{effects.map(effect => {
            const removal = effect.kind === 'source_compensation';
            const profile = Number(effect.platform_id) === Number(receipt.purchase?.platform_id) ? 'Paid subscription' : 'Tanzania exposure';
            const state = effect.state === 'succeeded' ? (removal ? 'Removal confirmed' : 'Activation confirmed') : effect.state === 'prepared' ? 'Not started' : effect.state === 'wp_verified' ? 'Website confirmed · Updating CRM' : 'Needs checking';
            return <li key={effect.id}><div className="flex flex-wrap justify-between gap-x-4 gap-y-1"><span>{profile}</span><strong className="font-medium">{state}</strong></div>{effect.last_error_code && <p className="mt-1 text-xs leading-5">{explain(effect.last_error_code)}</p>}</li>;
        })}</ul>}
        <p className="text-xs leading-5">Money received stays recorded. A pending removal does not confirm that service has stopped.</p>
    </section>;
}

export default function CollectionActivationCorrection({ receipt, item = null, action = 'reassign', initialCorrection = null, onUpdated, onCancel, onComplete }) {
    const source = item?.purchase || receipt.purchase;
    const sourceClient = item?.client || receipt.clients?.find(c => Number(c.id) === Number(source?.client_id));
    const sourceName = sourceClient?.name || 'Previous profile';
    const removed = (item?.state || receipt.allocation?.state) === 'unassigned';
    const removing = action === 'undo_effect';
    const initialTarget = initialCorrection?.preview?.target_client || (initialCorrection?.preview?.target ? { ...initialCorrection.preview.target, id: initialCorrection.preview.target.client_id, name: initialCorrection.preview.target.name || 'Selected profile' } : null);
    const [target, setTarget] = useState(initialTarget || (!removed && !removing ? (sourceClient || { id: source.client_id, name: sourceName, platform_id: source.platform_id }) : null));
    const [term, setTerm] = useState('');
    const [searchTerm, setSearchTerm] = useState('');
    const [price, setPrice] = useState('');
    const [note, setNote] = useState('');
    const [saved, setSaved] = useState(initialCorrection);
    const [busy, setBusy] = useState(false);
    const [error, setError] = useState('');
    const [status, setStatus] = useState('');
    const key = useRef(initialCorrection?.request_key || crypto.randomUUID());
    const started = useRef(Boolean(initialCorrection?.request_key));
    const explicitPrice = useRef(false);
    const completionNotified = useRef(false);
    const locked = started.current && !finished(saved?.state);
    const relatedHold = Boolean(receipt.related_payments && (!receipt.related_payments.complete || receipt.related_payments.matches?.length));
    const base = '/crm/collections/receipts/' + receipt.id;
    const serverCorrection = (item?.corrections || receipt.corrections || []).find(c => c.id === saved?.id) || initialCorrection;
    function complete(correction) {
        if (completionNotified.current) return;
        completionNotified.current = true;
        const name = correction.preview?.target_client?.name || target?.name || 'the selected profile';
        const message = removing ? relatedHold ? 'Activation removed. Review the other payment record before assigning again.' : 'Activation removed. The payment is ready to assign.' : 'Payment assigned to ' + name + '.';
        setStatus(message);
        if (onComplete) onComplete(message);
        else onCancel?.();
    }
    useEffect(() => {
        if (!serverCorrection?.request_key || serverCorrection.id !== saved?.id) return;
        setSaved(serverCorrection);
        key.current = serverCorrection.request_key;
        started.current = true;
        if (finished(serverCorrection.state)) complete(serverCorrection);
    }, [serverCorrection]);

    useEffect(() => { const timer = setTimeout(() => setSearchTerm(term.trim()), 250); return () => clearTimeout(timer); }, [term]);
    const context = useQuery({ queryKey: ['correction-suggestions', receipt.id], enabled: !removing,
        queryFn: () => api.get(base + '/review-context', { params: { workflow: 'manual' } }).then(r => r.data), retry: false });
    const search = useQuery({ queryKey: ['correction-profiles', receipt.id, searchTerm], enabled: !removing && searchTerm.length >= 2,
        queryFn: () => api.get(base + '/candidates', { params: { search: searchTerm, workflow: 'manual' } }).then(r => r.data), retry: false });
    const options = useQuery({ queryKey: ['correction-packages', receipt.id, target?.id], enabled: !removing && Boolean(target),
        queryFn: () => api.get(base + '/candidates', { params: { selected_client_id: target.id, workflow: 'manual' } }).then(r => r.data), retry: false });
    const prices = (options.data?.prices || []).filter(p => Number(p.product?.platform_id) === Number(source.platform_id));
    const amount = item?.amount_minor ?? source?.amount_minor ?? receipt.principal_minor;
    useEffect(() => {
        if (!options.data || explicitPrice.current) return;
        const valid = (options.data.prices || []).filter(p => Number(p.product?.platform_id) === Number(source.platform_id));
        const same = valid.find(p => Number(p.id) === Number(source.product_price_id));
        const equivalent = valid.filter(p => p.product?.tier === source.tier && Number(p.duration_days) === Number(source.duration_days) && Math.round(Number(p.price) * 100) === amount);
        setPrice(same ? String(same.id) : equivalent.length === 1 ? String(equivalent[0].id) : '');
    }, [options.data, source.product_price_id, source.platform_id, source.tier, source.duration_days, amount]);
    const suggestions = searchTerm.length >= 2 ? search.data?.clients || [] : context.data?.suggestions || receipt.match_suggestions || [];
    const candidates = suggestions.filter(c => Number(c.platform_id) === Number(source.platform_id));
    const chosenPrice = prices.find(p => String(p.id) === price);
    function choose(client) {
        setTarget(client); setTerm(''); setSearchTerm(''); explicitPrice.current = false; setPrice(''); setSaved(null); setError(''); key.current = crypto.randomUUID();
    }
    async function submit(event) {
        event.preventDefault(); if (busy || (!started.current && !removing && relatedHold)) return;
        setBusy(true); setError('');
        try {
            let correction = saved;
            if (!started.current) {
                setStatus('Checking the correction…');
                const reason = note.trim() || (removing ? 'Staff removed this payment activation.' : Number(target.id) === Number(source.client_id) ? 'Staff corrected the purchased package.' : 'Staff reassigned the payment from ' + sourceName + ' to ' + target.name + '.');
                const body = item ? { item_version: item.version, action: removing ? 'undo_effect' : Number(target.id) === Number(source.client_id) ? 'change_package' : 'reassign', reason }
                    : { expected_version: receipt.allocation.version, action: removing ? 'undo_effect' : 'reassign', reason };
                if (!removing) Object.assign(body, { client_id: Number(target.id), product_price_id: Number(price) });
                const previewPath = item ? '/crm/collections/sales/items/' + item.id + '/correction-preview' : base + '/correction-preview';
                correction = (await api.post(previewPath, body)).data;
                setSaved(correction);
                if (!correction.preview.can_execute) { setStatus(''); return; }
            }
            started.current = true;
            setStatus(removing ? 'Removing this activation…' : removed ? 'Assigning the payment…' : 'Updating the activation…');
            const executePath = item ? '/crm/collections/sales/corrections/' + correction.id + '/execute' : '/crm/collections/corrections/' + correction.id + '/execute';
            const result = (await api.post(executePath, item ? { version: correction.version, request_key: key.current }
                : { preview_version: correction.version, idempotency_key: key.current })).data;
            setSaved(result);
            await onUpdated?.();
            if (finished(result.state)) {
                complete(result);
            } else setStatus('The correction is saved. Use Check saved correction to confirm its website result.');
        } catch (failure) {
            const code = failure.response?.data?.reason;
            setError(code ? explain(code) : failure.response?.data?.message || 'The result could not be confirmed. Check this saved correction before trying another change.');
            if (started.current) setStatus('The result is not confirmed. Check this correction before submitting another activation.');
            else setStatus('');
        } finally { setBusy(false); }
    }

    const unchanged = !removing && !removed && !locked && target && Number(target.id) === Number(source.client_id) && Number(price) === Number(source.product_price_id);
    const label = locked ? 'Check saved correction' : removing ? 'Remove activation' : removed ? 'Assign payment' : target && Number(target.id) !== Number(source.client_id) ? 'Move activation' : 'Update package';
    return <form onSubmit={submit} aria-label={removing ? 'Remove payment activation' : 'Change profile or package'} className="space-y-4 border-t border-slate-200 pt-4">
        <div><h4 className="text-sm font-semibold text-slate-900">{removing ? 'Remove this activation' : removed ? 'Assign this payment' : 'Change profile or package'}</h4>
            <p className="mt-1 text-sm leading-6 text-slate-600">{removed ? 'The previous activation is removed. Choose the profile to receive this payment.' : removing ? 'Remove only the service added by this payment. Other paid days stay in place.' : 'Choose the correct profile or package. Other paid days stay in place.'}</p></div>
        {locked && saved && <CorrectionProgress receipt={receipt} correction={saved} />}
        {!removing && relatedHold && <p role="alert" className="rounded-lg bg-amber-50 p-3 text-sm text-amber-950">Review the related payment record before assigning this payment again. Existing subscriptions stay in place.</p>}
        {!removing && !locked && <>
            <label className="block text-sm font-medium text-slate-800">Profile
                <input className="crm-input mt-1 w-full" value={term} onChange={e => setTerm(e.target.value)} placeholder="Search by name, phone or profile link" disabled={busy} />
            </label>
            {target && <div className="flex items-center justify-between gap-3 rounded-lg border border-teal-200 bg-teal-50/60 px-3 py-2.5"><div><p className="text-sm font-semibold text-teal-950">{target.name}</p><p className="mt-0.5 text-xs text-teal-800">{Number(target.id) === Number(source.client_id) ? 'Current profile' : 'Selected profile'}</p></div><span aria-hidden="true" className="text-teal-700">✓</span></div>}
            {(search.isFetching || context.isPending) && <p role="status" className="text-xs text-slate-600">Finding profiles…</p>}
            {((searchTerm.length >= 2 && search.isError) || (!searchTerm && context.isError)) && <p role="alert" className="text-sm text-rose-800">Profiles could not be loaded. <button type="button" className="min-h-11 underline" onClick={() => searchTerm ? search.refetch() : context.refetch()}>Try again</button></p>}
            {!!candidates.filter(c => Number(c.id) !== Number(target?.id)).length && <div className="space-y-1" aria-label="Suggested profiles">{candidates.filter(c => Number(c.id) !== Number(target?.id)).slice(0, 8).map(c => <button key={c.id} type="button" className="flex min-h-11 w-full items-center justify-between gap-3 rounded-lg px-3 py-2 text-left text-sm text-slate-800 hover:bg-teal-50 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-teal-600" disabled={busy} onClick={() => choose(c)}><span className="font-medium">{c.name}</span><span className="text-xs text-teal-800">Choose</span></button>)}</div>}
            {searchTerm.length >= 2 && !search.isFetching && !search.isError && !candidates.length && <p className="text-sm text-slate-600">No profiles found. Try the name or full profile link.</p>}
            {target && <label className="block text-sm font-medium text-slate-800">Package
                <select className="crm-input mt-1 w-full" value={price} disabled={busy || options.isPending} onChange={e => { setPrice(e.target.value); explicitPrice.current = true; setSaved(null); setError(''); }}>
                    <option value="">{options.isPending ? 'Loading packages…' : 'Choose the purchased package'}</option>
                    {prices.map(p => <option key={p.id} value={p.id}>{p.product?.name || p.product?.tier?.toUpperCase()} · {p.duration_days} days · {p.currency} {Number(p.price).toLocaleString()}</option>)}
                </select>
                {price && Number(price) === Number(source.product_price_id) && <span className="mt-1 block text-xs font-normal text-slate-600">Current package kept. Change it only if the package was wrong.</span>}
            </label>}
            {options.isError && <p role="alert" className="text-sm text-rose-800">Packages could not be loaded. <button type="button" className="min-h-11 underline" onClick={() => options.refetch()}>Retry packages</button></p>}
        </>}
        {!locked && <details className="text-sm text-slate-600"><summary className="min-h-11 cursor-pointer py-3">Add a note (optional)</summary><label className="block">Correction note<textarea rows={2} maxLength={1000} className="crm-input mt-1 w-full" value={note} disabled={busy} onChange={e => { setNote(e.target.value); setSaved(null); }} /></label></details>}
        {!removing && target && <div className="rounded-lg bg-slate-50 px-3 py-3 text-sm leading-6 text-slate-700">
            <p>{removed ? 'Assign to ' : 'Apply to '}<strong className="text-slate-900">{target.name}</strong>{chosenPrice ? ' · ' + chosenPrice.duration_days + ' days' : ''}</p>
            <p className="mt-1 text-xs">{money(amount, receipt.currency)} received. No additional payment is taken.</p>
        </div>}
        {saved && !saved.preview.can_execute && <div role="alert" className="space-y-2 rounded-lg bg-amber-50 p-3 text-sm text-amber-950">{saved.preview.reasons.map(code => <p key={code}>{explain(code)}</p>)}</div>}
        {status && <p role="status" className="text-sm text-teal-900">{status}</p>}
        {error && <p role="alert" className="text-sm text-rose-800">{error}</p>}
        {unchanged && <p className="text-xs text-slate-500">Choose a different profile or package to apply a change.</p>}
        <div className="flex flex-wrap gap-3"><button type="submit" className={primaryButton} disabled={busy || unchanged || (!locked && !removing && (relatedHold || !target || !price || options.isError))}>{busy ? (locked ? 'Checking saved correction…' : 'Applying correction…') : label}</button>
            <button type="button" className={secondaryButton} disabled={busy} onClick={onCancel}>{locked ? 'Close' : 'Cancel'}</button></div>
    </form>;
}
