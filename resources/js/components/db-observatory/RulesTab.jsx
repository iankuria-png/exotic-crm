import React, { useEffect, useMemo, useState } from 'react';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import dbObservatory from '../../services/dbObservatory';
import { useToast } from '../ToastProvider';
import { apiError, Empty, ErrorState, fmtAgo, humanize, Loading, Panel, SeverityBadge } from './shared';

function RuleEditor({ rule, canConfigure, markets, onTested }) {
    const toast = useToast();
    const queryClient = useQueryClient();
    const [scope, setScope] = useState('');
    const override = rule.overrides.find((o) => (scope ? String(o.platform_id) === String(scope) : o.scope_key === 'network'));
    const [draft, setDraft] = useState({});
    const [testMarket, setTestMarket] = useState('');

    useEffect(() => {
        setDraft({
            enabled: override?.enabled ?? null,
            severity: override?.severity ?? '',
            thresholds: { ...(override?.thresholds || {}) },
            disabled_lists: override?.disabled_lists || [],
        });
    }, [rule.key, scope, override?.revision]); // eslint-disable-line react-hooks/exhaustive-deps

    const save = useMutation({
        mutationFn: (payload) => dbObservatory.updateRule(rule.key, payload),
        onSuccess: () => { toast.success('Saved — applies to new runs.'); queryClient.invalidateQueries({ queryKey: ['dbo', 'rules'] }); },
        onError: (e) => toast.error(apiError(e)),
    });
    const test = useMutation({
        mutationFn: () => dbObservatory.testRule(rule.key, Number(testMarket)),
        onSuccess: (res) => { toast.success(`Test queued as run ${res.run_id}.`); onTested(res.run_id); },
        onError: (e) => toast.error(apiError(e)),
    });

    const effectiveEnabled = draft.enabled === null || draft.enabled === undefined ? rule.enabled : draft.enabled;

    return (
        <div className="space-y-4 p-4">
            <div>
                <div className="flex flex-wrap items-center gap-2">
                    <h3 className="crm-mono text-base font-semibold text-slate-900">{rule.key}</h3>
                    <SeverityBadge severity={rule.default_severity} />
                    <span className="text-xs text-slate-500">{rule.pack} v{rule.pack_version} · {humanize(rule.kind)} · {rule.version_hash}</span>
                </div>
                <p className="mt-1 text-sm font-medium text-slate-800">{rule.title}</p>
                <p className="mt-1 text-sm text-slate-600">{rule.why}</p>
            </div>

            <div className="grid gap-3 text-xs sm:grid-cols-2">
                <div><p className="font-semibold uppercase tracking-wide text-slate-500">Surfaces</p><p className="crm-mono mt-1 text-slate-700">{rule.surfaces.join(' · ') || 'sweep correlation'}</p></div>
                <div><p className="font-semibold uppercase tracking-wide text-slate-500">Profiles</p><p className="mt-1 capitalize text-slate-700">{rule.profiles.join(', ')}</p></div>
            </div>

            {canConfigure ? (
                <div className="space-y-3 rounded-lg border border-slate-200 bg-slate-50/70 p-3">
                    <div className="flex flex-wrap items-end gap-3">
                        <label className="text-xs font-semibold text-slate-600">Scope
                            <select className="crm-select mt-1 block" value={scope} onChange={(e) => setScope(e.target.value)}>
                                <option value="">Network-wide</option>
                                {markets.map((m) => <option key={m.platform_id} value={m.platform_id}>{m.market}</option>)}
                            </select>
                        </label>
                        <label className="text-xs font-semibold text-slate-600">Enabled
                            <select className="crm-select mt-1 block" value={draft.enabled === null || draft.enabled === undefined ? '' : String(draft.enabled)} onChange={(e) => setDraft({ ...draft, enabled: e.target.value === '' ? null : e.target.value === 'true' })}>
                                <option value="">Inherit ({rule.enabled ? 'on' : 'off'})</option>
                                <option value="true">On</option>
                                <option value="false">Off</option>
                            </select>
                        </label>
                        <label className="text-xs font-semibold text-slate-600">Severity
                            <select className="crm-select mt-1 block" value={draft.severity || ''} onChange={(e) => setDraft({ ...draft, severity: e.target.value })}>
                                <option value="">Inherit ({rule.default_severity})</option>
                                {['critical', 'warn', 'info'].map((s) => <option key={s} value={s}>{s}</option>)}
                            </select>
                        </label>
                    </div>
                    {Object.keys(rule.thresholds || {}).length ? (
                        <div className="flex flex-wrap gap-3">
                            {Object.entries(rule.thresholds).map(([name, def]) => (
                                <label key={name} className="text-xs font-semibold text-slate-600">{humanize(name)} <span className="font-normal text-slate-400">(default {def})</span>
                                    <input type="number" min={0} className="crm-input mt-1 w-36" value={draft.thresholds?.[name] ?? ''} placeholder={String(def)} onChange={(e) => setDraft({ ...draft, thresholds: { ...draft.thresholds, [name]: e.target.value === '' ? undefined : Number(e.target.value) } })} />
                                </label>
                            ))}
                        </div>
                    ) : null}
                    {rule.lists.length ? (
                        <div>
                            <p className="text-xs font-semibold text-slate-600">Lists used ({scope ? 'untick to disable for this market' : 'untick to disable network-wide'})</p>
                            <div className="mt-1 flex flex-wrap gap-2">
                                {rule.lists.map((l) => (
                                    <label key={l} className="crm-mono flex items-center gap-1.5 rounded-md border border-slate-200 bg-white px-2 py-1 text-xs">
                                        <input type="checkbox" checked={!draft.disabled_lists?.includes(l)} onChange={(e) => setDraft({ ...draft, disabled_lists: e.target.checked ? draft.disabled_lists.filter((x) => x !== l) : [...(draft.disabled_lists || []), l] })} />
                                        {l}
                                    </label>
                                ))}
                            </div>
                        </div>
                    ) : null}
                    <div className="flex flex-wrap gap-2">
                        <button
                            type="button"
                            className="crm-btn-primary px-3 py-1.5"
                            disabled={save.isPending}
                            onClick={() => save.mutate({
                                platform_id: scope ? Number(scope) : null,
                                enabled: draft.enabled,
                                severity: draft.severity || null,
                                thresholds: Object.fromEntries(Object.entries(draft.thresholds || {}).filter(([, v]) => v !== undefined && v !== '')),
                                disabled_lists: draft.disabled_lists,
                                revision: override?.revision ?? null,
                            })}
                        >
                            Save {scope ? 'market' : 'network'} override
                        </button>
                        {override ? <button type="button" className="crm-btn-secondary px-3 py-1.5" disabled={save.isPending} onClick={() => save.mutate({ platform_id: scope ? Number(scope) : null, reset: true, revision: override.revision })}>Reset to pack default</button> : null}
                    </div>
                    <p className="text-xs text-slate-500">Effective now: {effectiveEnabled ? 'enabled' : 'disabled'}. Phase 1 changes enablement, severity, bounded thresholds and lists only; patterns and rule bodies ship in reviewed packs.</p>
                </div>
            ) : null}

            {rule.overrides.length ? (
                <div>
                    <p className="text-xs font-semibold uppercase tracking-wide text-slate-500">Overrides</p>
                    <ul className="mt-1 space-y-1 text-xs text-slate-700">
                        {rule.overrides.map((o) => (
                            <li key={o.id}><span className="font-semibold">{o.market}</span>: {[o.enabled === false ? 'disabled' : o.enabled ? 'enabled' : null, o.severity ? `severity ${o.severity}` : null, o.thresholds ? `thresholds ${JSON.stringify(o.thresholds)}` : null, o.disabled_lists?.length ? `lists off: ${o.disabled_lists.join(', ')}` : null].filter(Boolean).join(' · ') || 'no changes'}</li>
                        ))}
                    </ul>
                </div>
            ) : null}

            <div className="grid gap-3 sm:grid-cols-2">
                <div className="rounded-lg border border-slate-200 p-3 text-sm"><p className="text-[10px] font-semibold uppercase tracking-[0.12em] text-slate-500">How to fix</p><p className="mt-1 text-slate-700">{rule.remediation}</p></div>
                {canConfigure ? (
                    <div className="rounded-lg border border-slate-200 p-3">
                        <p className="text-[10px] font-semibold uppercase tracking-[0.12em] text-slate-500">Test on market</p>
                        <p className="mt-1 text-xs text-slate-500">Runs this rule once with normal limits and shows up to 20 redacted samples. Never creates findings.</p>
                        <div className="mt-2 flex gap-2">
                            <select aria-label="Test market" className="crm-select flex-1" value={testMarket} onChange={(e) => setTestMarket(e.target.value)}>
                                <option value="">Choose a market</option>
                                {markets.filter((m) => m.connection?.preflight_status === 'passed').map((m) => <option key={m.platform_id} value={m.platform_id}>{m.market}</option>)}
                            </select>
                            <button type="button" className="crm-btn-secondary px-3" disabled={!testMarket || test.isPending} onClick={() => test.mutate()}>Test</button>
                        </div>
                    </div>
                ) : null}
            </div>
            {rule.references?.length ? (
                <p className="text-xs text-slate-500">References: {rule.references.map((r) => <span key={r} className="crm-mono mr-2 break-all">{r}</span>)}</p>
            ) : null}
        </div>
    );
}

function ListsPanel({ canConfigure, markets }) {
    const toast = useToast();
    const queryClient = useQueryClient();
    const query = useQuery({ queryKey: ['dbo', 'lists'], queryFn: dbObservatory.lists });
    const [selected, setSelected] = useState(null);
    const [scope, setScope] = useState('');
    const [text, setText] = useState('');

    const networkLists = (query.data?.data || []).filter((l) => l.scope_key === 'network');
    const current = (query.data?.data || []).find((l) => l.key === selected && (scope ? String(l.platform_id) === String(scope) : l.scope_key === 'network'));

    useEffect(() => {
        setText((current?.entries || []).map((e) => (e.note && e.note !== 'Shipped default' ? `${e.value}  # ${e.note}` : e.value)).join('\n'));
    }, [selected, scope, current?.revision]); // eslint-disable-line react-hooks/exhaustive-deps

    const save = useMutation({
        mutationFn: () => dbObservatory.updateList(selected, {
            platform_id: scope ? Number(scope) : null,
            revision: current?.revision ?? null,
            entries: text.split('\n').map((line) => line.trim()).filter(Boolean).map((line) => {
                const [value, ...note] = line.split('#');
                return { value: value.trim(), note: note.join('#').trim() || null };
            }).filter((e) => e.value),
        }),
        onSuccess: (res) => { toast.success(`Saved ${res.entries} entries.`); queryClient.invalidateQueries({ queryKey: ['dbo', 'lists'] }); },
        onError: (e) => toast.error(apiError(e)),
    });

    if (query.isLoading) return <Loading rows={4} />;
    if (query.isError) return <ErrorState error={query.error} onRetry={query.refetch} />;

    return (
        <div className="grid gap-3 lg:grid-cols-[18rem_1fr]">
            <ul className="max-h-[36rem] divide-y divide-slate-100 overflow-y-auto rounded-lg border border-slate-200">
                {networkLists.map((l) => (
                    <li key={l.key}>
                        <button type="button" onClick={() => setSelected(l.key)} className={`block w-full px-3 py-2 text-left hover:bg-slate-50 ${selected === l.key ? 'bg-teal-50' : ''}`}>
                            <p className="crm-mono text-xs font-semibold text-slate-800">{l.key}</p>
                            <p className="text-xs text-slate-500">{l.entries.length} entries · {l.kind}</p>
                        </button>
                    </li>
                ))}
            </ul>
            {!selected ? <Empty title="Choose a list">Allowlists are exact (entries ending in * are reviewed prefixes); IOC lists hold vetted indicators with their source in the note.</Empty> : (
                <div className="space-y-2">
                    <p className="text-sm text-slate-600">{networkLists.find((l) => l.key === selected)?.description}</p>
                    <label className="text-xs font-semibold text-slate-600">Scope
                        <select className="crm-select ml-2" value={scope} onChange={(e) => setScope(e.target.value)}>
                            <option value="">Network</option>
                            {markets.map((m) => <option key={m.platform_id} value={m.platform_id}>{m.market} (adds to network)</option>)}
                        </select>
                    </label>
                    <textarea
                        aria-label="List entries, one per line"
                        className="crm-input crm-mono min-h-[22rem] text-xs"
                        value={text}
                        onChange={(e) => setText(e.target.value)}
                        readOnly={!canConfigure}
                        placeholder="One value per line. Add a note after #, e.g. GTM-ABC123  # approved by Ian 2026-10-01"
                    />
                    {canConfigure ? <button type="button" className="crm-btn-primary" disabled={save.isPending} onClick={() => save.mutate()}>Save list</button> : null}
                    {current ? <p className="text-xs text-slate-500">Revision {current.revision} · updated {fmtAgo(current.updated_at)}. Changes apply to new runs.</p> : null}
                </div>
            )}
        </div>
    );
}

export default function RulesTab({ canConfigure, markets, onOpenRun }) {
    const query = useQuery({ queryKey: ['dbo', 'rules'], queryFn: dbObservatory.rules });
    const [view, setView] = useState('rules');
    const [selected, setSelected] = useState(null);
    const [category, setCategory] = useState('');
    const [search, setSearch] = useState('');

    const rules = useMemo(() => (query.data?.data || []).filter((r) => (!category || r.category === category) && (!search || r.key.includes(search.toLowerCase()) || r.title.toLowerCase().includes(search.toLowerCase()))), [query.data, category, search]);
    const rule = (query.data?.data || []).find((r) => r.key === selected);

    return (
        <Panel
            title={view === 'rules' ? 'Rules' : 'Lists'}
            subtitle="Shipped packs: core, malware and exotic. Every finding keeps the pack version that produced it."
            action={(
                <div className="flex gap-1">
                    {['rules', 'lists'].map((v) => (
                        <button key={v} type="button" onClick={() => setView(v)} className={`rounded-lg border px-3 py-1.5 text-sm font-semibold ${view === v ? 'border-teal-300 bg-teal-50 text-teal-800' : 'border-slate-200 text-slate-600'}`}>{humanize(v)}</button>
                    ))}
                </div>
            )}
        >
            {view === 'lists' ? <div className="p-4"><ListsPanel canConfigure={canConfigure} markets={markets} /></div> : query.isLoading ? <Loading rows={6} /> : query.isError ? <ErrorState error={query.error} onRetry={query.refetch} /> : (
                <div className="grid lg:grid-cols-[22rem_1fr]">
                    <div className="border-b border-slate-100 lg:border-b-0 lg:border-r">
                        <div className="flex gap-2 p-3">
                            <select aria-label="Category" className="crm-select py-1.5 text-sm" value={category} onChange={(e) => setCategory(e.target.value)}>
                                <option value="">All</option>
                                {['malware', 'access', 'persistence', 'content', 'config', 'seo', 'hygiene'].map((c) => <option key={c} value={c}>{humanize(c)}</option>)}
                            </select>
                            <input aria-label="Search rules" className="crm-input py-1.5" placeholder="Search" value={search} onChange={(e) => setSearch(e.target.value)} />
                        </div>
                        <ul className="max-h-[40rem] divide-y divide-slate-100 overflow-y-auto">
                            {rules.map((r) => {
                                const overridden = r.overrides.length > 0;
                                return (
                                    <li key={r.key}>
                                        <button type="button" onClick={() => setSelected(r.key)} className={`flex w-full items-start gap-2 px-3 py-2 text-left hover:bg-slate-50 ${selected === r.key ? 'bg-teal-50' : ''}`}>
                                            <span className={`mt-1.5 h-2 w-2 shrink-0 rounded-full ${r.enabled ? 'bg-teal-500' : 'bg-slate-300'}`} aria-label={r.enabled ? 'enabled' : 'disabled'} />
                                            <span className="min-w-0">
                                                <span className="crm-mono block truncate text-xs font-semibold text-slate-800">{r.key}</span>
                                                <span className="block truncate text-xs text-slate-500">{r.default_severity}{overridden ? ` · ${r.overrides.length} override(s)` : ''}</span>
                                            </span>
                                        </button>
                                    </li>
                                );
                            })}
                        </ul>
                    </div>
                    {rule ? <RuleEditor rule={rule} canConfigure={canConfigure} markets={markets} onTested={onOpenRun} /> : <Empty title="Select a rule">Rules reference named database surfaces and never contain SQL. Choose one to see why it matters, its thresholds and lists.</Empty>}
                </div>
            )}
        </Panel>
    );
}
