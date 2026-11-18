import React, { useState } from 'react';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { Link } from 'react-router-dom';
import api from '../../services/api';
import { useAuth } from '../../hooks/useAuth';
import { relative } from './collectionUi';

// Why staff chose a profile. Keys match CollectionMatchFeedbackService::REASONS.
export const MATCH_REASONS = [
    { key: 'advertiser_confirmed', label: 'Advertiser told us', hint: 'They named the profile by message or call' },
    { key: 'pays_for_several', label: 'Pays for several profiles', hint: 'A manager, partner or agent paying for more than one profile' },
    { key: 'new_profile', label: 'New profile', hint: 'The profile was created for this payment' },
    { key: 'old_duplicate', label: 'Suggestion is old or a duplicate', hint: 'Stops suggesting that profile for this payer' },
    { key: 'other', label: 'Other', hint: 'Add a short note' },
];
export const reasonLabel = key => MATCH_REASONS.find(r => r.key === key)?.label || '';

export const GUARD_TEXT = {
    payer_funds_several_profiles: 'This payer has paid for more than one profile, so a person confirms which one.',
    learned_payer_conflicts_with_other_evidence: 'The payer’s confirmed profile and other evidence point to different profiles.',
    suggested_profile_dormant: 'The suggested profile has no paid subscription in the last 60 days. It may be an older or duplicate profile.',
};

const dayFormat = new Intl.DateTimeFormat('en-GB', { day: 'numeric', month: 'short', timeZone: 'Africa/Nairobi' });
/** Subscription timing at the moment of payment, as a short pill. */
export function subscriptionBadge(subscription) {
    if (!subscription) return null;
    const until = subscription.expires_at ? dayFormat.format(new Date(subscription.expires_at)) : '';
    switch (subscription.state) {
        case 'due': return { tone: 'bg-teal-50 text-teal-900 ring-teal-600/25', label: `Due now · ends ${until}` };
        case 'lapsed': return { tone: 'bg-amber-50 text-amber-950 ring-amber-600/30', label: `Lapsed ${Math.round(subscription.days_from_expiry)} days ago` };
        case 'active': return { tone: 'bg-slate-100 text-slate-700 ring-slate-500/20', label: `Active until ${until}` };
        case 'new': return { tone: 'bg-sky-50 text-sky-950 ring-sky-600/25', label: 'New profile · no paid plan yet' };
        case 'dormant': return { tone: 'bg-rose-50 text-rose-900 ring-rose-600/25', label: 'No paid plan in 60+ days' };
        default: return { tone: 'bg-rose-50 text-rose-900 ring-rose-600/25', label: 'Never paid' };
    }
}

export function SubscriptionBadge({ subscription }) {
    const badge = subscriptionBadge(subscription);
    if (!badge) return null;
    return <span className={`inline-flex items-center rounded-full px-2 py-0.5 text-xs font-medium ring-1 ring-inset ${badge.tone}`}>{badge.label}</span>;
}

/**
 * One-tap reason chips. Native radios keep arrow-key navigation and screen-reader semantics.
 * `suggestion` present → the staff choice differs from it (required); absent → optional "how did you find it".
 */
export function MatchReasonPicker({ id, suggestion, chosenName, value, note = '', onChange, required = false, disabled = false, prompt = null }) {
    const options = MATCH_REASONS.filter(r => suggestion || r.key !== 'old_duplicate');
    const missing = required && !value;
    return <fieldset disabled={disabled} className={`space-y-2.5 rounded-lg p-3 ring-1 ring-inset ${suggestion ? 'bg-amber-50/70 ring-amber-200' : 'bg-slate-50 ring-slate-200'}`} aria-describedby={`${id}-hint`}>
        <legend className="sr-only">{suggestion ? `Why ${chosenName || 'this profile'} instead of the suggested profile` : 'How this profile was identified'}</legend>
        <p className="text-sm leading-6 text-slate-800" aria-hidden="true">{prompt || (suggestion
            ? <><span className="font-semibold text-amber-950">Not the suggested profile.</span> Matching suggested <strong>{suggestion.name}</strong>{Number.isFinite(suggestion.score) ? ` (${suggestion.score} / 100)` : ''}. Why {chosenName ? <strong>{chosenName}</strong> : 'this one'}?</>
            : <><span className="font-medium">How did you identify this profile?</span> <span className="text-slate-500">Optional</span></>)}</p>
        <div role="radiogroup" aria-required={required || undefined} className="flex flex-wrap gap-2">{options.map(option => <label key={option.key} className="relative cursor-pointer" title={option.hint}>
            <input type="radio" className="peer sr-only" name={`${id}-reason`} value={option.key} checked={value === option.key} onChange={() => onChange({ reason: option.key, note: option.key === 'other' ? note : '' })} />
            <span className="inline-flex min-h-9 items-center rounded-full border border-slate-300 bg-white px-3 text-sm font-medium text-slate-700 transition-colors hover:border-teal-600 hover:text-teal-900 peer-checked:border-teal-700 peer-checked:bg-teal-700 peer-checked:text-white peer-focus-visible:ring-2 peer-focus-visible:ring-teal-600 peer-focus-visible:ring-offset-1 peer-disabled:opacity-50">{option.label}</span>
        </label>)}</div>
        {value === 'other' && <label className="block text-xs font-medium text-slate-700">What happened?<input className="crm-input mt-1 w-full" value={note} maxLength={500} required onChange={e => onChange({ reason: 'other', note: e.target.value })} placeholder="e.g. Agency owner named the profile in the group chat" /></label>}
        <p id={`${id}-hint`} className={`text-xs ${missing ? 'font-medium text-amber-900' : 'text-slate-500'}`}>{missing ? 'Choose a reason to continue. It takes one tap.' : value === 'old_duplicate' ? 'This profile will stop being suggested for this payer.' : 'Your answer teaches matching which profile this payer means.'}</p>
    </fieldset>;
}

export function useRecordMatchFeedback(receiptId) {
    const client = useQueryClient();
    return useMutation({
        mutationFn: async body => (await api.post(`/crm/collections/receipts/${receiptId}/match-feedback`, body)).data,
        onSuccess: () => { client.invalidateQueries({ queryKey: ['collection-match-feedback'] }); client.invalidateQueries({ queryKey: ['collection-match-accuracy'] }); },
    });
}

/** Drawer card: compare the activated profile with the suggestion and collect the reason once. */
export function CollectionMatchFeedbackCard({ row }) {
    const { user } = useAuth();
    const query = useQuery({ queryKey: ['collection-match-feedback', user?.id, row.id, row.version, row.usage?.state, row.sale?.state, row.allocation?.state],
        queryFn: async () => (await api.get(`/crm/collections/receipts/${row.id}/match-feedback`)).data, retry: false, refetchOnWindowFocus: false });
    const data = query.data;
    if (query.isPending || query.isError || !data?.outcomes?.length) return null;
    const source = row.usage?.state === 'already_recorded' ? 'recorded' : 'review';
    return <section aria-label="Matching check" className="space-y-3 rounded-xl border border-slate-200 bg-white p-4 sm:p-5">
        <div className="flex flex-wrap items-baseline justify-between gap-2"><h3 className="text-sm font-semibold text-slate-900">Matching check</h3>
            <span className="text-xs text-slate-500">{data.suggestion ? `Suggested: ${data.suggestion.name || 'profile'} · ${data.suggestion.score} / 100` : 'No suggestion was available'}</span></div>
        {data.outcomes.map(outcome => <OutcomeFeedback key={outcome.id} receiptId={row.id} outcome={outcome} suggestion={data.suggestion}
            saved={data.feedback.find(f => f.client_id === outcome.id)} source={source} />)}
    </section>;
}

function OutcomeFeedback({ receiptId, outcome, suggestion, saved, source }) {
    const [editing, setEditing] = useState(false);
    const [choice, setChoice] = useState({ reason: saved?.reason || '', note: saved?.note || '' });
    const record = useRecordMatchFeedback(receiptId);
    const name = <Link to={`/clients/${outcome.id}`} className="font-semibold text-teal-800 underline decoration-teal-800/30 underline-offset-2">{outcome.name}</Link>;
    if (outcome.agreed) return <p className="flex items-center gap-2 text-sm text-teal-900"><span aria-hidden="true" className="flex h-5 w-5 items-center justify-center rounded-full bg-teal-700 text-xs text-white">✓</span><span>{name} matched the suggestion.</span></p>;
    if (saved?.reason && !editing) return <div className="flex flex-wrap items-center justify-between gap-2 rounded-lg bg-slate-50 px-3 py-2.5 text-sm">
        <p className="min-w-0 text-slate-700">{name} · <span className="font-medium text-slate-900">{saved.reason_label || reasonLabel(saved.reason)}</span>{saved.note ? <span className="text-slate-500"> — “{saved.note}”</span> : null}<span className="block text-xs text-slate-500">{saved.by ? `Recorded by ${saved.by} · ` : ''}{relative(saved.at)}</span></p>
        <button type="button" className="min-h-9 px-2 text-sm font-medium text-teal-800 underline" onClick={() => setEditing(true)}>Change</button>
    </div>;
    const ready = choice.reason && (choice.reason !== 'other' || choice.note.trim().length >= 3);
    return <form className="space-y-2" onSubmit={e => { e.preventDefault(); if (ready) record.mutate({ client_id: outcome.id, reason: choice.reason, note: choice.note.trim() || undefined, source }, { onSuccess: () => setEditing(false) }); }}>
        <MatchReasonPicker id={`feedback-${receiptId}-${outcome.id}`} suggestion={suggestion} chosenName={outcome.name} value={choice.reason} note={choice.note} onChange={setChoice} required={!!suggestion} disabled={record.isPending} />
        <div className="flex flex-wrap items-center gap-3"><button type="submit" disabled={!ready || record.isPending} className="inline-flex min-h-9 items-center rounded-lg bg-teal-700 px-3 text-sm font-medium text-white shadow-sm hover:bg-teal-800 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-teal-600 focus-visible:ring-offset-1 disabled:cursor-not-allowed disabled:opacity-45">{record.isPending ? 'Saving…' : 'Save reason'}</button>
            {editing && <button type="button" className="min-h-9 px-2 text-sm text-slate-600 underline" onClick={() => setEditing(false)}>Cancel</button>}
            {record.isError && <p role="alert" className="text-sm text-rose-800">{record.error?.response?.data?.message || 'The reason was not saved. Try again.'}</p>}</div>
    </form>;
}
