import React, { useEffect, useState } from 'react';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import dbObservatory from '../../services/dbObservatory';
import ConfirmDialog from '../ConfirmDialog';
import { useToast } from '../ToastProvider';
import { apiError, Empty, ErrorState, fmtAge, fmtAgo, fmtDateTime, humanize, Loading, Panel, PROFILE_HINT, Status } from './shared';

function ScannerControls({ settings, canConfigure }) {
    const toast = useToast();
    const queryClient = useQueryClient();
    const [confirm, setConfirm] = useState(null);
    const mutation = useMutation({
        mutationFn: (patch) => dbObservatory.updateSettings({ ...patch, revision: settings.revision }),
        onSuccess: () => { toast.success('Scanner settings saved.'); setConfirm(null); queryClient.invalidateQueries({ queryKey: ['dbo'] }); },
        onError: (e) => { toast.error(apiError(e)); setConfirm(null); queryClient.invalidateQueries({ queryKey: ['dbo', 'settings'] }); },
    });

    const toggles = [
        ['enabled', 'Scanning enabled', 'Allows scans, tests and schedules to start (with the deployment switch).', false],
        ['paused', 'Pause all scanning', 'Running markets stop at their next chunk; progress is kept until you resume.', true],
        ['emergency_stop', 'Emergency stop', 'Blocks every scanner statement, including credential probes. Use if the scanner must not touch any database.', true],
    ];

    return (
        <Panel title="Scanner controls" subtitle={settings.deployment_enabled ? 'Deployment switch is on.' : 'Deployment switch DB_SCANNER_ENABLED is off — scans and schedules cannot run on this server.'}>
            <div className="divide-y divide-slate-100">
                {toggles.map(([key, label, help, danger]) => (
                    <div key={key} className="flex items-center justify-between gap-4 px-4 py-3">
                        <div>
                            <p className="text-sm font-semibold text-slate-900">{label}</p>
                            <p className="text-xs text-slate-500">{help}</p>
                        </div>
                        <button
                            type="button"
                            role="switch"
                            aria-checked={Boolean(settings[key])}
                            disabled={!canConfigure || mutation.isPending}
                            onClick={() => (danger && !settings[key] ? setConfirm(key) : mutation.mutate({ [key]: !settings[key] }))}
                            className={`relative h-6 w-11 shrink-0 rounded-full transition disabled:opacity-50 ${settings[key] ? (danger ? 'bg-rose-600' : 'bg-teal-600') : 'bg-slate-300'}`}
                        >
                            <span className={`absolute top-0.5 h-5 w-5 rounded-full bg-white shadow transition ${settings[key] ? 'left-[1.375rem]' : 'left-0.5'}`} />
                        </button>
                    </div>
                ))}
            </div>
            <ConfirmDialog
                open={Boolean(confirm)}
                tone={confirm === 'emergency_stop' ? 'danger' : 'warning'}
                title={confirm === 'emergency_stop' ? 'Turn on emergency stop?' : 'Pause all scanning?'}
                message={confirm === 'emergency_stop'
                    ? 'Running scans stop at their next statement and no probe or scan can start until this is cleared. If a worker cannot drain, revoke the reader credentials as the database-level stop.'
                    : 'Running markets pause at their next safe boundary. Use Resume (turn this off) to continue from the last committed chunk.'}
                confirmLabel={confirm === 'emergency_stop' ? 'Emergency stop' : 'Pause all'}
                isPending={mutation.isPending}
                onCancel={() => setConfirm(null)}
                onConfirm={() => mutation.mutate({ [confirm]: true })}
            />
        </Panel>
    );
}

function LimitsForm({ settings, canConfigure }) {
    const toast = useToast();
    const queryClient = useQueryClient();
    const l = settings.limits;
    const [draft, setDraft] = useState({});
    useEffect(() => {
        setDraft({
            statement_timeout_seconds: l.statement_timeout_seconds.value,
            global_slots: l.global_slots.value,
            chunk_rows: l.chunk_rows.value,
            daily_market_seconds: l.daily_market_seconds.value,
            budgets: Object.fromEntries(Object.entries(l.budgets).map(([k, v]) => [k, v.value])),
        });
    }, [settings.revision]); // eslint-disable-line react-hooks/exhaustive-deps

    const save = useMutation({
        mutationFn: () => dbObservatory.updateSettings({ limits: draft, revision: settings.revision }),
        onSuccess: () => { toast.success('Limits saved — new runs use them.'); queryClient.invalidateQueries({ queryKey: ['dbo', 'settings'] }); },
        onError: (e) => toast.error(apiError(e)),
    });

    const input = (key, label, unit, range) => (
        <label className="text-xs font-semibold text-slate-600">
            {label} <span className="font-normal text-slate-400">({range.min}–{range.max}{unit})</span>
            <input type="number" min={range.min} max={range.max} disabled={!canConfigure} className="crm-input mt-1" value={draft[key] ?? ''} onChange={(e) => setDraft({ ...draft, [key]: Number(e.target.value) })} />
        </label>
    );

    return (
        <Panel title="Limits" subtitle="Bounded by the tested safety envelope; limits can only be lowered. Per-host concurrency is fixed at one connection.">
            <div className="grid gap-3 p-4 sm:grid-cols-2">
                {input('statement_timeout_seconds', 'Statement timeout', ' s', l.statement_timeout_seconds)}
                {input('global_slots', 'Concurrent markets (global)', '', l.global_slots)}
                {input('chunk_rows', 'Rows per chunk', '', l.chunk_rows)}
                {input('daily_market_seconds', 'Active seconds per market per day', ' s', l.daily_market_seconds)}
                {Object.entries(l.budgets).map(([profile, range]) => (
                    <label key={profile} className="text-xs font-semibold text-slate-600">
                        <span className="capitalize">{profile}</span> run budget <span className="font-normal text-slate-400">({range.min}–{range.max} s)</span>
                        <input type="number" min={range.min} max={range.max} disabled={!canConfigure} className="crm-input mt-1" value={draft.budgets?.[profile] ?? ''} onChange={(e) => setDraft({ ...draft, budgets: { ...draft.budgets, [profile]: Number(e.target.value) } })} />
                    </label>
                ))}
            </div>
            <div className="flex flex-wrap items-center justify-between gap-2 border-t border-slate-100 px-4 py-3 text-xs text-slate-500">
                <span>Fixed: {Math.round(l.fixed.chunk_bytes / 1048576)} MiB per chunk · {Math.round(l.fixed.value_bytes / 1024)} KiB per value · {l.fixed.slice_seconds} s slices · {l.fixed.lease_seconds} s leases · 1 connection per host</span>
                {canConfigure ? <button type="button" className="crm-btn-primary px-3 py-1.5" disabled={save.isPending} onClick={() => save.mutate()}>Save limits</button> : null}
            </div>
        </Panel>
    );
}

function HealthPanel({ health, gates }) {
    const rows = [
        ['Dispatcher heartbeat', fmtAge(health.dispatcher_heartbeat_age_seconds)],
        ['Worker heartbeat', fmtAge(health.worker_heartbeat_age_seconds)],
        ['Recovery heartbeat', fmtAge(health.recovery_heartbeat_age_seconds)],
        ['Oldest waiting run', health.oldest_waiting_age_seconds === null ? 'none' : fmtAge(health.oldest_waiting_age_seconds).replace(' ago', '')],
        ['Stalled runs', health.stalled_runs],
        ['P95 query (24h)', `${health.p95_query_ms_24h} ms`],
        ['Last successful sweep', health.last_successful_sweep_at ? fmtAgo(health.last_successful_sweep_at) : 'never'],
    ];
    return (
        <Panel title="Scanner health" action={<Status value={health.status} />}>
            <dl className="grid grid-cols-2 gap-x-4 gap-y-2 p-4 text-sm">
                {rows.map(([k, v]) => <React.Fragment key={k}><dt className="text-slate-500">{k}</dt><dd className="crm-mono text-right text-slate-900">{v}</dd></React.Fragment>)}
            </dl>
            <p className="border-t border-slate-100 px-4 py-2 text-xs text-slate-500">
                Admission fails closed when load level ≥ {gates.max_load_level + 1}, ops state is stale{gates.require_ops_state ? '' : ' (disabled on this server)'}, or market health is not fresh and healthy{gates.require_market_health ? '' : ' (disabled on this server)'}.
            </p>
        </Panel>
    );
}

const BLANK = { name: '', profile: 'quick', cron: '30 1 * * *', window: { start: '01:00', end: '05:30' }, market_scope: { mode: 'enabled_connections', platform_ids: [] }, enabled: false };

function ScheduleEditor({ schedule, markets, onClose }) {
    const toast = useToast();
    const queryClient = useQueryClient();
    const [form, setForm] = useState(schedule ? { ...schedule, window: schedule.window || { start: '', end: '' } } : BLANK);
    const save = useMutation({
        mutationFn: () => {
            const payload = {
                name: form.name, profile: form.profile, cron: form.cron, enabled: form.enabled,
                window: form.window?.start && form.window?.end ? form.window : null,
                market_scope: form.market_scope,
            };
            return schedule ? dbObservatory.updateSchedule(schedule.id, { ...payload, revision: schedule.revision }) : dbObservatory.createSchedule(payload);
        },
        onSuccess: () => { toast.success('Schedule saved.'); queryClient.invalidateQueries({ queryKey: ['dbo', 'schedules'] }); onClose(); },
        onError: (e) => toast.error(apiError(e)),
    });

    return (
        <div className="space-y-3 rounded-lg border border-teal-200 bg-teal-50/40 p-4">
            <div className="grid gap-3 sm:grid-cols-3">
                <label className="text-xs font-semibold text-slate-600">Name<input className="crm-input mt-1" value={form.name} onChange={(e) => setForm({ ...form, name: e.target.value })} /></label>
                <label className="text-xs font-semibold text-slate-600">Profile
                    <select className="crm-select mt-1 w-full" value={form.profile} onChange={(e) => setForm({ ...form, profile: e.target.value })}>{['quick', 'standard', 'deep'].map((p) => <option key={p} value={p}>{humanize(p)}</option>)}</select>
                </label>
                <label className="text-xs font-semibold text-slate-600">Cron (market local time)<input className="crm-input crm-mono mt-1" value={form.cron} onChange={(e) => setForm({ ...form, cron: e.target.value })} /></label>
                <label className="text-xs font-semibold text-slate-600">Window start<input className="crm-input mt-1" placeholder="01:00" value={form.window?.start || ''} onChange={(e) => setForm({ ...form, window: { ...form.window, start: e.target.value } })} /></label>
                <label className="text-xs font-semibold text-slate-600">Window end<input className="crm-input mt-1" placeholder="05:30" value={form.window?.end || ''} onChange={(e) => setForm({ ...form, window: { ...form.window, end: e.target.value } })} /></label>
                <label className="text-xs font-semibold text-slate-600">Markets
                    <select className="crm-select mt-1 w-full" value={form.market_scope.mode} onChange={(e) => setForm({ ...form, market_scope: { ...form.market_scope, mode: e.target.value } })}>
                        <option value="enabled_connections">All enabled, preflighted markets</option>
                        <option value="platforms">Selected markets</option>
                    </select>
                </label>
            </div>
            {form.market_scope.mode === 'platforms' ? (
                <div className="flex max-h-40 flex-wrap gap-2 overflow-y-auto">
                    {markets.map((m) => {
                        const on = (form.market_scope.platform_ids || []).includes(m.platform_id);
                        return (
                            <label key={m.platform_id} className={`flex items-center gap-1.5 rounded-md border px-2 py-1 text-xs ${on ? 'border-teal-300 bg-white' : 'border-slate-200'}`}>
                                <input type="checkbox" checked={on} onChange={() => setForm({ ...form, market_scope: { ...form.market_scope, platform_ids: on ? form.market_scope.platform_ids.filter((x) => x !== m.platform_id) : [...(form.market_scope.platform_ids || []), m.platform_id] } })} />
                                {m.market}
                            </label>
                        );
                    })}
                </div>
            ) : null}
            <p className="text-xs text-slate-500">{PROFILE_HINT[form.profile]}. At most hourly. Missed runs catch up only the latest occurrence within 24 hours; work outside the window pauses until it reopens.</p>
            <div className="flex items-center gap-3">
                <label className="flex items-center gap-2 text-sm"><input type="checkbox" checked={Boolean(form.enabled)} onChange={(e) => setForm({ ...form, enabled: e.target.checked })} /> Enabled</label>
                <button type="button" className="crm-btn-primary px-3 py-1.5" disabled={save.isPending} onClick={() => save.mutate()}>Save schedule</button>
                <button type="button" className="crm-btn-secondary px-3 py-1.5" onClick={onClose}>Cancel</button>
            </div>
        </div>
    );
}

export default function SchedulesTab({ canConfigure, markets }) {
    const toast = useToast();
    const queryClient = useQueryClient();
    const settings = useQuery({ queryKey: ['dbo', 'settings'], queryFn: dbObservatory.settings, refetchInterval: 30_000 });
    const schedules = useQuery({ queryKey: ['dbo', 'schedules'], queryFn: dbObservatory.schedules });
    const [editing, setEditing] = useState(null);
    const disable = useMutation({
        mutationFn: (id) => dbObservatory.disableSchedule(id),
        onSuccess: () => { toast.success('Schedule disabled; history kept.'); queryClient.invalidateQueries({ queryKey: ['dbo', 'schedules'] }); },
        onError: (e) => toast.error(apiError(e)),
    });

    if (settings.isLoading) return <Loading rows={5} />;
    if (settings.isError) return <ErrorState error={settings.error} onRetry={settings.refetch} />;
    const names = Object.fromEntries((markets || []).map((m) => [m.platform_id, m.market]));

    return (
        <div className="space-y-4">
            <div className="grid gap-4 xl:grid-cols-3">
                <ScannerControls settings={settings.data} canConfigure={canConfigure} />
                <HealthPanel health={settings.data.health} gates={settings.data.gates} />
                <div className="xl:row-span-1"><LimitsForm settings={settings.data} canConfigure={canConfigure} /></div>
            </div>

            <Panel
                title="Schedules"
                subtitle="Disabling or deleting a schedule never stops a scan already running — use Stop this scan in Runs for that."
                action={canConfigure && editing === null ? <button type="button" className="crm-btn-primary px-3 py-1.5" onClick={() => setEditing('new')}>New schedule</button> : null}
                bodyClass="space-y-3 p-4"
            >
                {editing === 'new' ? <ScheduleEditor markets={markets} onClose={() => setEditing(null)} /> : null}
                {schedules.isLoading ? <Loading rows={2} /> : schedules.isError ? <ErrorState error={schedules.error} onRetry={schedules.refetch} /> : schedules.data.data.length === 0 ? <Empty title="No schedules" /> : schedules.data.data.map((s) => (
                    editing === s.id ? <ScheduleEditor key={s.id} schedule={s} markets={markets} onClose={() => setEditing(null)} /> : (
                        <div key={s.id} className="rounded-lg border border-slate-200 p-3">
                            <div className="flex flex-wrap items-center justify-between gap-2">
                                <div>
                                    <p className="text-sm font-semibold text-slate-900">{s.name} <span className="ml-1 text-xs font-normal capitalize text-slate-500">{s.profile}</span></p>
                                    <p className="text-xs text-slate-500">
                                        <span className="crm-mono">{s.cron}</span> market time{s.window ? ` · window ${s.window.start}–${s.window.end}` : ''} · {s.market_scope?.mode === 'platforms' ? `${(s.market_scope.platform_ids || []).length} selected markets` : 'all enabled markets'}
                                        {s.enabled && s.next_due_at ? ` · next ${fmtDateTime(s.next_due_at)}` : ''}
                                    </p>
                                </div>
                                <div className="flex items-center gap-2">
                                    <Status value={s.enabled ? 'running' : 'stopped'} label={s.enabled ? 'Enabled' : 'Disabled'} />
                                    {canConfigure ? <button type="button" className="text-xs font-semibold text-teal-700 hover:underline" onClick={() => setEditing(s.id)}>Edit</button> : null}
                                    {canConfigure && s.enabled ? <button type="button" className="text-xs font-semibold text-rose-700 hover:underline" disabled={disable.isPending} onClick={() => disable.mutate(s.id)}>Disable schedule</button> : null}
                                </div>
                            </div>
                            {s.recent_occurrences?.length ? (
                                <ul className="mt-2 grid gap-1 text-xs text-slate-500 sm:grid-cols-2">
                                    {s.recent_occurrences.slice(0, 6).map((o, i) => (
                                        <li key={i}>{names[o.platform_id] || `#${o.platform_id}`} · due {fmtDateTime(o.due_at)} · <span className="font-medium text-slate-700">{humanize(o.state)}</span>{o.skip_reason ? ` (${o.skip_reason})` : ''}</li>
                                    ))}
                                </ul>
                            ) : null}
                        </div>
                    )
                ))}
            </Panel>
        </div>
    );
}
