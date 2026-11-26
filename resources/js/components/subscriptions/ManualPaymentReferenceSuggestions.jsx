import React, { useEffect, useId, useState } from 'react';
import { Link } from 'react-router-dom';
import { useInfiniteQuery } from '@tanstack/react-query';
import api from '../../services/api';

const unavailableText = {
    used: 'This code is already recorded. Review it in Incoming payments.',
    reversed: 'This payment was reversed or withheld. Ask Finance before using it.',
    owned: 'This payment already has an activation in progress or recorded.',
    historical: 'This is a historical receipt. Review it in Incoming payments.',
    conflicting: 'This payment needs review in Incoming payments.',
    incomplete: 'Payment usage could not be checked completely. Review it in Incoming payments.',
    amount_mismatch: 'The receipt amount differs from this subscription. Review the amount before proceeding.',
};

function paidTime(row) {
    const raw = row.paid_at || row.received_at;
    if (!raw) return 'Time not supplied';
    const date = new Date(raw);
    if (!Number.isFinite(date.getTime())) return 'Time not supplied';
    const options = { day: '2-digit', month: 'short', year: 'numeric', hour: '2-digit', minute: '2-digit', hour12: false, timeZoneName: 'short' };
    let formatted;
    try { formatted = new Intl.DateTimeFormat('en-GB', { ...options, timeZone: row.timezone || 'UTC' }).format(date); }
    catch { formatted = new Intl.DateTimeFormat('en-GB', { ...options, timeZone: 'UTC' }).format(date); }
    const minutes = Math.max(0, Math.floor((Date.now() - date.getTime()) / 60000));
    const relative = minutes < 1 ? 'just now' : minutes < 60 ? `${minutes} min ago` : minutes < 1440 ? `${Math.floor(minutes / 60)} h ago` : `${Math.floor(minutes / 1440)} d ago`;
    return `${row.paid_at ? 'Paid' : 'Received'} ${formatted} · ${relative}`;
}

export default function ManualPaymentReferenceSuggestions({ clientId, dealId, amountMinor, value, onChange, inputId, error = '', refreshKey = 0 }) {
    const [dismissed, setDismissed] = useState(false);
    const [selected, setSelected] = useState(null);
    const [editing, setEditing] = useState(false);
    const [searchText, setSearchText] = useState('');
    const [search, setSearch] = useState('');
    const [lookupOpen, setLookupOpen] = useState(false);
    const [notice, setNotice] = useState('');
    const lookupId = useId();
    const query = useInfiniteQuery({
        queryKey: ['client-reference-suggestions', clientId, dealId, amountMinor, search, refreshKey],
        queryFn: ({ pageParam, signal }) => api.get(`/crm/collections/clients/${clientId}/reference-suggestions`, {
            signal, params: { deal_id: dealId, ...(amountMinor > 0 ? { amount_minor: amountMinor } : {}), ...(search ? { search } : {}), ...(pageParam ? { cursor: pageParam } : {}) },
        }).then(response => response.data),
        initialPageParam: null,
        getNextPageParam: page => page.next_cursor || undefined,
        enabled: Boolean(clientId && dealId && !dismissed),
        staleTime: 0,
        retry: false,
    });
    const pages = query.data?.pages || [];
    const rows = [...new Map(pages.flatMap(page => page.items || []).map(row => [row.receipt_id, row])).values()];
    const partial = pages.some(page => page.coverage === 'partial');
    const last = pages.at(-1);
    const selectedMatches = selected && value === selected.row.reference;
    const quoteChanged = selectedMatches && selected.amountMinor !== amountMinor;
    useEffect(() => {
        if (selected && value !== selected.row.reference) setSelected(null);
    }, [value, selected]);
    // Continue bounded scan pages until something can be shown; never confuse an empty page with no match.
    useEffect(() => {
        if (!dismissed && !search && rows.length < 3 && query.hasNextPage && !query.isFetching && !query.isError) query.fetchNextPage();
    }, [dismissed, search, rows.length, query.hasNextPage, query.isFetching, query.isError, query.fetchNextPage]);

    const focusInput = () => document.getElementById(inputId)?.focus();
    const dismiss = () => { setDismissed(true); setNotice('Payment suggestions dismissed. You can enter a code.'); focusInput(); };
    const useCode = row => {
        onChange(row.reference);
        setSelected({ row, amountMinor });
        setEditing(false);
        setNotice(`Code ${row.reference} inserted. Confirm the customer, then submit the form.`);
        focusInput();
    };
    if (pages[0]?.enabled === false) return null;
    const buttonClass = 'min-h-11 rounded-md px-3 py-2 text-sm font-medium text-teal-800 hover:bg-teal-50 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-teal-700';
    return <div id={`${inputId}-feedback`} className="mt-2 space-y-2" data-testid="payment-reference-suggestions">
        <p className="sr-only" role="status" aria-live="polite">{notice}</p>
        {error && <p role="alert" className="text-sm text-red-700">{error}</p>}
        {dismissed ? <button type="button" className={buttonClass} onClick={() => { setDismissed(false); setEditing(true); }}>Show incoming payments</button>
            : selectedMatches && !editing && !error ? <div className="flex flex-wrap items-center justify-between gap-2 rounded-md bg-teal-50 px-3 py-1 text-xs text-slate-700">
                <span>From {selected.row.sender_name || 'sender not supplied'} · {selected.row.currency} {(selected.row.principal_minor / 100).toLocaleString()}<span className="block text-slate-500">{paidTime(selected.row)}</span>{quoteChanged && <span className="block font-medium text-amber-800">Subscription amount changed. Review this code.</span>}</span>
                <button type="button" className={buttonClass} onClick={() => setEditing(true)}>Change</button>
            </div> : <section aria-label="Incoming payment suggestions" className="rounded-lg border border-slate-200 bg-white">
                <div className="flex items-center justify-between gap-2 border-b border-slate-100 px-3">
                    <h4 className="text-xs font-semibold text-slate-700">Possible incoming payments</h4>
                    <button type="button" aria-label="Dismiss payment suggestions" className={`${buttonClass} min-w-11 text-xl text-slate-500`} onClick={dismiss}>×</button>
                </div>
                <div className="space-y-3 p-3">
                    {query.isPending && <p role="status" className="text-xs text-slate-500">Checking incoming payments…</p>}
                    {query.isError && <div role="alert" className="text-sm text-slate-600"><p>Couldn’t load payment suggestions. You can still type or paste a code.</p><button type="button" className={buttonClass} onClick={() => query.refetch()}>Retry</button></div>}
                    {partial && <p className="text-xs text-amber-800">Some payer history is incomplete. Other profiles may also be possible; confirm with the customer.</p>}
                    {rows.map(row => <article key={row.receipt_id} className="border-l-2 border-teal-600 pl-3">
                        <div className="flex flex-wrap items-start justify-between gap-2">
                            <div><p className="break-all font-mono text-base font-semibold tracking-wide text-slate-900">{row.reference}</p><p className="mt-1 text-sm text-slate-700">{row.sender_name || (['incomplete', 'conflicting'].includes(row.sender_state) ? 'Sender details need review' : 'Name not supplied')} · <strong>{row.currency} {(row.principal_minor / 100).toLocaleString(undefined, { maximumFractionDigits: 2 })}</strong></p></div>
                            {row.use_code_available && <button type="button" className="min-h-11 shrink-0 rounded-md bg-teal-700 px-4 py-2 text-sm font-semibold text-white hover:bg-teal-800 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-teal-700" onClick={() => useCode(row)}>{value.trim() && value !== row.reference ? 'Replace code' : 'Use code'}</button>}
                        </div>
                        <p className="mt-1 text-xs leading-relaxed text-slate-500">{paidTime(row)}</p>
                        <p className="mt-1 text-xs text-slate-500">{row.account_label}</p>
                        {row.unavailable_reason && <p className="mt-2 text-xs text-amber-800">{unavailableText[row.unavailable_reason]}</p>}
                        {row.principal_minor > amountMinor && !row.use_code_available && <Link className={`${buttonClass} inline-flex items-center`} to={`/payments?workspace=collections&receipt=${row.receipt_id}`} target="_blank" rel="noopener noreferrer">Review shared payment ↗<span className="sr-only"> (opens in a new tab)</span></Link>}
                        {row.possible_profiles?.length > 0 ? <details className="mt-2 text-xs" open={row.possible_profiles.length > 1 || undefined}>
                            <summary className="min-h-11 cursor-pointer py-3 font-medium text-slate-700 focus-visible:outline focus-visible:outline-2 focus-visible:outline-teal-700">{row.possible_profiles.length} possible {row.possible_profiles.length === 1 ? 'profile' : 'profiles'} · confirm with the customer</summary>
                            <ul className="space-y-2 pb-2">{row.possible_profiles.map(profile => <li key={profile.id}>
                                <div className="flex flex-wrap items-center gap-2"><Link to={`/clients/${profile.id}`} target="_blank" rel="noopener noreferrer" className="inline-flex min-h-11 items-center gap-1 font-medium text-teal-800 underline underline-offset-2 focus-visible:outline focus-visible:outline-2 focus-visible:outline-teal-700">{profile.name}<span aria-hidden="true"> ↗</span><span className="sr-only"> (opens in a new tab)</span></Link>{profile.current_profile ? <span className="rounded bg-slate-100 px-2 py-1 text-slate-600">This profile</span> : profile.role === 'alternative' ? <span className="text-slate-500">Another possibility</span> : null}</div>
                                {profile.reasons?.map((reason, i) => <p key={i} className="text-xs leading-relaxed text-slate-500">{reason}</p>)}
                            </li>)}</ul>
                        </details> : <p className="mt-2 text-xs text-amber-800">No supported profile match. Confirm which customer this payment is for.</p>}
                    </article>)}
                    {!query.isPending && !query.isError && !rows.length && last?.scan_complete && !search && <p className="text-xs text-slate-500">No recent payment suggestion for this profile. Type a code or find an incoming payment.</p>}
                    {search && last?.lookup?.state === 'not_found' && <p className="text-xs text-slate-600">No incoming payment found with that code in this market. You can still enter it manually.</p>}
                    {search && last?.lookup?.state === 'unavailable' && !rows.length && <p className="text-xs text-amber-800">{unavailableText[last.lookup.reason] || 'This payment needs review.'}</p>}
                    {query.isFetchingNextPage && <p role="status" className="text-xs text-slate-500">Checking more incoming payments…</p>}
                    {query.hasNextPage && !query.isFetching && <button type="button" className={buttonClass} onClick={() => query.fetchNextPage()}>Show more payments</button>}
                    <div className="border-t border-slate-100 pt-1">
                        <button type="button" className={buttonClass} onClick={() => setLookupOpen(open => !open)} aria-expanded={lookupOpen}>Find another payment</button>
                        {lookupOpen && <div className="space-y-2 pb-2"><label htmlFor={lookupId} className="block text-xs font-medium text-slate-700">Find by exact transaction code</label><div className="flex gap-2"><input id={lookupId} value={searchText} onChange={event => setSearchText(event.target.value)} onKeyDown={event => { if (event.key === 'Enter') { event.preventDefault(); setSearch(searchText.trim()); } }} className="crm-input min-w-0 flex-1" maxLength={128} /><button type="button" className={buttonClass} onClick={() => setSearch(searchText.trim())}>Find code</button></div>{search && <button type="button" className={buttonClass} onClick={() => { setSearch(''); setSearchText(''); }}>Back to suggestions</button>}</div>}
                    </div>
                </div>
            </section>}
    </div>;
}
