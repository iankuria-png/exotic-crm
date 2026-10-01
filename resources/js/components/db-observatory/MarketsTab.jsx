import React, { useEffect, useState } from 'react';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import dbObservatory from '../../services/dbObservatory';
import { LoadOverridePrompt } from './LoadOverride';
import { useToast } from '../ToastProvider';
import {
    apiError, Drawer, Empty, ErrorState, fmtAgo, fmtBytes, fmtDateTime, humanize, InertCode, Loading, Panel, Status,
} from './shared';

function ConnectionForm({ platformId, onSaved }) {
    const toast = useToast();
    const queryClient = useQueryClient();
    const connections = useQuery({ queryKey: ['dbo', 'connections'], queryFn: dbObservatory.connections });
    const row = (connections.data?.data || []).find((c) => c.platform_id === platformId);
    const c = row?.connection;
    const [form, setForm] = useState(null);
    const [result, setResult] = useState(null);

    useEffect(() => {
        if (!row) return;
        setForm({
            host: c?.host ?? row.suggested.host ?? '',
            port: c?.port ?? 3306,
            socket: c?.socket ?? '',
            database: c?.database ?? row.suggested.database ?? '',
            prefix: c?.prefix ?? row.suggested.prefix ?? 'wp_',
            username: '',
            password: '',
            tls_mode: c?.tls_mode ?? 'none',
            tls_ca: '',
            host_group: c?.host_group ?? '',
            enabled: c?.enabled ?? false,
            revision: c?.revision ?? null,
        });
    }, [row?.platform_id, c?.revision]); // eslint-disable-line react-hooks/exhaustive-deps

    const save = useMutation({
        mutationFn: () => dbObservatory.updateConnection(platformId, {
            ...form,
            port: Number(form.port) || 3306,
            socket: form.socket || null,
            host: form.host || null,
            username: form.username || null,
            password: form.password || null,
            tls_ca: form.tls_ca || null,
        }),
        onSuccess: (res) => {
            setResult(null);
            toast.success(res.preflight_status === 'passed' ? 'Connection saved.' : 'Connection saved — run preflight to approve it.');
            queryClient.invalidateQueries({ queryKey: ['dbo'] });
            onSaved?.();
        },
        onError: (e) => toast.error(apiError(e)),
    });

    const preflight = useMutation({
        mutationFn: (reason) => dbObservatory.preflight(platformId, reason ? { override_reason: reason } : {}),
        onSuccess: (res) => { setResult(res); toast.success('Preflight passed.'); queryClient.invalidateQueries({ queryKey: ['dbo'] }); },
        onError: (e) => { setResult(e?.response?.data || { status: 'failed', message: apiError(e) }); queryClient.invalidateQueries({ queryKey: ['dbo'] }); },
    });

    if (connections.isLoading || !form) return <Loading rows={3} />;
    if (connections.isError) return <ErrorState error={connections.error} onRetry={connections.refetch} />;

    const field = (key, label, props = {}) => (
        <label className="block text-xs font-semibold text-slate-600">
            {label}
            <input className="crm-input mt-1" value={form[key] ?? ''} onChange={(e) => setForm({ ...form, [key]: e.target.value })} {...props} />
        </label>
    );
    const shown = result || (c?.preflight_error ? { status: 'failed', message: c.preflight_error } : null);

    return (
        <div className="space-y-4">
            <div className="rounded-lg border border-sky-200 bg-sky-50 px-3 py-2 text-xs text-sky-900">
                Use a <span className="font-semibold">dedicated SELECT-only</span> MySQL/MariaDB account for this market's schema. The scanner never falls back to the payment/sync credentials. Remote hosts require verified TLS; secrets are write-only and encrypted.
            </div>
            <div className="grid gap-3 sm:grid-cols-2">
                {field('host', 'Host', { placeholder: 'localhost or db.example.com' })}
                {field('port', 'Port', { type: 'number' })}
                {field('socket', 'Unix socket (optional)', { placeholder: '/var/lib/mysql/mysql.sock' })}
                {field('database', 'Database')}
                {field('prefix', 'Table prefix')}
                {field('host_group', 'Host group', { placeholder: 'auto from host' })}
                {field('username', c?.username_configured ? 'Username (leave blank to keep)' : 'Username', { autoComplete: 'off' })}
                {field('password', c?.password_configured ? 'Password (leave blank to keep)' : 'Password', { type: 'password', autoComplete: 'new-password' })}
                <label className="block text-xs font-semibold text-slate-600">
                    TLS
                    <select className="crm-select mt-1 w-full" value={form.tls_mode} onChange={(e) => setForm({ ...form, tls_mode: e.target.value })}>
                        <option value="none">None (local host or socket only)</option>
                        <option value="verify">Verify server certificate</option>
                    </select>
                </label>
                <label className="flex items-center gap-2 self-end pb-2 text-sm text-slate-700">
                    <input type="checkbox" checked={Boolean(form.enabled)} onChange={(e) => setForm({ ...form, enabled: e.target.checked })} />
                    Enabled for scans
                </label>
            </div>
            {form.tls_mode === 'verify' ? (
                <label className="block text-xs font-semibold text-slate-600">CA certificate (PEM, optional{c?.tls_ca_configured ? '; leave blank to keep' : ''})
                    <textarea className="crm-input crm-mono mt-1 min-h-[5rem] text-xs" value={form.tls_ca} onChange={(e) => setForm({ ...form, tls_ca: e.target.value })} />
                </label>
            ) : null}
            <div className="flex flex-wrap items-center gap-2">
                <button type="button" className="crm-btn-primary" disabled={save.isPending} onClick={() => save.mutate()}>{save.isPending ? 'Saving…' : 'Save connection'}</button>
                <button type="button" className="crm-btn-secondary" disabled={!c || preflight.isPending} onClick={() => preflight.mutate()}>{preflight.isPending ? 'Running preflight…' : 'Run preflight'}</button>
                {c ? <span className="flex items-center gap-1.5 text-xs text-slate-500">Config v{c.config_version} · preflight <Status value={c.preflight_status} /> {c.preflight_at ? fmtAgo(c.preflight_at) : ''}</span> : null}
            </div>
            {shown?.code === 'load' && shown.load ? (
                <LoadOverridePrompt key={`${platformId}-${c?.config_version}`} load={shown.load} market={row.market || row.name || 'this market'} preflight pending={preflight.isPending || save.isPending} onRun={(reason) => preflight.mutate(reason)} />
            ) : null}
            <p className="text-xs text-slate-500">Changing host, database, prefix or credentials creates a new configuration version and requires a fresh preflight before any scan. Preflight reads only identity, grants, session settings and table names — never row values.</p>
            {shown && !(shown.code === 'load' && shown.load) ? (
                <div className={`rounded-lg border px-3 py-2 text-sm ${shown.status === 'passed' ? 'border-emerald-200 bg-emerald-50 text-emerald-900' : 'border-rose-200 bg-rose-50 text-rose-900'}`}>
                    <p className="font-semibold">{shown.message}</p>
                    {shown.capabilities?.engine ? <p className="mt-1 text-xs">Engine {shown.capabilities.engine} · TLS {shown.capabilities.tls} · timeout {shown.capabilities.statement_timeout_seconds}s</p> : null}
                    {shown.capabilities?.grant_summary?.length ? <InertCode className="mt-2 !text-slate-200">{shown.capabilities.grant_summary.join('\n')}</InertCode> : null}
                </div>
            ) : null}
        </div>
    );
}

function Inventory({ platformId }) {
    const query = useQuery({ queryKey: ['dbo', 'inventory', platformId], queryFn: () => dbObservatory.inventory(platformId) });
    if (query.isLoading) return <Loading rows={5} />;
    if (query.isError) return <ErrorState error={query.error} onRetry={query.refetch} />;
    const s = query.data.snapshot;
    if (!s) return <Empty title="No inventory yet">The first completed inventory read records the baseline that drift rules compare against.</Empty>;
    const core = s.core || {};

    const Section = ({ title, complete, children }) => (
        <section className="rounded-lg border border-slate-200">
            <header className="flex items-center justify-between border-b border-slate-100 px-3 py-2">
                <h4 className="text-xs font-semibold uppercase tracking-[0.1em] text-slate-600">{title}</h4>
                {complete === false ? <Status value="incomplete" /> : null}
            </header>
            <div className="px-3 py-2 text-sm">{children}</div>
        </section>
    );

    return (
        <div className="space-y-3">
            <p className="text-xs text-slate-500">Snapshot from run {s.run_id} · {fmtDateTime(s.taken_at)} · {s.profile} profile. Emails are masked.</p>
            <div className="grid gap-3 lg:grid-cols-2">
                <Section title={`Administrators (${s.administrators.length})`} complete={s.completeness.administrators}>
                    <ul className="space-y-1">
                        {s.administrators.map((a) => <li key={a.id} className="text-xs"><span className="crm-mono font-semibold">#{a.id} {a.login}</span> · {a.email_masked} · registered {a.registered}</li>)}
                    </ul>
                    {s.hidden_capabilities ? <p className="mt-1 text-xs font-semibold text-rose-700">{s.hidden_capabilities} privileged capability row(s) under other keys — see findings.</p> : null}
                </Section>
                <Section title="Site" complete={s.completeness.core_options}>
                    <dl className="grid grid-cols-[7rem_1fr] gap-y-0.5 text-xs">
                        {['siteurl', 'home', 'blog_public', 'admin_email_masked', 'users_can_register', 'default_role', 'permalink_structure', 'template', 'stylesheet', 'db_version'].map((k) => (
                            <React.Fragment key={k}><dt className="text-slate-500">{k.replace('_masked', '')}</dt><dd className="crm-mono truncate">{String(core[k] ?? '—')}</dd></React.Fragment>
                        ))}
                    </dl>
                </Section>
                <Section title={`Active plugins (${(core.active_plugins || []).length})`} complete={s.completeness.core_options}>
                    <ul className="crm-mono columns-1 text-xs sm:columns-2">{(core.active_plugins || []).map((p) => <li key={p} className="truncate">{p}</li>)}</ul>
                </Section>
                <Section title={`Cron (${core.cron?.events ?? 0} events · ${core.cron?.hook_count ?? 0} hooks)`}>
                    <p className="text-xs text-slate-500">{core.cron?.overdue ? `${core.cron.overdue} overdue by 24h+` : 'None overdue'}</p>
                    <ul className="crm-mono mt-1 max-h-40 overflow-y-auto text-xs">{Object.entries(core.cron?.hooks || {}).map(([h, n]) => <li key={h}>{h} <span className="text-slate-400">×{n}</span></li>)}</ul>
                </Section>
                <Section title="Triggers, events and routines" complete={s.triggers?.complete}>
                    {s.triggers?.visibility === 'unverified' ? <p className="mb-1 text-xs text-amber-700">Visibility unverified: a SELECT-only account may not see every trigger. An empty list is not proof of none.</p> : null}
                    {[...(s.triggers?.data || []), ...(s.events_routines?.data || [])].length === 0 ? <p className="text-xs text-slate-500">None visible.</p> : (
                        <ul className="space-y-1 text-xs">
                            {(s.triggers?.data || []).map((t) => <li key={t.name}><span className="crm-mono font-semibold">{t.name}</span> · {t.timing} {t.event} on {t.table}</li>)}
                            {(s.events_routines?.data || []).map((t) => <li key={t.name}><span className="crm-mono font-semibold">{t.type} {t.name}</span></li>)}
                        </ul>
                    )}
                </Section>
                <Section title={`Tables (${s.table_count})`} complete={s.completeness.tables}>
                    {s.autoload ? <p className="mb-1 text-xs text-slate-600">Autoload {fmtBytes(s.autoload.bytes)} across {s.autoload.count} options (engine view; runtime filters not observable).</p> : null}
                    <ul className="crm-mono max-h-40 overflow-y-auto text-xs">{Object.entries(s.tables).map(([t, m]) => <li key={t}>{t} <span className="text-slate-400">{fmtBytes(m.bytes)} est.</span></li>)}</ul>
                </Section>
            </div>
            {s.outbound_domains?.length ? (
                <Section title={`Outbound domains (${s.outbound_domains.length})`}>
                    <p className="crm-mono text-xs leading-relaxed text-slate-700">{s.outbound_domains.join(' · ')}</p>
                </Section>
            ) : null}
            <Section title="Change timeline">
                {query.data.timeline.length === 0 ? <p className="text-xs text-slate-500">No changes between recorded snapshots.</p> : (
                    <ol className="space-y-2 border-l border-slate-200 pl-3">
                        {query.data.timeline.map((t, i) => (
                            <li key={i} className="text-xs"><span className="font-semibold text-slate-800">{fmtDateTime(t.taken_at)}</span> · run {t.run_id}<ul className="text-slate-600">{t.changes.map((c) => <li key={c}>{c}</li>)}</ul></li>
                        ))}
                    </ol>
                )}
            </Section>
        </div>
    );
}

export default function MarketsTab({ canConfigure, canOperate, onOpenFindings, onOpenRun, onScanMarket }) {
    const query = useQuery({ queryKey: ['dbo', 'markets'], queryFn: dbObservatory.markets, refetchInterval: 30_000 });
    const [open, setOpen] = useState(null);
    const [view, setView] = useState('inventory');
    const [filter, setFilter] = useState('');

    if (query.isLoading) return <Loading rows={6} />;
    if (query.isError) return <ErrorState error={query.error} onRetry={query.refetch} />;

    const rows = (query.data.data || []).filter((m) => !filter || m.market.toLowerCase().includes(filter.toLowerCase()));
    const selected = (query.data.data || []).find((m) => m.platform_id === open);

    return (
        <>
            <Panel
                title="Markets"
                subtitle="Connection, coverage freshness and open findings per market. A stale or incomplete sweep is never shown as healthy."
                action={<input aria-label="Filter markets" className="crm-input w-48 py-1.5" placeholder="Filter markets" value={filter} onChange={(e) => setFilter(e.target.value)} />}
                bodyClass="overflow-x-auto"
            >
                {rows.length === 0 ? <Empty title="No markets in your scope" /> : (
                    <table className="w-full min-w-[56rem] text-sm">
                        <thead>
                            <tr className="border-b border-slate-100 text-left text-xs uppercase tracking-wide text-slate-500">
                                <th className="px-4 py-2">Market</th><th className="px-2 py-2">Reader</th><th className="px-2 py-2">Last sweep</th>
                                <th className="px-2 py-2">Coverage age</th><th className="px-2 py-2 text-right">Critical</th><th className="px-2 py-2 text-right">Warn</th>
                                <th className="px-2 py-2 text-right">Malware</th><th className="px-4 py-2" />
                            </tr>
                        </thead>
                        <tbody className="divide-y divide-slate-100">
                            {rows.map((m) => {
                                const stale = m.coverage_age_hours === null || m.coverage_age_hours > 48;
                                return (
                                    <tr key={m.platform_id} className="hover:bg-slate-50">
                                        <td className="px-4 py-2.5">
                                            <button type="button" className="text-left" onClick={() => { setOpen(m.platform_id); setView('inventory'); }}>
                                                <p className="font-semibold text-slate-900 hover:text-teal-700">{m.market}</p>
                                                <p className="text-xs text-slate-500">{m.domain} · health {m.health_status || 'unknown'}</p>
                                            </button>
                                        </td>
                                        <td className="px-2 py-2.5">
                                            {m.connection.configured ? (
                                                <div className="flex flex-wrap items-center gap-1">
                                                    <Status value={m.connection.preflight_status} label={`preflight ${m.connection.preflight_status}`} />
                                                    {!m.connection.enabled ? <Status value="stopped" label="disabled" /> : null}
                                                </div>
                                            ) : <span className="text-xs text-slate-400">Not configured</span>}
                                        </td>
                                        <td className="px-2 py-2.5">
                                            {m.last_sweep ? (
                                                <div><Status value={m.last_sweep.status} /><p className="mt-0.5 text-xs text-slate-500">{m.last_sweep.profile}{m.last_sweep.stop_reason ? ` · ${humanize(m.last_sweep.stop_reason)}` : ''}</p></div>
                                            ) : <span className="text-xs text-slate-400">Never scanned</span>}
                                        </td>
                                        <td className={`px-2 py-2.5 text-xs ${stale ? 'font-semibold text-amber-700' : 'text-slate-600'}`}>
                                            {m.coverage_age_hours === null ? 'No finished sweep' : `${m.coverage_age_hours} h`}
                                        </td>
                                        <td className="crm-mono px-2 py-2.5 text-right">{m.open.critical ? <button type="button" className="font-semibold text-rose-700 hover:underline" onClick={() => onOpenFindings({ platform_id: m.platform_id, severity: 'critical', status: 'active' })}>{m.open.critical}</button> : <span className="text-slate-300">0</span>}</td>
                                        <td className="crm-mono px-2 py-2.5 text-right">{m.open.warn || <span className="text-slate-300">0</span>}</td>
                                        <td className="crm-mono px-2 py-2.5 text-right">{m.open.malware ? <button type="button" className="font-semibold text-rose-700 hover:underline" onClick={() => onOpenFindings({ platform_id: m.platform_id, category: 'malware', status: 'active' })}>{m.open.malware}</button> : <span className="text-slate-300">0</span>}</td>
                                        <td className="px-4 py-2.5 text-right">
                                            <div className="flex justify-end gap-2">
                                                {m.last_run ? <button type="button" className="text-xs font-semibold text-teal-700 hover:underline" onClick={() => onOpenRun(m.last_run.id)}>Last run</button> : null}
                                                {canConfigure ? <button type="button" className="text-xs font-semibold text-slate-600 hover:underline" onClick={() => { setOpen(m.platform_id); setView('connection'); }}>Connection</button> : null}
                                                {canOperate && m.connection.preflight_status === 'passed' && m.connection.enabled ? <button type="button" className="text-xs font-semibold text-slate-600 hover:underline" onClick={() => onScanMarket(m.platform_id)}>Scan</button> : null}
                                            </div>
                                        </td>
                                    </tr>
                                );
                            })}
                        </tbody>
                    </table>
                )}
            </Panel>

            <Drawer
                open={Boolean(selected)}
                onClose={() => setOpen(null)}
                title={selected?.market || 'Market'}
                subtitle={selected ? (
                    <div className="flex gap-2 pt-1">
                        {['inventory', ...(canConfigure ? ['connection'] : [])].map((v) => (
                            <button key={v} type="button" onClick={() => setView(v)} className={`rounded-lg border px-3 py-1 text-xs font-semibold ${view === v ? 'border-teal-300 bg-teal-50 text-teal-800' : 'border-slate-200 text-slate-600'}`}>
                                {v === 'inventory' ? 'Inventory & drift' : 'Reader connection'}
                            </button>
                        ))}
                    </div>
                ) : null}
                width="max-w-3xl"
            >
                {selected ? (view === 'connection' && canConfigure ? <ConnectionForm platformId={selected.platform_id} /> : <Inventory platformId={selected.platform_id} />) : null}
            </Drawer>
        </>
    );
}
