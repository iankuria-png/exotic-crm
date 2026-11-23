import React, { useEffect, useMemo, useState } from 'react';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import api from '../../services/api';
import { Spinner, errorMessage, timeAgo, useScrollLock } from './scraperUi';
import ConvertPlaceDialog from './ConvertPlaceDialog';

const FILTERS = [
    ['gaps', 'Gaps', 'We have 5 or fewer and competitors have profiles'],
    ['none', 'None of ours', 'We have no available profiles there'],
    ['all', 'All places', ''],
];

/**
 * Customer-service workflow: find places where we have the fewest available profiles
 * but competitors list many, then scrape exactly those places from the market's sites.
 */
export default function LocationGapsPanel({ platformId, platforms, onPickMarket, onOpenRun, toast }) {
    const qc = useQueryClient();
    const [filter, setFilter] = useState('gaps');
    const [threshold, setThreshold] = useState(5);
    const [search, setSearch] = useState('');
    const [selected, setSelected] = useState(() => new Set());
    const [dialog, setDialog] = useState(false);
    const [result, setResult] = useState(null);

    useEffect(() => { setSelected(new Set()); setResult(null); }, [platformId]);

    const supply = useQuery({
        queryKey: ['scraper-location-supply', platformId],
        enabled: !!platformId,
        queryFn: () => api.get('/crm/scraper/location-supply', { params: { platform_id: platformId } }).then((r) => r.data),
    });
    const data = supply.data;
    const sites = data?.sites || [];
    const siteById = useMemo(() => Object.fromEntries(sites.map((s) => [s.id, s])), [sites]);

    const rows = useMemo(() => {
        const q = search.trim().toLowerCase();
        return (data?.rows || []).filter((r) => {
            if (q && !r.place.toLowerCase().includes(q)) return false;
            if (filter === 'gaps') return r.ours.available <= threshold && r.competitors.total > 0;
            if (filter === 'none') return r.ours.available === 0;
            return true;
        });
    }, [data, filter, threshold, search]);

    const maxCompetitors = Math.max(1, ...rows.map((r) => r.competitors.total));
    const selectedRows = (data?.rows || []).filter((r) => selected.has(r.place));
    const toggle = (place) => setSelected((prev) => { const next = new Set(prev); next.has(place) ? next.delete(place) : next.add(place); return next; });

    if (!platformId) {
        return (
            <div className="crm-surface px-6 py-14 text-center">
                <p className="text-base font-semibold text-slate-900">Choose a market</p>
                <p className="mx-auto mt-1 max-w-md text-sm text-slate-500">Location gaps compare our available profiles per place with what competitor sites list in the same market.</p>
                <div className="mt-4 flex flex-wrap justify-center gap-2">
                    {(platforms || []).map((p) => <button key={p.id} type="button" className="crm-btn-secondary px-3 py-1.5" onClick={() => onPickMarket(String(p.id))}>{p.name}</button>)}
                </div>
            </div>
        );
    }

    if (supply.isLoading) {
        return <div className="crm-surface space-y-3 p-5">{[0, 1, 2, 3].map((i) => <div key={i} className="h-12 animate-pulse rounded-lg bg-slate-100 motion-reduce:animate-none" />)}</div>;
    }
    if (supply.isError) {
        return <div className="crm-surface p-6 text-sm text-rose-800">Could not load location gaps. <button type="button" className="font-semibold underline" onClick={() => supply.refetch()}>Retry</button></div>;
    }

    const t = data.totals;
    const noCompetitorData = sites.every((s) => s.basis === 'none');

    return (
        <div className="space-y-4">
            {result ? <BatchResult result={result} platform={data.platform} onOpenRun={onOpenRun} onClose={() => setResult(null)} toast={toast} onConverted={() => qc.invalidateQueries({ queryKey: ['scraper-location-supply', platformId] })} /> : null}

            <section className="grid gap-3 sm:grid-cols-2 xl:grid-cols-4" aria-label="Summary">
                <Tile label="Places with our profiles" value={t.our_places} hint={`${t.our_available} available profiles`} />
                <Tile label="Thin places (≤5 of ours)" value={t.low_places} tone="amber" />
                <Tile label="Only competitors there" value={t.competitor_only_places} tone="rose" />
                <Tile label="Competitor profiles seen" value={t.competitor_profiles_seen} hint={`${sites.length} sites in ${data.platform.name}`} />
            </section>

            <section className="crm-surface overflow-hidden">
                <header className="flex flex-col gap-3 border-b border-slate-100 px-4 py-3 lg:flex-row lg:items-center lg:justify-between">
                    <div>
                        <h3 className="crm-panel-title">Where we’re thin in {data.platform.name}</h3>
                        <p className="crm-panel-subtitle">Fewest of our available profiles first. Pick places, then scrape them from competitor sites.</p>
                    </div>
                    <div className="flex flex-wrap items-center gap-2">
                        <div className="flex rounded-lg border border-slate-200 p-0.5" role="radiogroup" aria-label="Show">
                            {FILTERS.map(([key, label, hint]) => (
                                <button key={key} type="button" role="radio" aria-checked={filter === key} title={hint || undefined} onClick={() => setFilter(key)}
                                    className={`rounded-md px-2.5 py-1 text-xs font-semibold ${filter === key ? 'bg-slate-800 text-white' : 'text-slate-600 hover:text-slate-900'}`}>{label}</button>
                            ))}
                        </div>
                        {filter === 'gaps' ? (
                            <label className="flex items-center gap-1.5 text-xs text-slate-600">
                                Ours ≤
                                <input type="number" min={0} max={100} value={threshold} onChange={(e) => setThreshold(Math.max(0, Number(e.target.value) || 0))} className="crm-input h-8 w-16 py-1 text-xs tabular-nums" aria-label="Our profiles at most" />
                            </label>
                        ) : null}
                        <input className="crm-input h-8 w-44 py-1 text-xs" placeholder="Search places" value={search} onChange={(e) => setSearch(e.target.value)} aria-label="Search places" />
                    </div>
                </header>

                {noCompetitorData ? (
                    <p className="border-b border-amber-100 bg-amber-50 px-4 py-2 text-sm text-amber-900">No competitor data yet for this market. Check or run its sites first, then competitor counts appear here.</p>
                ) : null}

                <div className="relative overflow-x-auto">
                    <table className="min-w-full text-sm">
                        <thead className="bg-slate-50 text-left text-xs text-slate-500">
                            <tr>
                                <th className="w-10 px-3 py-2"><span className="sr-only">Select</span></th>
                                <th className="px-3 py-2 font-medium">Place</th>
                                <th className="px-3 py-2 font-medium">Our profiles</th>
                                <th className="px-3 py-2 font-medium">Competitor profiles</th>
                                <th className="px-3 py-2 font-medium">Found on</th>
                                <th className="px-3 py-2 font-medium"><span className="sr-only">Action</span></th>
                            </tr>
                        </thead>
                        <tbody className="divide-y divide-slate-100">
                            {rows.length === 0 ? (
                                <tr><td colSpan={6} className="px-4 py-10 text-center text-sm text-slate-500">
                                    {filter === 'gaps' ? `No place has ${threshold} or fewer of ours where competitors list profiles.` : 'No places match.'}
                                </td></tr>
                            ) : rows.slice(0, 200).map((r) => {
                                const isSel = selected.has(r.place);
                                return (
                                    <tr key={r.place} className={isSel ? 'bg-teal-50/60' : 'hover:bg-slate-50/60'}>
                                        <td className="px-3 py-2">
                                            <input type="checkbox" checked={isSel} onChange={() => toggle(r.place)} aria-label={`Select ${r.place}`} disabled={!isSel && selected.size >= 20} />
                                        </td>
                                        <td className="px-3 py-2">
                                            <span className="font-medium text-slate-900">{r.place}</span>
                                            {r.kind === 'competitor_only' ? <span className="ml-2 rounded bg-rose-50 px-1.5 py-0.5 text-[11px] font-semibold text-rose-700">Only competitors</span> : null}
                                        </td>
                                        <td className="px-3 py-2">
                                            <span className={`crm-mono text-[13px] font-semibold tabular-nums ${r.ours.available === 0 ? 'text-rose-700' : r.ours.available <= 5 ? 'text-amber-700' : 'text-slate-800'}`}>{r.ours.available}</span>
                                            {r.kind === 'ours' ? <span className="ml-2 text-xs text-slate-500">{r.ours.active} active · {r.ours.unlockable} unlockable</span> : null}
                                        </td>
                                        <td className="min-w-[10rem] px-3 py-2">
                                            <div className="flex items-center gap-2">
                                                <span className="crm-mono w-10 text-right text-[13px] font-semibold tabular-nums text-slate-800">{r.competitors.total}</span>
                                                <span className="h-1.5 flex-1 overflow-hidden rounded-full bg-slate-100" aria-hidden="true">
                                                    <span className="block h-full rounded-full bg-teal-600" style={{ width: `${Math.round((r.competitors.total / maxCompetitors) * 100)}%` }} />
                                                </span>
                                            </div>
                                        </td>
                                        <td className="px-3 py-2 text-xs text-slate-600">
                                            {r.competitors.by_site.slice(0, 3).map((b) => `${siteById[b.site_id]?.name || 'Site'} ${b.count}`).join(' · ') || '—'}
                                            {r.competitors.by_site.length > 3 ? ` +${r.competitors.by_site.length - 3}` : ''}
                                        </td>
                                        <td className="px-3 py-2 text-right">
                                            <button type="button" className="text-xs font-semibold text-teal-700 hover:underline disabled:opacity-40" disabled={r.competitors.total === 0}
                                                onClick={() => { setSelected(new Set([r.place])); setDialog(true); }}>Scrape</button>
                                        </td>
                                    </tr>
                                );
                            })}
                        </tbody>
                    </table>
                </div>
                <footer className="border-t border-slate-100 px-4 py-2 text-xs text-slate-500">
                    Our profiles = available (active + unlockable), as on Clients → Locations. Competitor counts come from each site’s latest run, or its latest check (first page only):
                    {' '}{sites.map((s) => `${s.name} ${s.basis === 'run' ? 'run' : s.basis === 'check' ? 'check' : 'no data'}${s.as_of ? ` ${timeAgo(s.as_of)}` : ''}`).join(' · ')}.
                </footer>
            </section>


            {selected.size > 0 ? (
                <div className="sticky bottom-3 z-30 mx-auto flex max-w-3xl flex-wrap items-center justify-between gap-3 rounded-xl border border-slate-200 bg-white/95 px-4 py-3 shadow-lg backdrop-blur">
                    <p className="min-w-0 text-sm text-slate-700">
                        <strong>{selected.size}</strong> place{selected.size === 1 ? '' : 's'} · about <strong className="tabular-nums">{selectedRows.reduce((n, r) => n + r.competitors.total, 0)}</strong> competitor profiles
                        <span className="block truncate text-xs text-slate-500">📍 {[...selected].join(', ')}</span>
                    </p>
                    <div className="flex gap-2">
                        <button type="button" className="crm-btn-secondary px-3 py-1.5" onClick={() => setSelected(new Set())}>Clear</button>
                        <button type="button" className="crm-btn-primary px-3 py-1.5" onClick={() => setDialog(true)}>Scrape these places</button>
                    </div>
                </div>
            ) : null}

            {dialog ? (
                <BatchDialog
                    platform={data.platform}
                    places={[...selected]}
                    rows={selectedRows}
                    sites={sites}
                    onClose={() => setDialog(false)}
                    onDone={(res) => {
                        setDialog(false);
                        setResult(res);
                        setSelected(new Set());
                        qc.invalidateQueries({ queryKey: ['scraper-overview'] });
                        toast.success(`${res.started.length} run${res.started.length === 1 ? '' : 's'} started${res.skipped.length ? `, ${res.skipped.length} skipped` : ''}.`);
                    }}
                    toast={toast}
                />
            ) : null}
        </div>
    );
}

function BatchDialog({ platform, places, rows, sites, onClose, onDone, toast }) {
    const perSite = useMemo(() => {
        const m = {};
        rows.forEach((r) => r.competitors.by_site.forEach((b) => { m[b.site_id] = (m[b.site_id] || 0) + b.count; }));
        return m;
    }, [rows]);
    useScrollLock(true);
    const [mode, setMode] = useState('dry_run');
    const [picked, setPicked] = useState(() => new Set(sites.filter((s) => s.runnable && (perSite[s.id] || s.basis === 'none')).map((s) => s.id)));
    const ordered = [...sites].sort((a, b) => (b.runnable - a.runnable) || ((perSite[b.id] || 0) - (perSite[a.id] || 0)));

    useEffect(() => {
        const onKey = (e) => { if (e.key === 'Escape') onClose(); };
        window.addEventListener('keydown', onKey);
        return () => window.removeEventListener('keydown', onKey);
    }, [onClose]);

    const start = useMutation({
        mutationFn: () => api.post('/crm/scraper/batch-runs', { platform_id: platform.id, site_ids: [...picked], locations: places, mode }).then((r) => r.data),
        onSuccess: onDone,
        onError: (e) => toast.error(errorMessage(e, 'Could not start the runs.')),
    });

    return (
        <div className="fixed inset-0 z-50 flex items-end justify-center bg-slate-900/45 sm:items-center sm:p-4" onMouseDown={onClose}>
            <div role="dialog" aria-modal="true" aria-labelledby="batch-title" onMouseDown={(e) => e.stopPropagation()} className="flex max-h-[92vh] w-full max-w-xl flex-col overflow-hidden rounded-t-2xl bg-white shadow-2xl sm:rounded-xl">
                <header className="crm-panel-header">
                    <div>
                        <h3 id="batch-title" className="crm-panel-title">Scrape {places.length === 1 ? places[0] : `${places.length} places`}</h3>
                        <p className="crm-panel-subtitle">Each chosen site runs once, keeping only profiles in 📍 {places.join(', ')}.</p>
                    </div>
                    <button type="button" className="crm-btn-secondary px-3 py-1.5" onClick={onClose}>Close</button>
                </header>
                <div className="flex-1 space-y-4 overflow-y-auto overscroll-contain px-4 py-4">
                    <div className="grid gap-2 sm:grid-cols-2">
                        {[['dry_run', 'Preview first', 'Review and pick before any lead is created.'], ['import', 'Run & import', 'Every new matching profile becomes a lead.']].map(([key, title, hint]) => (
                            <label key={key} className={`flex cursor-pointer flex-col rounded-lg border p-3 text-sm ${mode === key ? 'border-teal-600 bg-teal-50/60 ring-1 ring-teal-600' : 'border-slate-200 hover:bg-slate-50'}`}>
                                <span className="flex items-center gap-2 font-semibold text-slate-900"><input type="radio" name="batch-mode" checked={mode === key} onChange={() => setMode(key)} />{title}</span>
                                <span className="mt-1 text-xs text-slate-500">{hint}</span>
                            </label>
                        ))}
                    </div>
                    <fieldset>
                        <legend className="text-sm font-semibold text-slate-900">Competitor sites in {platform.name}</legend>
                        <ul className="mt-2 divide-y divide-slate-100 rounded-lg border border-slate-200">
                            {ordered.map((s) => (
                                <li key={s.id} className={`flex items-center justify-between gap-3 px-3 py-2 ${!s.runnable ? 'opacity-60' : ''}`}>
                                    <label className="flex min-w-0 items-center gap-2 text-sm">
                                        <input type="checkbox" disabled={!s.runnable} checked={picked.has(s.id)}
                                            onChange={() => setPicked((prev) => { const n = new Set(prev); n.has(s.id) ? n.delete(s.id) : n.add(s.id); return n; })} />
                                        <span className="truncate font-medium text-slate-900">{s.name}</span>
                                    </label>
                                    <span className="shrink-0 text-xs text-slate-500">
                                        {!s.runnable ? s.not_runnable_reason : perSite[s.id] ? `${perSite[s.id]} seen here` : s.basis === 'none' ? 'not checked yet' : 'none seen yet'}
                                    </span>
                                </li>
                            ))}
                        </ul>
                        <p className="mt-2 text-xs text-slate-500">“Seen” counts come from each site’s last run or check; a run reads more pages and may find more.</p>
                    </fieldset>
                </div>
                <footer className="flex items-center justify-end gap-2 border-t border-slate-200 px-4 py-3">
                    <button type="button" className="crm-btn-secondary" onClick={onClose}>Cancel</button>
                    <button type="button" className="crm-btn-primary disabled:cursor-not-allowed disabled:opacity-50" disabled={!picked.size || start.isPending} onClick={() => start.mutate()}>
                        {start.isPending ? <><Spinner className="mr-2 h-4 w-4" />Starting…</> : `${mode === 'import' ? 'Run & import on' : 'Preview on'} ${picked.size} site${picked.size === 1 ? '' : 's'}`}
                    </button>
                </footer>
            </div>
        </div>
    );
}

function BatchResult({ result, platform, onOpenRun, onClose, toast, onConverted }) {
    const runIds = result.started.map((s) => s.run.id);
    const runs = useQuery({
        queryKey: ['scraper-batch-runs', runIds.join(',')],
        queryFn: () => Promise.all(runIds.map((id) => api.get(`/crm/scraper/runs/${id}`).then((r) => r.data.run))),
        refetchInterval: (q) => ((q.state.data || []).some((r) => ['queued', 'running'].includes(r.status)) ? 3000 : false),
    });
    const [busy, setBusy] = useState(false);
    const [convertLeads, setConvertLeads] = useState(null);
    const panelRef = React.useRef(null);
    useEffect(() => { panelRef.current?.scrollIntoView({ behavior: 'smooth', block: 'start' }); }, [runIds.join(',')]); // eslint-disable-line react-hooks/exhaustive-deps
    const list = runs.data || [];
    const finished = list.length === runIds.length && list.every((r) => !['queued', 'running'].includes(r.status));
    const newCount = list.reduce((n, r) => n + (r.candidates || []).filter((c) => c.status === 'new').length, 0);
    const createdLeads = list.flatMap((r) => (r.candidates || []).filter((c) => c.status === 'created' && c.lead_id).map((c) => ({ lead_id: c.lead_id, name: c.name, city: c.city })));

    const importAndConvert = async () => {
        setBusy(true);
        try {
            const imported = [];
            for (const r of list) {
                if (r.mode === 'dry_run' && ['success', 'partial'].includes(r.status) && (r.candidates || []).some((c) => c.status === 'new')) {
                    const { data } = await api.post(`/crm/scraper/runs/${r.id}/import`, { indexes: (r.candidates || []).map((c, i) => (c.status === 'new' ? i : null)).filter((i) => i !== null), reason: 'Location gaps: import before convert & place' });
                    imported.push(...(data.run.candidates || []).filter((c) => c.status === 'created' && c.lead_id));
                } else {
                    imported.push(...(r.candidates || []).filter((c) => c.status === 'created' && c.lead_id));
                }
            }
            const unique = [...new Map(imported.map((c) => [c.lead_id, { lead_id: c.lead_id, name: c.name, city: c.city }])).values()];
            if (!unique.length) { toast.error('No new leads to convert.'); return; }
            setConvertLeads(unique);
            runs.refetch();
        } catch (e) {
            toast.error(errorMessage(e, 'Import failed.'));
        } finally {
            setBusy(false);
        }
    };

    return (
        <section ref={panelRef} className="crm-surface scroll-mt-4 p-4" aria-live="polite">
            <div className="flex items-start justify-between gap-3">
                <div>
                    <h4 className="text-sm font-semibold text-slate-900">Scraping 📍 {result.filters.locations.join(', ')}</h4>
                    <p className="text-xs text-slate-500">{finished ? 'All runs finished.' : 'Runs go one after another in the background; this updates by itself.'}</p>
                </div>
                <button type="button" className="text-xs font-semibold text-slate-500 hover:text-slate-800" onClick={onClose}>Dismiss</button>
            </div>
            <ul className="mt-3 flex flex-wrap gap-2">
                {result.started.map((s) => {
                    const r = list.find((x) => x.id === s.run.id);
                    const running = !r || ['queued', 'running'].includes(r.status);
                    return (
                        <li key={s.site_id}>
                            <button type="button" onClick={() => onOpenRun(s.site_id, s.run.id)} className="inline-flex items-center gap-1.5 rounded-full border border-teal-200 bg-teal-50 px-3 py-1 text-xs font-semibold text-teal-800 hover:border-teal-600">
                                {running ? <Spinner className="h-3 w-3" /> : null}{s.name} · {running ? (r?.status === 'running' ? 'running' : 'queued') : `${r.discovered_count} found`} →
                            </button>
                        </li>
                    );
                })}
                {result.skipped.map((s) => (
                    <li key={s.site_id} className="rounded-full border border-slate-200 px-3 py-1 text-xs text-slate-500">{s.name || `Site ${s.site_id}`}: {s.reason}</li>
                ))}
            </ul>
            {finished && (newCount > 0 || createdLeads.length > 0) ? (
                <div className="mt-4 flex flex-col gap-3 rounded-xl border border-teal-200 bg-gradient-to-r from-teal-50 to-white p-4 sm:flex-row sm:items-center sm:justify-between">
                    <div>
                        <p className="text-sm font-semibold text-slate-900">{newCount + createdLeads.length} new profile{newCount + createdLeads.length === 1 ? '' : 's'} found in these places</p>
                        <p className="text-sm text-slate-600">Convert them to clients and place them in our thinnest locations?</p>
                    </div>
                    <button type="button" className="crm-btn-primary shrink-0 px-3 py-1.5 disabled:opacity-50" disabled={busy} onClick={importAndConvert}>
                        {busy ? <><Spinner className="mr-2 h-4 w-4" />Importing…</> : newCount ? 'Import & convert' : 'Convert & place'}
                    </button>
                </div>
            ) : finished ? <p className="mt-3 text-sm text-slate-600">No new profiles turned up for these places this time.</p> : null}
            {convertLeads ? (
                <ConvertPlaceDialog platformId={platform.id} platformName={platform.name} leads={convertLeads} toast={toast}
                    onClose={() => setConvertLeads(null)} onFinished={(n) => { if (n) onConverted?.(); }} />
            ) : null}
        </section>
    );
}

function Tile({ label, value, hint, tone }) {
    const tones = { amber: 'text-amber-700', rose: 'text-rose-700' };
    return (
        <div className="crm-surface px-4 py-3">
            <p className="text-[11px] font-semibold uppercase tracking-[0.08em] text-slate-500">{label}</p>
            <p className={`crm-mono mt-1 text-2xl font-semibold tabular-nums ${tones[tone] || 'text-slate-900'}`}>{value}</p>
            {hint ? <p className="text-xs text-slate-500">{hint}</p> : null}
        </div>
    );
}
