import React, { useEffect, useMemo, useState } from 'react';
import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query';
import api from '../../services/api';
import { useToast } from '../ToastProvider';
import AiStateBlock from '../ai/AiStateBlock';

const SUBTABS = [
    { id: 'overview', label: 'Overview & Health' },
    { id: 'recipients', label: 'Recipients' },
    { id: 'schedule', label: 'Schedule & SMS' },
    { id: 'cost', label: 'Cost & Providers' },
    { id: 'audit', label: 'Audit' },
];

export default function AiWorkspacePanel() {
    const toast = useToast();
    const queryClient = useQueryClient();
    const [subTab, setSubTab] = useState('overview');

    const settingsQuery = useQuery({
        queryKey: ['ai-settings'],
        queryFn: () => api.get('/crm/settings/ai').then((r) => r.data),
        staleTime: 30_000,
    });

    if (settingsQuery.isLoading) {
        return <AiStateBlock variant="loading" message="Loading AI configuration…" />;
    }

    if (settingsQuery.isError) {
        return (
            <AiStateBlock
                variant="error"
                message="Could not load AI settings."
                onRetry={() => settingsQuery.refetch()}
            />
        );
    }

    const data = settingsQuery.data;

    return (
        <div className="space-y-4">
            <nav className="flex flex-wrap gap-1 border-b border-slate-200 pb-2" aria-label="AI settings sections">
                {SUBTABS.map((t) => (
                    <button
                        key={t.id}
                        type="button"
                        onClick={() => setSubTab(t.id)}
                        aria-current={subTab === t.id ? 'page' : undefined}
                        className={`rounded-md px-3 py-2 text-sm font-medium transition focus:outline-none focus-visible:ring-2 focus-visible:ring-teal-500 ${
                            subTab === t.id
                                ? 'bg-white text-slate-900 ring-1 ring-slate-200'
                                : 'text-slate-500 hover:bg-slate-100 hover:text-slate-700'
                        }`}
                    >
                        {t.label}
                    </button>
                ))}
            </nav>

            {subTab === 'overview' ? <OverviewSection data={data} /> : null}
            {subTab === 'recipients' ? (
                <RecipientsSection data={data} toast={toast} queryClient={queryClient} />
            ) : null}
            {subTab === 'schedule' ? <ScheduleSection data={data} toast={toast} queryClient={queryClient} /> : null}
            {subTab === 'cost' ? <CostSection data={data} toast={toast} queryClient={queryClient} /> : null}
            {subTab === 'audit' ? <AuditSection /> : null}
        </div>
    );
}

function Badge({ ok, children }) {
    return (
        <span
            className={`inline-flex items-center rounded-full px-2.5 py-0.5 text-xs font-semibold ${
                ok ? 'bg-emerald-100 text-emerald-700' : 'bg-slate-200 text-slate-600'
            }`}
        >
            {children}
        </span>
    );
}

function OverviewSection({ data }) {
    const toast = useToast();
    const queryClient = useQueryClient();
    const b = data.briefings || {};
    const [previewAudience, setPreviewAudience] = useState('ceo');
    const [preview, setPreview] = useState(null);

    const previewMutation = useMutation({
        mutationFn: (audience) => api.post('/crm/settings/ai/briefings/preview', { audience }).then((r) => r.data),
        onSuccess: (res) => {
            setPreview(res);
            if (res.status === 'skipped') {
                toast.info?.(`Preview skipped: ${res.reason || 'unknown'}`);
            }
        },
        onError: (err) => toast.error(err?.response?.data?.message || 'Preview failed.'),
    });

    const enabledMutation = useMutation({
        mutationFn: (enabled) => api.patch('/crm/settings/ai/briefings', { enabled }).then((r) => r.data),
        onSuccess: () => {
            toast.success('Briefings setting updated.');
            queryClient.invalidateQueries({ queryKey: ['ai-settings'] });
        },
        onError: (err) => toast.error(err?.response?.data?.message || 'Could not update.'),
    });

    const recipientCount = (data.recipients || []).filter((r) => !r.opt_out).length;

    return (
        <div className="space-y-4">
            <div className="crm-surface p-4">
                <div className="flex flex-wrap items-center justify-between gap-3">
                    <div>
                        <h3 className="text-sm font-semibold text-slate-900">Weekly AI briefings</h3>
                        <p className="mt-1 text-sm text-slate-500">
                            Automated Monday-morning SMS digests with a deep link to a full briefing.
                        </p>
                    </div>
                    <div className="flex items-center gap-3">
                        <Badge ok={!!b.enabled}>{b.enabled ? 'Enabled' : 'Disabled'}</Badge>
                        <label className="inline-flex cursor-pointer items-center gap-2 text-sm">
                            <input
                                type="checkbox"
                                checked={!!b.enabled}
                                onChange={(e) => enabledMutation.mutate(e.target.checked)}
                                className="h-4 w-4 rounded border-slate-300 text-teal-600 focus:ring-teal-500"
                            />
                            Master enable
                        </label>
                    </div>
                </div>

                <dl className="mt-4 grid grid-cols-2 gap-3 sm:grid-cols-4">
                    <Stat label="Active recipients" value={recipientCount} />
                    <Stat label="Link TTL (days)" value={b.link_ttl_days ?? '—'} />
                    <Stat label="Weekly cost cap" value={`$${Number(b.weekly_cost_cap_usd ?? 0).toFixed(2)}`} />
                    <Stat label="Timezone" value={b.timezone || '—'} />
                </dl>
            </div>

            <div className="crm-surface p-4">
                <h3 className="text-sm font-semibold text-slate-900">Dry-run preview</h3>
                <p className="mt-1 text-sm text-slate-500">
                    Compute the exact SMS + briefing body without sending or persisting anything.
                </p>
                <div className="mt-3 flex flex-wrap items-center gap-2">
                    <select
                        value={previewAudience}
                        onChange={(e) => setPreviewAudience(e.target.value)}
                        className="rounded-md border border-slate-300 px-3 py-2 text-sm focus:border-teal-500 focus:ring-teal-500"
                        aria-label="Preview audience"
                    >
                        <option value="ceo">CEO</option>
                        <option value="sales">Sales</option>
                    </select>
                    <button
                        type="button"
                        onClick={() => previewMutation.mutate(previewAudience)}
                        disabled={previewMutation.isPending}
                        className="rounded-md bg-teal-600 px-4 py-2 text-sm font-medium text-white transition hover:bg-teal-700 disabled:opacity-60"
                    >
                        {previewMutation.isPending ? 'Running…' : 'Run dry-run'}
                    </button>
                </div>

                {preview && preview.status !== 'skipped' ? (
                    <div className="mt-4 space-y-3">
                        <p className="text-xs text-slate-500">
                            Period {preview.period?.from} → {preview.period?.to} ({preview.period?.timezone}) · estimated cost $
                            {Number(preview.cost_usd ?? 0).toFixed(6)}
                        </p>
                        {(preview.briefings || []).map((br, i) => (
                            <div key={i} className="rounded-lg border border-slate-200 p-3">
                                <p className="text-xs font-semibold text-slate-700">
                                    Scope {i + 1}: {br.scope?.platform_ids === null ? 'Org-wide' : `Markets [${(br.scope?.platform_ids || []).join(', ')}]`}{' '}
                                    <Badge ok={br.used_ai}>{br.used_ai ? 'AI' : 'Template'}</Badge>
                                </p>
                                <p className="mt-2 text-sm text-slate-800">{br.sms_digest}</p>
                                <ul className="mt-2 space-y-1">
                                    {(br.recipients || []).map((r, j) => (
                                        <li key={j} className="rounded bg-slate-50 px-2 py-1 text-xs text-slate-600">
                                            <span className="font-medium">{r.name || `user#${r.user_id}`}</span> · {r.sms_char_count} units / {r.sms_segments} seg
                                            <div className="mt-1 font-mono text-[11px] text-slate-500">{r.sms_text}</div>
                                        </li>
                                    ))}
                                </ul>
                            </div>
                        ))}
                    </div>
                ) : null}

                {preview && preview.status === 'skipped' ? (
                    <AiStateBlock
                        className="mt-4"
                        variant="empty"
                        title="Nothing to preview"
                        message={preview.reason === 'no_recipients' ? 'No opted-in recipients for this audience.' : `Skipped: ${preview.reason}`}
                    />
                ) : null}
            </div>
        </div>
    );
}

function Stat({ label, value }) {
    return (
        <div className="rounded-lg bg-slate-50 px-3 py-2">
            <dt className="text-xs text-slate-500">{label}</dt>
            <dd className="mt-0.5 text-sm font-semibold text-slate-900">{value}</dd>
        </div>
    );
}

function RecipientsSection({ data, toast, queryClient }) {
    const users = data.users || [];
    const platforms = data.platforms || [];
    const [rows, setRows] = useState(() => normalizeRecipients(data.recipients || []));

    useEffect(() => {
        setRows(normalizeRecipients(data.recipients || []));
    }, [data.recipients]);

    const saveMutation = useMutation({
        mutationFn: (recipients) => api.put('/crm/settings/ai/recipients', { recipients }).then((r) => r.data),
        onSuccess: () => {
            toast.success('Recipients saved.');
            queryClient.invalidateQueries({ queryKey: ['ai-settings'] });
        },
        onError: (err) => toast.error(err?.response?.data?.message || 'Could not save recipients.'),
    });

    const addRow = () =>
        setRows((cur) => [...cur, { user_id: '', name: '', phone: '', audience: 'sales', scope_platform_ids: [], opt_out: false }]);

    const updateRow = (i, patch) => setRows((cur) => cur.map((r, idx) => (idx === i ? { ...r, ...patch } : r)));
    const removeRow = (i) => setRows((cur) => cur.filter((_, idx) => idx !== i));

    const onPickUser = (i, userId) => {
        const u = users.find((x) => String(x.id) === String(userId));
        updateRow(i, {
            user_id: userId ? Number(userId) : '',
            name: u?.name || '',
            phone: u?.phone || '',
        });
    };

    const save = () => {
        const cleaned = rows
            .filter((r) => r.user_id)
            .map((r) => ({
                user_id: Number(r.user_id),
                name: r.name || null,
                phone: r.phone || null,
                audience: r.audience === 'ceo' ? 'ceo' : 'sales',
                scope_platform_ids: (r.scope_platform_ids || []).map(Number),
                opt_out: !!r.opt_out,
            }));
        saveMutation.mutate(cleaned);
    };

    return (
        <div className="crm-surface p-4">
            <div className="flex items-center justify-between">
                <div>
                    <h3 className="text-sm font-semibold text-slate-900">Recipients</h3>
                    <p className="mt-1 text-sm text-slate-500">
                        Each recipient must map to a CRM user — deep-link access depends on it. Sales scope defaults to the
                        user's assigned markets unless you set explicit markets.
                    </p>
                </div>
                <button
                    type="button"
                    onClick={addRow}
                    className="rounded-md border border-slate-300 bg-white px-3 py-2 text-sm font-medium text-slate-700 transition hover:bg-slate-50"
                >
                    Add recipient
                </button>
            </div>

            {rows.length === 0 ? (
                <AiStateBlock
                    className="mt-4"
                    variant="empty"
                    title="No recipients yet"
                    message="Add at least one recipient to start sending weekly briefings."
                />
            ) : (
                <div className="mt-4 overflow-x-auto">
                    <table className="min-w-full text-sm">
                        <thead>
                            <tr className="border-b border-slate-200 text-left text-xs uppercase tracking-wide text-slate-500">
                                <th className="px-2 py-2">User</th>
                                <th className="px-2 py-2">Phone</th>
                                <th className="px-2 py-2">Audience</th>
                                <th className="px-2 py-2">Market scope (sales)</th>
                                <th className="px-2 py-2">Opt-out</th>
                                <th className="px-2 py-2" />
                            </tr>
                        </thead>
                        <tbody>
                            {rows.map((row, i) => (
                                <tr key={i} className="border-b border-slate-100 align-top">
                                    <td className="px-2 py-2">
                                        <select
                                            value={row.user_id || ''}
                                            onChange={(e) => onPickUser(i, e.target.value)}
                                            className="w-44 rounded-md border border-slate-300 px-2 py-1.5 text-sm focus:border-teal-500 focus:ring-teal-500"
                                            aria-label="Recipient user"
                                        >
                                            <option value="">Select user…</option>
                                            {users.map((u) => (
                                                <option key={u.id} value={u.id}>
                                                    {u.name} ({u.role})
                                                </option>
                                            ))}
                                        </select>
                                    </td>
                                    <td className="px-2 py-2">
                                        <input
                                            type="text"
                                            value={row.phone || ''}
                                            onChange={(e) => updateRow(i, { phone: e.target.value })}
                                            placeholder="2547…"
                                            className="w-32 rounded-md border border-slate-300 px-2 py-1.5 text-sm focus:border-teal-500 focus:ring-teal-500"
                                            aria-label="Recipient phone"
                                        />
                                    </td>
                                    <td className="px-2 py-2">
                                        <select
                                            value={row.audience}
                                            onChange={(e) => updateRow(i, { audience: e.target.value })}
                                            className="rounded-md border border-slate-300 px-2 py-1.5 text-sm focus:border-teal-500 focus:ring-teal-500"
                                            aria-label="Recipient audience"
                                        >
                                            <option value="ceo">CEO</option>
                                            <option value="sales">Sales</option>
                                        </select>
                                    </td>
                                    <td className="px-2 py-2">
                                        {row.audience === 'sales' ? (
                                            <select
                                                multiple
                                                value={(row.scope_platform_ids || []).map(String)}
                                                onChange={(e) =>
                                                    updateRow(i, {
                                                        scope_platform_ids: Array.from(e.target.selectedOptions).map((o) => Number(o.value)),
                                                    })
                                                }
                                                className="h-20 w-48 rounded-md border border-slate-300 px-2 py-1 text-sm focus:border-teal-500 focus:ring-teal-500"
                                                aria-label="Market scope"
                                            >
                                                {platforms.map((p) => (
                                                    <option key={p.id} value={p.id}>
                                                        {p.name}
                                                    </option>
                                                ))}
                                            </select>
                                        ) : (
                                            <span className="text-xs text-slate-400">Org-wide</span>
                                        )}
                                    </td>
                                    <td className="px-2 py-2">
                                        <input
                                            type="checkbox"
                                            checked={!!row.opt_out}
                                            onChange={(e) => updateRow(i, { opt_out: e.target.checked })}
                                            className="h-4 w-4 rounded border-slate-300 text-teal-600 focus:ring-teal-500"
                                            aria-label="Opt out"
                                        />
                                    </td>
                                    <td className="px-2 py-2">
                                        <button
                                            type="button"
                                            onClick={() => removeRow(i)}
                                            className="rounded-md px-2 py-1 text-xs font-medium text-rose-600 hover:bg-rose-50"
                                        >
                                            Remove
                                        </button>
                                    </td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                </div>
            )}

            <div className="mt-4 flex justify-end">
                <button
                    type="button"
                    onClick={save}
                    disabled={saveMutation.isPending}
                    className="rounded-md bg-teal-600 px-4 py-2 text-sm font-medium text-white transition hover:bg-teal-700 disabled:opacity-60"
                >
                    {saveMutation.isPending ? 'Saving…' : 'Save recipients'}
                </button>
            </div>
        </div>
    );
}

function normalizeRecipients(list) {
    return (list || []).map((r) => ({
        user_id: r.user_id || '',
        name: r.name || '',
        phone: r.phone || '',
        audience: r.audience === 'ceo' ? 'ceo' : 'sales',
        scope_platform_ids: Array.isArray(r.scope_platform_ids) ? r.scope_platform_ids : [],
        opt_out: !!r.opt_out,
    }));
}

function ScheduleSection({ data, toast, queryClient }) {
    const b = data.briefings || {};
    const [form, setForm] = useState({
        timezone: b.timezone || 'Africa/Nairobi',
        base_url: b.base_url || '',
        sms_provider_override: b.sms_provider_override || '',
        admin_override: !!b.admin_override,
        schedule: {
            ceo_enabled: b.schedule?.ceo_enabled ?? true,
            sales_enabled: b.schedule?.sales_enabled ?? true,
            ceo_time: b.schedule?.ceo_time || '07:30',
            sales_time: b.schedule?.sales_time || '07:45',
        },
    });

    const mutation = useMutation({
        mutationFn: (payload) => api.patch('/crm/settings/ai/briefings', payload).then((r) => r.data),
        onSuccess: () => {
            toast.success('Schedule saved.');
            queryClient.invalidateQueries({ queryKey: ['ai-settings'] });
        },
        onError: (err) => toast.error(err?.response?.data?.message || 'Could not save schedule.'),
    });

    const save = () =>
        mutation.mutate({
            timezone: form.timezone,
            base_url: form.base_url,
            sms_provider_override: form.sms_provider_override || null,
            admin_override: form.admin_override,
            schedule: form.schedule,
        });

    const setSchedule = (patch) => setForm((cur) => ({ ...cur, schedule: { ...cur.schedule, ...patch } }));

    return (
        <div className="crm-surface space-y-4 p-4">
            <h3 className="text-sm font-semibold text-slate-900">Schedule & SMS</h3>

            <div className="grid gap-4 sm:grid-cols-2">
                <Field label="Timezone">
                    <input
                        type="text"
                        value={form.timezone}
                        onChange={(e) => setForm({ ...form, timezone: e.target.value })}
                        className="w-full rounded-md border border-slate-300 px-3 py-2 text-sm focus:border-teal-500 focus:ring-teal-500"
                    />
                </Field>
                <Field label="Deep-link base URL">
                    <input
                        type="text"
                        value={form.base_url}
                        onChange={(e) => setForm({ ...form, base_url: e.target.value })}
                        placeholder="https://crm.exotic-online.com"
                        className="w-full rounded-md border border-slate-300 px-3 py-2 text-sm focus:border-teal-500 focus:ring-teal-500"
                    />
                </Field>
                <Field label="SMS provider override (optional)">
                    <input
                        type="text"
                        value={form.sms_provider_override}
                        onChange={(e) => setForm({ ...form, sms_provider_override: e.target.value })}
                        placeholder="Leave blank to use default routing"
                        className="w-full rounded-md border border-slate-300 px-3 py-2 text-sm focus:border-teal-500 focus:ring-teal-500"
                    />
                </Field>
                <Field label="Admin / CEO link override">
                    <label className="inline-flex cursor-pointer items-center gap-2 text-sm">
                        <input
                            type="checkbox"
                            checked={form.admin_override}
                            onChange={(e) => setForm({ ...form, admin_override: e.target.checked })}
                            className="h-4 w-4 rounded border-slate-300 text-teal-600 focus:ring-teal-500"
                        />
                        Admins/CEO can open any briefing link
                    </label>
                </Field>
            </div>

            <div className="grid gap-4 sm:grid-cols-2">
                <div className="rounded-lg border border-slate-200 p-3">
                    <label className="flex items-center justify-between text-sm font-medium text-slate-700">
                        CEO briefing (Mon)
                        <input
                            type="checkbox"
                            checked={form.schedule.ceo_enabled}
                            onChange={(e) => setSchedule({ ceo_enabled: e.target.checked })}
                            className="h-4 w-4 rounded border-slate-300 text-teal-600 focus:ring-teal-500"
                        />
                    </label>
                    <input
                        type="time"
                        value={form.schedule.ceo_time}
                        onChange={(e) => setSchedule({ ceo_time: e.target.value })}
                        className="mt-2 w-full rounded-md border border-slate-300 px-3 py-2 text-sm focus:border-teal-500 focus:ring-teal-500"
                    />
                </div>
                <div className="rounded-lg border border-slate-200 p-3">
                    <label className="flex items-center justify-between text-sm font-medium text-slate-700">
                        Sales briefing (Mon)
                        <input
                            type="checkbox"
                            checked={form.schedule.sales_enabled}
                            onChange={(e) => setSchedule({ sales_enabled: e.target.checked })}
                            className="h-4 w-4 rounded border-slate-300 text-teal-600 focus:ring-teal-500"
                        />
                    </label>
                    <input
                        type="time"
                        value={form.schedule.sales_time}
                        onChange={(e) => setSchedule({ sales_time: e.target.value })}
                        className="mt-2 w-full rounded-md border border-slate-300 px-3 py-2 text-sm focus:border-teal-500 focus:ring-teal-500"
                    />
                </div>
            </div>

            <p className="text-xs text-slate-500">
                Scheduler enforces these times in the configured timezone. Cron entries are fixed weekly Monday slots; the
                feature self-guards on the master enable flag and per-audience toggles.
            </p>

            <div className="flex justify-end">
                <button
                    type="button"
                    onClick={save}
                    disabled={mutation.isPending}
                    className="rounded-md bg-teal-600 px-4 py-2 text-sm font-medium text-white transition hover:bg-teal-700 disabled:opacity-60"
                >
                    {mutation.isPending ? 'Saving…' : 'Save schedule'}
                </button>
            </div>
        </div>
    );
}

function CostSection({ data, toast, queryClient }) {
    const b = data.briefings || {};
    const [form, setForm] = useState({
        weekly_cost_cap_usd: b.weekly_cost_cap_usd ?? 5,
        link_ttl_days: b.link_ttl_days ?? 14,
    });

    const mutation = useMutation({
        mutationFn: (payload) => api.patch('/crm/settings/ai/briefings', payload).then((r) => r.data),
        onSuccess: () => {
            toast.success('Cost settings saved.');
            queryClient.invalidateQueries({ queryKey: ['ai-settings'] });
        },
        onError: (err) => toast.error(err?.response?.data?.message || 'Could not save.'),
    });

    return (
        <div className="crm-surface space-y-4 p-4">
            <h3 className="text-sm font-semibold text-slate-900">Cost & Providers</h3>
            <div className="grid gap-4 sm:grid-cols-2">
                <Field label="Weekly cost cap (USD)">
                    <input
                        type="number"
                        min="0"
                        step="0.5"
                        value={form.weekly_cost_cap_usd}
                        onChange={(e) => setForm({ ...form, weekly_cost_cap_usd: e.target.value })}
                        className="w-full rounded-md border border-slate-300 px-3 py-2 text-sm focus:border-teal-500 focus:ring-teal-500"
                    />
                </Field>
                <Field label="Link TTL (days)">
                    <input
                        type="number"
                        min="1"
                        max="90"
                        value={form.link_ttl_days}
                        onChange={(e) => setForm({ ...form, link_ttl_days: e.target.value })}
                        className="w-full rounded-md border border-slate-300 px-3 py-2 text-sm focus:border-teal-500 focus:ring-teal-500"
                    />
                </Field>
            </div>
            <p className="text-xs text-slate-500">
                Provider order and models follow the SEO engine waterfall. When the weekly cap is reached, briefings fall
                back to a deterministic template so delivery never blocks.
            </p>
            <div className="flex justify-end">
                <button
                    type="button"
                    onClick={() =>
                        mutation.mutate({
                            weekly_cost_cap_usd: Number(form.weekly_cost_cap_usd),
                            link_ttl_days: Number(form.link_ttl_days),
                        })
                    }
                    disabled={mutation.isPending}
                    className="rounded-md bg-teal-600 px-4 py-2 text-sm font-medium text-white transition hover:bg-teal-700 disabled:opacity-60"
                >
                    {mutation.isPending ? 'Saving…' : 'Save'}
                </button>
            </div>
        </div>
    );
}

function AuditSection() {
    const query = useQuery({
        queryKey: ['ai-briefing-history'],
        queryFn: () => api.get('/crm/settings/ai/history').then((r) => r.data),
        staleTime: 15_000,
    });

    if (query.isLoading) {
        return <AiStateBlock variant="loading" message="Loading run history…" />;
    }
    if (query.isError) {
        return <AiStateBlock variant="error" message="Could not load history." onRetry={() => query.refetch()} />;
    }

    const runs = query.data?.runs || [];
    const interactions = query.data?.interactions || [];

    return (
        <div className="space-y-4">
            <div className="crm-surface p-4">
                <h3 className="text-sm font-semibold text-slate-900">Recent runs</h3>
                {runs.length === 0 ? (
                    <AiStateBlock className="mt-3" variant="empty" title="No runs yet" message="Briefing runs appear here after the first scheduled or manual send." />
                ) : (
                    <div className="mt-3 overflow-x-auto">
                        <table className="min-w-full text-sm">
                            <thead>
                                <tr className="border-b border-slate-200 text-left text-xs uppercase tracking-wide text-slate-500">
                                    <th className="px-2 py-2">When</th>
                                    <th className="px-2 py-2">Audience</th>
                                    <th className="px-2 py-2">Status</th>
                                    <th className="px-2 py-2">Briefings</th>
                                    <th className="px-2 py-2">Cost</th>
                                </tr>
                            </thead>
                            <tbody>
                                {runs.map((r) => (
                                    <tr key={r.id} className="border-b border-slate-100">
                                        <td className="px-2 py-2 text-slate-600">{(r.created_at || '').slice(0, 16).replace('T', ' ')}</td>
                                        <td className="px-2 py-2">{r.audience}</td>
                                        <td className="px-2 py-2">{r.status}</td>
                                        <td className="px-2 py-2">{r.briefings_count ?? '—'}</td>
                                        <td className="px-2 py-2">${Number(r.cost_usd ?? 0).toFixed(4)}</td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>
                )}
            </div>

            <div className="crm-surface p-4">
                <h3 className="text-sm font-semibold text-slate-900">AI interactions</h3>
                {interactions.length === 0 ? (
                    <AiStateBlock className="mt-3" variant="empty" title="No AI calls logged" message="Each provider call is logged here for cost and reliability tracking." />
                ) : (
                    <div className="mt-3 overflow-x-auto">
                        <table className="min-w-full text-sm">
                            <thead>
                                <tr className="border-b border-slate-200 text-left text-xs uppercase tracking-wide text-slate-500">
                                    <th className="px-2 py-2">When</th>
                                    <th className="px-2 py-2">Feature</th>
                                    <th className="px-2 py-2">Provider</th>
                                    <th className="px-2 py-2">Status</th>
                                    <th className="px-2 py-2">Latency</th>
                                    <th className="px-2 py-2">Cost</th>
                                </tr>
                            </thead>
                            <tbody>
                                {interactions.map((it) => (
                                    <tr key={it.id} className="border-b border-slate-100">
                                        <td className="px-2 py-2 text-slate-600">{(it.created_at || '').slice(0, 16).replace('T', ' ')}</td>
                                        <td className="px-2 py-2">{it.feature}</td>
                                        <td className="px-2 py-2">{it.provider || '—'}</td>
                                        <td className="px-2 py-2">
                                            <Badge ok={it.status === 'success'}>{it.status}</Badge>
                                        </td>
                                        <td className="px-2 py-2">{it.latency_ms ?? '—'}ms</td>
                                        <td className="px-2 py-2">${Number(it.est_cost_usd ?? 0).toFixed(6)}</td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>
                )}
            </div>
        </div>
    );
}

function Field({ label, children }) {
    return (
        <label className="block">
            <span className="mb-1 block text-xs font-medium text-slate-600">{label}</span>
            {children}
        </label>
    );
}
