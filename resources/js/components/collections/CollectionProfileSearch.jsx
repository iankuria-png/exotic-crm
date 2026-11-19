import React, { useEffect, useRef, useState } from 'react';
import { useQuery } from '@tanstack/react-query';
import { Link } from 'react-router-dom';
import api from '../../services/api';
import { useAuth } from '../../hooks/useAuth';
import { Icon } from './collectionUi';
import { receiptTime } from './CollectionReceiptEvidence';

const MATCHED_BY = { profile_link: 'Profile link', similar_link: 'Similar to link', profile_id: 'Profile ID', phone: 'Phone', payer: 'Paid from this number before',
    email: 'Email', name_exact: 'Exact name', name: 'Name', other: 'Match' };
const isLink = value => /^https?:\/\//i.test(value.trim());

/** What a pasted profile link resolved to, in the words the Clients page uses. */
function LinkResult({ resolution }) {
    if (!resolution) return null;
    const post = resolution.resolved_wp_post_id ? `profile ${resolution.resolved_wp_post_id}` : 'one profile';
    const notes = {
        exact: ['live', 'Exact profile link match', `Matched the pasted link directly to ${post}.`],
        conflict: ['action', 'CRM profile found, but the site resolves this link differently', 'Check the profile link before activating.'],
        exact_missing: ['action', 'Link found, but the profile is not in the CRM yet', `The site resolves it to ${post}. Sync the market from Clients, then search again.`],
        not_selectable: ['action', 'This profile cannot take this payment', 'It is in another market, paused, closed or marked high risk. Check the profile on the Clients page.'],
        fallback: ['info', 'No exact link match', 'Showing profiles similar to the link instead.'],
        unresolved: ['action', 'This link is not from this payment’s market', 'Paste a profile link from the market that received this payment.'],
    };
    const [tone, title, body] = notes[resolution.mode] || notes.unresolved;
    const styles = { live: 'bg-teal-50 text-teal-950 ring-teal-200', action: 'bg-amber-50 text-amber-950 ring-amber-200', info: 'bg-sky-50 text-sky-950 ring-sky-200' };
    return <div role="status" className={`rounded-lg px-3 py-2.5 text-sm ring-1 ring-inset ${styles[tone]}`}><p className="font-semibold">{title}</p><p className="mt-0.5 text-xs leading-5">{body}</p></div>;
}

/**
 * Profile search used wherever staff pick a profile for a payment. Same behaviour as the Clients page search:
 * paste a profile link, or type a name, phone (any format), email or profile ID.
 */
export default function CollectionProfileSearch({ receiptId, platformId = null, suggestions = [], onChoose, disabled = false, autoFocus = false, label = 'Find advertiser profile', hint = null, excludeId = null }) {
    const { user } = useAuth();
    const [value, setValue] = useState('');
    const [term, setTerm] = useState('');
    const input = useRef(null);
    useEffect(() => { if (isLink(value)) { setTerm(value.trim()); return undefined; } const timer = setTimeout(() => setTerm(value.trim()), 250); return () => clearTimeout(timer); }, [value]);
    useEffect(() => { if (autoFocus) input.current?.focus(); }, [autoFocus]);
    const results = useQuery({ queryKey: ['collection-profile-search', user?.id, receiptId, term], enabled: term.length >= 2,
        queryFn: async () => (await api.get(`/crm/collections/receipts/${receiptId}/candidates`, { params: { search: term, workflow: 'manual' } })).data, retry: false, staleTime: 30000 });
    const searching = term.length >= 2;
    const rows = (searching ? results.data?.clients || [] : suggestions)
        .filter(c => (!platformId || Number(c.platform_id) === Number(platformId)) && Number(c.id) !== Number(excludeId));
    const choose = client => { onChoose(client); setValue(''); setTerm(''); };
    const id = `profile-search-${receiptId}`;
    return <div className="space-y-2">
        <label htmlFor={id} className="block text-sm font-medium text-slate-800">{label}</label>
        <div className="relative">
            <Icon name="search" className="pointer-events-none absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-slate-400" />
            <input id={id} ref={input} type="text" inputMode="search" className="crm-input w-full !pl-9 !pr-9" value={value} disabled={disabled} autoComplete="off" spellCheck={false}
                onChange={e => setValue(e.target.value)} onKeyDown={e => { if (e.key === 'Enter') { e.preventDefault(); if (rows.length === 1) choose(rows[0]); } if (e.key === 'Escape' && value) { e.stopPropagation(); setValue(''); } }}
                placeholder="Paste a profile link, or type a name, phone, email or profile ID" aria-describedby={`${id}-help`} />
            {results.isFetching && searching && <span aria-hidden="true" className="absolute right-3 top-1/2 h-4 w-4 -translate-y-1/2 rounded-full border-2 border-slate-300 border-t-teal-600 motion-safe:animate-spin" />}
        </div>
        <p id={`${id}-help`} className="text-xs text-slate-500">{hint || 'Works like the Clients search: links from the site, local or international phone numbers, profile IDs, names and emails.'}</p>
        {searching && results.isSuccess && <LinkResult resolution={results.data.resolution} />}
        {searching && results.isError && <p role="alert" className="text-sm text-rose-800">Search failed. <button type="button" className="underline" onClick={() => results.refetch()}>Try again</button></p>}
        {!!rows.length && <ul aria-label={searching ? 'Matching profiles' : 'Suggested profiles'} className="divide-y divide-slate-100 overflow-hidden rounded-lg border border-slate-200">{rows.map(client => <li key={client.id} className="flex items-center gap-3 bg-white px-3 py-2.5 hover:bg-teal-50/50">
            <button type="button" disabled={disabled} className="min-w-0 flex-1 text-left focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-teal-600" onClick={() => choose(client)}
                aria-label={`Choose ${client.name} · Profile ${client.wp_post_id}${client.matched_by ? ` · matched by ${MATCHED_BY[client.matched_by] || 'search'}` : ''}`}>
                <span className="flex flex-wrap items-center gap-x-2 gap-y-1"><strong className="text-sm text-slate-900">{client.name}</strong>
                    {client.matched_by && <span className="rounded-full bg-teal-50 px-2 py-0.5 text-[11px] font-semibold text-teal-900 ring-1 ring-inset ring-teal-600/20">{MATCHED_BY[client.matched_by] || 'Match'}</span>}</span>
                <span className="mt-0.5 block text-xs text-slate-500">{[`Profile ${client.wp_post_id}`, client.city, client.phone, client.profile_status && client.profile_status !== 'publish' ? client.profile_status : null].filter(Boolean).join(' · ')}</span>
                <span className="mt-0.5 block text-xs text-slate-600">{client.status?.subscription ? <><span className="font-medium text-teal-800">{client.status.subscription.plan_type?.toUpperCase()} active</span> · until {receiptTime(client.status.subscription.expires_at)}</> : client.reasons?.[0] || 'No active subscription in CRM'}</span>
            </button>
            <Link to={`/clients/${client.id}`} target="_blank" rel="noopener noreferrer" aria-label={`Open ${client.name} profile in a new tab`} className="shrink-0 py-2 text-sm font-medium text-teal-800 underline">Open</Link>
        </li>)}</ul>}
        {searching && results.isSuccess && !rows.length && !results.data.resolution && <p className="rounded-lg border border-dashed border-slate-300 px-3 py-4 text-center text-sm text-slate-600">No matching profile in this market. Try the full phone number, the profile ID or paste the profile link.</p>}
    </div>;
}
