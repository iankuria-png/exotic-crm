import React, { useState } from 'react';
import { useSearchParams } from 'react-router-dom';
import { activityDate, domainLabel, FAMILIES, FILTER_KEYS, readFindingFilters } from './interpretation';
import { keepPreviousData, useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import CampaignContainment from './CampaignContainment';
import ContainmentPanel from './ContainmentPanel';
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
    ['contained', 'Contained'],
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
        onSuccess: (_res, payload) => { toast.success(payload.record_remediation ? 'Remediation report recorded; verification is still needed.' : 'Finding updated.'); setMode(null); setNote(''); refresh(); },
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
    const unresolved = !['resolved','contained'].includes(finding.status);

    return (
        <div className="space-y-3">
            <div className="flex flex-wrap gap-2">
                {['open', 'acknowledged'].includes(finding.status) ? <button type="button" className="crm-btn-primary px-3 py-1.5" disabled={busy} onClick={() => setMode(mode === 'remediation' ? null : 'remediation')}>Record remediation</button> : null}
                {unresolved && finding.status !== 'acknowledged' ? <button type="button" className="crm-btn-primary px-3 py-1.5" disabled={busy} onClick={() => update.mutate({ status: 'acknowledged' })}>Acknowledge</button> : null}
                <button type="button" className="crm-btn-secondary px-3 py-1.5" disabled={busy} onClick={() => update.mutate({ assigned_to: user?.id })}>Assign to me</button>
                {unresolved ? <button type="button" className="crm-btn-secondary px-3 py-1.5" disabled={busy} onClick={() => update.mutate({ status: 'snoozed', snoozed_until: new Date(Date.now() + 7 * 864e5).toISOString() })}>Snooze 7d</button> : null}
                {unresolved ? <button type="button" className="crm-btn-secondary px-3 py-1.5" disabled={busy} onClick={() => setMode(mode === 'fp' ? null : 'fp')}>False positive…</button> : null}
                {['acknowledged', 'snoozed', 'false_positive', 'resolved','contained'].includes(finding.status) ? <button type="button" className="crm-btn-secondary px-3 py-1.5" disabled={busy} onClick={() => update.mutate({ status: 'open' })}>Reopen</button> : null}
                {canConfigure && unresolved && data.rule?.allowlistable && finding.confidence !== 'confirmed' ? <button type="button" className="crm-btn-secondary px-3 py-1.5" disabled={busy} onClick={() => setMode(mode === 'suppress' ? null : 'suppress')}>Suppress…</button> : null}
                <button type="button" className="crm-btn-secondary px-3 py-1.5" disabled={rescan.isPending} onClick={() => rescan.mutate()}>Rescan market</button>
            </div>

            {mode === 'remediation' ? <div className="space-y-2 rounded-lg border border-teal-200 bg-teal-50 p-3">
                <label htmlFor="remediation-note" className="block text-xs font-semibold text-teal-900">What was fixed and what evidence supports it?</label>
                <textarea id="remediation-note" className="crm-input min-h-[5rem]" value={note} onChange={e => setNote(e.target.value)} maxLength={1000} placeholder="Describe the exact change, verification and tracker reference. Keep credentials out of this note." />
                <button type="button" className="crm-btn-primary" disabled={busy || note.trim().length < 10} onClick={() => update.mutate({ record_remediation: true, note: note.trim() })}>Save remediation report</button>
                <p className="text-xs leading-5 text-teal-900">This creates an audited report and keeps the native finding status. Only compatible scanner verification can resolve a finding.</p>
            </div> : null}

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

export function FindingDrawer({ findingId, onClose, onOpenFinding, canOperate, canConfigure }) {
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
            footer={d ? <div className="max-h-[45dvh] overflow-y-auto"><FindingActions key={f.id} data={d} canOperate={canOperate} canConfigure={canConfigure} onDone={() => query.refetch()} /></div> : null}
        >
            {!findingId ? null : query.isError ? <ErrorState error={query.error} onRetry={query.refetch} /> : !f ? <Loading rows={5} /> : (
                <div className="space-y-4 text-sm">
                    <section className="space-y-3" aria-label="Finding explanation">
                        <div><p className="text-xs font-semibold text-teal-800">{f.interpretation?.family_label || FAMILIES[f.category] || 'Evidence requiring review'}</p><p className="mt-1 font-semibold text-slate-900">{f.market} · {domainLabel(f.domain)}</p><p className="mt-1 text-sm leading-6 text-slate-700">{f.interpretation?.next_action || d.rule?.remediation}</p></div>
                        <dl className="grid gap-3 border-y border-slate-200 py-3 sm:grid-cols-2">
                            <div><dt className="text-xs font-semibold text-slate-500">{f.interpretation?.event_label || 'Recorded activity'}</dt><dd className="mt-1 text-slate-900">{f.interpretation?.event_at ? activityDate(f.interpretation.event_at, f.interpretation.event_precision) : 'Not recorded for this finding'}</dd>{!f.interpretation?.event_at && f.linked_activity_start ? <p className="mt-1 text-xs text-slate-600">Authorized linked activity from {activityDate(f.linked_activity_start)}; see linked evidence below.</p> : null}</div>
                            <div><dt className="text-xs font-semibold text-slate-500">First detected by scanner</dt><dd className="mt-1 text-slate-900">{activityDate(f.first_seen_at)}</dd></div>
                            <div><dt className="text-xs font-semibold text-slate-500">Review outcome</dt><dd className="mt-1 font-medium text-slate-900">{f.workflow?.label || 'Needs follow-up'}</dd></div>
                            <div><dt className="text-xs font-semibold text-slate-500">Current account controls</dt><dd className="mt-1 text-slate-900">{f.interpretation?.current_controls || 'Not established by this finding'}</dd></div>
                            <div><dt className="text-xs font-semibold text-slate-500">Review owner</dt><dd className="mt-1 text-slate-900">{f.assigned_name || 'Unassigned'}</dd></div>
                            <div><dt className="text-xs font-semibold text-slate-500">Last detected</dt><dd className="mt-1 text-slate-900">{activityDate(f.last_seen_at)}</dd></div>
                        </dl>
                        {f.workflow?.reported_at ? <div className="rounded-lg border border-slate-200 bg-slate-50 p-3"><p className="text-xs font-semibold text-slate-700">Remediation reported {activityDate(f.workflow.reported_at)}</p><p className="mt-1 whitespace-pre-wrap break-words text-slate-800">{f.workflow.report_note}</p><p className="mt-2 text-xs leading-5 text-slate-600">{f.workflow.explanation}</p></div> : <p className="text-xs leading-5 text-slate-600">Acknowledgement records review. A remediation report records a claimed fix. Neither proves absence; the scanner verifies resolution.</p>}
                        {f.linked_findings?.length ? <section><h4 className="text-sm font-semibold text-slate-800">Linked evidence · {f.linked_findings_total} authorized findings</h4><p className="mt-1 text-xs leading-5 text-slate-600">These account records support the correlation; they are not additional independent incidents. Recorded activity can be historical.</p><ul className="mt-2 divide-y divide-slate-100">{f.linked_findings.map(link => <li key={link.id}><button type="button" onClick={() => onOpenFinding?.(link.id)} className="w-full py-2 text-left text-sm hover:text-teal-700"><span className="font-semibold">{link.market} · {link.subject || link.title}</span><span className="block text-xs text-slate-500">{domainLabel(link.domain)} · {activityDate(link.event_at)} · {humanize(link.status)}</span></button></li>)}</ul>{f.linked_findings_total > f.linked_findings.length || f.evidence.details?.linked_evidence_limited ? <p className="text-xs text-amber-800">Linked evidence preview is limited. Use the evidence IDs in technical details for the remaining authorized subjects.</p> : null}</section> : null}
                    </section>
                    <p className="rounded-lg border border-amber-200 bg-amber-50 px-3 py-2 text-xs leading-5 text-amber-900">Database evidence only. Filesystem and live responses were not inspected. This finding cannot establish whether files are clean.</p>
                    <details className="rounded-lg border border-slate-200 p-3"><summary className="cursor-pointer font-semibold text-slate-700">Technical evidence and stored location</summary><div className="mt-3 space-y-4">
                    <dl className="grid grid-cols-2 gap-x-4 gap-y-2 rounded-lg border border-slate-200 p-3 sm:grid-cols-3">
                        <div><dt className="text-[10px] font-semibold uppercase tracking-wide text-slate-500">Rule</dt><dd className="crm-mono text-xs">{f.rule_key}</dd></div>
                        <div><dt className="text-[10px] font-semibold uppercase tracking-wide text-slate-500">Pack</dt><dd className="text-xs">{f.pack} v{f.pack_version} · <CopyId value={f.rule_version_hash} label="rule version" /></dd></div>
                        <div><dt className="text-[10px] font-semibold uppercase tracking-wide text-slate-500">Behavior</dt><dd className="text-xs">{f.behavior ? humanize(f.behavior) : '—'}</dd></div>
                        <div><dt className="text-[10px] font-semibold uppercase tracking-wide text-slate-500">First detected</dt><dd className="text-xs">{fmtDateTime(f.first_seen_at)}</dd></div>
                        <div><dt className="text-[10px] font-semibold uppercase tracking-wide text-slate-500">Last detected</dt><dd className="text-xs">{fmtDateTime(f.last_seen_at)} · {f.occurrences} run(s)</dd></div>
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

                    </div></details>
                    {d.rule ? (
                        <section className="grid gap-3 sm:grid-cols-2">
                            <div className="rounded-lg border border-slate-200 p-3">
                                <h4 className="text-[10px] font-semibold uppercase tracking-[0.12em] text-slate-500">Why it matters</h4>
                                <p className="mt-1 text-slate-700">{d.rule.why}</p>
                            </div>
                            <div className="rounded-lg border border-slate-200 p-3">
                                <h4 className="text-[10px] font-semibold uppercase tracking-[0.12em] text-slate-500">How to fix</h4>
                                <p className="mt-1 text-slate-700">{d.rule.remediation}</p>
                                <p className="mt-2 text-xs text-slate-500">The scanner does not repair market databases. Compatible verification can resolve supported findings after cleanup; drift findings require a separate review.</p>
                            </div>
                        </section>
                    ) : null}

                    {canOperate ? <ContainmentPanel key={f.id} findingId={f.id} /> : null}
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

function FindingRow({ finding: f, onOpen }) {
    const subject = f.evidence?.details?.login || f.evidence?.details?.role || f.subject?.label || (f.subject?.row_id ? `${f.subject.table} #${f.subject.row_id}` : null);
    return <button type="button" onClick={() => onOpen(f.id)} className="block w-full px-4 py-3 text-left hover:bg-slate-50 focus-visible:ring-2 focus-visible:ring-inset focus-visible:ring-teal-500">
        <div className="flex flex-wrap items-center justify-between gap-2"><span className="text-xs font-semibold text-teal-800">{f.interpretation?.family_label || f.category}</span><div className="flex flex-wrap gap-1"><SeverityBadge severity={f.severity} /><ConfidenceBadge confidence={f.confidence} /><Status value={f.status} /></div></div>
        <p className="mt-2 break-words text-sm font-semibold text-slate-900">{f.title}</p><p className="mt-1 break-words text-xs text-slate-600">{f.market} · {domainLabel(f.domain)}{subject ? ` · ${subject}` : ''}</p>
        <p className="mt-1 text-xs leading-5 text-slate-600">{f.interpretation?.event_at ? `${f.interpretation.event_label}: ${activityDate(f.interpretation.event_at, f.interpretation.event_precision)}` : f.linked_activity_start ? `Linked activity from ${activityDate(f.linked_activity_start)}` : 'Activity date not recorded'}</p>
        <p className="mt-1 text-xs text-slate-500">Detected {fmtAgo(f.last_seen_at)} · {f.workflow?.label || 'Needs follow-up'} · {f.assigned_name || 'Unassigned'}</p>
    </button>;
}

export default function FindingsTab({ canOperate, canConfigure, markets }) {
    const toast = useToast();
    const [searchParams, setSearchParams] = useSearchParams();
    const filters = readFindingFilters(searchParams);
    const openId = Number(searchParams.get('finding')) || null;
    const setOpenId = id => setSearchParams(prev => { const next = new URLSearchParams(prev); if (id) next.set('finding', String(id)); else next.delete('finding'); return next; });
    const setFilters = (change, options = {}) => setSearchParams(prev => {
        const current = readFindingFilters(prev);
        const nextFilters = typeof change === 'function' ? change(current) : change;
        const next = new URLSearchParams(prev);
        next.set('tab', 'findings');
        for (const key of FILTER_KEYS) {
            const value = nextFilters[key];
            if (value !== undefined && value !== null) next.set(key, String(value)); else next.delete(key);
        }
        return next;
    }, options);
    const [campaignTargets, setCampaignTargets] = useState([]);
    const [campaignOpen, setCampaignOpen] = useState(false);
    const [exporting, setExporting] = useState(false);

    const params = Object.fromEntries(Object.entries(filters).filter(([k, v]) => v !== '' && v !== null && v !== undefined && k !== 'finding' && !k.startsWith('_')));
    const query = useQuery({
        queryKey: ['dbo', 'findings', params],
        queryFn: () => dbObservatory.findings({ ...params, per_page: 50 }),
        placeholderData: keepPreviousData,
    });

    const set = (patch, options) => setFilters(f => ({ ...f, page: 1, ...patch }), options);
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
            if (response.headers['x-selection-sampled'] === '1') toast.warning('This review-group export covers the bounded selection only. Narrow the filters to include omitted records.');
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
            {canOperate ? <div className="flex flex-wrap items-center gap-2 text-xs text-slate-600"><span>Select suspicious-key findings below for an exact revoke campaign · {campaignTargets.length} selected</span><button type="button" className="crm-btn-secondary" disabled={!campaignTargets.length} onClick={() => setCampaignOpen(true)}>Review campaign</button>{campaignTargets.length ? <button type="button" className="text-xs underline" onClick={() => setCampaignTargets([])}>Clear selection</button> : null}</div> : null}
            {campaignOpen ? <CampaignContainment targets={campaignTargets} onClose={() => setCampaignOpen(false)} /> : null}
            <div className="crm-surface flex flex-wrap items-center gap-2 px-4 py-3">
                <button
                    type="button"
                    onClick={() => set({ category: malwareOn ? '' : 'malware', confidence: '', behavior: '' })}
                    className={`rounded-lg border px-3 py-1.5 text-sm font-semibold transition ${malwareOn ? 'border-rose-300 bg-rose-600 text-white' : 'border-slate-300 bg-white text-slate-700 hover:border-rose-300'}`}
                    aria-pressed={malwareOn}
                >
                    Database indicators{facets.category?.malware ? ` · ${facets.category.malware}` : ''}
                </button>
                <select aria-label="Evidence family" className="crm-select py-1.5 text-sm" value={filters.family || ''} onChange={e => set({ family: e.target.value, category: '', confidence: '', behavior: '', review_group: '', rule_key: '', identity: '', definition_hash: '' })}><option value="">All evidence families</option>{Object.entries(FAMILIES).map(([key, label]) => <option key={key} value={key}>{label}</option>)}</select>
                <select aria-label="Follow-up" className="crm-select py-1.5 text-sm" value={filters.workflow || ''} onChange={e => set({ workflow: e.target.value, review_group: '' })}><option value="">All follow-up</option><option value="needs_follow_up">Needs follow-up</option><option value="awaiting_verification">Reported fixes awaiting verification</option></select>
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
                    {(markets || []).map((m) => <option key={m.platform_id} value={m.platform_id}>{m.market} · {domainLabel(m.domain)}</option>)}
                </select>
                <input aria-label="Search findings" className="crm-input w-44 py-1.5" placeholder="Search title or rule" value={filters.q || ''} onChange={(e) => set({ q: e.target.value, review_group: '' }, { replace: true })} />
                {filters.run_id ? <button type="button" className="rounded-md bg-teal-50 px-2 py-1 text-xs font-semibold text-teal-800" onClick={() => set({ run_id: '' })}>Run {filters.run_id} ✕</button> : null}
                {filters.kind ? <button type="button" className="rounded-md bg-teal-50 px-2 py-1 text-xs font-semibold text-teal-800" onClick={() => set({ kind: '' })}>{humanize(filters.kind)} rules ✕</button> : null}
                {filters.identity ? <button type="button" className="max-w-xs truncate rounded-md bg-teal-50 px-2 py-1 text-xs font-semibold text-teal-800" aria-label={`Remove identity filter ${filters.identity}`} onClick={() => set({ identity: '', definition_hash: '' })}>{filters.identity}{filters.definition_hash ? ' · matching privileges' : ''} ✕</button> : null}
                {filters.rule_key ? <button type="button" className="crm-mono rounded-md bg-teal-50 px-2 py-1 text-xs font-semibold text-teal-800" onClick={() => set({ rule_key: '' })}>{filters.rule_key} ✕</button> : null}
                <div className="ml-auto flex gap-2">
                    <select aria-label="Group by" className="crm-select py-1.5 text-sm" value={filters.group_by || ''} onChange={(e) => set({ group_by: e.target.value, review_group: '' })}>
                        <option value="related">Related review groups</option><option value="rule">Group by rule</option>
                        <option value="market">Group by market</option>
                        <option value="">No grouping</option>
                    </select>
                    <button type="button" className="crm-btn-secondary px-3 py-1.5" onClick={exportCsv} disabled={exporting}>{exporting ? 'Exporting…' : 'Export CSV'}</button>
                </div>
            </div>

            {filters.review_group ? <div className="flex flex-wrap items-center justify-between gap-2 rounded-lg border border-teal-200 bg-teal-50 px-4 py-3 text-xs text-teal-900"><span>Members of this exact review group{query.data?.selection_sampled ? ' within the bounded selection' : ''}.</span><button type="button" className="font-semibold underline" onClick={() => set({ review_group: '', group_by: 'related' })}>Back to related groups</button></div> : null}
            {filters.workflow || filters.run_id || filters.identity || filters.rule_key || filters.category || filters.confidence || filters.behavior || filters.kind || filters.definition_hash ? <div className="flex flex-wrap items-center gap-2 px-1 text-xs text-slate-600"><span>Active context: {[filters.workflow, filters.run_id ? `run ${filters.run_id}` : '', filters.identity, filters.rule_key, filters.category, filters.confidence, filters.behavior, filters.kind].filter(Boolean).map(humanize).join(' · ')}</span><button type="button" className="font-semibold text-teal-700 underline" onClick={() => set({ workflow: '', run_id: '', identity: '', rule_key: '', category: '', confidence: '', behavior: '', kind: '', definition_hash: '', review_group: '' })}>Clear context filters</button></div> : null}
            {query.isFetching && !query.isLoading ? <p role="status" className="px-1 text-xs text-slate-500">Updating evidence…</p> : null}
            {query.data?.review_meta?.sampled ? <p role="status" className="rounded-lg border border-amber-200 bg-amber-50 px-4 py-3 text-xs text-amber-900">Review groups cover the first {query.data.review_meta.findings_considered.toLocaleString()} matching findings. Group counts describe this selection; narrow filters for omitted records. Family totals can cover more records.</p> : null}
            {malwareOn ? (
                <p className="rounded-lg border border-slate-200 bg-white px-4 py-2 text-xs text-slate-600">
                    <span className="font-semibold">Confirmed</span> means a vetted exact indicator matched. <span className="font-semibold">Strong</span> means behaviour, remote target and execution context align. <span className="font-semibold">Needs review</span> is a single generic signal — a lone script, Base64 string or foreign text is never labelled malware.
                </p>
            ) : null}

            {filters.group_by === 'related' && !filters.review_group ? <Panel title="Related review groups" subtitle="Grouped by exact indicator, matching role definition or market subject. Groups can share linked evidence and are not independent incident counts." bodyClass="divide-y divide-slate-200">
                {query.isLoading ? <Loading rows={5} /> : query.isError ? <ErrorState error={query.error} onRetry={query.refetch} /> : !query.data?.review_groups?.length ? <Empty title="No review groups match these filters">Check market coverage before interpreting this as a clean result.</Empty> : query.data.review_groups.map(g => <details key={g.key} className="group"><summary className="cursor-pointer px-4 py-4 text-sm"><span className="font-semibold text-slate-900">{g.label}</span><span className="mt-1 block text-xs text-slate-600">{g.family_label} · {g.findings} finding records · {g.markets} market records{g.critical ? ` · ${g.critical} critical` : ''}</span><span className="mt-1 block text-xs text-slate-500">{g.market_names.join(', ')}</span></summary><ul className="divide-y divide-slate-100 border-t border-slate-100">{g.members.map(f => <li key={f.id}><FindingRow finding={f} onOpen={setOpenId} /></li>)}</ul><button type="button" className="w-full border-t border-slate-100 px-4 py-3 text-left text-sm font-semibold text-teal-700 hover:bg-slate-50" onClick={() => set({ review_group: g.key, group_by: '' })}>View all {g.findings} member records →</button></details>)}
                {query.data?.review_meta ? <div className="flex flex-wrap items-center justify-between gap-3 px-4 py-3 text-xs text-slate-600"><span>{query.data.review_meta.total} review groups · {query.data.review_meta.findings_considered} finding records considered</span><div className="flex gap-2"><button type="button" className="crm-btn-secondary" disabled={filters.page <= 1} onClick={() => set({ page: filters.page - 1 })}>Previous</button><button type="button" className="crm-btn-secondary" disabled={filters.page >= query.data.review_meta.last_page} onClick={() => set({ page: filters.page + 1 })}>Next</button></div></div> : null}
            </Panel> : <>
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
                        <Empty title={malwareOn ? 'No database indicators match these filters' : 'No findings match these filters'}>
                            This does not mean the database is clean — it only reflects surfaces that were scanned. Open a market's latest run to review its coverage.
                        </Empty>
                    ) : (
                        <>
                            <ul className="divide-y divide-slate-100">
                                {query.data.data.map((f) => (
                                    <li key={f.id}>
                                        {canOperate && f.rule_key === 'access.application_passwords' && ['open','acknowledged'].includes(f.status) ? <label className="flex items-center gap-2 px-4 pt-3 text-xs text-slate-600"><input type="checkbox" checked={campaignTargets.some((t) => t.finding_id === f.id)} onChange={(e) => setCampaignTargets(e.target.checked ? [...campaignTargets, { finding_id: f.id, actions: ['revoke_app_password'] }] : campaignTargets.filter((t) => t.finding_id !== f.id))} />Select key group for campaign preview</label> : null}
                                        <FindingRow finding={f} onOpen={setOpenId} />
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

            </>}
            <FindingDrawer findingId={openId} onOpenFinding={setOpenId} onClose={() => setOpenId(null)} canOperate={canOperate} canConfigure={canConfigure} />
        </div>
    );
}
