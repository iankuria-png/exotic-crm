import React, { useEffect, useMemo, useRef, useState } from 'react';
import { Link } from 'react-router-dom';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import api from '../../services/api';
import { useToast } from '../ToastProvider';
import ConfirmDialog from '../ConfirmDialog';
import MetricCard from '../MetricCard';
import { ISSUE_COPY, ProofMark, ProofText, bioHtmlToText } from '../seo/BioTextCheck';

// Bio text: profile bios that show broken text to visitors and Google —
// garbled accents ("dÃ©tour"), missing letters, escaped code, invisible
// characters and AI leftovers. A check reads every linked bio in the market; the
// repair fixes what can be fixed without guessing, backs up each bio first,
// confirms the result with WordPress and can be undone.

const SCAN_STATUS = {
    queued: { label: 'Queued', tone: 'bg-slate-100 text-slate-700 ring-slate-200' },
    scanning: { label: 'Checking', tone: 'bg-sky-50 text-sky-700 ring-sky-200' },
    scanned: { label: 'Checked', tone: 'bg-slate-100 text-slate-700 ring-slate-200' },
    repairing: { label: 'Repairing', tone: 'bg-sky-50 text-sky-700 ring-sky-200' },
    repaired: { label: 'Repaired', tone: 'bg-emerald-50 text-emerald-700 ring-emerald-200' },
    restoring: { label: 'Undoing', tone: 'bg-sky-50 text-sky-700 ring-sky-200' },
    restored: { label: 'Undone', tone: 'bg-amber-50 text-amber-700 ring-amber-200' },
    failed: { label: 'Stopped', tone: 'bg-rose-50 text-rose-700 ring-rose-200' },
};

const FINDING_STATUS = {
    found: { label: 'Broken', tone: 'bg-rose-50 text-rose-700 ring-rose-200' },
    queued: { label: 'Queued', tone: 'bg-slate-100 text-slate-700 ring-slate-200' },
    repaired: { label: 'Repaired', tone: 'bg-emerald-50 text-emerald-700 ring-emerald-200' },
    unchanged: { label: 'Already fixed', tone: 'bg-slate-100 text-slate-600 ring-slate-200' },
    failed: { label: 'Not repaired', tone: 'bg-rose-50 text-rose-700 ring-rose-200' },
    restore_queued: { label: 'Undo queued', tone: 'bg-slate-100 text-slate-700 ring-slate-200' },
    restored: { label: 'Undone', tone: 'bg-amber-50 text-amber-700 ring-amber-200' },
};

const ACTIVE = new Set(['queued', 'scanning', 'repairing', 'restoring']);
const REPAIRABLE = new Set(['found', 'failed', 'restored', 'queued']);
const MANUAL_KINDS = new Set(['lost_characters', 'ai_text']);
const KIND_ORDER = ['broken_accents', 'lost_characters', 'escaped_html', 'ai_text', 'invisible_characters'];
const PER_PAGE = 25;
const MAX_SELECTION = 1000;

const VIEWS = [
    { key: 'all', label: 'All' },
    { key: 'fixable', label: 'Can repair' },
    { key: 'manual', label: 'Rewrite by hand' },
    { key: 'repaired', label: 'Repaired' },
    { key: 'problems', label: 'Needs a look' },
];

const numberFormat = new Intl.NumberFormat('en-US');
const fmt = (value) => numberFormat.format(Number(value || 0));
const plural = (count, one, many) => (Number(count) === 1 ? one : many);
const bios = (count) => `${fmt(count)} ${plural(count, 'bio', 'bios')}`;

function relativeTime(iso) {
    if (!iso) return '';
    const seconds = Math.round((Date.now() - new Date(iso).getTime()) / 1000);
    if (Number.isNaN(seconds)) return '';
    if (seconds < 45) return 'just now';
    const minutes = Math.round(seconds / 60);
    if (minutes < 60) return `${minutes} min ago`;
    const hours = Math.round(minutes / 60);
    if (hours < 24) return `${hours} h ago`;
    return new Date(iso).toLocaleDateString();
}

const canRepairFinding = (finding) => finding.fixable && REPAIRABLE.has(finding.status);
const canRestoreFinding = (finding) => Boolean(finding.repaired_at) && ['repaired', 'failed'].includes(finding.status);
const needsRewrite = (finding) => (finding.issues || []).some((issue) => MANUAL_KINDS.has(issue.kind));

// ─── Small pieces ────────────────────────────────────────────────────────────

function Pill({ children, tone = 'bg-slate-100 text-slate-700 ring-slate-200' }) {
    return (
        <span className={`inline-flex items-center whitespace-nowrap rounded-full px-2 py-0.5 text-[11px] font-semibold ring-1 ring-inset ${tone}`}>
            {children}
        </span>
    );
}

function Panel({ title, subtitle, actions, children, className = '' }) {
    return (
        <section className={`crm-surface relative overflow-hidden rounded-xl border border-slate-200 ${className}`}>
            {title ? (
                <header className="flex flex-wrap items-start justify-between gap-3 border-b border-slate-100 bg-slate-50/60 px-5 py-3.5">
                    <div className="min-w-0">
                        <h3 className="text-sm font-semibold text-slate-800">{title}</h3>
                        {subtitle ? <p className="mt-1 max-w-3xl text-xs leading-relaxed text-slate-500">{subtitle}</p> : null}
                    </div>
                    {actions}
                </header>
            ) : null}
            {children}
        </section>
    );
}

function Notice({ tone, title, children, action }) {
    const tones = {
        amber: 'border-amber-200 bg-amber-50 text-amber-900',
        rose: 'border-rose-200 bg-rose-50 text-rose-900',
        slate: 'border-slate-200 bg-slate-50 text-slate-700',
    };
    return (
        <div className={`flex flex-wrap items-start justify-between gap-3 rounded-xl border px-5 py-4 ${tones[tone] || tones.slate}`} role="status">
            <div className="min-w-0 max-w-2xl">
                <p className="text-sm font-semibold">{title}</p>
                <div className="mt-1 text-xs leading-relaxed opacity-90">{children}</div>
            </div>
            {action}
        </div>
    );
}

const primaryButton = 'rounded-lg bg-teal-700 px-4 py-2 text-sm font-semibold text-white shadow-sm transition hover:bg-teal-800 disabled:cursor-not-allowed disabled:opacity-50 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-teal-600 focus-visible:ring-offset-2';
const quietButton = 'rounded-lg border border-slate-300 bg-white px-3 py-1.5 text-xs font-semibold text-slate-700 transition hover:bg-slate-50 disabled:cursor-not-allowed disabled:opacity-50 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-teal-500';

// ─── Sections ────────────────────────────────────────────────────────────────

function Introduction({ marketName, linkedProfiles, onStart, isPending }) {
    return (
        <section className="crm-surface overflow-hidden rounded-xl border border-slate-200">
            <div className="grid gap-6 px-6 py-6 lg:grid-cols-[minmax(0,1.2fr)_minmax(0,1fr)] lg:items-center">
                <div>
                    <p className="text-[11px] font-semibold uppercase tracking-[0.14em] text-teal-700">Bio text check</p>
                    <h3 className="mt-1.5 text-lg font-semibold text-slate-900">
                        Find bios in {marketName || 'this market'} that read as broken text
                    </h3>
                    <p className="mt-2 max-w-xl text-sm leading-relaxed text-slate-600">
                        Reads all {bios(linkedProfiles)} linked to WordPress. Nothing changes until you review what was found and start a repair, and every repair can be undone.
                    </p>
                    <button type="button" onClick={onStart} disabled={isPending || !linkedProfiles} className={`${primaryButton} mt-4`}>
                        {isPending ? 'Starting…' : `Check ${bios(linkedProfiles)}`}
                    </button>
                </div>
                <div className="rounded-xl border border-slate-200 bg-slate-50/70 p-4 text-sm leading-7 text-slate-700">
                    <p className="text-[10px] font-semibold uppercase tracking-[0.12em] text-slate-400">What it looks for</p>
                    <p className="mt-1">
                        Une rencontre sans <ProofMark found="dÃ©tour" fixed="détour" />, qui met <ProofMark found="Ã" fixed="à" /> l’aise.
                    </p>
                    <ul className="mt-2 space-y-0.5 text-xs text-slate-500">
                        <li><span className="font-semibold text-teal-700">Fixed by the repair:</span> garbled accents, escaped code and invisible characters.</li>
                        <li><span className="font-semibold text-amber-700">Flagged for a person:</span> missing letters (�), AI preambles, refusals and placeholders like [Name].</li>
                    </ul>
                </div>
            </div>
        </section>
    );
}

function Headline({ scan, marketName, onRecheck, busy }) {
    const affected = Number(scan.profiles_affected || 0);
    const open = Number(scan.open_fixable || 0);
    const manual = Number(scan.profiles_manual || 0);
    const repaired = Number(scan.status_counts?.repaired || 0);

    let tone = 'border-emerald-200 bg-gradient-to-r from-emerald-50 to-white';
    let icon = '✓';
    let iconTone = 'bg-emerald-600';
    let headline = `Every bio in ${marketName || 'this market'} reads cleanly`;
    let sub = `${bios(scan.profiles_scanned)} checked. Staff are warned about broken text as they write, and garbled accents are repaired on save.`;

    if (open > 0) {
        tone = 'border-rose-200 bg-gradient-to-r from-rose-50 to-white';
        icon = '!';
        iconTone = 'bg-rose-600';
        headline = `${bios(affected)} ${plural(affected, 'shows', 'show')} broken text to visitors and Google`;
        sub = [`${fmt(open)} can be repaired automatically`, manual ? `${fmt(manual)} need rewriting by hand` : null, repaired ? `${fmt(repaired)} already repaired` : null].filter(Boolean).join(' · ');
    } else if (manual > 0) {
        tone = 'border-amber-200 bg-gradient-to-r from-amber-50 to-white';
        icon = '◐';
        iconTone = 'bg-amber-500';
        headline = `${bios(manual)} ${plural(manual, 'needs', 'need')} rewriting by hand`;
        sub = repaired ? `Everything that could be repaired automatically has been (${fmt(repaired)}).` : 'Nothing here can be repaired without guessing. Open each profile to rewrite it.';
    } else if (repaired > 0) {
        headline = `All ${bios(repaired)} with broken text ${plural(repaired, 'is', 'are')} repaired`;
        sub = `${bios(scan.profiles_scanned)} checked. Each repair is backed up and can be undone below.`;
    }

    return (
        <div className={`flex flex-wrap items-center justify-between gap-4 rounded-xl border px-5 py-4 ${tone}`} aria-live="polite">
            <div className="flex min-w-0 items-start gap-3">
                <span className={`mt-0.5 inline-flex h-7 w-7 shrink-0 items-center justify-center rounded-full text-sm font-bold text-white ${iconTone}`} aria-hidden="true">{icon}</span>
                <div className="min-w-0">
                    <p className="text-[15px] font-semibold leading-snug text-slate-900">{headline}</p>
                    <p className="mt-0.5 text-xs leading-relaxed text-slate-600">{sub}</p>
                </div>
            </div>
            <div className="flex items-center gap-3 text-xs text-slate-500">
                <span title={scan.scanned_at ? new Date(scan.scanned_at).toLocaleString() : ''}>
                    {marketName ? `${marketName} · ` : ''}checked {relativeTime(scan.scanned_at) || '—'}
                </span>
                <button type="button" onClick={onRecheck} disabled={busy} title={busy ? 'Wait for the current run to finish' : undefined} className={quietButton}>
                    Check again
                </button>
            </div>
        </div>
    );
}

function Progress({ scan }) {
    let title = 'Reading bios from WordPress…';
    let total = Number(scan.total_profiles || 0);
    let done = Number(scan.profiles_scanned || 0);
    let detail = `${fmt(done)} of ${fmt(total)} bios`;

    if (scan.status === 'repairing') {
        title = 'Repairing bios in WordPress…';
        total = Number(scan.repair?.target || 0);
        done = Number(scan.repair?.repaired || 0) + Number(scan.repair?.unchanged || 0) + Number(scan.repair?.failed || 0);
        detail = `${fmt(done)} of ${fmt(total)} · ${fmt(scan.repair?.repaired)} repaired and confirmed`;
    } else if (scan.status === 'restoring') {
        title = 'Putting the backed-up bios back…';
        total = Number(scan.restore?.target || 0);
        done = Number(scan.restore?.restored || 0) + Number(scan.restore?.failed || 0);
        detail = `${fmt(done)} of ${fmt(total)} bios`;
    }

    const percent = total > 0 ? Math.min(100, Math.round((done / total) * 100)) : 0;

    return (
        <div className="rounded-xl border border-sky-200 bg-sky-50 px-5 py-4 text-sky-900" aria-live="polite">
            <div className="flex flex-wrap items-baseline justify-between gap-2">
                <p className="text-sm font-semibold">{title}</p>
                <p className="text-xs tabular-nums opacity-80">{detail}</p>
            </div>
            <div className="mt-3 h-2 overflow-hidden rounded-full bg-sky-100" role="progressbar" aria-valuemin={0} aria-valuemax={100} aria-valuenow={percent} aria-label={title}>
                <div className="h-full rounded-full bg-sky-600 transition-[width] duration-700 ease-out" style={{ width: `${Math.max(percent, 3)}%` }} />
            </div>
            <p className="mt-2 text-[11px] opacity-70">You can leave this page. The run continues in the background.</p>
        </div>
    );
}

function IssueChips({ issues }) {
    return (
        <div className="flex flex-wrap gap-1">
            {issues.map((issue) => (
                <Pill
                    key={issue.kind}
                    tone={MANUAL_KINDS.has(issue.kind) ? 'bg-amber-50 text-amber-800 ring-amber-200' : 'bg-rose-50 text-rose-700 ring-rose-200'}
                >
                    {ISSUE_COPY[issue.kind]?.short || issue.kind}
                    {issue.count > 1 ? <span className="ml-1 font-normal opacity-70">×{issue.count}</span> : null}
                </Pill>
            ))}
        </div>
    );
}

function FindingRow({ finding, selected, onToggle, onRepair, onRestore, busy }) {
    const [expanded, setExpanded] = useState(false);
    const before = useMemo(() => bioHtmlToText(finding.original_html), [finding.original_html]);
    const after = useMemo(() => (finding.repaired_html ? bioHtmlToText(finding.repaired_html) : ''), [finding.repaired_html]);
    const status = FINDING_STATUS[finding.status] || FINDING_STATUS.found;
    const statusTone = finding.status === 'found' && !finding.fixable ? 'bg-amber-50 text-amber-800 ring-amber-200' : status.tone;
    const statusLabel = finding.status === 'found' && !finding.fixable ? 'Rewrite by hand' : status.label;
    const selectable = canRepairFinding(finding) || canRestoreFinding(finding);
    const showRepaired = ['repaired', 'failed', 'restore_queued'].includes(finding.status) && after;

    return (
        <li className={`flex gap-3 px-5 py-4 transition ${selected ? 'bg-teal-50/60' : 'hover:bg-slate-50/60'}`}>
            <input
                type="checkbox"
                checked={selected}
                disabled={!selectable}
                onChange={() => onToggle(finding)}
                aria-label={`Select ${finding.name}`}
                className="mt-1 h-4 w-4 shrink-0 cursor-pointer rounded border-slate-300 accent-teal-600 disabled:cursor-not-allowed disabled:opacity-30"
            />
            <div className="grid min-w-0 flex-1 gap-3 lg:grid-cols-[minmax(0,13rem)_minmax(0,1fr)_auto]">
                <div className="min-w-0">
                    {finding.client_id ? (
                        <Link to={`/clients/${finding.client_id}`} className="block truncate text-sm font-semibold text-slate-900 hover:text-teal-700" title={finding.name}>
                            {finding.name || `Profile #${finding.wp_post_id}`}
                        </Link>
                    ) : (
                        <span className="block truncate text-sm font-semibold text-slate-900">{finding.name || `Profile #${finding.wp_post_id}`}</span>
                    )}
                    <p className="mt-0.5 flex items-center gap-2 text-[11px] text-slate-400">
                        <span className="font-mono">#{finding.wp_post_id}</span>
                        {finding.profile_url ? (
                            <a href={finding.profile_url} target="_blank" rel="noreferrer" className="font-medium text-slate-500 hover:text-teal-700">View live ↗</a>
                        ) : null}
                    </p>
                    <div className="mt-2 flex flex-wrap items-center gap-1">
                        <Pill tone={statusTone}>{statusLabel}</Pill>
                    </div>
                </div>

                <div className="min-w-0">
                    <IssueChips issues={finding.issues || []} />
                    {expanded ? (
                        <div className="mt-2 space-y-3">
                            <div>
                                <p className="mb-1 text-[10px] font-semibold uppercase tracking-[0.1em] text-slate-400">
                                    {showRepaired ? 'Before the repair' : 'On the profile now'}
                                </p>
                                <ProofText text={before} className="text-[13px] leading-6 text-slate-700" />
                            </div>
                            {showRepaired ? (
                                <div>
                                    <p className="mb-1 text-[10px] font-semibold uppercase tracking-[0.1em] text-teal-700">WordPress now shows</p>
                                    <p className="whitespace-pre-wrap break-words rounded-lg border border-teal-100 bg-teal-50/40 px-3 py-2 text-[13px] leading-6 text-slate-800">{after}</p>
                                </div>
                            ) : null}
                        </div>
                    ) : (
                        <>
                            {showRepaired ? (
                                <p className="mt-2 text-[10px] font-semibold uppercase tracking-[0.1em] text-emerald-700">What the repair changed</p>
                            ) : null}
                            <ProofText text={before} window={34} className={`${showRepaired ? 'mt-1' : 'mt-2'} text-[13px] leading-6 text-slate-700`} />
                        </>
                    )}
                    <button
                        type="button"
                        onClick={() => setExpanded((value) => !value)}
                        className="mt-1 text-[11px] font-semibold text-slate-500 hover:text-teal-700 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-teal-500"
                        aria-expanded={expanded}
                    >
                        {expanded ? 'Show less' : showRepaired ? 'Show before and after' : 'Show the whole bio'}
                    </button>
                    {finding.error ? (
                        <p className="mt-1.5 text-[11px] leading-snug text-rose-700">{finding.error}</p>
                    ) : null}
                </div>

                <div className="flex shrink-0 flex-wrap items-start gap-1.5 lg:flex-col lg:items-end">
                    {canRepairFinding(finding) ? (
                        <button type="button" onClick={() => onRepair(finding)} disabled={busy} className="rounded-md bg-teal-700 px-2.5 py-1 text-xs font-semibold text-white transition hover:bg-teal-800 disabled:opacity-40 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-teal-600">
                            Repair
                        </button>
                    ) : null}
                    {canRestoreFinding(finding) ? (
                        <button type="button" onClick={() => onRestore(finding)} disabled={busy} className="rounded-md border border-slate-300 bg-white px-2.5 py-1 text-xs font-semibold text-slate-700 transition hover:border-amber-300 hover:bg-amber-50 hover:text-amber-800 disabled:opacity-40 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-amber-500">
                            Undo
                        </button>
                    ) : null}
                    {needsRewrite(finding) && finding.client_id ? (
                        <Link to={`/clients/${finding.client_id}?tab=edit_profile`} className="rounded-md px-2 py-1 text-xs font-semibold text-amber-800 transition hover:bg-amber-50">
                            Rewrite in profile →
                        </Link>
                    ) : null}
                </div>
            </div>
        </li>
    );
}

function History({ history }) {
    if (!history.length) return null;

    return (
        <Panel title="Check history" subtitle="Every repair keeps each bio as it was before. Export a check to get the before and after of every bio as CSV.">
            <div className="relative overflow-x-auto">
                <table className="min-w-full text-left text-xs">
                    <thead className="border-b border-slate-100 text-[10px] uppercase tracking-[0.1em] text-slate-400">
                        <tr>
                            <th scope="col" className="px-5 py-2 font-semibold">Checked</th>
                            <th scope="col" className="px-3 py-2 font-semibold">By</th>
                            <th scope="col" className="px-3 py-2 text-right font-semibold">Bios read</th>
                            <th scope="col" className="px-3 py-2 text-right font-semibold">Broken</th>
                            <th scope="col" className="px-3 py-2 text-right font-semibold">Repaired</th>
                            <th scope="col" className="px-5 py-2 font-semibold">Status</th>
                        </tr>
                    </thead>
                    <tbody className="divide-y divide-slate-100">
                        {history.map((scan) => {
                            const status = SCAN_STATUS[scan.status] || SCAN_STATUS.queued;
                            return (
                                <tr key={scan.id} className="align-top">
                                    <td className="whitespace-nowrap px-5 py-3 text-slate-700">
                                        {scan.created_at ? new Date(scan.created_at).toLocaleString(undefined, { dateStyle: 'medium', timeStyle: 'short' }) : '—'}
                                    </td>
                                    <td className="px-3 py-3 text-slate-600">
                                        {scan.requested_by || '—'}
                                        {scan.repair_requested_by ? <span className="block text-[11px] text-slate-400">repair by {scan.repair_requested_by}</span> : null}
                                    </td>
                                    <td className="px-3 py-3 text-right tabular-nums text-slate-700">{fmt(scan.profiles_scanned)}</td>
                                    <td className="px-3 py-3 text-right tabular-nums text-slate-700">{scan.scanned_at ? fmt(scan.profiles_affected) : '—'}</td>
                                    <td className="px-3 py-3 text-right tabular-nums text-slate-700">{fmt(scan.repair?.repaired)}</td>
                                    <td className="px-5 py-3">
                                        <Pill tone={status.tone}>{status.label}</Pill>
                                        {scan.notes ? <p className="mt-1 max-w-sm whitespace-pre-line text-[11px] leading-snug text-slate-500">{scan.notes}</p> : null}
                                    </td>
                                </tr>
                            );
                        })}
                    </tbody>
                </table>
            </div>
        </Panel>
    );
}

function LoadingState() {
    return (
        <div className="space-y-4" aria-busy="true" aria-label="Loading bio text check">
            <div className="h-[72px] animate-pulse rounded-xl border border-slate-200 bg-slate-50" />
            <div className="grid gap-3 sm:grid-cols-3">
                {[0, 1, 2].map((index) => <div key={index} className="h-[104px] animate-pulse rounded-xl border border-slate-200 bg-slate-50" />)}
            </div>
            <div className="h-64 animate-pulse rounded-xl border border-slate-200 bg-slate-50" />
        </div>
    );
}

// ─── View ────────────────────────────────────────────────────────────────────

export default function BioTextHealthView({ platformId, platforms = [], marketName }) {
    const toast = useToast();
    const queryClient = useQueryClient();
    const [selectedPlatform, setSelectedPlatform] = useState(platformId ? String(platformId) : '');
    const [view, setView] = useState('all');
    const [kind, setKind] = useState('');
    const [searchInput, setSearchInput] = useState('');
    const [search, setSearch] = useState('');
    const [page, setPage] = useState(1);
    const [selection, setSelection] = useState(() => new Map());
    // { action: 'repair' | 'restore', scope: 'all' | 'selected' | finding }
    const [confirm, setConfirm] = useState(null);
    const [exporting, setExporting] = useState(false);

    useEffect(() => {
        if (platformId) setSelectedPlatform(String(platformId));
    }, [platformId]);

    useEffect(() => {
        const timer = window.setTimeout(() => setSearch(searchInput.trim()), 300);
        return () => window.clearTimeout(timer);
    }, [searchInput]);

    useEffect(() => setPage(1), [view, kind, search, selectedPlatform]);
    useEffect(() => setSelection(new Map()), [selectedPlatform]);

    const hasMarket = Boolean(selectedPlatform);
    const selectedMarketName = marketName || platforms.find((platform) => String(platform.id) === selectedPlatform)?.name || '';

    const overview = useQuery({
        queryKey: ['bio-text-health', selectedPlatform],
        enabled: hasMarket,
        queryFn: async () => (await api.get('/crm/bio-text-health', { params: { platform_id: selectedPlatform } })).data,
        refetchInterval: (query) => (ACTIVE.has(query.state.data?.scan?.status) ? 2500 : false),
    });

    const scan = overview.data?.scan || null;
    const active = Boolean(scan && ACTIVE.has(scan.status));
    const reviewable = Boolean(scan?.scanned_at);

    const findings = useQuery({
        queryKey: ['bio-text-findings', scan?.id, view, kind, search, page],
        enabled: Boolean(scan?.id && reviewable),
        placeholderData: (previous) => previous,
        queryFn: async () => (await api.get(`/crm/bio-text-health/scans/${scan.id}/findings`, {
            params: { view, kind: kind || undefined, search: search || undefined, page, per_page: PER_PAGE },
        })).data,
        refetchInterval: active ? 4000 : false,
    });

    // Tell the admin once when a run they are watching finishes.
    const lastStatus = useRef(null);
    useEffect(() => {
        if (!scan) return;
        const previous = lastStatus.current;
        lastStatus.current = `${scan.id}:${scan.status}`;
        if (!previous || previous === lastStatus.current || !previous.startsWith(`${scan.id}:`)) return;
        const wasActive = ACTIVE.has(previous.split(':')[1]);
        if (!wasActive || active) return;

        queryClient.invalidateQueries({ queryKey: ['bio-text-findings', scan.id] });
        if (scan.status === 'scanned') {
            toast?.success?.(scan.profiles_affected ? `Check finished: ${bios(scan.profiles_affected)} with broken text.` : 'Check finished: every bio reads cleanly.');
        } else if (scan.status === 'repaired') {
            toast?.success?.(`Repair finished: ${bios(scan.repair?.repaired)} repaired and confirmed in WordPress.`);
        } else if (scan.status === 'restored') {
            toast?.success?.(`Undo finished: ${bios(scan.restore?.restored)} put back as they were.`);
        } else if (scan.status === 'failed') {
            toast?.error?.(scan.notes || 'The run stopped. Nothing already done was lost.');
        }
    }, [scan, active, queryClient, toast]);

    const invalidate = () => {
        queryClient.invalidateQueries({ queryKey: ['bio-text-health', selectedPlatform] });
        queryClient.invalidateQueries({ queryKey: ['bio-text-findings'] });
    };

    const startScan = useMutation({
        mutationFn: async () => (await api.post('/crm/bio-text-health/scans', { platform_id: selectedPlatform })).data.data,
        onSuccess: () => {
            setSelection(new Map());
            setView('all');
            setKind('');
            invalidate();
            toast?.success?.('Check started. Progress updates below.');
        },
        onError: (error) => toast?.error?.(error?.response?.data?.message ?? 'Could not start the check.'),
    });

    const runAction = useMutation({
        mutationFn: async ({ action, ids }) => (await api.post(`/crm/bio-text-health/scans/${scan.id}/${action}`, ids ? { finding_ids: ids } : {})).data.data,
        onSuccess: (_data, { action, ids }) => {
            setConfirm(null);
            if (ids && ids.length > 1) setSelection(new Map());
            invalidate();
            toast?.success?.(action === 'repair' ? 'Repair queued. Progress updates below.' : 'Undo queued. Progress updates below.');
        },
        onError: (error) => {
            setConfirm(null);
            toast?.error?.(error?.response?.data?.message ?? 'Could not start that.');
        },
    });

    const items = findings.data?.data ?? [];
    const total = Number(findings.data?.total || 0);
    const pageCount = Math.max(1, Math.ceil(total / PER_PAGE));
    const selected = useMemo(() => [...selection.values()], [selection]);
    const selectedRepairable = selected.filter(canRepairFinding);
    const selectedRestorable = selected.filter(canRestoreFinding);
    const pageSelectable = items.filter((item) => canRepairFinding(item) || canRestoreFinding(item));
    const pageSelectedCount = pageSelectable.filter((item) => selection.has(item.id)).length;
    const pageAllSelected = pageSelectable.length > 0 && pageSelectedCount === pageSelectable.length;

    const toggle = (finding) => setSelection((current) => {
        const next = new Map(current);
        if (next.has(finding.id)) {
            next.delete(finding.id);
        } else if (next.size >= MAX_SELECTION) {
            toast?.error?.(`You can choose up to ${fmt(MAX_SELECTION)} bios at a time.`);
            return current;
        } else {
            next.set(finding.id, finding);
        }
        return next;
    });

    const togglePage = () => setSelection((current) => {
        const next = new Map(current);
        if (pageAllSelected) {
            pageSelectable.forEach((item) => next.delete(item.id));
            return next;
        }
        pageSelectable.forEach((item) => {
            if (next.size < MAX_SELECTION) next.set(item.id, item);
        });
        return next;
    });

    const exportCsv = async () => {
        setExporting(true);
        try {
            const response = await api.get(`/crm/bio-text-health/scans/${scan.id}/export`, {
                params: { view, kind: kind || undefined, search: search || undefined },
                responseType: 'blob',
            });
            const url = URL.createObjectURL(response.data);
            const link = document.createElement('a');
            link.href = url;
            link.download = `bio-text-check-${scan.id}.csv`;
            document.body.appendChild(link);
            link.click();
            link.remove();
            URL.revokeObjectURL(url);
        } catch {
            toast?.error?.('The export could not be downloaded. Try again.');
        } finally {
            setExporting(false);
        }
    };

    const marketPicker = !platformId && platforms.length ? (
        <label className="flex items-center gap-2 text-xs text-slate-600">
            <span className="font-semibold">Market</span>
            <select
                value={selectedPlatform}
                onChange={(event) => setSelectedPlatform(event.target.value)}
                className="rounded-lg border border-slate-300 bg-white px-2.5 py-1.5 text-sm text-slate-800 focus:border-teal-500 focus:outline-none focus:ring-2 focus:ring-teal-200"
            >
                <option value="">Choose a market…</option>
                {platforms.map((platform) => <option key={platform.id} value={platform.id}>{platform.name}</option>)}
            </select>
        </label>
    ) : null;

    if (!hasMarket) {
        return (
            <div className="space-y-4">
                {marketPicker}
                <Notice tone="slate" title="Choose a market to check its bios">
                    Each market is its own WordPress site, so its bios are checked and repaired separately.
                </Notice>
            </div>
        );
    }

    if (overview.isLoading) {
        return <div className="space-y-4">{marketPicker}<LoadingState /></div>;
    }

    if (overview.isError) {
        return (
            <div className="space-y-4">
                {marketPicker}
                <Notice
                    tone="rose"
                    title="The bio text check could not load"
                    action={<button type="button" onClick={() => overview.refetch()} className="rounded-lg border border-rose-300 bg-white px-3 py-1.5 text-xs font-semibold text-rose-800 hover:bg-rose-100">Try again</button>}
                >
                    {overview.error?.response?.data?.message || 'Something went wrong while loading this market.'}
                </Notice>
            </div>
        );
    }

    const history = overview.data?.history ?? [];
    const linkedProfiles = Number(overview.data?.linked_profiles || 0);
    const counts = scan?.issue_counts || {};
    const statusCounts = scan?.status_counts || {};
    const viewCounts = {
        all: scan?.profiles_affected,
        fixable: scan?.open_fixable,
        manual: scan?.profiles_manual,
        repaired: statusCounts.repaired,
        problems: null,
    };

    const confirmCount = (() => {
        if (!confirm) return 0;
        if (confirm.scope === 'all') return confirm.action === 'repair' ? Number(scan?.open_fixable || 0) : Number(scan?.restorable || 0);
        if (confirm.scope === 'selected') return confirm.action === 'repair' ? selectedRepairable.length : selectedRestorable.length;
        return 1;
    })();
    const confirmIds = () => {
        if (!confirm || confirm.scope === 'all') return null;
        if (confirm.scope === 'selected') return (confirm.action === 'repair' ? selectedRepairable : selectedRestorable).map((item) => item.id);
        return [confirm.scope.id];
    };
    const confirmSubject = confirm && typeof confirm.scope === 'object' ? `${confirm.scope.name}’s bio` : bios(confirmCount);

    return (
        <div className="space-y-4">
            {marketPicker}

            {!scan ? (
                <Introduction marketName={selectedMarketName} linkedProfiles={linkedProfiles} onStart={() => startScan.mutate()} isPending={startScan.isPending} />
            ) : null}

            {scan && reviewable ? (
                <Headline scan={scan} marketName={selectedMarketName} onRecheck={() => startScan.mutate()} busy={active || startScan.isPending} />
            ) : null}

            {active ? <Progress scan={scan} /> : null}

            {scan && scan.status === 'failed' && !reviewable ? (
                <Notice
                    tone="rose"
                    title="The check stopped before it finished"
                    action={<button type="button" onClick={() => startScan.mutate()} disabled={startScan.isPending} className="rounded-lg border border-rose-300 bg-white px-3 py-1.5 text-xs font-semibold text-rose-800 hover:bg-rose-100">Check again</button>}
                >
                    {scan.notes || 'Nothing was changed.'}
                </Notice>
            ) : null}

            {scan && reviewable && scan.notes && !active ? (
                <Notice tone={scan.status === 'failed' ? 'rose' : 'amber'} title={scan.status === 'failed' ? 'The last run stopped' : 'Worth knowing'}>
                    {scan.notes}
                </Notice>
            ) : null}

            {scan && reviewable && Number(scan.profiles_affected) > 0 ? (
                <>
                    <div className="grid gap-3" style={{ gridTemplateColumns: 'repeat(auto-fit, minmax(13.5rem, 1fr))' }}>
                        {KIND_ORDER.filter((key) => key === 'broken_accents' || Number(counts[key] || 0) > 0).map((key) => (
                            <MetricCard
                                key={key}
                                label={ISSUE_COPY[key].label}
                                value={fmt(counts[key])}
                                hint={Number(counts[key] || 0) === 0 ? 'None found' : MANUAL_KINDS.has(key) ? 'Needs a person' : 'Can be repaired'}
                                subHint={ISSUE_COPY[key].detail}
                                tone={Number(counts[key] || 0) === 0 ? 'neutral' : MANUAL_KINDS.has(key) ? 'warning' : 'danger'}
                                active={kind === key}
                                onClick={() => setKind((current) => (current === key ? '' : key))}
                            />
                        ))}
                    </div>

                    <Panel
                        title="What the repair changes"
                        subtitle="Only the broken characters change: garbled accents, escaped code and invisible characters. Each bio is read again just before it changes, backed up, then read back from WordPress to confirm the fix. Bios needing a rewrite are left for a person."
                        actions={(
                            <div className="flex flex-wrap items-center gap-2">
                                {Number(scan.restorable) > 0 ? (
                                    <button type="button" onClick={() => setConfirm({ action: 'restore', scope: 'all' })} disabled={active || runAction.isPending} className={quietButton}>
                                        Undo {fmt(scan.restorable)} {plural(scan.restorable, 'repair', 'repairs')}
                                    </button>
                                ) : null}
                                <button type="button" onClick={() => setConfirm({ action: 'repair', scope: 'all' })} disabled={active || runAction.isPending || !scan.can_repair} className={primaryButton}>
                                    {scan.status === 'repairing' ? 'Repair running…' : Number(scan.open_fixable) > 0 ? `Repair ${bios(scan.open_fixable)}` : 'Nothing left to repair'}
                                </button>
                            </div>
                        )}
                    >
                        <dl className="grid grid-cols-2 gap-x-6 gap-y-3 px-5 py-4 sm:grid-cols-4">
                            {[
                                { value: scan.open_fixable, label: 'to repair', tone: 'text-rose-700' },
                                { value: statusCounts.repaired, label: 'repaired and confirmed', tone: 'text-emerald-700' },
                                { value: scan.profiles_manual, label: 'to rewrite by hand', tone: 'text-amber-700' },
                                { value: scan.profiles_scanned, label: 'bios checked', tone: 'text-slate-800' },
                            ].map((item) => (
                                <div key={item.label}>
                                    <dt className="sr-only">{item.label}</dt>
                                    <dd className="flex items-baseline gap-1.5">
                                        <span className={`text-lg font-semibold tabular-nums ${item.tone}`}>{fmt(item.value)}</span>
                                        <span className="text-xs text-slate-500">{item.label}</span>
                                    </dd>
                                </div>
                            ))}
                        </dl>
                    </Panel>

                    <Panel
                        title="Bios with broken text"
                        subtitle="Struck-through text is what visitors see now; the teal text is the repair. Amber text needs a person."
                        actions={(
                            <div className="flex flex-wrap items-center gap-2">
                                <input
                                    type="search"
                                    value={searchInput}
                                    onChange={(event) => setSearchInput(event.target.value)}
                                    placeholder="Name or ID"
                                    aria-label="Search bios by name or ID"
                                    className="w-36 rounded-lg border border-slate-300 bg-white px-2.5 py-1.5 text-xs text-slate-800 focus:border-teal-500 focus:outline-none focus:ring-2 focus:ring-teal-200"
                                />
                                <button type="button" onClick={exportCsv} disabled={exporting} className={quietButton}>
                                    {exporting ? 'Preparing…' : 'Export CSV'}
                                </button>
                            </div>
                        )}
                    >
                        <div className="flex flex-wrap items-center justify-between gap-2 border-b border-slate-100 px-5 py-2.5">
                            <div className="inline-flex max-w-full overflow-x-auto rounded-lg border border-slate-200 bg-slate-50 p-0.5" role="group" aria-label="Show">
                                {VIEWS.map((option) => (
                                    <button
                                        key={option.key}
                                        type="button"
                                        aria-pressed={view === option.key}
                                        onClick={() => setView(option.key)}
                                        className={`whitespace-nowrap rounded-md px-2.5 py-1 text-xs font-semibold transition focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-teal-500 ${view === option.key ? 'bg-white text-teal-700 shadow-sm' : 'text-slate-600 hover:text-slate-800'}`}
                                    >
                                        {option.label}
                                        {viewCounts[option.key] !== null && viewCounts[option.key] !== undefined ? (
                                            <span className="ml-1 font-normal text-slate-400">{fmt(viewCounts[option.key])}</span>
                                        ) : null}
                                    </button>
                                ))}
                            </div>
                            {kind ? (
                                <button type="button" onClick={() => setKind('')} className="inline-flex items-center gap-1 rounded-full bg-teal-50 px-2.5 py-1 text-[11px] font-semibold text-teal-800 ring-1 ring-teal-200 hover:bg-teal-100">
                                    {ISSUE_COPY[kind]?.label} <span aria-hidden="true">×</span><span className="sr-only">Clear filter</span>
                                </button>
                            ) : null}
                        </div>

                        {pageSelectable.length ? (
                            <div className="flex items-center justify-between gap-3 border-b border-slate-100 px-5 py-2 text-xs text-slate-600">
                                <label className="inline-flex cursor-pointer items-center gap-3 font-semibold">
                                    <input
                                        type="checkbox"
                                        checked={pageAllSelected}
                                        ref={(element) => {
                                            if (element) element.indeterminate = pageSelectedCount > 0 && !pageAllSelected;
                                        }}
                                        onChange={togglePage}
                                        className="h-4 w-4 cursor-pointer rounded border-slate-300 accent-teal-600"
                                    />
                                    Select all on this page
                                </label>
                                {selection.size ? (
                                    <span className="tabular-nums text-slate-500">
                                        {fmt(selection.size)} selected{selection.size > pageSelectedCount ? ` · ${fmt(selection.size - pageSelectedCount)} on other pages` : ''}
                                    </span>
                                ) : null}
                            </div>
                        ) : null}

                        {findings.isLoading ? (
                            <div className="space-y-2 px-5 py-4" aria-busy="true">
                                {[0, 1, 2].map((index) => <div key={index} className="h-20 animate-pulse rounded-lg bg-slate-50" />)}
                            </div>
                        ) : findings.isError ? (
                            <div className="px-5 py-6 text-center text-xs text-rose-700">
                                The list could not load. <button type="button" onClick={() => findings.refetch()} className="font-semibold underline">Try again</button>
                            </div>
                        ) : items.length ? (
                            <ul className={`divide-y divide-slate-100 transition-opacity ${findings.isFetching && !active ? 'opacity-60' : ''}`}>
                                {items.map((finding) => (
                                    <FindingRow
                                        key={finding.id}
                                        finding={finding}
                                        selected={selection.has(finding.id)}
                                        onToggle={toggle}
                                        onRepair={(item) => setConfirm({ action: 'repair', scope: item })}
                                        onRestore={(item) => setConfirm({ action: 'restore', scope: item })}
                                        busy={active || runAction.isPending}
                                    />
                                ))}
                            </ul>
                        ) : (
                            <p className="px-5 py-10 text-center text-xs text-slate-500">
                                {search ? `No bios match “${search}”.` : 'No bios in this view.'}
                            </p>
                        )}

                        {total > PER_PAGE ? (
                            <footer className="flex items-center justify-between gap-3 border-t border-slate-100 px-5 py-3 text-xs text-slate-500">
                                <span className="tabular-nums">{fmt((page - 1) * PER_PAGE + 1)}–{fmt(Math.min(page * PER_PAGE, total))} of {fmt(total)}</span>
                                <div className="flex gap-1">
                                    <button type="button" disabled={page <= 1} onClick={() => setPage((current) => current - 1)} className="rounded-md border border-slate-300 bg-white px-2.5 py-1 font-semibold text-slate-700 hover:bg-slate-50 disabled:opacity-40">Previous</button>
                                    <button type="button" disabled={page >= pageCount} onClick={() => setPage((current) => current + 1)} className="rounded-md border border-slate-300 bg-white px-2.5 py-1 font-semibold text-slate-700 hover:bg-slate-50 disabled:opacity-40">Next</button>
                                </div>
                            </footer>
                        ) : null}
                    </Panel>
                </>
            ) : null}

            {scan && reviewable && Number(scan.profiles_affected) === 0 && !active ? (
                <div className="flex flex-col items-center rounded-xl border border-dashed border-slate-200 bg-slate-50/50 px-6 py-10 text-center">
                    <p className="text-sm font-medium text-slate-700">Nothing to repair</p>
                    <p className="mt-1 max-w-md text-xs leading-relaxed text-slate-500">
                        Staff see a warning when a bio they write has broken text, and garbled accents are repaired automatically when a bio is saved.
                    </p>
                </div>
            ) : null}

            {selection.size > 0 ? (
                <div className="sticky bottom-4 z-20" role="region" aria-label="Selected bios">
                    <div className="flex flex-wrap items-center justify-between gap-3 rounded-xl border border-teal-200 bg-white px-4 py-3 shadow-lg ring-1 ring-teal-100">
                        <div className="min-w-0 text-sm">
                            <p className="font-semibold text-slate-900">{bios(selection.size)} selected</p>
                            <p className="mt-0.5 text-xs text-slate-500">
                                {[
                                    selectedRepairable.length ? `${fmt(selectedRepairable.length)} can be repaired` : null,
                                    selectedRestorable.length ? `${fmt(selectedRestorable.length)} can be undone` : null,
                                ].filter(Boolean).join(' · ') || 'Nothing to do for these'}
                            </p>
                        </div>
                        <div className="flex items-center gap-2">
                            <button type="button" onClick={() => setSelection(new Map())} className="rounded-lg px-3 py-2 text-xs font-semibold text-slate-600 transition hover:bg-slate-100 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-teal-500">
                                Clear
                            </button>
                            {selectedRestorable.length ? (
                                <button type="button" onClick={() => setConfirm({ action: 'restore', scope: 'selected' })} disabled={active || runAction.isPending} className={quietButton}>
                                    Undo {fmt(selectedRestorable.length)}
                                </button>
                            ) : null}
                            {selectedRepairable.length ? (
                                <button type="button" onClick={() => setConfirm({ action: 'repair', scope: 'selected' })} disabled={active || runAction.isPending} className={primaryButton}>
                                    Repair {fmt(selectedRepairable.length)}
                                </button>
                            ) : null}
                        </div>
                    </div>
                </div>
            ) : null}

            <History history={history} />

            <ConfirmDialog
                open={confirm?.action === 'repair'}
                title={`Repair ${confirmSubject}${confirm?.scope === 'all' ? ` in ${selectedMarketName || 'this market'}` : ''}?`}
                confirmLabel="Start repair"
                isPending={runAction.isPending}
                onCancel={() => setConfirm(null)}
                onConfirm={() => runAction.mutate({ action: 'repair', ids: confirmIds() })}
            >
                <ul className="space-y-1.5 text-sm text-slate-700">
                    <li>Garbled accents, escaped code and invisible characters are corrected. Nothing else in the {plural(confirmCount, 'bio', 'bios')} changes.</li>
                    <li>Each bio is backed up first, then read back from WordPress to confirm the fix.</li>
                    <li>A bio edited since the check is checked again before it changes.</li>
                </ul>
                <p className="mt-3 text-xs leading-relaxed text-slate-500">Runs in the background. Any repair can be undone from this page.</p>
            </ConfirmDialog>

            <ConfirmDialog
                open={confirm?.action === 'restore'}
                title={`Undo the repair of ${confirmSubject}?`}
                tone="warning"
                confirmLabel="Put them back"
                isPending={runAction.isPending}
                onCancel={() => setConfirm(null)}
                onConfirm={() => runAction.mutate({ action: 'restore', ids: confirmIds() })}
            >
                <p className="text-sm text-slate-700">
                    Puts the {plural(confirmCount, 'bio', 'bios')} back exactly as {plural(confirmCount, 'it was', 'they were')} before the repair, broken text included.
                    A bio someone has edited since the repair is left alone.
                </p>
            </ConfirmDialog>
        </div>
    );
}
