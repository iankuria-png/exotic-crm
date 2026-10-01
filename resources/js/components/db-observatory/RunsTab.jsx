import React, { useEffect, useMemo, useRef, useState } from 'react';
import { keepPreviousData, useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import dbObservatory from '../../services/dbObservatory';
import ConfirmDialog from '../ConfirmDialog';
import { useToast } from '../ToastProvider';
import {
    apiError, ConfidenceBadge, Empty, ErrorState, fmtDateTime, fmtNumber, fmtTime, humanize, InertCode, Loading, Meter, Panel,
    SeverityBadge, Status, TERMINAL_PASS, TERMINAL_RUN,
} from './shared';

const LEVEL_TONE = {
    debug: 'text-slate-500',
    info: 'text-slate-300',
    warn: 'text-amber-300',
    error: 'text-rose-300',
    finding: 'text-orange-200 font-semibold',
};

function PassControls({ pass, canOperate }) {
    const queryClient = useQueryClient();
    const toast = useToast();
    const [confirmStop, setConfirmStop] = useState(false);
    const mutation = useMutation({
        mutationFn: ({ action }) => dbObservatory[`${action}Pass`](pass.id),
        onSuccess: (data, { action }) => {
            toast.success(action === 'stop' ? 'Stop requested — running markets finish their current chunk.' : action === 'pause' ? 'Pause requested — progress is kept.' : 'Resumed.');
            queryClient.invalidateQueries({ queryKey: ['dbo'] });
            setConfirmStop(false);
        },
        onError: (error) => toast.error(apiError(error)),
    });

    if (!canOperate || !pass || TERMINAL_PASS.includes(pass.status)) return null;
    const paused = ['paused', 'pausing'].includes(pass.status);

    return (
        <div className="flex flex-wrap gap-2">
            {paused ? (
                <button type="button" className="crm-btn-secondary" disabled={mutation.isPending} onClick={() => mutation.mutate({ action: 'resume' })}>Resume</button>
            ) : (
                <button type="button" className="crm-btn-secondary" disabled={mutation.isPending || pass.status === 'stopping'} onClick={() => mutation.mutate({ action: 'pause' })}>Pause</button>
            )}
            <button type="button" className="crm-btn-danger" disabled={mutation.isPending || pass.status === 'stopping'} onClick={() => setConfirmStop(true)}>Stop this scan</button>
            <ConfirmDialog
                open={confirmStop}
                tone="danger"
                title="Stop this scan?"
                message="Running markets commit their current chunk and stop; unstarted markets end now. The sweep is closed and nothing is resolved. A later Scan now starts a new sweep. Schedules are not affected."
                confirmLabel="Stop scan"
                isPending={mutation.isPending}
                onCancel={() => setConfirmStop(false)}
                onConfirm={() => mutation.mutate({ action: 'stop' })}
            />
        </div>
    );
}

function useRunEvents(runId, terminal) {
    const [events, setEvents] = useState([]);
    const [failed, setFailed] = useState(0);
    const lastId = useRef(null);

    useEffect(() => {
        setEvents([]);
        lastId.current = null;
    }, [runId]);

    useEffect(() => {
        let cancelled = false;
        let timer = null;
        let delay = 3000;

        const poll = async () => {
            if (document.hidden) {
                timer = window.setTimeout(poll, 3000);
                return;
            }
            try {
                const params = lastId.current ? { after_id: lastId.current, limit: 500 } : { limit: 300 };
                const data = await dbObservatory.runEvents(runId, params);
                if (cancelled) return;
                const incoming = data.events || [];
                if (incoming.length) {
                    lastId.current = incoming[incoming.length - 1].id;
                    setEvents((current) => [...current, ...incoming].slice(-1500));
                }
                setFailed(0);
                delay = 3000;
                if (data.run?.terminal && terminal) return;
            } catch {
                setFailed((n) => n + 1);
                delay = Math.min(60_000, delay * 2);
            }
            if (!cancelled) timer = window.setTimeout(poll, delay);
        };

        poll();
        return () => {
            cancelled = true;
            window.clearTimeout(timer);
        };
    }, [runId, terminal]);

    return { events, failed };
}

function EventLog({ runId, terminal }) {
    const { events, failed } = useRunEvents(runId, terminal);
    const [level, setLevel] = useState('all');
    const [surface, setSurface] = useState('all');
    const [follow, setFollow] = useState(true);
    const box = useRef(null);

    const surfaces = useMemo(() => Array.from(new Set(events.map((e) => e.surface).filter(Boolean))), [events]);
    const visible = events.filter((e) => (level === 'all' || e.level === level) && (surface === 'all' || e.surface === surface));

    useEffect(() => {
        if (follow && box.current) box.current.scrollTop = box.current.scrollHeight;
    }, [visible.length, follow]);

    return (
        <Panel
            title="Event log"
            subtitle={terminal ? 'Final log for this run' : 'Refreshes every few seconds while the run is active'}
            action={(
                <div className="flex flex-wrap items-center gap-2">
                    <select aria-label="Level" className="crm-select py-1 text-xs" value={level} onChange={(e) => setLevel(e.target.value)}>
                        {['all', 'info', 'warn', 'error', 'finding', 'debug'].map((l) => <option key={l} value={l}>{l === 'all' ? 'All levels' : l}</option>)}
                    </select>
                    <select aria-label="Surface" className="crm-select py-1 text-xs" value={surface} onChange={(e) => setSurface(e.target.value)}>
                        <option value="all">All surfaces</option>
                        {surfaces.map((s) => <option key={s} value={s}>{s}</option>)}
                    </select>
                    <button type="button" onClick={() => setFollow((v) => !v)} className={`rounded-md border px-2 py-1 text-xs font-semibold ${follow ? 'border-teal-300 bg-teal-50 text-teal-800' : 'border-slate-200 text-slate-600'}`}>
                        Follow {follow ? '●' : '○'}
                    </button>
                </div>
            )}
        >
            <div ref={box} className="crm-mono h-[26rem] overflow-y-auto rounded-b-xl bg-slate-950 px-3 py-2 text-[11.5px] leading-relaxed" aria-live="off">
                {failed > 0 ? <p className="text-amber-300">Connection problem — retrying with backoff…</p> : null}
                {visible.length === 0 ? <p className="text-slate-500">{terminal ? 'No events recorded.' : 'Waiting for the first slice…'}</p> : null}
                {visible.map((e) => (
                    <p key={e.id} className={`whitespace-pre-wrap break-words ${LEVEL_TONE[e.level] || 'text-slate-300'}`}>
                        <span className="text-slate-500">{fmtTime(e.at)}</span>{' '}
                        <span className="uppercase">{e.level.padEnd(7, ' ')}</span>{' '}
                        {e.surface ? <span className="text-teal-300">{e.surface} </span> : null}
                        {e.rule_key ? <span className="text-sky-300">{e.rule_key} </span> : null}
                        {e.message}
                    </p>
                ))}
            </div>
        </Panel>
    );
}

function CoveragePanel({ runId }) {
    const [showRules, setShowRules] = useState(false);
    const query = useQuery({ queryKey: ['dbo', 'coverage', runId], queryFn: () => dbObservatory.runCoverage(runId) });
    if (query.isLoading) return <Loading rows={2} />;
    if (query.isError) return <ErrorState error={query.error} onRetry={query.refetch} />;
    const c = query.data;

    return (
        <Panel
            title="Coverage"
            subtitle={c.time_spanning ? 'Time-spanning sweep: this run continued a traversal started by earlier runs.' : 'What this run actually examined'}
            action={<div className="flex gap-1.5 text-xs"><Status value="complete" label={`${c.complete} complete`} /><Status value="incomplete" label={`${c.incomplete} incomplete`} /><Status value="not_applicable" label={`${c.not_applicable} n/a`} /></div>}
        >
            <p className={`mx-4 mt-3 rounded-lg px-3 py-2 text-sm ${c.incomplete ? 'bg-amber-50 text-amber-900' : 'bg-slate-50 text-slate-700'}`}>{c.statement}</p>
            <div className="overflow-x-auto p-3">
                <table className="w-full min-w-[40rem] text-sm">
                    <thead>
                        <tr className="text-left text-xs uppercase tracking-wide text-slate-500">
                            <th className="px-2 py-1.5">Surface</th><th className="px-2 py-1.5">Status</th><th className="px-2 py-1.5 text-right">Rows</th>
                            <th className="px-2 py-1.5 text-right">Excluded</th><th className="px-2 py-1.5 text-right">Truncated</th><th className="px-2 py-1.5">Extent</th>
                        </tr>
                    </thead>
                    <tbody className="divide-y divide-slate-100">
                        {c.surfaces.map((s) => (
                            <tr key={s.surface_key}>
                                <td className="px-2 py-1.5"><p className="crm-mono text-xs font-semibold text-slate-800">{s.surface_key}</p><p className="text-xs text-slate-500">{s.title}</p></td>
                                <td className="px-2 py-1.5"><Status value={s.status} />{s.reason ? <span className="ml-1.5 text-xs text-slate-500">{humanize(s.reason)}</span> : null}</td>
                                <td className="crm-mono px-2 py-1.5 text-right text-xs">{fmtNumber(s.rows_scanned)}</td>
                                <td className="crm-mono px-2 py-1.5 text-right text-xs" title="Secret-named values excluded whole before reading">{s.excluded_values || '—'}</td>
                                <td className="crm-mono px-2 py-1.5 text-right text-xs" title="Values larger than 64 KiB; tails not examined">{s.values_truncated || '—'}</td>
                                <td className="crm-mono px-2 py-1.5 text-xs text-slate-500">{s.cursor_end ? `${s.cursor_start ?? 0}→${s.cursor_end}${s.high_water ? ` / ${s.high_water}` : ''}` : '—'}</td>
                            </tr>
                        ))}
                    </tbody>
                </table>
                <button type="button" onClick={() => setShowRules((v) => !v)} className="mt-3 text-sm font-semibold text-teal-700 hover:underline">
                    {showRules ? 'Hide' : 'Show'} rule-by-rule coverage ({c.rules?.length || 0})
                </button>
                {showRules ? (
                    <div className="mt-2 max-h-80 overflow-y-auto rounded-lg border border-slate-200">
                        <table className="w-full text-xs">
                            <tbody className="divide-y divide-slate-100">
                                {(c.rules || []).map((r) => (
                                    <tr key={`${r.rule_key}-${r.surface_key}`}>
                                        <td className="crm-mono px-2 py-1">{r.rule_key}</td>
                                        <td className="crm-mono px-2 py-1 text-slate-500">{r.surface_key}</td>
                                        <td className="px-2 py-1"><Status value={r.status} /> <span className="text-slate-500">{r.reason ? humanize(r.reason) : ''}</span></td>
                                        <td className="crm-mono px-2 py-1 text-right">{r.matches || ''}</td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>
                ) : null}
            </div>
        </Panel>
    );
}

export function RunDetail({ runId, canOperate, onBack, onOpenFindings }) {
    const query = useQuery({
        queryKey: ['dbo', 'run', runId],
        queryFn: () => dbObservatory.run(runId),
        refetchInterval: (q) => {
            const status = q.state.data?.run?.status;
            if (status && TERMINAL_RUN.includes(status)) return false;
            return q.state.error ? 15_000 : 3_000;
        },
    });
    const passId = query.data?.run?.pass_id;
    const passQuery = useQuery({
        queryKey: ['dbo', 'pass', passId],
        queryFn: () => dbObservatory.pass(passId),
        enabled: Boolean(passId),
        refetchInterval: (q) => (q.state.data?.pass && TERMINAL_PASS.includes(q.state.data.pass.status) ? false : 5_000),
    });

    if (query.isLoading) return <Loading rows={6} />;
    if (query.isError) return <ErrorState error={query.error} onRetry={query.refetch} />;

    const { run, sweep } = query.data;
    const terminal = TERMINAL_RUN.includes(run.status);
    const m = run.metrics || {};
    const progress = run.progress || {};

    return (
        <div className="space-y-4">
            <div className="crm-surface flex flex-col gap-3 px-5 py-4 lg:flex-row lg:items-center lg:justify-between">
                <div className="min-w-0">
                    <button type="button" onClick={onBack} className="mb-1 text-sm font-semibold text-teal-700 hover:underline">← All runs</button>
                    <h3 className="text-xl font-semibold tracking-tight text-slate-900">Run {run.id} · {run.market}</h3>
                    <p className="mt-0.5 flex flex-wrap items-center gap-2 text-sm text-slate-500">
                        <Status value={run.status} />
                        {run.pause_reason ? <span>paused: {humanize(run.pause_reason)}</span> : null}
                        <span className="capitalize">{run.profile}</span>
                        {run.mode !== 'scan' ? <span className="rounded bg-violet-50 px-1.5 text-violet-700">{run.mode}</span> : null}
                        <span>pass {run.pass_id}</span>
                        {run.db_engine ? <span className="crm-mono text-xs">{run.db_engine}</span> : null}
                        {run.error_code ? <span className="text-rose-700">{humanize(run.error_code)}</span> : null}
                    </p>
                </div>
                <div className="flex flex-wrap items-center gap-2">
                    {run.mode === 'scan' ? <button type="button" className="crm-btn-secondary" onClick={() => onOpenFindings({ run_id: run.id, status: '' })}>Findings seen in this run</button> : null}
                    <PassControls pass={passQuery.data?.pass} canOperate={canOperate} />
                </div>
            </div>

            <div className="grid grid-cols-2 gap-2 sm:grid-cols-3 lg:grid-cols-7">
                {[
                    ['Surfaces', `${progress.surfaces_done ?? 0}/${progress.surfaces_total ?? 0}`],
                    ['Rows read', fmtNumber(m.rows_read)],
                    ['Queries', fmtNumber(m.queries)],
                    ['P95 query', `${m.p95_ms ?? 0} ms`],
                    ['Timeouts', m.timeouts ?? 0],
                    ['Active time', `${run.active_seconds}s / ${run.budget_seconds}s`],
                    ['New findings', run.findings_new],
                ].map(([label, value]) => (
                    <div key={label} className="crm-kpi px-3 py-2.5">
                        <p className="text-[10px] font-semibold uppercase tracking-[0.1em] text-slate-500">{label}</p>
                        <p className={`crm-mono mt-1 text-base font-semibold ${label === 'New findings' && value ? 'text-rose-700' : 'text-slate-900'}`}>{value}</p>
                    </div>
                ))}
            </div>

            {run.unsupported ? (
                <div className="rounded-xl border border-rose-200 bg-rose-50 px-4 py-3 text-sm text-rose-800">
                    {run.unsupported === 'multisite_unsupported' ? 'Multisite tables found — phase 1 scans single-site schemas only; every surface is recorded incomplete.' : 'Core WordPress tables are missing for the configured prefix — fix the prefix in Markets → connection.'}
                </div>
            ) : null}

            <div className="grid gap-4 xl:grid-cols-[1fr_1.35fr]">
                <div className="space-y-4">
                    <Panel title="Surface progress" subtitle={sweep ? `Sweep ${sweep.id} · ${humanize(sweep.status)} · ${sweep.runs} run(s) · expires ${fmtDateTime(sweep.expires_at)}` : 'Single run'} bodyClass="divide-y divide-slate-100">
                        {(progress.inventory || []).length ? (
                            <div className="px-4 py-2.5">
                                <div className="flex items-center justify-between text-sm"><span className="font-semibold text-slate-800">Inventory</span><span className="text-xs text-slate-500">{progress.inventory.filter((s) => s.status === 'complete').length}/{progress.inventory.length} complete</span></div>
                                <div className="mt-1.5 flex flex-wrap gap-1">
                                    {progress.inventory.map((s) => <span key={s.key} title={s.reason || s.status}><Status value={s.status === 'pending' ? 'queued' : s.status} label={s.key} /></span>)}
                                </div>
                            </div>
                        ) : null}
                        {(progress.surfaces || []).map((s) => (
                            <div key={s.key} className={`px-4 py-2.5 ${progress.current_surface === s.key && !terminal ? 'bg-teal-50/60' : ''}`}>
                                <div className="flex items-center justify-between gap-2">
                                    <span className="crm-mono text-xs font-semibold text-slate-800">{s.key}</span>
                                    <span className="flex items-center gap-1.5 text-xs text-slate-500">
                                        {fmtNumber(s.rows)} rows{s.excluded ? ` · ${s.excluded} excluded` : ''}{s.truncated ? ` · ${s.truncated} truncated` : ''}
                                        <Status value={s.status === 'pending' ? (progress.current_surface === s.key && !terminal ? 'running' : 'queued') : s.status} label={s.status === 'pending' ? undefined : undefined} />
                                    </span>
                                </div>
                                <div className="mt-1.5"><Meter fraction={s.fraction} tone={s.status === 'incomplete' ? 'amber' : 'teal'} /></div>
                                {s.reason ? <p className="mt-1 text-xs text-amber-700">{humanize(s.reason)}</p> : null}
                            </div>
                        ))}
                    </Panel>

                    {run.mode === 'test' ? (
                        <Panel title="Test samples" subtitle="Matches from this rule test — never recorded as findings">
                            {(run.test_samples || []).length === 0 ? <Empty title={terminal ? 'No matches in the scanned surfaces' : 'Test running…'} /> : (
                                <div className="space-y-3 p-4">
                                    {run.test_samples.map((s, i) => (
                                        <div key={i} className="rounded-lg border border-slate-200 p-3">
                                            <div className="flex flex-wrap items-center gap-2"><SeverityBadge severity={s.severity} /><ConfidenceBadge confidence={s.confidence} /><span className="text-sm font-semibold">{s.title}</span></div>
                                            <p className="crm-mono mt-1 text-xs text-slate-500">{s.subject?.table} · row {s.subject?.row_id ?? '—'} · {s.subject?.field || s.subject?.item}</p>
                                            {(s.evidence?.excerpts || []).map((x, j) => <InertCode key={j} className="mt-2">{x}</InertCode>)}
                                        </div>
                                    ))}
                                </div>
                            )}
                        </Panel>
                    ) : null}
                </div>

                <EventLog runId={run.id} terminal={terminal} />
            </div>

            {terminal ? <CoveragePanel runId={run.id} /> : null}
        </div>
    );
}

export default function RunsTab({ canOperate, onOpenRun, onOpenFindings, openRunId, onCloseRun }) {
    const [filters, setFilters] = useState({ status: '', mode: '', page: 1 });
    const [expanded, setExpanded] = useState(null);
    const query = useQuery({
        queryKey: ['dbo', 'passes', filters],
        queryFn: () => dbObservatory.passes({ ...filters, status: filters.status || undefined, mode: filters.mode || undefined }),
        placeholderData: keepPreviousData,
        refetchInterval: (q) => (q.state.error ? 60_000 : (q.state.data?.data || []).some((p) => !TERMINAL_PASS.includes(p.status)) ? 5_000 : 30_000),
        enabled: !openRunId,
    });

    if (openRunId) {
        return <RunDetail runId={openRunId} canOperate={canOperate} onBack={onCloseRun} onOpenFindings={onOpenFindings} />;
    }

    return (
        <Panel
            title="Scan passes"
            subtitle="Each pass holds one bounded run per market. Budget-limited runs continue their sweep in follow-on passes."
            action={(
                <div className="flex flex-wrap gap-2">
                    <select aria-label="Status filter" className="crm-select py-1.5 text-sm" value={filters.status} onChange={(e) => setFilters({ ...filters, status: e.target.value, page: 1 })}>
                        <option value="">All statuses</option>
                        {['queued', 'running', 'paused', 'stopping', 'stopped', 'completed', 'completed_with_gaps', 'completed_with_errors'].map((s) => <option key={s} value={s}>{humanize(s)}</option>)}
                    </select>
                    <select aria-label="Mode filter" className="crm-select py-1.5 text-sm" value={filters.mode} onChange={(e) => setFilters({ ...filters, mode: e.target.value, page: 1 })}>
                        <option value="">Scans and tests</option>
                        <option value="scan">Scans</option>
                        <option value="test">Rule tests</option>
                        <option value="preflight">Preflights</option>
                    </select>
                </div>
            )}
        >
            {query.isLoading ? <Loading rows={4} /> : query.isError ? <ErrorState error={query.error} onRetry={query.refetch} /> : (query.data.data || []).length === 0 ? (
                <Empty title="No scans yet">Configure a market connection, pass its preflight, then use Scan now. Scheduled scans appear here too.</Empty>
            ) : (
                <div className="divide-y divide-slate-100">
                    {query.data.data.map((pass) => (
                        <div key={pass.id}>
                            <button type="button" onClick={() => setExpanded(expanded === pass.id ? null : pass.id)} className="grid w-full grid-cols-[1fr_auto] items-center gap-3 px-4 py-3 text-left transition hover:bg-slate-50 md:grid-cols-[minmax(0,1.4fr)_8rem_10rem_9rem_7rem]">
                                <div className="min-w-0">
                                    <p className="truncate text-sm font-semibold text-slate-900">Pass {pass.id} · <span className="capitalize">{pass.profile}</span>{pass.mode !== 'scan' ? ` · ${pass.mode}` : ''}{pass.rules ? ` · ${pass.rules.join(', ')}` : ''}</p>
                                    <p className="text-xs text-slate-500">{humanize(pass.trigger)} · {fmtDateTime(pass.created_at)}{pass.sweep_id ? ` · sweep ${pass.sweep_id}` : ''}</p>
                                </div>
                                <div className="hidden md:block"><Meter fraction={pass.markets_total ? pass.markets_done / pass.markets_total : 0} /><p className="mt-1 text-xs text-slate-500">{pass.markets_done}/{pass.markets_total} markets</p></div>
                                <div className="hidden text-xs text-slate-500 md:block">{Object.entries(pass.run_statuses).map(([s, n]) => `${n} ${humanize(s).toLowerCase()}`).join(' · ')}</div>
                                <div className="hidden text-xs md:block">{pass.findings_new ? <span className="font-semibold text-rose-700">{pass.findings_new} new/reopened</span> : <span className="text-slate-400">no new findings</span>}</div>
                                <div className="justify-self-end"><Status value={pass.status} /></div>
                            </button>
                            {expanded === pass.id ? (
                                <div className="space-y-2 bg-slate-50/70 px-4 py-3">
                                    <PassControls pass={pass} canOperate={canOperate} />
                                    <div className="overflow-x-auto">
                                        <table className="w-full min-w-[36rem] text-sm">
                                            <tbody className="divide-y divide-slate-200">
                                                {pass.runs.map((run) => (
                                                    <tr key={run.id} className="cursor-pointer hover:bg-white" onClick={() => onOpenRun(run.id)}>
                                                        <td className="px-2 py-2 font-semibold text-slate-800">{run.market}</td>
                                                        <td className="w-40 px-2 py-2"><Meter fraction={run.progress?.fraction} tone={['incomplete', 'partial'].includes(run.status) ? 'amber' : 'teal'} /></td>
                                                        <td className="px-2 py-2 text-xs text-slate-500">{run.progress?.current_surface && !TERMINAL_RUN.includes(run.status) ? run.progress.current_surface : `${fmtNumber(run.metrics?.rows_read)} rows · ${run.active_seconds}s`}</td>
                                                        <td className="px-2 py-2 text-right"><Status value={run.status} />{run.error_code ? <span className="ml-1 text-xs text-rose-700">{humanize(run.error_code)}</span> : null}</td>
                                                    </tr>
                                                ))}
                                            </tbody>
                                        </table>
                                    </div>
                                </div>
                            ) : null}
                        </div>
                    ))}
                    <div className="flex items-center justify-between px-4 py-3 text-sm text-slate-500">
                        <span>{query.data.meta.total} passes</span>
                        <div className="flex gap-2">
                            <button type="button" className="crm-btn-secondary px-3 py-1" disabled={filters.page <= 1} onClick={() => setFilters({ ...filters, page: filters.page - 1 })}>Previous</button>
                            <button type="button" className="crm-btn-secondary px-3 py-1" disabled={filters.page >= query.data.meta.last_page} onClick={() => setFilters({ ...filters, page: filters.page + 1 })}>Next</button>
                        </div>
                    </div>
                </div>
            )}
        </Panel>
    );
}
