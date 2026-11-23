import React, { useEffect, useMemo, useRef, useState } from 'react';
import { useQuery } from '@tanstack/react-query';
import { Link } from 'react-router-dom';
import api from '../../services/api';
import { Spinner, errorMessage } from './scraperUi';

const LIMIT = 100;
const CONFIDENCE = {
    exact: ['Exact', 'bg-emerald-50 text-emerald-800'],
    close: ['Matched', 'bg-emerald-50 text-emerald-800'],
    guess: ['Close spelling', 'bg-amber-50 text-amber-800'],
    region: ['Region · thinnest city', 'bg-sky-50 text-sky-800'],
    none: ['No match', 'bg-rose-50 text-rose-700'],
    manual: ['Chosen', 'bg-slate-100 text-slate-700'],
};
const optionKey = (o) => (o ? `${o.region_id}:${o.city_id ?? ''}` : '');
const THIN = 5;

/**
 * After a scrape: turn imported leads into private client profiles placed in a real
 * WordPress city. Step 1 places each scraped place (cleaned + loosely matched, thinnest
 * cities first), step 2 runs the server review, step 3 creates profiles one by one.
 */
export default function ConvertPlaceDialog({ platformId, platformName, leads, onClose, onFinished, toast }) {
    const usable = useMemo(() => leads.filter((l) => l.lead_id).slice(0, LIMIT), [leads]);
    const [step, setStep] = useState('place');
    const [choice, setChoice] = useState({}); // clean place → option key | 'skip'
    const [review, setReview] = useState(null);
    const [reviewError, setReviewError] = useState(null);
    const [reviewing, setReviewing] = useState(false);
    const [progress, setProgress] = useState({ running: false, done: 0, results: {} });
    const stopRef = useRef(false);

    const places = useMemo(() => [...new Set(usable.map((l) => l.city || ''))], [usable]);
    const match = useQuery({
        queryKey: ['scraper-placements', platformId, places.join('|')],
        queryFn: () => api.post('/crm/scraper/placements', { platform_id: Number(platformId), places }).then((r) => r.data),
        staleTime: 60_000,
        retry: 1,
    });
    const options = match.data?.options || [];
    const byKey = useMemo(() => Object.fromEntries(options.map((o) => [optionKey(o), o])), [options]);
    const matchByPlace = useMemo(() => Object.fromEntries((match.data?.matches || []).map((m) => [m.place, m])), [match.data]);

    // Group by suggested destination city (unmatched places stay separate), so one choice moves them all.
    const groups = useMemo(() => {
        const g = {};
        usable.forEach((l) => {
            const m = matchByPlace[l.city || ''] || { clean: l.city || '', match: null, confidence: 'none', alternatives: [] };
            const key = m.match ? `to:${optionKey(m.match)}` : `raw:${m.clean || '(no place listed)'}`;
            const group = (g[key] ||= { key, match: m, names: new Set(), leads: [], confidences: new Set() });
            group.leads.push(l);
            group.names.add(m.clean || '(no place listed)');
            group.confidences.add(m.confidence);
        });
        return Object.values(g).map((x) => ({
            ...x,
            title: x.match.match ? (x.match.match.city || x.match.match.region) : [...x.names][0],
            // Weakest confidence in the group decides the badge, so guesses stay visible.
            confidence: ['none', 'guess', 'region', 'close', 'exact'].find((c) => x.confidences.has(c)) || 'none',
        })).sort((a, b) => b.leads.length - a.leads.length);
    }, [usable, matchByPlace]);

    useEffect(() => {
        if (!match.data) return;
        setChoice((prev) => {
            const next = { ...prev };
            groups.forEach((g) => { if (!(g.key in next)) next[g.key] = g.match.match ? optionKey(g.match.match) : ''; });
            return next;
        });
    }, [match.data, groups]);

    useEffect(() => {
        const onKey = (e) => { if (e.key === 'Escape' && !progress.running) onClose(); };
        window.addEventListener('keydown', onKey);
        return () => window.removeEventListener('keydown', onKey);
    }, [onClose, progress.running]);

    const placementFor = (lead) => {
        const g = groups.find((x) => x.leads.includes(lead));
        const key = g ? choice[g.key] : '';
        return key && key !== 'skip' ? byKey[key] : null;
    };
    const included = usable.filter((l) => {
        const g = groups.find((x) => x.leads.includes(l));
        return g && choice[g.key] !== 'skip';
    });
    const unplaced = groups.filter((g) => !choice[g.key]).reduce((n, g) => n + g.leads.length, 0);
    const cityTotals = useMemo(() => {
        const t = {};
        included.forEach((l) => { const o = placementFor(l); if (o) t[o.label] = { o, n: (t[o.label]?.n || 0) + 1 }; });
        return Object.values(t).sort((a, b) => a.o.our_available - b.o.our_available);
    }, [included, choice]); // eslint-disable-line react-hooks/exhaustive-deps

    const runReview = async () => {
        setReviewing(true);
        setReviewError(null);
        try {
            const placements = {};
            included.forEach((l) => { const o = placementFor(l); if (o) placements[l.lead_id] = { region_id: o.region_id, city_id: o.city_id }; });
            const { data } = await api.post('/crm/leads/bulk-convert/preview', { lead_ids: included.map((l) => l.lead_id), placements });
            setReview(data);
            setStep('review');
        } catch (e) {
            setReviewError(errorMessage(e, 'Review failed.'));
        } finally {
            setReviewing(false);
        }
    };

    const ready = (review?.rows || []).filter((r) => r.action === 'create');
    const create = async (rows) => {
        stopRef.current = false;
        setStep('create');
        setProgress((p) => ({ ...p, running: true }));
        for (const row of rows) {
            if (stopRef.current) break;
            let res;
            try {
                const { data } = await api.post(`/crm/leads/${row.id}/bulk-convert`, { review_token: row.review_token, reason: 'Lead Scraper: convert & place in thin locations' });
                res = data;
            } catch (e) {
                res = { status: e?.response?.data?.status || 'failed', message: errorMessage(e, 'Could not convert.') };
            }
            setProgress((p) => ({ ...p, done: p.done + 1, results: { ...p.results, [row.id]: res } }));
        }
        setProgress((p) => ({ ...p, running: false }));
    };

    const results = Object.values(progress.results);
    const createdCount = results.filter((r) => r.status === 'created').length;
    useEffect(() => {
        if (step === 'create' && !progress.running && results.length) {
            onFinished?.(createdCount);
            if (createdCount) toast.success(`${createdCount} client${createdCount === 1 ? '' : 's'} created and placed.`);
        }
    }, [progress.running]); // eslint-disable-line react-hooks/exhaustive-deps

    return (
        <div className="fixed inset-0 z-50 flex items-end justify-center bg-slate-900/45 sm:items-center sm:p-4" onMouseDown={() => !progress.running && onClose()}>
            <div role="dialog" aria-modal="true" aria-labelledby="convert-title" onMouseDown={(e) => e.stopPropagation()} className="flex max-h-[94vh] w-full max-w-3xl flex-col overflow-hidden rounded-t-2xl bg-white shadow-2xl sm:rounded-xl">
                <header className="crm-panel-header">
                    <div>
                        <h3 id="convert-title" className="crm-panel-title">Convert to clients in {platformName}</h3>
                        <p className="crm-panel-subtitle">New profiles stay private and keep the lead’s owner. Each one is placed in a WordPress city.</p>
                    </div>
                    <button type="button" className="crm-btn-secondary px-3 py-1.5" onClick={onClose} disabled={progress.running}>Close</button>
                </header>

                <ol className="flex gap-1 border-b border-slate-100 px-4 py-2 text-xs font-semibold" aria-label="Steps">
                    {[['place', '1 Place'], ['review', '2 Review'], ['create', '3 Create']].map(([key, label]) => (
                        <li key={key} aria-current={step === key ? 'step' : undefined} className={`rounded-full px-2.5 py-1 ${step === key ? 'bg-teal-700 text-white' : 'text-slate-500'}`}>{label}</li>
                    ))}
                    {leads.length > LIMIT ? <li className="ml-auto self-center font-normal text-amber-700">First {LIMIT} of {leads.length} leads</li> : null}
                </ol>

                <div className="flex-1 overflow-y-auto px-4 py-4">
                    {step === 'place' ? (
                        match.isLoading ? (
                            <p className="flex items-center gap-2 py-10 text-sm text-slate-600"><Spinner />Matching scraped places to {platformName} cities…</p>
                        ) : match.isError ? (
                            <p className="rounded-lg border border-rose-200 bg-rose-50 p-3 text-sm text-rose-800">{errorMessage(match.error, 'Could not load WordPress cities.')} <button type="button" className="font-semibold underline" onClick={() => match.refetch()}>Retry</button></p>
                        ) : (
                            <div className="space-y-4">
                                {cityTotals.length ? (
                                    <div className="flex flex-wrap gap-1.5" aria-label="Where profiles will go">
                                        {cityTotals.map(({ o, n }) => (
                                            <span key={o.label} className={`inline-flex items-center gap-1 rounded-full border px-2.5 py-1 text-xs font-semibold ${o.our_available <= THIN ? 'border-amber-300 bg-amber-50 text-amber-900' : 'border-slate-200 bg-white text-slate-700'}`}>
                                                📍 {o.city || o.region} <span className="font-normal">+{n} · now {o.our_available}</span>
                                            </span>
                                        ))}
                                    </div>
                                ) : null}
                                <ul className="divide-y divide-slate-100 rounded-lg border border-slate-200">
                                    {groups.map((g) => {
                                        const [label, tone] = CONFIDENCE[choice[g.key] && choice[g.key] !== optionKey(g.match.match) ? 'manual' : g.confidence] || CONFIDENCE.none;
                                        const names = [...g.names];
                                        const value = choice[g.key] ?? '';
                                        return (
                                            <li key={g.key} className="grid gap-2 px-3 py-3 sm:grid-cols-[minmax(0,1fr)_16rem] sm:items-center">
                                                <div className="min-w-0">
                                                    <p className="truncate text-sm font-semibold text-slate-900">{g.match.match ? `📍 ${g.title}` : g.title}
                                                        <span className="ml-2 font-normal text-slate-500">{g.leads.length} lead{g.leads.length === 1 ? '' : 's'}</span></p>
                                                    <details className="text-xs text-slate-500">
                                                        <summary className="cursor-pointer truncate">{names.length === 1 ? `Listed as “${names[0]}”` : `From ${names.length} listed place names`} · {g.leads.slice(0, 3).map((l) => l.name).join(', ')}{g.leads.length > 3 ? '…' : ''}</summary>
                                                        <p className="mt-1 text-slate-600">{names.slice(0, 20).join(' · ')}{names.length > 20 ? ` · +${names.length - 20} more` : ''}</p>
                                                    </details>
                                                </div>
                                                <div className="space-y-1">
                                                    <select className="crm-select-enhanced" value={value} onChange={(e) => setChoice((c) => ({ ...c, [g.key]: e.target.value }))} aria-label={`City for ${g.title}`}>
                                                        <option value="">Choose a city…</option>
                                                        <option value="skip">Don’t convert these</option>
                                                        {g.match.match ? <option value={optionKey(g.match.match)}>★ {g.match.match.label} ({g.match.match.our_available} of ours)</option> : null}
                                                        {(g.match.alternatives || []).map((o) => <option key={optionKey(o)} value={optionKey(o)}>☆ {o.label} ({o.our_available} of ours)</option>)}
                                                        <optgroup label="All cities · thinnest first">
                                                            {options.map((o) => <option key={optionKey(o)} value={optionKey(o)}>{o.label} ({o.our_available} of ours)</option>)}
                                                        </optgroup>
                                                    </select>
                                                    <span className={`inline-block rounded px-1.5 py-0.5 text-[11px] font-semibold ${value === 'skip' ? 'bg-slate-100 text-slate-500' : tone}`}>{value === 'skip' ? 'Skipped' : label}</span>
                                                </div>
                                            </li>
                                        );
                                    })}
                                </ul>
                            </div>
                        )
                    ) : null}

                    {step === 'review' && review ? (
                        <div className="space-y-3">
                            <dl className="grid grid-cols-3 gap-3">
                                <Stat label="Ready to create" value={ready.length} tone="text-teal-800" />
                                <Stat label="Already clients" value={(review.rows || []).filter((r) => ['existing_match', 'already_converted'].includes(r.action)).length} />
                                <Stat label="Need attention" value={(review.rows || []).filter((r) => ['needs_attention', 'skipped'].includes(r.action)).length} tone="text-amber-700" />
                            </dl>
                            <ul className="max-h-[22rem] divide-y divide-slate-100 overflow-y-auto rounded-lg border border-slate-200 text-sm">
                                {review.rows.map((r) => (
                                    <li key={r.id} className="flex items-center justify-between gap-3 px-3 py-2">
                                        <span className="min-w-0 truncate"><span className="font-medium text-slate-900">{r.name}</span> <span className="crm-mono text-xs text-slate-500">{r.contact}</span></span>
                                        <span className="shrink-0 text-xs">{r.place ? <span className="mr-2 text-slate-600">📍 {r.place}</span> : null}<span className={r.action === 'create' ? 'font-semibold text-teal-800' : 'text-slate-500'}>{r.message}</span></span>
                                    </li>
                                ))}
                            </ul>
                            <p className="text-xs text-slate-500">This review is valid until {new Date(review.expires_at).toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' })}. Existing matches are not changed; reconcile them from Leads.</p>
                        </div>
                    ) : null}

                    {step === 'create' ? (
                        <div className="space-y-3" aria-live="polite">
                            <div className="h-2 overflow-hidden rounded-full bg-slate-100" role="progressbar" aria-valuenow={progress.done} aria-valuemin={0} aria-valuemax={ready.length}>
                                <div className="h-full rounded-full bg-teal-600 transition-[width] duration-300 motion-reduce:transition-none" style={{ width: `${ready.length ? Math.round((progress.done / ready.length) * 100) : 0}%` }} />
                            </div>
                            <p className="flex items-center gap-2 text-sm text-slate-700">
                                {progress.running ? <Spinner /> : null}
                                {progress.running ? `Creating ${progress.done + 1} of ${ready.length}…` : `${createdCount} created · ${results.length - createdCount} not created`}
                            </p>
                            <ul className="max-h-[22rem] divide-y divide-slate-100 overflow-y-auto rounded-lg border border-slate-200 text-sm">
                                {ready.map((r) => {
                                    const res = progress.results[r.id];
                                    return (
                                        <li key={r.id} className="flex items-center justify-between gap-3 px-3 py-2">
                                            <span className="min-w-0 truncate font-medium text-slate-900">{r.name}</span>
                                            <span className="shrink-0 text-xs">
                                                {!res ? <span className="text-slate-400">Waiting</span>
                                                    : res.status === 'created' ? <Link className="font-semibold text-teal-700 hover:underline" to={`/clients/${res.client_id}`}>Created{res.city ? ` in ${res.city}` : ''} →</Link>
                                                        : <span className="text-rose-700">{res.message}</span>}
                                            </span>
                                        </li>
                                    );
                                })}
                            </ul>
                        </div>
                    ) : null}
                </div>

                <footer className="flex flex-wrap items-center justify-between gap-2 border-t border-slate-200 px-4 py-3">
                    <p className="text-xs text-slate-500">
                        {step === 'place' ? `${included.length} lead${included.length === 1 ? '' : 's'} to convert${unplaced ? ` · ${unplaced} still need a city` : ''}` : step === 'review' ? `${ready.length} profile${ready.length === 1 ? '' : 's'} will be created` : ''}
                        {reviewError ? <span className="ml-2 text-rose-700">{reviewError}</span> : null}
                    </p>
                    <div className="flex gap-2">
                        {step === 'place' ? (
                            <>
                                <button type="button" className="crm-btn-secondary" onClick={onClose}>Not now</button>
                                <button type="button" className="crm-btn-primary disabled:cursor-not-allowed disabled:opacity-50" disabled={!included.length || unplaced > 0 || reviewing || match.isLoading} onClick={runReview}>
                                    {reviewing ? <><Spinner className="mr-2 h-4 w-4" />Reviewing…</> : 'Review conversion'}
                                </button>
                            </>
                        ) : step === 'review' ? (
                            <>
                                <button type="button" className="crm-btn-secondary" onClick={() => setStep('place')}>Back</button>
                                <button type="button" className="crm-btn-primary disabled:cursor-not-allowed disabled:opacity-50" disabled={!ready.length} onClick={() => create(ready)}>Create {ready.length} private profile{ready.length === 1 ? '' : 's'}</button>
                            </>
                        ) : progress.running ? (
                            <button type="button" className="crm-btn-danger" onClick={() => { stopRef.current = true; }}>Stop after current</button>
                        ) : (
                            <button type="button" className="crm-btn-primary" onClick={onClose}>Done</button>
                        )}
                    </div>
                </footer>
            </div>
        </div>
    );
}

function Stat({ label, value, tone }) {
    return (
        <div className="rounded-lg border border-slate-200 px-3 py-2">
            <dt className="text-[11px] font-semibold uppercase tracking-[0.08em] text-slate-500">{label}</dt>
            <dd className={`crm-mono text-xl font-semibold tabular-nums ${tone || 'text-slate-900'}`}>{value}</dd>
        </div>
    );
}
