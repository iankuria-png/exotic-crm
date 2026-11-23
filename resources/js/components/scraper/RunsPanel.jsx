import React, { useEffect, useMemo, useState } from 'react';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import api from '../../services/api';
import { RunChip, Spinner, errorMessage, timeAgo } from './scraperUi';
import ConvertPlaceDialog from './ConvertPlaceDialog';

const ACTIVE = ['queued', 'running'];
const STOP_REASON = {
    last_page: 'reached the last page',
    page_limit: 'reached the page limit',
    no_new: 'a page had nothing new',
    empty_page: 'a page had no profiles',
    time_budget: 'hit the time limit',
    robots: 'robots.txt disallowed the next page',
    page_error: 'a page failed to load',
    cancelled: 'cancelled',
};

export default function RunsPanel({ site, selectedRunId, onSelectRun, toast }) {
    const qc = useQueryClient();
    const runs = useQuery({
        queryKey: ['scraper-runs', site.id],
        queryFn: () => api.get(`/crm/scraper/sites/${site.id}/runs`).then((r) => r.data.runs),
        refetchInterval: (q) => ((q.state.data || []).some((r) => ACTIVE.includes(r.status)) ? 2000 : false),
    });

    const runId = selectedRunId || runs.data?.[0]?.id || null;
    const run = useQuery({
        queryKey: ['scraper-run', runId],
        enabled: !!runId,
        queryFn: () => api.get(`/crm/scraper/runs/${runId}`).then((r) => r.data.run),
        refetchInterval: (q) => (ACTIVE.includes(q.state.data?.status) ? 1500 : false),
    });

    useEffect(() => {
        if (run.data && !ACTIVE.includes(run.data.status)) {
            qc.invalidateQueries({ queryKey: ['scraper-overview'] });
            qc.invalidateQueries({ queryKey: ['scraper-runs', site.id] });
        }
    }, [run.data?.status]); // eslint-disable-line react-hooks/exhaustive-deps

    const cancel = useMutation({
        mutationFn: (id) => api.post(`/crm/scraper/runs/${id}/cancel`).then((r) => r.data.run),
        onSuccess: () => { toast.success('Stopping the run…'); qc.invalidateQueries({ queryKey: ['scraper-run', runId] }); },
        onError: (e) => toast.error(errorMessage(e, 'Could not cancel.')),
    });

    if (runs.isLoading) {
        return <div className="space-y-2">{[0, 1, 2].map((i) => <div key={i} className="h-12 animate-pulse rounded-lg bg-slate-100 motion-reduce:animate-none" />)}</div>;
    }
    if (runs.isError) {
        return <p className="rounded-lg border border-rose-200 bg-rose-50 p-3 text-sm text-rose-800">Could not load runs. <button type="button" className="font-semibold underline" onClick={() => runs.refetch()}>Retry</button></p>;
    }
    if (!runs.data?.length) {
        return (
            <div className="rounded-xl border border-dashed border-slate-300 px-6 py-10 text-center">
                <p className="text-sm font-semibold text-slate-900">No runs yet</p>
                <p className="mt-1 text-sm text-slate-500">Start with a preview: it collects profiles for you to review without creating any leads.</p>
            </div>
        );
    }

    return (
        <div className="space-y-4">
            <ol className="flex gap-2 overflow-x-auto pb-1" aria-label="Run history">
                {runs.data.map((r) => (
                    <li key={r.id} className="shrink-0">
                        <button type="button" onClick={() => onSelectRun(r.id)} aria-current={r.id === runId ? 'true' : undefined}
                            className={`w-52 rounded-lg border px-3 py-2 text-left transition ${r.id === runId ? 'border-teal-600 bg-teal-50/60 ring-1 ring-teal-600' : 'border-slate-200 hover:bg-slate-50'}`}>
                            <div className="flex items-center justify-between gap-2">
                                <span className="text-sm font-semibold text-slate-900">{r.mode === 'import' ? 'Import' : 'Preview'} #{r.id}</span>
                                <RunChip status={r.status} />
                            </div>
                            <p className="mt-0.5 truncate text-xs text-slate-500">
                                {timeAgo(r.created_at)} · {r.discovered_count} found{r.created_count ? ` · ${r.created_count} imported` : ''}
                            </p>
                            <p className="mt-0.5 truncate text-[11px] font-medium text-teal-700">{r.filters?.locations?.length ? `📍 ${r.filters.locations.join(', ')}` : <span className="font-normal text-slate-400">All locations</span>}</p>
                        </button>
                    </li>
                ))}
            </ol>
            <div className="min-w-0">
                {run.isLoading || !run.data ? <div className="h-40 animate-pulse rounded-xl bg-slate-100 motion-reduce:animate-none" /> : (
                    ACTIVE.includes(run.data.status)
                        ? <LiveProgress run={run.data} onCancel={() => cancel.mutate(run.data.id)} cancelling={cancel.isPending || run.data.cancel_requested} />
                        : <RunResults run={run.data} site={site} toast={toast} />
                )}
            </div>
        </div>
    );
}

function LiveProgress({ run, onCancel, cancelling }) {
    const p = run.progress || {};
    const isProfiles = p.phase === 'profiles';
    const total = isProfiles ? (p.profiles_total || 0) : (p.estimated_pages ? Math.min(p.estimated_pages, p.max_pages || p.estimated_pages) : (p.max_pages || 1));
    const done = isProfiles ? (p.profiles_done || 0) : (p.pages_fetched || 0);
    const pct = total ? Math.min(100, Math.round((done / total) * 100)) : 0;

    return (
        <section className="space-y-4 rounded-xl border border-sky-200 bg-sky-50/40 p-4" aria-live="polite">
            <div className="flex items-center justify-between gap-3">
                <div className="flex items-center gap-2 text-sm font-semibold text-sky-900">
                    <Spinner />
                    {run.status === 'queued' ? 'Waiting for a worker…' : isProfiles ? `Opening profiles ${done}/${total}` : `Reading page ${p.page || done || 1}${total > 1 ? ` of ~${total}` : ''}`}
                </div>
                <button type="button" className="crm-btn-danger px-3 py-1.5 text-xs" onClick={onCancel} disabled={cancelling}>{cancelling ? 'Stopping…' : 'Stop'}</button>
            </div>
            <div className="h-2 overflow-hidden rounded-full bg-sky-100" role="progressbar" aria-valuenow={pct} aria-valuemin={0} aria-valuemax={100}>
                <div className="h-full rounded-full bg-sky-600 transition-[width] duration-500 motion-reduce:transition-none" style={{ width: `${Math.max(pct, run.status === 'queued' ? 2 : 6)}%` }} />
            </div>
            {run.filters?.locations?.length ? <WhereChips filters={run.filters} outside={p.outside_location} /> : null}
            <dl className="grid grid-cols-3 gap-3">
                <Metric label="Profiles found" value={p.candidates ?? 0} />
                <Metric label="With phone" value={p.with_phone ?? 0} />
                <Metric label="Pages read" value={p.pages_fetched ?? 0} />
            </dl>
            <EventLog events={p.events} />
        </section>
    );
}

function RunResults({ run, site, toast }) {
    const qc = useQueryClient();
    const [filter, setFilter] = useState('new');
    const [query, setQuery] = useState('');
    const [selected, setSelected] = useState(() => new Set());
    const [convertOpen, setConvertOpen] = useState(false);
    const [convertDismissed, setConvertDismissed] = useState(false);
    const candidates = useMemo(() => (run.candidates || []).map((c, index) => ({ ...c, index })), [run.candidates]);

    useEffect(() => { setSelected(new Set()); setConvertDismissed(false); }, [run.id]);
    const importedLeads = useMemo(() => candidates.filter((c) => c.status === 'created' && c.lead_id).map((c) => ({ lead_id: c.lead_id, name: c.name, city: c.city })), [candidates]);

    const counts = useMemo(() => ({
        all: candidates.length,
        new: candidates.filter((c) => c.status === 'new').length,
        existing: candidates.filter((c) => c.status === 'existing').length,
        created: candidates.filter((c) => c.status === 'created').length,
        no_phone: candidates.filter((c) => !c.phone).length,
    }), [candidates]);

    const rows = useMemo(() => {
        const q = query.trim().toLowerCase();
        return candidates.filter((c) => {
            if (filter === 'new' && c.status !== 'new') return false;
            if (filter === 'existing' && c.status !== 'existing') return false;
            if (filter === 'created' && c.status !== 'created') return false;
            if (filter === 'no_phone' && c.phone) return false;
            if (!q) return true;
            return [c.name, c.phone, c.city, c.profile_url].some((v) => String(v || '').toLowerCase().includes(q));
        });
    }, [candidates, filter, query]);

    const importable = (c) => c.status === 'new';
    const selectable = rows.filter(importable);
    const allSelected = selectable.length > 0 && selectable.every((c) => selected.has(c.index));

    const doImport = useMutation({
        mutationFn: (indexes) => api.post(`/crm/scraper/runs/${run.id}/import`, { indexes }).then((r) => r.data),
        onSuccess: (data) => {
            toast.success(`${data.created} lead${data.created === 1 ? '' : 's'} created${data.duplicates ? `, ${data.duplicates} already in the CRM` : ''}.`);
            setSelected(new Set());
            qc.setQueryData(['scraper-run', run.id], data.run);
            qc.invalidateQueries({ queryKey: ['scraper-runs'] });
            qc.invalidateQueries({ queryKey: ['leads'] });
        },
        onError: (e) => toast.error(errorMessage(e, 'Import failed.')),
    });

    const exportCsv = () => {
        const header = ['name', 'phone', 'whatsapp', 'email', 'city', 'age', 'profile_url', 'status'];
        const esc = (v) => `"${String(v ?? '').replace(/"/g, '""')}"`;
        const csv = [header.join(','), ...rows.map((c) => header.map((h) => esc(c[h])).join(','))].join('\n');
        const blob = new Blob([csv], { type: 'text/csv;charset=utf-8' });
        const a = document.createElement('a');
        a.href = URL.createObjectURL(blob);
        a.download = `scrape-run-${run.id}.csv`;
        a.click();
        URL.revokeObjectURL(a.href);
    };

    const r = run.result || {};
    const failed = ['blocked', 'error'].includes(run.status);

    return (
        <section className="space-y-3">
            <div className={`rounded-xl border p-4 ${failed ? 'border-rose-200 bg-rose-50/50' : 'border-slate-200'}`}>
                <div className="flex flex-wrap items-center gap-2">
                    <RunChip status={run.status} />
                    <span className="text-sm font-semibold text-slate-900">{run.mode === 'import' ? 'Import run' : 'Preview run'} #{run.id}</span>
                    <span className="text-xs text-slate-500">· {run.initiated_by?.name || 'Someone'} · {timeAgo(run.completed_at || run.created_at)}</span>
                </div>
                {run.filters?.locations?.length ? <div className="mt-2"><WhereChips filters={run.filters} outside={r.outside_location} /></div> : null}
                <p className="mt-1 text-sm text-slate-700">
                    {run.reason || r.message}
                    {r.stop_reason && STOP_REASON[r.stop_reason] ? ` Stopped because it ${STOP_REASON[r.stop_reason]}.` : ''}
                </p>
                <dl className="mt-3 grid grid-cols-2 gap-3 sm:grid-cols-5">
                    <Metric label="Found" value={r.discovered ?? candidates.length} />
                    <Metric label="With phone" value={r.with_phone ?? 0} />
                    <Metric label="New" value={counts.new} accent />
                    <Metric label="Already in CRM" value={counts.existing} />
                    <Metric label="Imported" value={counts.created} />
                </dl>
                {run.status === 'empty' && r.outside_location ? (
                    <p className="mt-3 rounded-md bg-amber-50 px-3 py-2 text-sm text-amber-900">
                        {r.outside_location} profiles were found, but none in {run.filters.locations.join(', ')}.
                        {(r.locations || []).length ? ` Places on this site include ${r.locations.slice(0, 5).map((l) => l.label).join(', ')}.` : ''}
                    </p>
                ) : null}
                {(run.progress?.events || []).length ? <details className="mt-3"><summary className="cursor-pointer text-xs font-semibold text-slate-600">Run log</summary><EventLog events={run.progress.events} /></details> : null}
            </div>

            {importedLeads.length > 0 && !convertDismissed ? (
                <div className="flex flex-col gap-3 rounded-xl border border-teal-200 bg-gradient-to-r from-teal-50 to-white p-4 sm:flex-row sm:items-center sm:justify-between">
                    <div>
                        <p className="text-sm font-semibold text-slate-900">{importedLeads.length} lead{importedLeads.length === 1 ? '' : 's'} imported from this run</p>
                        <p className="text-sm text-slate-600">Convert them to clients and place them in our thinnest locations?</p>
                    </div>
                    <div className="flex shrink-0 gap-2">
                        <button type="button" className="crm-btn-secondary px-3 py-1.5" onClick={() => setConvertDismissed(true)}>Not now</button>
                        <button type="button" className="crm-btn-primary px-3 py-1.5" onClick={() => setConvertOpen(true)}>Convert &amp; place</button>
                    </div>
                </div>
            ) : null}
            {convertOpen ? (
                <ConvertPlaceDialog platformId={site.platform_id} platformName={site.platform?.name || 'this market'} leads={importedLeads} toast={toast}
                    onClose={() => setConvertOpen(false)} onFinished={(n) => { if (n) setConvertDismissed(true); }} />
            ) : null}

            {candidates.length > 0 ? (
                <div className="overflow-hidden rounded-xl border border-slate-200">
                    <div className="flex flex-wrap items-center justify-between gap-2 border-b border-slate-100 bg-slate-50 px-3 py-2">
                        <div className="flex flex-wrap gap-1" role="tablist" aria-label="Filter profiles">
                            {[['new', 'New'], ['existing', 'In CRM'], ['created', 'Imported'], ['no_phone', 'No phone'], ['all', 'All']].map(([k, label]) => (
                                <button key={k} type="button" role="tab" aria-selected={filter === k} onClick={() => setFilter(k)}
                                    className={`rounded-md px-2.5 py-1 text-xs font-semibold ${filter === k ? 'bg-white text-slate-900 shadow-sm ring-1 ring-slate-200' : 'text-slate-600 hover:text-slate-900'}`}>
                                    {label} <span className="tabular-nums text-slate-400">{counts[k]}</span>
                                </button>
                            ))}
                        </div>
                        <div className="flex flex-wrap items-center gap-2">
                            <input className="crm-input h-8 w-48 py-1 text-xs" placeholder="Search name, phone, city" value={query} onChange={(e) => setQuery(e.target.value)} aria-label="Search profiles" />
                            <button type="button" className="crm-btn-secondary px-3 py-1.5 text-xs" onClick={exportCsv}>Export CSV</button>
                            {run.mode === 'dry_run' && ['success', 'partial'].includes(run.status) ? (
                                <>
                                    <button type="button" className="crm-btn-secondary px-3 py-1.5 text-xs" disabled={!selected.size || doImport.isPending} onClick={() => doImport.mutate([...selected])}>
                                        Import selected ({selected.size})
                                    </button>
                                    <button type="button" className="crm-btn-primary px-3 py-1.5 text-xs" disabled={!counts.new || doImport.isPending} onClick={() => doImport.mutate(candidates.filter(importable).map((c) => c.index))}>
                                        {doImport.isPending ? 'Importing…' : `Import all new (${counts.new})`}
                                    </button>
                                </>
                            ) : null}
                        </div>
                    </div>
                    <div className="relative max-h-[34rem] overflow-auto">
                        <table className="min-w-full text-sm">
                            <thead className="sticky top-0 bg-white text-left text-xs text-slate-500 shadow-[0_1px_0_#e2e8f0]">
                                <tr>
                                    <th className="w-8 px-3 py-2">
                                        <input type="checkbox" aria-label="Select all new on this view" checked={allSelected} disabled={!selectable.length}
                                            onChange={(e) => setSelected((prev) => { const next = new Set(prev); selectable.forEach((c) => (e.target.checked ? next.add(c.index) : next.delete(c.index))); return next; })} />
                                    </th>
                                    <th className="px-3 py-2 font-medium">Name</th>
                                    <th className="px-3 py-2 font-medium">Phone</th>
                                    <th className="px-3 py-2 font-medium">City</th>
                                    <th className="px-3 py-2 font-medium">Status</th>
                                    <th className="px-3 py-2 font-medium">Profile</th>
                                </tr>
                            </thead>
                            <tbody className="divide-y divide-slate-100">
                                {rows.length === 0 ? (
                                    <tr><td colSpan={6} className="px-3 py-8 text-center text-sm text-slate-500">No profiles in this view.</td></tr>
                                ) : rows.map((c) => (
                                    <tr key={c.index} className={selected.has(c.index) ? 'bg-teal-50/50' : ''}>
                                        <td className="px-3 py-2">
                                            <input type="checkbox" aria-label={`Select ${c.name || 'profile'}`} disabled={!importable(c)} checked={selected.has(c.index)}
                                                onChange={(e) => setSelected((prev) => { const next = new Set(prev); e.target.checked ? next.add(c.index) : next.delete(c.index); return next; })} />
                                        </td>
                                        <td className="px-3 py-2">
                                            <div className="flex items-center gap-2">
                                                {c.image_url ? <img src={c.image_url} alt="" loading="lazy" referrerPolicy="no-referrer" className="h-8 w-8 shrink-0 rounded object-cover" /> : <span className="h-8 w-8 shrink-0 rounded bg-slate-100" />}
                                                <span className="max-w-[14rem] truncate font-medium text-slate-900">{c.name}</span>
                                                {c.age ? <span className="text-xs text-slate-500">{c.age}</span> : null}
                                            </div>
                                        </td>
                                        <td className="crm-mono whitespace-nowrap px-3 py-2 text-[13px]">
                                            {c.phone ? `+${c.phone}` : <span className="font-sans text-xs text-amber-700">None</span>}
                                            {c.whatsapp ? <span className="ml-1.5 rounded bg-emerald-50 px-1 text-[10px] font-semibold text-emerald-700">WA</span> : null}
                                        </td>
                                        <td className="max-w-[12rem] truncate px-3 py-2 text-slate-600">{c.city || '—'}</td>
                                        <td className="px-3 py-2"><CandidateStatus c={c} /></td>
                                        <td className="px-3 py-2">{c.profile_url ? <a className="text-teal-700 hover:underline" href={c.profile_url} target="_blank" rel="noreferrer noopener">Open ↗</a> : '—'}</td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>
                </div>
            ) : null}
        </section>
    );
}

function WhereChips({ filters, outside }) {
    return (
        <div className="flex flex-wrap items-center gap-1.5 text-xs">
            <span className="font-semibold text-slate-500">Only in</span>
            {filters.locations.map((l) => <span key={l} className="rounded-full bg-teal-700 px-2 py-0.5 font-semibold text-white">📍 {l}</span>)}
            {filters.include_unknown_location ? <span className="text-slate-500">+ profiles with no location</span> : null}
            {outside ? <span className="text-slate-500">· {outside} elsewhere skipped</span> : null}
        </div>
    );
}

function CandidateStatus({ c }) {
    const map = {
        new: ['New', 'bg-teal-50 text-teal-800'],
        existing: ['In CRM', 'bg-slate-100 text-slate-600'],
        created: ['Imported', 'bg-emerald-50 text-emerald-800'],
        error: ['Error', 'bg-rose-50 text-rose-800'],
    };
    const [label, tone] = map[c.status] || [c.status || '—', 'bg-slate-100 text-slate-600'];
    return <span title={c.error || undefined} className={`rounded px-1.5 py-0.5 text-xs font-semibold ${tone}`}>{label}</span>;
}

function Metric({ label, value, accent }) {
    return (
        <div>
            <dt className="text-[11px] font-semibold uppercase tracking-[0.08em] text-slate-500">{label}</dt>
            <dd className={`crm-mono text-lg font-semibold tabular-nums ${accent ? 'text-teal-800' : 'text-slate-900'}`}>{value}</dd>
        </div>
    );
}

function EventLog({ events = [] }) {
    if (!events?.length) return null;
    const tone = { error: 'text-rose-700', warning: 'text-amber-700', info: 'text-slate-600' };
    return (
        <ol className="mt-2 max-h-40 space-y-0.5 overflow-y-auto rounded-md bg-white/70 p-2 text-xs">
            {[...events].reverse().slice(0, 30).map((e, i) => (
                <li key={i} className={`flex gap-2 ${tone[e.level] || tone.info}`}>
                    <span className="crm-mono shrink-0 text-slate-400">{new Date(e.at).toLocaleTimeString([], { hour: '2-digit', minute: '2-digit', second: '2-digit' })}</span>
                    <span>{e.message}</span>
                </li>
            ))}
        </ol>
    );
}
