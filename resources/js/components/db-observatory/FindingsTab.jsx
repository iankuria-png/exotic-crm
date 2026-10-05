import React, { useEffect, useState } from 'react';
import { keepPreviousData, useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import dbObservatory from '../../services/dbObservatory';
import { useAuth } from '../../hooks/useAuth';
import { useToast } from '../ToastProvider';
import {
    apiError, ConfidenceBadge, CopyId, Drawer, Empty, ErrorState, fmtAgo, fmtDateTime, humanize, InertCode, Loading, Panel, SeverityBadge, Status,
} from './shared';

const STATUS_VIEWS = [
    ['active', 'Open & acknowledged'],
    ['unresolved', 'All unresolved'],
    ['suppressed', 'Suppressed'],
    ['resolved', 'Resolved'],
    ['', 'Everything'],
];

const BEHAVIORS = ['backdoor', 'injected_loader', 'redirect', 'fake_update', 'overlay', 'obfuscation', 'credential_theft', 'known_campaign', 'reinfection', 'cross_market_campaign', 'third_party_execution', 'business_review', 'persistence', 'stored_code', 'gtm_unbaselined'];

function FindingActions({ data, canOperate, canConfigure, onDone }) {
    const toast = useToast();
    const { user } = useAuth();
    const queryClient = useQueryClient();
    const finding = data.finding;
    const [mode, setMode] = useState(null);
    const [note, setNote] = useState('');
    const [suppress, setSuppress] = useState({ scope: 'finding', match: 'subject', reason: '', expires_in_days: 90 });
    const [preview, setPreview] = useState(null);

    const refresh = () => {
        queryClient.invalidateQueries({ queryKey: ['dbo'] });
        onDone?.();
    };
    const update = useMutation({
        mutationFn: (payload) => dbObservatory.updateFinding(finding.id, payload),
        onSuccess: () => { toast.success('Finding updated.'); setMode(null); setNote(''); refresh(); },
        onError: (e) => toast.error(apiError(e)),
    });
    const suppression = useMutation({
        mutationFn: (payload) => dbObservatory.suppress(finding.id, payload),
        onSuccess: (res, payload) => {
            if (res.preview && (payload.preview || res.requires_acknowledgement) && !res.suppression_id) {
                setPreview(res);
                return;
            }
            toast.success(`Suppressed ${res.preview?.findings ?? 1} finding(s).`);
            setMode(null);
            setPreview(null);
            refresh();
        },
        onError: (e) => toast.error(apiError(e)),
    });
    const revoke = useMutation({
        mutationFn: () => dbObservatory.revokeSuppression(data.suppression.id),
        onSuccess: () => { toast.success('Suppression revoked; findings reopened.'); refresh(); },
        onError: (e) => toast.error(apiError(e)),
    });
    const rescan = useMutation({
        mutationFn: () => dbObservatory.startPass({ profile: 'standard', markets: [finding.platform_id] }),
        onSuccess: (res) => { toast.success(`Rescan queued as pass ${res.pass_id}.`); refresh(); },
        onError: (e) => toast.error(apiError(e)),
    });

    if (!canOperate) {
        return <p className="text-xs text-slate-500">Read-only: administrators triage findings.</p>;
    }

    const busy = update.isPending || suppression.isPending;
    const unresolved = finding.status !== 'resolved';

    return (
        <div className="space-y-3">
            <div className="flex flex-wrap gap-2">
                {unresolved && finding.status !== 'acknowledged' ? <button type="button" className="crm-btn-primary px-3 py-1.5" disabled={busy} onClick={() => update.mutate({ status: 'acknowledged' })}>Acknowledge</button> : null}
                <button type="button" className="crm-btn-secondary px-3 py-1.5" disabled={busy} onClick={() => update.mutate({ assigned_to: user?.id })}>Assign to me</button>
                {unresolved ? <button type="button" className="crm-btn-secondary px-3 py-1.5" disabled={busy} onClick={() => update.mutate({ status: 'snoozed', snoozed_until: new Date(Date.now() + 7 * 864e5).toISOString() })}>Snooze 7d</button> : null}
                {unresolved ? <button type="button" className="crm-btn-secondary px-3 py-1.5" disabled={busy} onClick={() => setMode(mode === 'fp' ? null : 'fp')}>False positive…</button> : null}
                {['acknowledged', 'snoozed', 'false_positive', 'resolved'].includes(finding.status) ? <button type="button" className="crm-btn-secondary px-3 py-1.5" disabled={busy} onClick={() => update.mutate({ status: 'open' })}>Reopen</button> : null}
                {canConfigure && unresolved && data.rule?.allowlistable && finding.confidence !== 'confirmed' ? <button type="button" className="crm-btn-secondary px-3 py-1.5" disabled={busy} onClick={() => setMode(mode === 'suppress' ? null : 'suppress')}>Suppress…</button> : null}
                <button type="button" className="crm-btn-secondary px-3 py-1.5" disabled={rescan.isPending} onClick={() => rescan.mutate()}>Rescan market</button>
            </div>

            {mode === 'fp' ? (
                <div className="space-y-2 rounded-lg border border-slate-200 bg-slate-50 p-3">
                    <label className="block text-xs font-semibold text-slate-600" htmlFor="fp-note">Why is this a false positive? (required, audited)</label>
                    <textarea id="fp-note" className="crm-input min-h-[4rem]" value={note} onChange={(e) => setNote(e.target.value)} maxLength={1000} />
                    <button type="button" className="crm-btn-primary px-3 py-1.5" disabled={busy || note.trim().length < 3} onClick={() => update.mutate({ status: 'false_positive', note })}>Mark false positive</button>
                    <p className="text-xs text-slate-500">A disposition never proves absence. If the payload or rule changes, the finding reopens for review.</p>
                </div>
            ) : null}

            {mode === 'suppress' ? (
                <div className="space-y-2 rounded-lg border border-slate-200 bg-slate-50 p-3">
                    <div className="grid gap-2 sm:grid-cols-3">
                        <label className="text-xs font-semibold text-slate-600">Scope
                            <select className="crm-select mt-1 w-full" value={suppress.scope} onChange={(e) => { setPreview(null); setSuppress({ ...suppress, scope: e.target.value }); }}>
                                <option value="finding">This market (exact subject)</option>
                                <option value="market">This market</option>
                                <option value="network">Network-wide</option>
                            </select>
                        </label>
                        <label className="text-xs font-semibold text-slate-600">Match
                            <select className="crm-select mt-1 w-full" value={suppress.match} onChange={(e) => { setPreview(null); setSuppress({ ...suppress, match: e.target.value }); }}>
                                <option value="subject">Same row and field</option>
                                <option value="payload" disabled={finding.evidence.payload_hash_type !== 'full'}>Same exact payload</option>
                            </select>
                        </label>
                        <label className="text-xs font-semibold text-slate-600">Expires in (days)
                            <input type="number" min={1} max={365} className="crm-input mt-1" value={suppress.expires_in_days} onChange={(e) => setSuppress({ ...suppress, expires_in_days: Number(e.target.value) })} />
                        </label>
                    </div>
                    <label className="block text-xs font-semibold text-slate-600">Reason (audited)
                        <input className="crm-input mt-1" value={suppress.reason} onChange={(e) => setSuppress({ ...suppress, reason: e.target.value })} maxLength={500} />
                    </label>
                    {preview ? (
                        <p className="rounded-md bg-amber-50 px-3 py-2 text-sm text-amber-900">
                            Affects {preview.preview.findings} finding(s) on {preview.preview.markets} market(s){preview.preview.market_names?.length ? `: ${preview.preview.market_names.join(', ')}` : ''}.
                        </p>
                    ) : null}
                    <div className="flex gap-2">
                        <button type="button" className="crm-btn-secondary px-3 py-1.5" disabled={busy || suppress.reason.length < 5} onClick={() => suppression.mutate({ ...suppress, scope: suppress.scope, preview: true })}>Preview</button>
                        <button
                            type="button"
                            className="crm-btn-primary px-3 py-1.5"
                            disabled={busy || suppress.reason.length < 5 || (suppress.scope === 'network' && !preview)}
                            onClick={() => suppression.mutate({ ...suppress, acknowledge_preview: suppress.scope === 'network' })}
                        >
                            Create suppression
                        </button>
                    </div>
                    <p className="text-xs text-slate-500">Suppressions are exact and expire. A changed payload or rule version reopens review. Network-wide suppression needs the preview first.</p>
                </div>
            ) : null}

            {data.suppression && !data.suppression.revoked_at && canConfigure ? (
                <button type="button" className="text-sm font-semibold text-rose-700 hover:underline" disabled={revoke.isPending} onClick={() => revoke.mutate()}>Revoke suppression (expires {fmtDateTime(data.suppression.expires_at)})</button>
            ) : null}
        </div>
    );
}

export function FindingDrawer({ findingId, onClose, canOperate, canConfigure }) {
    const query = useQuery({ queryKey: ['dbo', 'finding', findingId], queryFn: () => dbObservatory.finding(findingId), enabled: Boolean(findingId) });
    const d = query.data;
    const f = d?.finding;

    return (
        <Drawer
            open={Boolean(findingId)}
            onClose={onClose}
            title={f ? f.title : 'Finding'}
            subtitle={f ? (
                <span className="flex flex-wrap items-center gap-2">
                    <SeverityBadge severity={f.severity} /><ConfidenceBadge confidence={f.confidence} /><Status value={f.status} />
                    <span>{f.market}</span>
                </span>
            ) : null}
            footer={d ? <FindingActions data={d} canOperate={canOperate} canConfigure={canConfigure} onDone={() => query.refetch()} /> : null}
        >
            {!findingId ? null : query.isError ? <ErrorState error={query.error} onRetry={query.refetch} /> : !f ? <Loading rows={5} /> : (
                <div className="space-y-4 text-sm">
                    <dl className="grid grid-cols-2 gap-x-4 gap-y-2 rounded-lg border border-slate-200 p-3 sm:grid-cols-3">
                        <div><dt className="text-[10px] font-semibold uppercase tracking-wide text-slate-500">Rule</dt><dd className="crm-mono text-xs">{f.rule_key}</dd></div>
                        <div><dt className="text-[10px] font-semibold uppercase tracking-wide text-slate-500">Pack</dt><dd className="text-xs">{f.pack} v{f.pack_version} · <CopyId value={f.rule_version_hash} label="rule version" /></dd></div>
                        <div><dt className="text-[10px] font-semibold uppercase tracking-wide text-slate-500">Behavior</dt><dd className="text-xs">{f.behavior ? humanize(f.behavior) : '—'}</dd></div>
                        <div><dt className="text-[10px] font-semibold uppercase tracking-wide text-slate-500">First seen</dt><dd className="text-xs">{fmtDateTime(f.first_seen_at)}</dd></div>
                        <div><dt className="text-[10px] font-semibold uppercase tracking-wide text-slate-500">Last seen</dt><dd className="text-xs">{fmtDateTime(f.last_seen_at)} · {f.occurrences} run(s)</dd></div>
                        <div><dt className="text-[10px] font-semibold uppercase tracking-wide text-slate-500">Finding ID</dt><dd><CopyId value={f.id} label="finding id" /></dd></div>
                    </dl>

                    <section>
                        <h4 className="text-[10px] font-semibold uppercase tracking-[0.12em] text-slate-500">Where it lives</h4>
                        <div className="mt-1.5 grid gap-x-4 gap-y-1 rounded-lg bg-slate-50 p-3 text-xs sm:grid-cols-2">
                            <p>Surface <span className="crm-mono font-semibold">{f.subject.surface}</span></p>
                            <p>Table <span className="crm-mono font-semibold">{f.subject.table}</span></p>
                            {f.subject.row_id !== undefined ? <p>Row <span className="crm-mono font-semibold">{f.subject.row_id}</span></p> : null}
                            {f.subject.field ? <p>Field <span className="crm-mono font-semibold">{f.subject.field}</span></p> : null}
                            {f.subject.label ? <p className="sm:col-span-2">Name <span className="crm-mono font-semibold">{f.subject.label}</span></p> : null}
                            {f.subject.object_type ? <p>WordPress object <span className="crm-mono font-semibold">{f.subject.object_type} {f.subject.object_id ?? ''}</span></p> : null}
                            {f.subject.item ? <p className="sm:col-span-2">Item <span className="crm-mono font-semibold">{f.subject.item}</span></p> : null}
                        </div>
                    </section>

                    <section>
                        <h4 className="text-[10px] font-semibold uppercase tracking-[0.12em] text-slate-500">Evidence (redacted, inert text)</h4>
                        {(f.evidence.excerpts || []).length ? f.evidence.excerpts.map((x, i) => <InertCode key={i} className="mt-1.5">{x}</InertCode>) : <p className="mt-1 text-xs text-slate-500">No excerpt stored for this finding type.</p>}
                        {f.evidence.details ? <InertCode className="mt-1.5 !text-slate-200">{JSON.stringify(f.evidence.details, null, 2)}</InertCode> : null}
                        <div className="mt-2 flex flex-wrap gap-1">
                            {(f.evidence.signals || []).map((s) => <span key={s} className="crm-mono rounded bg-slate-100 px-1.5 py-0.5 text-[11px] text-slate-700">{s}</span>)}
                        </div>
                        {(f.evidence.transformations || []).length ? <p className="mt-1.5 text-xs text-slate-600">Decoded via <span className="crm-mono font-semibold">{f.evidence.transformations.join(' → ')}</span></p> : null}
                        <div className="mt-1.5 flex flex-wrap items-center gap-3 text-xs text-slate-500">
                            {f.evidence.payload_sha256 ? <span>{f.evidence.payload_hash_type === 'full' ? 'Payload' : 'Fragment'} SHA-256 <CopyId value={f.evidence.payload_sha256} label="hash" /></span> : null}
                            {f.evidence.decoded_sha256 ? <span>Decoded SHA-256 <CopyId value={f.evidence.decoded_sha256} label="hash" /></span> : null}
                            {f.evidence.bytes ? <span>{f.evidence.bytes} bytes{f.evidence.fully_read === false ? ' (value larger than 64 KiB — tail not examined)' : ''}</span> : null}
                        </div>
                    </section>

                    {d.rule ? (
                        <section className="grid gap-3 sm:grid-cols-2">
                            <div className="rounded-lg border border-slate-200 p-3">
                                <h4 className="text-[10px] font-semibold uppercase tracking-[0.12em] text-slate-500">Why it matters</h4>
                                <p className="mt-1 text-slate-700">{d.rule.why}</p>
                            </div>
                            <div className="rounded-lg border border-slate-200 p-3">
                                <h4 className="text-[10px] font-semibold uppercase tracking-[0.12em] text-slate-500">How to fix</h4>
                                <p className="mt-1 text-slate-700">{d.rule.remediation}</p>
                                <p className="mt-2 text-xs text-slate-500">The scanner never writes to market databases. After cleanup, a complete verification scan resolves this automatically.</p>
                            </div>
                        </section>
                    ) : null}

                    <section>
                        <h4 className="text-[10px] font-semibold uppercase tracking-[0.12em] text-slate-500">History</h4>
                        <ol className="mt-1.5 space-y-1.5 border-l border-slate-200 pl-3">
                            {d.events.map((e) => (
                                <li key={e.id} className="text-xs text-slate-600">
                                    <span className="font-semibold text-slate-800">{humanize(e.type)}</span>
                                    {e.to_status ? ` → ${humanize(e.to_status)}` : ''} · {e.actor || 'Scanner'} · {fmtAgo(e.created_at)}
                                    {e.note ? <span className="block text-slate-500">{e.note}</span> : null}
                                </li>
                            ))}
                        </ol>
                        <p className="mt-2 text-xs text-slate-500">Seen in runs: {d.observations.map((o) => o.run_id).join(', ') || '—'}</p>
                    </section>
                </div>
            )}
        </Drawer>
    );
}

export default function FindingsTab({ initialFilters, canOperate, canConfigure, markets }) {
    const toast = useToast();
    const [filters, setFilters] = useState({ status: 'active', page: 1, group_by: 'rule', ...initialFilters });
    const [openId, setOpenId] = useState(initialFilters?.finding || null);
    const [exporting, setExporting] = useState(false);

    useEffect(() => {
        if (initialFilters) {
            setFilters({ status: 'active', page: 1, group_by: 'rule', ...initialFilters });
            if (initialFilters.finding) setOpenId(initialFilters.finding);
        }
    }, [initialFilters]);

    const params = Object.fromEntries(Object.entries(filters).filter(([k, v]) => v !== '' && v !== null && v !== undefined && k !== 'finding' && !k.startsWith('_')));
    const query = useQuery({
        queryKey: ['dbo', 'findings', params],
        queryFn: () => dbObservatory.findings({ ...params, per_page: 50 }),
        placeholderData: keepPreviousData,
    });

    const set = (patch) => setFilters((f) => ({ ...f, page: 1, ...patch }));
    const malwareOn = filters.category === 'malware';

    const exportCsv = async () => {
        setExporting(true);
        try {
            const response = await dbObservatory.exportFindings({ ...params, group_by: undefined, page: undefined });
            const url = window.URL.createObjectURL(new Blob([response.data], { type: 'text/csv' }));
            const a = document.createElement('a');
            a.href = url;
            a.download = `db-observatory-findings-${new Date().toISOString().slice(0, 10)}.csv`;
            a.click();
            window.URL.revokeObjectURL(url);
            if (response.headers['x-export-truncated'] === '1') {
                toast.warning(`Export capped at 10,000 of ${response.headers['x-export-total']} rows — narrow the filters for the rest.`);
            }
        } catch (error) {
            toast.error(apiError(error, 'Export failed.'));
        } finally {
            setExporting(false);
        }
    };

    const facets = query.data?.facets || {};

    return (
        <div className="space-y-3">
            <div className="crm-surface flex flex-wrap items-center gap-2 px-4 py-3">
                <button
                    type="button"
                    onClick={() => set({ category: malwareOn ? '' : 'malware', confidence: '', behavior: '' })}
                    className={`rounded-lg border px-3 py-1.5 text-sm font-semibold transition ${malwareOn ? 'border-rose-300 bg-rose-600 text-white' : 'border-slate-300 bg-white text-slate-700 hover:border-rose-300'}`}
                    aria-pressed={malwareOn}
                >
                    Malware{facets.category?.malware ? ` · ${facets.category.malware}` : ''}
                </button>
                <select aria-label="Status" className="crm-select py-1.5 text-sm" value={filters.status} onChange={(e) => set({ status: e.target.value })}>
                    {STATUS_VIEWS.map(([v, l]) => <option key={v} value={v}>{l}</option>)}
                </select>
                <select aria-label="Severity" className="crm-select py-1.5 text-sm" value={filters.severity || ''} onChange={(e) => set({ severity: e.target.value })}>
                    <option value="">All severities</option>
                    {['critical', 'warn', 'info'].map((s) => <option key={s} value={s}>{humanize(s)}{facets.severity?.[s] ? ` (${facets.severity[s]})` : ''}</option>)}
                </select>
                {!malwareOn ? (
                    <select aria-label="Category" className="crm-select py-1.5 text-sm" value={filters.category || ''} onChange={(e) => set({ category: e.target.value })}>
                        <option value="">All categories</option>
                        {['access', 'persistence', 'content', 'config', 'seo', 'hygiene'].map((c) => <option key={c} value={c}>{humanize(c)}{facets.category?.[c] ? ` (${facets.category[c]})` : ''}</option>)}
                    </select>
                ) : (
                    <>
                        <select aria-label="Confidence" className="crm-select py-1.5 text-sm" value={filters.confidence || ''} onChange={(e) => set({ confidence: e.target.value })}>
                            <option value="">Any confidence</option>
                            <option value="confirmed">Confirmed indicator</option>
                            <option value="strong">Strong behavioral match</option>
                            <option value="needs_review">Needs review</option>
                        </select>
                        <select aria-label="Behavior" className="crm-select py-1.5 text-sm" value={filters.behavior || ''} onChange={(e) => set({ behavior: e.target.value })}>
                            <option value="">Any behavior</option>
                            {BEHAVIORS.map((b) => <option key={b} value={b}>{humanize(b)}{facets.behavior?.[b] ? ` (${facets.behavior[b]})` : ''}</option>)}
                        </select>
                    </>
                )}
                <select aria-label="Market" className="crm-select py-1.5 text-sm" value={filters.platform_id || ''} onChange={(e) => set({ platform_id: e.target.value })}>
                    <option value="">All my markets</option>
                    {(markets || []).map((m) => <option key={m.platform_id} value={m.platform_id}>{m.market}</option>)}
                </select>
                <input aria-label="Search findings" className="crm-input w-44 py-1.5" placeholder="Search title or rule" value={filters.q || ''} onChange={(e) => set({ q: e.target.value })} />
                {filters.run_id ? <button type="button" className="rounded-md bg-teal-50 px-2 py-1 text-xs font-semibold text-teal-800" onClick={() => set({ run_id: '' })}>Run {filters.run_id} ✕</button> : null}
                {filters.kind ? <button type="button" className="rounded-md bg-teal-50 px-2 py-1 text-xs font-semibold text-teal-800" onClick={() => set({ kind: '' })}>{humanize(filters.kind)} rules ✕</button> : null}
                {filters.identity ? <button type="button" className="max-w-xs truncate rounded-md bg-teal-50 px-2 py-1 text-xs font-semibold text-teal-800" aria-label={`Remove identity filter ${filters.identity}`} onClick={() => set({ identity: '', definition_hash: '' })}>{filters.identity}{filters.definition_hash ? ' · matching privileges' : ''} ✕</button> : null}
                {filters.rule_key ? <button type="button" className="crm-mono rounded-md bg-teal-50 px-2 py-1 text-xs font-semibold text-teal-800" onClick={() => set({ rule_key: '' })}>{filters.rule_key} ✕</button> : null}
                <div className="ml-auto flex gap-2">
                    <select aria-label="Group by" className="crm-select py-1.5 text-sm" value={filters.group_by || ''} onChange={(e) => set({ group_by: e.target.value })}>
                        <option value="rule">Group by rule</option>
                        <option value="market">Group by market</option>
                        <option value="">No grouping</option>
                    </select>
                    <button type="button" className="crm-btn-secondary px-3 py-1.5" onClick={exportCsv} disabled={exporting}>{exporting ? 'Exporting…' : 'Export CSV'}</button>
                </div>
            </div>

            {malwareOn ? (
                <p className="rounded-lg border border-slate-200 bg-white px-4 py-2 text-xs text-slate-600">
                    <span className="font-semibold">Confirmed</span> means a vetted exact indicator matched. <span className="font-semibold">Strong</span> means behaviour, remote target and execution context align. <span className="font-semibold">Needs review</span> is a single generic signal — a lone script, Base64 string or foreign text is never labelled malware.
                </p>
            ) : null}

            <div className={`grid gap-3 ${query.data?.groups ? 'xl:grid-cols-[20rem_1fr]' : ''}`}>
                {query.data?.groups ? (
                    <Panel title={filters.group_by === 'market' ? 'By market' : 'By rule'} bodyClass="max-h-[40rem] divide-y divide-slate-100 overflow-y-auto">
                        {query.data.groups.length === 0 ? <p className="px-4 py-4 text-sm text-slate-500">No groups.</p> : query.data.groups.map((g) => {
                            const active = filters.group_by === 'market' ? String(filters.platform_id) === g.key : filters.rule_key === g.key;
                            return (
                                <button key={g.key} type="button" onClick={() => set(filters.group_by === 'market' ? { platform_id: active ? '' : g.key } : { rule_key: active ? '' : g.key })} className={`block w-full px-4 py-2 text-left transition hover:bg-slate-50 ${active ? 'bg-teal-50' : ''}`}>
                                    <p className={`truncate text-sm font-semibold text-slate-800 ${filters.group_by === 'rule' ? 'crm-mono text-xs' : ''}`}>{g.label}</p>
                                    <p className="text-xs text-slate-500">
                                        {g.critical ? <span className="font-semibold text-rose-700">{g.critical} critical · </span> : null}
                                        {g.findings} findings · {g.markets} market(s)
                                    </p>
                                </button>
                            );
                        })}
                    </Panel>
                ) : null}

                <Panel bodyClass="">
                    {query.isLoading ? <Loading rows={6} /> : query.isError ? <ErrorState error={query.error} onRetry={query.refetch} /> : query.data.data.length === 0 ? (
                        <Empty title={malwareOn ? 'No malware findings match these filters' : 'No findings match these filters'}>
                            This does not mean the database is clean — it only reflects surfaces that were scanned. Open a market's latest run to review its coverage.
                        </Empty>
                    ) : (
                        <>
                            <ul className="divide-y divide-slate-100">
                                {query.data.data.map((f) => (
                                    <li key={f.id}>
                                        <button type="button" onClick={() => setOpenId(f.id)} className="grid w-full grid-cols-[auto_1fr_auto] items-start gap-3 px-4 py-2.5 text-left transition hover:bg-slate-50">
                                            <div className="pt-0.5"><SeverityBadge severity={f.severity} /></div>
                                            <div className="min-w-0">
                                                <p className="truncate text-sm font-semibold text-slate-900">{f.title}</p>
                                                <p className="truncate text-xs text-slate-500">
                                                    <span className="font-semibold text-slate-700">{f.market}</span> · <span className="crm-mono">{f.subject.table}{f.subject.row_id !== undefined ? ` #${f.subject.row_id}` : ''}{f.subject.field ? ` · ${f.subject.field}` : ''}{f.subject.item && f.subject.row_id === undefined ? ` · ${f.subject.item}` : ''}</span>
                                                </p>
                                                {f.evidence.excerpts?.[0] ? <p className="crm-mono mt-0.5 truncate text-[11px] text-slate-500">{f.evidence.excerpts[0]}</p> : null}
                                            </div>
                                            <div className="flex flex-col items-end gap-1">
                                                <div className="flex gap-1"><ConfidenceBadge confidence={f.confidence} /><Status value={f.status} /></div>
                                                <span className="text-[11px] text-slate-400">{fmtAgo(f.last_seen_at)} · {f.occurrences}×</span>
                                            </div>
                                        </button>
                                    </li>
                                ))}
                            </ul>
                            <div className="flex items-center justify-between border-t border-slate-100 px-4 py-3 text-sm text-slate-500">
                                <span>{query.data.meta.total} findings</span>
                                <div className="flex gap-2">
                                    <button type="button" className="crm-btn-secondary px-3 py-1" disabled={filters.page <= 1} onClick={() => setFilters((f) => ({ ...f, page: f.page - 1 }))}>Previous</button>
                                    <button type="button" className="crm-btn-secondary px-3 py-1" disabled={filters.page >= query.data.meta.last_page} onClick={() => setFilters((f) => ({ ...f, page: f.page + 1 }))}>Next</button>
                                </div>
                            </div>
                        </>
                    )}
                </Panel>
            </div>

            <FindingDrawer findingId={openId} onClose={() => setOpenId(null)} canOperate={canOperate} canConfigure={canConfigure} />
        </div>
    );
}
