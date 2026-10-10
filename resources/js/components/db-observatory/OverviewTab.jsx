import React from 'react';
import { useQuery } from '@tanstack/react-query';
import dbObservatory from '../../services/dbObservatory';
import { useState } from 'react';
import { activityDate, domainLabel, FAMILIES } from './interpretation';
import { ConfidenceBadge, Empty, ErrorState, fmtAge, fmtAgo, Loading, Meter, Panel, SeverityBadge, Status } from './shared';

const CATEGORIES = ['malware', 'access', 'persistence', 'content', 'config', 'seo', 'hygiene'];

function cellTone(cell) {
    if (!cell) return 'border border-slate-100 bg-white text-slate-300';
    if (cell.critical) return 'bg-rose-600 text-white shadow-sm';
    if (cell.warn) return 'bg-amber-100 text-amber-900';
    return 'bg-slate-100 text-slate-600';
}

export function ScannerStateBanner({ scanner, onConfigure }) {
    if (!scanner) return null;
    let tone = null;
    let message = null;
    if (!scanner.rules_enabled) {
        tone = 'border-amber-300 bg-amber-50 text-amber-900';
        message = 'No rule packs are installed yet. Run "php artisan crm:db-scan-sync-packs" on the server after migrating.';
    } else if (scanner.emergency_stop) {
        tone = 'border-rose-300 bg-rose-50 text-rose-900';
        message = 'Emergency stop is on: no scans or credential probes will run until an administrator clears it.';
    } else if (!scanner.deployment_enabled) {
        tone = 'border-slate-300 bg-slate-50 text-slate-800';
        message = 'The scanner is not switched on for this deployment (DB_SCANNER_ENABLED). Credentials can be configured and preflighted; scans and schedules stay off.';
    } else if (!scanner.enabled) {
        tone = 'border-slate-300 bg-slate-50 text-slate-800';
        message = 'Scanning is turned off. Credential preflight still works so new market connections can be approved first.';
    } else if (scanner.paused) {
        tone = 'border-amber-300 bg-amber-50 text-amber-900';
        message = 'All scanning is paused. Progress is kept; resume from Schedules & limits.';
    }
    if (!message) return null;

    return (
        <div className={`flex flex-wrap items-center justify-between gap-3 rounded-xl border px-4 py-3 text-sm ${tone}`} role="status">
            <p className="font-medium">{message}</p>
            {onConfigure ? <button type="button" onClick={onConfigure} className="text-sm font-semibold underline underline-offset-2">Scanner controls</button> : null}
        </div>
    );
}

export default function OverviewTab({ onOpenFindings, onOpenRun, onConfigure, onOpenMarkets, markets = [] }) {
    const [showAllMarkets, setShowAllMarkets] = useState(false);
    const query = useQuery({
        queryKey: ['dbo', 'overview'],
        queryFn: dbObservatory.overview,
        refetchInterval: (q) => (q.state.error ? 60_000 : 15_000),
    });

    if (query.isLoading) return <Loading rows={5} />;
    if (query.isError) return <ErrorState error={query.error} onRetry={query.refetch} />;

    const d = query.data;
    const health = d.health || {};
    const coverage = d.coverage || {};
    const posture = d.posture || [];
    const work = d.work || {};
    const counts = Object.fromEntries((d.evidence_families || []).map(f => [f.key, f.findings]));
    const readyMarkets = [...markets].sort((a, b) => (b.open?.critical || 0) - (a.open?.critical || 0) || Number(a.reader_ready) - Number(b.reader_ready) || a.market.localeCompare(b.market));

    return (
        <div className="space-y-4">
            <ScannerStateBanner scanner={d.scanner} onConfigure={d.permissions?.configure ? onConfigure : null} />

            <section className="crm-surface overflow-hidden" aria-label="Review workload">
                <div className="grid divide-y divide-slate-200 md:grid-cols-3 md:divide-x md:divide-y-0">
                    {[
                        ['Needs follow-up', work.needs_follow_up ?? '—', 'finding records', 'Open risks needing investigation or further evidence. Historical activity can still require action.', () => onOpenFindings({ workflow: 'needs_follow_up', status: 'active' })],
                        ['Awaiting verification', work.awaiting_verification ?? '—', 'reported fixes', `${work.resolved_24h || 0} findings verified resolved in 24h. A remediation report still needs compatible verification.`, () => onOpenFindings({ workflow: 'awaiting_verification', status: 'active' })],
                        ['Reader readiness', `${coverage.markets_eligible || 0}/${coverage.markets_total || 0}`, 'market records', `${coverage.markets_blocked || 0} need reader setup or testing. Readiness does not guarantee scan admission.`, onOpenMarkets],
                    ].map(([label, value, unit, hint, action]) => <button key={label} type="button" onClick={action} className="px-5 py-5 text-left hover:bg-slate-50 focus-visible:ring-2 focus-visible:ring-inset focus-visible:ring-teal-500"><p className="text-sm font-semibold text-slate-700">{label}</p><p className="mt-2 text-3xl font-semibold tracking-tight text-slate-900">{value} <span className="text-xs font-normal text-slate-500">{unit}</span></p><p className="mt-2 text-xs leading-5 text-slate-600">{hint}</p></button>)}
                </div>
            </section>
            <Panel title="Evidence to review" subtitle="Counts are finding records, not infected files or independent incidents. Open a family to review related evidence." bodyClass="p-4">
                <div className="grid gap-2 sm:grid-cols-2 lg:grid-cols-3">{['confirmed_payload', 'stored_script', 'login_campaign', 'permissions', 'failed_attempt', 'access_review'].map(key => <button key={key} type="button" onClick={() => onOpenFindings({ family: key, status: 'active' })} className="flex min-h-12 items-center justify-between gap-3 rounded-lg border border-slate-200 px-3 py-3 text-left text-sm hover:border-teal-400 hover:bg-teal-50 focus-visible:ring-2 focus-visible:ring-teal-500"><span className="font-medium text-slate-800">{FAMILIES[key]}</span><span className="font-semibold tabular-nums">{counts[key] || 0}</span></button>)}</div>
                <p className="mt-3 max-w-3xl text-xs leading-5 text-slate-600">Login correlations are account-access evidence. Stored scripts need ownership and execution review. Zero confirmed indicators only describes database surfaces inspected; files and live pages are outside these scans.</p>
                <div className="mt-3 flex flex-wrap gap-2">{Object.entries(FAMILIES).filter(([key]) => counts[key] && !['confirmed_payload', 'stored_script', 'login_campaign', 'permissions', 'failed_attempt', 'access_review'].includes(key)).map(([key, label]) => <button key={key} type="button" className="crm-btn-secondary text-xs" onClick={() => onOpenFindings({ family: key, status: 'active' })}>{label} · {counts[key]}</button>)}</div>
            </Panel>
            <section className="crm-surface flex flex-wrap justify-between gap-4 px-5 py-4" aria-label="Scanner operation and coverage">
                <div><p className="text-sm font-semibold text-slate-800">Scanner operation <Status value={health.status} /></p><p className="mt-1 text-xs text-slate-600">{health.queued_runs || 0} queued · {health.running_runs || 0} running · {health.stalled_runs || 0} stalled</p><p className="mt-1 text-xs text-slate-500">Worker {fmtAge(health.worker_heartbeat_age_seconds)} · dispatcher {fmtAge(health.dispatcher_heartbeat_age_seconds)}{health.oldest_waiting_age_seconds != null ? ` · oldest wait ${Math.round(health.oldest_waiting_age_seconds / 60)} min` : ''}</p></div>
                <div><p className="text-sm font-semibold text-slate-800">Database coverage in 24h</p><p className="mt-1 text-xs text-slate-600">{coverage.markets_complete_24h || 0} complete · {coverage.markets_gapped_24h || 0} finished with gaps · {coverage.markets_total || 0} market records</p><p className="mt-1 text-xs text-slate-500">Worker health and scan coverage are separate.</p></div>
            </section>
            <Panel title="Market review queue" subtitle="All market records include their domain, coverage and next action. No findings can mean unscanned or blocked." action={<button type="button" className="text-sm font-semibold text-teal-700 hover:underline" onClick={onOpenMarkets}>Market setup →</button>} bodyClass="overflow-x-auto">
                {!readyMarkets.length ? <p className="px-4 py-5 text-sm text-slate-600">Market readiness is loading. Open Markets to check setup and coverage.</p> : <table className="w-full min-w-[38rem] text-sm"><thead><tr className="border-b border-slate-200 text-left text-xs text-slate-500"><th className="px-4 py-3">Market / domain</th><th className="px-3 py-3">Evidence / owner</th><th className="px-3 py-3">Next action / scan</th></tr></thead><tbody className="divide-y divide-slate-100">{(showAllMarkets ? readyMarkets : readyMarkets.slice(0, 10)).map(m => <tr key={m.platform_id}><td className="px-4 py-3 align-top"><button type="button" className="font-semibold text-slate-900 hover:text-teal-700" onClick={() => onOpenFindings({ platform_id: m.platform_id, status: 'active' })}>{m.market}</button><p className="mt-1 text-xs text-slate-500">{domainLabel(m.domain)}</p><p className="mt-1 text-xs">{m.last_sweep ? <Status value={m.last_sweep.status} /> : 'Never scanned'}</p></td><td className="px-3 py-3 align-top"><button type="button" className="font-medium text-teal-700 hover:underline" onClick={() => onOpenFindings({ platform_id: m.platform_id, status: 'active' })}>{(m.open?.critical || 0) + (m.open?.warn || 0) + (m.open?.info || 0)} finding records</button><p className="mt-1 text-xs text-slate-600">{m.evidence_families?.login_campaign || 0} login · {m.evidence_families?.stored_script || 0} script</p><p className="mt-1 text-xs text-slate-500">{m.owners?.join(', ') || 'No review owner assigned'}</p></td><td className="px-3 py-3 align-top"><p className="max-w-sm text-xs leading-5 text-slate-700">{m.next_action || 'Review reader and coverage'}</p><p className="mt-1 text-xs text-slate-500">{m.next_schedule ? `Planned ${activityDate(m.next_schedule.due_at)}` : 'No eligible scan scheduled'}</p></td></tr>)}</tbody></table>}
                {readyMarkets.length > 10 ? <button type="button" className="w-full border-t border-slate-200 px-4 py-3 text-sm font-semibold text-teal-700 hover:bg-slate-50" onClick={() => setShowAllMarkets(!showAllMarkets)}>{showAllMarkets ? 'Show priority markets' : `Show all ${readyMarkets.length} markets`}</button> : null}
            </Panel>
            <details className="crm-surface p-4"><summary className="cursor-pointer text-sm font-semibold text-slate-700">Additional cross-market summaries</summary><div className="mt-4 grid gap-4 lg:grid-cols-2">
                <Panel title="Fleet triage" subtitle="Matching findings grouped across markets; roles also match their privileged capability definitions.">
                    {!d.fleet?.groups?.length ? <Empty title="No fleet findings yet">Scanned markets will appear here.</Empty> : <div className="divide-y divide-slate-100">{d.fleet.groups.slice(0, 8).map((g) => <button type="button" key={g.key} onClick={() => onOpenFindings({ rule_key: g.rule_key, identity: g.identity, definition_hash: g.definition_hash || undefined, status: 'active' })} className="flex w-full items-start justify-between gap-3 px-4 py-3 text-left hover:bg-slate-50 focus-visible:ring-2 focus-visible:ring-inset focus-visible:ring-teal-500"><span className="min-w-0"><span className="block truncate text-sm font-semibold text-slate-900">{g.identity}</span><span className="block text-xs text-slate-500">{g.rule_key} · {g.market_names.join(', ')}</span></span><span className="shrink-0 text-xs text-slate-600">{g.findings} findings · {g.markets} markets{g.critical ? ` · ${g.critical} critical` : ''}</span></button>)}</div>}
                    {d.fleet?.sampled ? <p className="px-4 py-2 text-xs text-amber-800">Summary limited to the 10,000 most recent open findings.</p> : null}
                </Panel>
                <Panel title="Shared indicators" subtitle="IPs, usernames, key names and plugins seen in findings on several markets. Shared presence alone does not establish an attack.">
                    {!d.fleet?.correlations?.length ? <Empty title="No shared indicators yet">Connect and scan more markets to compare evidence.</Empty> : <ul className="divide-y divide-slate-100">{d.fleet.correlations.slice(0, 8).map((c) => <li key={`${c.type}:${c.value}`} className="px-4 py-3"><div className="flex flex-wrap justify-between gap-2"><span className="crm-mono break-all text-sm font-semibold text-slate-900">{c.value}</span><span className="text-xs text-slate-600">{c.markets} markets{c.same_day_markets >= 2 ? ` · ${c.same_day_markets} on one day` : ''}</span></div><p className="mt-1 text-xs text-slate-500">{c.type.replaceAll('_', ' ')} · {c.market_names.join(', ')}</p></li>)}</ul>}
                </Panel>
            </div>
            </details>
            <details className="crm-surface self-start p-4"><summary className="cursor-pointer text-sm font-semibold text-slate-700">Advanced category counts</summary>                <Panel title="Raw finding counts by category" subtitle="Legacy categories include related evidence. Counts are findings, not infections; open a family above for clearer interpretation." bodyClass="overflow-x-auto p-3">
                    {posture.length === 0 ? (
                        <Empty title="No open findings in your markets">
                            Either nothing has been scanned yet, or every finding has been resolved or triaged. Check Markets for coverage freshness before reading this as good news.
                        </Empty>
                    ) : (
                        <table className="w-full min-w-[34rem] border-separate border-spacing-1 text-center text-xs">
                            <thead>
                                <tr>
                                    <th scope="col" className="px-2 py-1 text-left font-semibold text-slate-500">Market</th>
                                    {CATEGORIES.map((c) => <th key={c} scope="col" className="px-1 py-1 font-semibold capitalize text-slate-500">{c === 'malware' ? 'Indicators*' : c}</th>)}
                                </tr>
                            </thead>
                            <tbody>
                                {posture.map((row) => (
                                    <tr key={row.platform_id}>
                                        <th scope="row" className="truncate px-2 py-1 text-left text-sm font-semibold text-slate-800">{row.market}<span className="block text-xs font-normal text-slate-500">{domainLabel(row.domain)}</span></th>
                                        {CATEGORIES.map((c) => {
                                            const cell = row.categories?.[c];
                                            return (
                                                <td key={c} className="p-0">
                                                    <button
                                                        type="button"
                                                        disabled={!cell}
                                                        onClick={() => onOpenFindings({ platform_id: row.platform_id, category: c, status: 'active' })}
                                                        className={`crm-mono h-8 w-full rounded-md text-sm font-semibold transition hover:ring-2 hover:ring-teal-400 disabled:cursor-default disabled:hover:ring-0 ${cellTone(cell)}`}
                                                        aria-label={`${row.market} ${c}: ${cell?.total || 0} open`}
                                                    >
                                                        {cell?.total || '·'}
                                                    </button>
                                                </td>
                                            );
                                        })}
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    )}
                    {coverage.unreachable?.length ? (
                        <div className="mt-2 space-y-1 border-t border-slate-100 px-2 pt-2">
                            {coverage.unreachable.map((u) => (
                                <p key={u.platform_id} className="text-xs text-rose-700"><span className="font-semibold">{u.market}</span> · unreachable since {fmtAgo(u.at)} · {String(u.error_code || '').replace(/_/g, ' ')}</p>
                            ))}
                        </div>
                    ) : null}
                </Panel></details>

                <div className="grid items-start gap-4 lg:grid-cols-[.75fr_1.25fr]">
                    <Panel title="Running now" bodyClass="divide-y divide-slate-100">
                        {(d.running || []).length === 0 ? (
                            <p className="px-4 py-5 text-sm text-slate-500">No scans are queued or running.</p>
                        ) : d.running.map((pass) => {
                            const active = pass.runs.find((r) => r.status === 'running') || pass.runs[0];
                            const done = pass.markets_total ? pass.markets_done / pass.markets_total : 0;
                            return (
                                <button key={pass.id} type="button" onClick={() => active && onOpenRun(active.id)} className="block w-full px-4 py-3 text-left transition hover:bg-slate-50">
                                    <div className="flex items-center justify-between gap-2">
                                        <p className="text-sm font-semibold text-slate-900">Pass {pass.id} · <span className="capitalize">{pass.profile}</span> {pass.mode !== 'scan' ? `(${pass.mode})` : ''}</p>
                                        <Status value={pass.status} />
                                    </div>
                                    <div className="mt-2"><Meter fraction={done} /></div>
                                    <p className="mt-1.5 text-xs text-slate-500">
                                        {pass.markets_done}/{pass.markets_total} markets
                                        {active?.market ? ` · ${active.market}` : ''}
                                        {active?.progress?.current_surface ? ` · ${active.progress.current_surface}` : ''}
                                    </p>
                                </button>
                            );
                        })}
                    </Panel>

                    <Panel title="Recently detected critical evidence" bodyClass="divide-y divide-slate-100">
                        {(d.latest_critical || []).length === 0 ? (
                            <p className="px-4 py-5 text-sm text-slate-500">No open critical findings.</p>
                        ) : d.latest_critical.map((f) => (
                            <button key={f.id} type="button" onClick={() => onOpenFindings({ finding: f.id })} className="block w-full px-4 py-2.5 text-left transition hover:bg-slate-50">
                                <div className="flex items-center gap-2">
                                    <SeverityBadge severity={f.severity} />
                                    <ConfidenceBadge confidence={f.confidence} />
                                </div>
                                <p className="mt-1 text-sm font-medium text-slate-900"><span className="font-semibold">{f.market}</span> · {f.title}</p>
                                <p className="mt-1 text-xs text-slate-600">{f.interpretation?.event_at ? `${f.interpretation.event_label}: ${activityDate(f.interpretation.event_at, f.interpretation.event_precision)}` : f.linked_activity_start ? `Linked activity from ${activityDate(f.linked_activity_start)}` : 'Activity date not recorded'}</p><p className="text-xs text-slate-500">First detected {fmtAgo(f.first_seen_at)}</p>
                            </button>
                        ))}
                    </Panel>
            </div>
        </div>
    );
}
