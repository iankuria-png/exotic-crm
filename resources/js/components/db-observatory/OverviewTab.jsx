import React from 'react';
import { useQuery } from '@tanstack/react-query';
import dbObservatory from '../../services/dbObservatory';
import MetricCard from '../MetricCard';
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

export default function OverviewTab({ onOpenFindings, onOpenRun, onConfigure }) {
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

    return (
        <div className="space-y-4">
            <ScannerStateBanner scanner={d.scanner} onConfigure={d.permissions?.configure ? onConfigure : null} />

            <div className="grid grid-cols-2 gap-3 lg:grid-cols-5">
                <MetricCard
                    label="Critical open"
                    value={d.counts.critical_open}
                    hint={`+${d.counts.critical_new_24h} in the last 24h`}
                    tone={d.counts.critical_open ? 'danger' : 'success'}
                    onClick={() => onOpenFindings({ severity: 'critical', status: 'active' })}
                />
                <MetricCard
                    label="Warnings open"
                    value={d.counts.warn_open}
                    hint={`${d.counts.resolved_24h} resolved by verification in 24h`}
                    tone="warning"
                    onClick={() => onOpenFindings({ severity: 'warn', status: 'active' })}
                />
                <MetricCard
                    label="Coverage 24h"
                    value={`${coverage.markets_covered_24h}/${coverage.markets_eligible}`}
                    hint={coverage.unreachable?.length ? `${coverage.unreachable.length} unreachable` : `${coverage.markets_configured} markets configured`}
                    subHint="Markets with a finished sweep in the last 24 hours"
                    tone={coverage.markets_eligible && coverage.markets_covered_24h < coverage.markets_eligible ? 'warning' : 'accent'}
                />
                <MetricCard label="Drift events 24h" value={d.counts.drift_24h} hint="Admins, plugins, theme, settings" tone="slate" onClick={() => onOpenFindings({ status: 'active', kind: 'drift' })} />
                <MetricCard
                    label="Scanner health"
                    value={<Status value={health.status} />}
                    hint={`queue ${health.queued_runs ?? 0} · running ${health.running_runs ?? 0} · p95 ${health.p95_query_ms_24h ?? 0} ms`}
                    subHint={`Worker ${fmtAge(health.worker_heartbeat_age_seconds)} · dispatcher ${fmtAge(health.dispatcher_heartbeat_age_seconds)}`}
                    tone={health.status === 'degraded' ? 'danger' : 'default'}
                />
            </div>

            <section className="crm-surface overflow-hidden">
                <div className="flex flex-wrap items-center gap-x-6 gap-y-3 bg-gradient-to-r from-slate-950 via-slate-900 to-slate-800 px-5 py-4 text-white">
                    <div className="min-w-[10rem]">
                        <p className="text-[10px] font-semibold uppercase tracking-[0.2em] text-slate-400">Malware · database surfaces</p>
                        <p className="mt-1 text-sm text-slate-300">Open findings by detection confidence</p>
                    </div>
                    {[
                        ['confirmed', 'Confirmed indicator', 'text-rose-300'],
                        ['strong', 'Strong behavioral match', 'text-orange-300'],
                        ['needs_review', 'Needs review', 'text-sky-300'],
                    ].map(([key, label, tone]) => (
                        <button
                            key={key}
                            type="button"
                            onClick={() => onOpenFindings({ category: 'malware', confidence: key, status: 'active' })}
                            className="group rounded-lg px-3 py-1.5 text-left transition hover:bg-white/10 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-teal-400"
                        >
                            <span className={`crm-mono block text-2xl font-semibold ${tone}`}>{d.malware[key]}</span>
                            <span className="text-xs text-slate-300 group-hover:text-white">{label}</span>
                        </button>
                    ))}
                    <p className="ml-auto max-w-sm text-xs leading-relaxed text-slate-400">
                        A sweep with no findings means no database indicators were found in the surfaces it covered — never that the site is clean. Files and live pages are not scanned.
                    </p>
                </div>
            </section>

            <div className="grid gap-4 xl:grid-cols-[1.55fr_1fr]">
                <Panel title="Posture by market" subtitle="Open findings by category. Each cell opens the filtered findings." bodyClass="overflow-x-auto p-3">
                    {posture.length === 0 ? (
                        <Empty title="No open findings in your markets">
                            Either nothing has been scanned yet, or every finding has been resolved or triaged. Check Markets for coverage freshness before reading this as good news.
                        </Empty>
                    ) : (
                        <table className="w-full min-w-[34rem] border-separate border-spacing-1 text-center text-xs">
                            <thead>
                                <tr>
                                    <th scope="col" className="px-2 py-1 text-left font-semibold text-slate-500">Market</th>
                                    {CATEGORIES.map((c) => <th key={c} scope="col" className="px-1 py-1 font-semibold capitalize text-slate-500">{c}</th>)}
                                </tr>
                            </thead>
                            <tbody>
                                {posture.map((row) => (
                                    <tr key={row.platform_id}>
                                        <th scope="row" className="truncate px-2 py-1 text-left text-sm font-semibold text-slate-800">{row.market}</th>
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
                </Panel>

                <div className="space-y-4">
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

                    <Panel title="Latest critical" bodyClass="divide-y divide-slate-100">
                        {(d.latest_critical || []).length === 0 ? (
                            <p className="px-4 py-5 text-sm text-slate-500">No open critical findings.</p>
                        ) : d.latest_critical.map((f) => (
                            <button key={f.id} type="button" onClick={() => onOpenFindings({ finding: f.id })} className="block w-full px-4 py-2.5 text-left transition hover:bg-slate-50">
                                <div className="flex items-center gap-2">
                                    <SeverityBadge severity={f.severity} />
                                    <ConfidenceBadge confidence={f.confidence} />
                                </div>
                                <p className="mt-1 text-sm font-medium text-slate-900"><span className="font-semibold">{f.market}</span> · {f.title}</p>
                                <p className="text-xs text-slate-500">first seen {fmtAgo(f.first_seen_at)}</p>
                            </button>
                        ))}
                    </Panel>
                </div>
            </div>
        </div>
    );
}
