import React, { useEffect, useState } from 'react';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import dbObservatory from '../../services/dbObservatory';
import { useToast } from '../ToastProvider';
import {
    apiError, Drawer, Empty, ErrorState, fmtAgo, fmtBytes, fmtDateTime, humanize, InertCode, Loading, Panel, Status,
} from './shared';

function ConnectionForm({ platformId, onSaved, onScan }) {
    const toast = useToast();
    const queryClient = useQueryClient();
    const connections = useQuery({ queryKey: ['dbo', 'connections'], queryFn: dbObservatory.connections });
    const row = (connections.data?.data || []).find((c) => c.platform_id === platformId);
    const c = row?.connection;
    const [form, setForm] = useState(null);
    const [result, setResult] = useState(null);
    const [dirty, setDirty] = useState(false);
    const [phase, setPhase] = useState(null);

    useEffect(() => {
        if (!row || dirty) return;
        setForm({
            host: c?.host ?? row.suggested.host ?? 'localhost', port: c?.port ?? 3306,
            socket: c?.socket ?? '', database: c?.database ?? row.suggested.database ?? '',
            prefix: c?.prefix ?? row.suggested.prefix ?? 'wp_', username: '', password: '',
            tls_mode: c?.tls_mode ?? 'none', tls_ca: '', host_group: c?.host_group ?? '',
            enabled: c?.enabled ?? true, load_gate_enabled: c?.load_gate_enabled ?? true,
            revision: c?.revision ?? null,
        });
    }, [row?.platform_id, c?.revision, dirty]); // eslint-disable-line react-hooks/exhaustive-deps

    const save = useMutation({
        mutationFn: async (test) => {
            setPhase('saving');
            const saved = await dbObservatory.updateConnection(platformId, {
                ...form, port: Number(form.port) || 3306, socket: form.socket || null,
                host: form.host || null, username: form.username || null,
                password: form.password || null, tls_ca: form.tls_ca || null,
            });
            // Refresh before clearing dirty, so the next edit uses the saved revision.
            await queryClient.invalidateQueries({ queryKey: ['dbo', 'connections'] });
            setForm((previous) => ({ ...previous, revision: saved.revision, username: '', password: '', tls_ca: '' }));
            setDirty(false);
            onSaved?.();
            if (test) {
                setPhase('testing');
                return dbObservatory.preflight(platformId);
            }
            return null;
        },
        onSuccess: (res) => {
            setResult(res);
            toast.success(res ? 'Connection verified. You can now scan this market.' : 'Connection settings saved.');
        },
        onError: (error) => {
            setResult({ status: 'failed', ...error?.response?.data, message: apiError(error) });
            toast.error(apiError(error));
        },
        onSettled: () => { setPhase(null); queryClient.invalidateQueries({ queryKey: ['dbo'] }); },
    });

    if (connections.isError) return <ErrorState error={connections.error} onRetry={connections.refetch} />;
    if (connections.isLoading || !form) return <Loading rows={3} />;
    const change = (key, value) => { setForm({ ...form, [key]: value }); setDirty(true); setResult(null); };
    const field = (key, label, props = {}) => (
        <label className="block text-xs font-semibold text-slate-600">{label}
            <input className="crm-input mt-1" value={form[key] ?? ''} disabled={save.isPending} onChange={(e) => change(key, e.target.value)} {...props} />
        </label>
    );
    const shown = result || (!dirty && c?.preflight_error ? { status: 'failed', code: c.preflight_error_code, message: c.preflight_error } : null);
    const ready = !dirty && !save.isPending && (result?.status === 'passed' || (!result && c?.preflight_status === 'passed'));
    const gateChanged = form.load_gate_enabled !== (c?.load_gate_enabled ?? true);

    return (
        <div className="space-y-5">
            <div className="flex flex-wrap gap-2 text-xs font-semibold text-slate-600" aria-label="Setup progress">
                <span className="rounded-md bg-slate-100 px-2 py-1">1 · Enter connection</span>
                <span className={`rounded-md px-2 py-1 ${ready ? 'bg-emerald-50 text-emerald-800' : 'bg-slate-100'}`}>2 · {ready ? 'Connection verified' : 'Save & test'}</span>
                <span className="rounded-md bg-slate-100 px-2 py-1">3 · Run first scan</span>
            </div>
            <details className="rounded-lg border border-slate-200 px-3 py-2 text-sm">
                <summary className="cursor-pointer font-semibold text-slate-700">First-time cPanel setup</summary>
                <ol className="mt-2 list-decimal space-y-1 pl-5 text-xs leading-5 text-slate-600">
                    <li>Create a separate database user in cPanel → Manage My Databases.</li>
                    <li>Under Add User To Database, assign it to <strong>{form.database || 'this market’s database'}</strong>. Select only SELECT and save.</li>
                    <li>Enter the full username including its cPanel prefix, and the password below.</li>
                </ol>
                <p className="mt-2 text-xs text-slate-500">Creating the user alone does not give it access to a database. The scanner checks read-only access before scanning.</p>
            </details>
            <div className="grid gap-3 sm:grid-cols-2">
                {field('host', 'Database host', { placeholder: 'localhost or db.example.com' })}
                {field('database', 'Database name')}
                {field('prefix', 'WordPress table prefix')}
                {field('port', 'Port', { type: 'number' })}
                {field('username', c?.username_configured ? 'Username · saved' : 'Full database username', { autoComplete: 'off', placeholder: c?.username_configured ? 'Leave empty to keep saved username' : 'e.g. exotickenya_crm_scanner_kenya' })}
                {field('password', c?.password_configured ? 'Password · saved' : 'Database password', { type: 'password', autoComplete: 'new-password', placeholder: c?.password_configured ? 'Leave empty to keep saved password' : 'Password for the database user' })}
            </div>
            <p className="text-xs text-slate-500">Saved credentials are encrypted and never displayed. Enter replacements only when changing them. “localhost” means the database server on the CRM host.</p>
            <label className="block text-xs font-semibold text-slate-600">Connection security
                <select className="crm-select mt-1 w-full" value={form.tls_mode} disabled={save.isPending} onChange={(e) => change('tls_mode', e.target.value)}>
                    <option value="none">Local connection (no TLS)</option>
                    <option value="verify">Remote connection (verify TLS certificate)</option>
                </select>
            </label>
            {form.tls_mode === 'verify' ? <label className="block text-xs font-semibold text-slate-600">CA certificate (PEM, optional; leave blank to keep saved)
                <textarea className="crm-input crm-mono mt-1 min-h-[5rem] text-xs" value={form.tls_ca} disabled={save.isPending} onChange={(e) => change('tls_ca', e.target.value)} />
            </label> : null}
            <details className="text-sm text-slate-600">
                <summary className="cursor-pointer font-semibold">Advanced connection settings</summary>
                <div className="mt-3 grid gap-3 sm:grid-cols-2">
                    {field('socket', 'Unix socket (optional)', { placeholder: 'Leave empty to use the server default' })}
                    {field('host_group', 'Shared database host group', { placeholder: 'Automatic from host' })}
                </div>
            </details>
            <section className={`rounded-lg border px-4 py-3 ${form.load_gate_enabled ? 'border-slate-200' : 'border-amber-300 bg-amber-50'}`}>
                <div className="flex items-center justify-between gap-4">
                    <div><h4 className="text-sm font-semibold text-slate-900">Load gate</h4><p className="mt-1 text-xs text-slate-600">{form.load_gate_enabled ? 'Pause this market when platform load is high.' : 'Ignore platform load for this market’s checks and scans.'}</p></div>
                    <button type="button" role="switch" aria-label="Load gate" aria-checked={form.load_gate_enabled} disabled={save.isPending} onClick={() => change('load_gate_enabled', !form.load_gate_enabled)} className={`inline-flex min-h-10 shrink-0 items-center gap-2 rounded-full border px-3 py-1.5 text-sm font-semibold ${form.load_gate_enabled ? 'border-teal-300 bg-teal-50 text-teal-900' : 'border-amber-400 bg-white text-amber-950'}`}>
                        <span className={`h-3 w-3 rounded-full ${form.load_gate_enabled ? 'bg-teal-600' : 'bg-amber-500'}`} />{form.load_gate_enabled ? 'On' : 'Off'}
                    </button>
                </div>
                <p className="mt-2 text-xs text-slate-600">{gateChanged ? 'Unsaved change — choose Save settings or Save & test below. ' : ''}Off stays off until you turn it on again. It bypasses all load readings, including Critical and missing readings, for this market only. Emergency stop, database permissions and health checks still apply.</p>
            </section>
            <label className="flex items-center gap-2 text-sm text-slate-700"><input type="checkbox" checked={form.enabled} disabled={save.isPending} onChange={(e) => change('enabled', e.target.checked)} />Enable this market for scans after its connection passes</label>
            <div className="flex flex-wrap items-center gap-2">
                <button type="button" className="crm-btn-primary" disabled={save.isPending} onClick={() => { setResult(null); save.mutate(true); }}>{phase === 'testing' ? 'Testing connection…' : phase === 'saving' ? 'Saving…' : 'Save & test connection'}</button>
                <button type="button" className="crm-btn-secondary" disabled={save.isPending} onClick={() => save.mutate(false)}>Save settings</button>
                {dirty ? <span className="text-xs text-amber-800">Unsaved changes</span> : c?.preflight_at ? <span className="text-xs text-slate-500">Last checked {fmtAgo(c.preflight_at)}</span> : null}
            </div>
            {shown ? <div role="status" className={`rounded-lg border px-3 py-3 text-sm ${shown.status === 'passed' ? 'border-emerald-200 bg-emerald-50 text-emerald-900' : 'border-rose-200 bg-rose-50 text-rose-900'}`}>
                <p className="font-semibold">{shown.message}</p>
                {shown.code === 'access_denied' ? <p className="mt-2 text-xs">Check the full username, password and cPanel database assignment. Turning off the load gate cannot grant database access.</p> : null}
                {['load', 'ops_state_missing', 'ops_state_stale'].includes(shown.code) ? <p className="mt-2 text-xs">To proceed without load checks, switch Load gate off above and choose Save & test connection.</p> : null}
                {shown.errors ? <ul className="mt-2 list-disc pl-4 text-xs">{Object.values(shown.errors).flat().map((message, i) => <li key={i}>{message}</li>)}</ul> : null}
                {shown.capabilities?.engine ? <p className="mt-2 text-xs">Engine {shown.capabilities.engine} · TLS {shown.capabilities.tls} · query timeout {shown.capabilities.statement_timeout_seconds}s</p> : null}
            </div> : null}
            {ready ? <div className="flex flex-wrap items-center justify-between gap-3 rounded-lg border border-emerald-200 bg-emerald-50 p-3">
                <p className="text-sm font-semibold text-emerald-900">Connection verified{form.enabled ? ' — ready for your first scan.' : '. Enable this market above to scan.'}</p>
                {form.enabled && onScan ? <button type="button" className="crm-btn-primary" onClick={onScan}>Scan this market</button> : null}
            </div> : null}
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
                                                    <Status value={m.connection.preflight_status} label={m.connection.preflight_status === 'passed' ? 'Connection verified' : `Connection ${m.connection.preflight_status === 'never' ? 'not tested' : m.connection.preflight_status}`} />
                                                    {m.connection.load_gate_enabled === false ? <span className="mt-1 block text-xs font-semibold text-amber-800">Load gate off</span> : null}
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
                {selected ? (view === 'connection' && canConfigure ? <ConnectionForm platformId={selected.platform_id} onScan={canOperate ? () => { setOpen(null); onScanMarket(selected.platform_id); } : null} /> : <Inventory platformId={selected.platform_id} />) : null}
            </Drawer>
        </>
    );
}
