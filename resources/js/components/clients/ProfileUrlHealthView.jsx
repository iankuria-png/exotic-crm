import React, { useEffect, useMemo, useState } from 'react';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import api from '../../services/api';
import { useToast } from '../ToastProvider';
import ConfirmDialog from '../ConfirmDialog';
import MetricCard from '../MetricCard';

// When a profile is renamed, WordPress keeps its old address as a redirect.
// This screen finds old addresses that send visitors — or would send them —
// to a different profile than the one that last used the name, and repairs
// them in WordPress (exotic-crm-sync 1.3.13).

const KINDS = {
    wrong_target: {
        label: 'Going to the wrong profile',
        short: 'Wrong profile',
        tone: 'danger',
        hint: 'Live problem',
        blurb: 'A newer profile used this name and was removed. Its old link now opens an older, unrelated profile.',
        chip: 'bg-rose-50 text-rose-700 ring-rose-200',
    },
    revivable: {
        label: 'Dead links that can work again',
        short: 'Can work again',
        tone: 'accent',
        hint: 'Returns 404 today',
        blurb: 'Several profiles claim this old name, so it shows a 404. The profile that used it last keeps it and gets the link whenever it is live.',
        chip: 'bg-teal-50 text-teal-700 ring-teal-200',
    },
    at_risk: {
        label: 'Waiting to misroute',
        short: 'At risk',
        tone: 'warning',
        hint: 'Fine until the owner changes',
        blurb: 'Works today, but an older profile still claims the name. If the current profile is renamed or removed, visitors land on the older one.',
        chip: 'bg-amber-50 text-amber-700 ring-amber-200',
    },
};

const KIND_ORDER = ['wrong_target', 'revivable', 'at_risk'];

const RUN_STATUS = {
    queued: { label: 'Queued', tone: 'bg-slate-100 text-slate-700 ring-slate-200' },
    running: { label: 'Repairing', tone: 'bg-sky-50 text-sky-700 ring-sky-200' },
    completed: { label: 'Repaired', tone: 'bg-emerald-50 text-emerald-700 ring-emerald-200' },
    failed: { label: 'Stopped', tone: 'bg-rose-50 text-rose-700 ring-rose-200' },
    restoring: { label: 'Restoring', tone: 'bg-sky-50 text-sky-700 ring-sky-200' },
    restored: { label: 'Restored', tone: 'bg-amber-50 text-amber-700 ring-amber-200' },
};

const ACTIVE_STATUSES = new Set(['queued', 'running', 'restoring']);
const PER_PAGE = 25;
// The plugin repairs at most this many chosen URLs per run.
const MAX_SELECTION = 500;

const itemKey = (item) => `${item.post_type}:${item.slug}`;

/** What repairing these audit rows changes, from each row's before/after. */
function selectionImpact(selected) {
    let wrongStopped = 0;
    let revived = 0;
    let protectedCount = 0;
    let claims = 0;
    selected.forEach((item) => {
        const before = item.before?.state;
        const after = item.after?.state;
        const retargeted = before === 'redirect' && after === 'redirect'
            && item.before?.profile?.post_id !== item.after?.profile?.post_id;
        if ((before === 'redirect' && after === 'not_found') || retargeted) wrongStopped += 1;
        else if (before === 'not_found' && after === 'redirect') revived += 1;
        else protectedCount += 1;
        claims += (item.release || []).length;
    });
    return { wrongStopped, revived, protectedCount, claims };
}

const numberFormat = new Intl.NumberFormat('en-US');
const fmt = (value) => numberFormat.format(Number(value || 0));
const plural = (count, one, many) => (Number(count) === 1 ? one : many);

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

function urlPath(url) {
    try {
        return new URL(url).pathname;
    } catch {
        return url || '';
    }
}

// ─── Small pieces ────────────────────────────────────────────────────────────

function Pill({ children, tone = 'bg-slate-100 text-slate-700 ring-slate-200' }) {
    return (
        <span className={`inline-flex items-center whitespace-nowrap rounded-full px-2 py-0.5 text-[11px] font-semibold ring-1 ring-inset ${tone}`}>
            {children}
        </span>
    );
}

function StatusDot({ status }) {
    if (!status || status === 'publish') return null;
    return (
        <span className="ml-1 rounded bg-slate-100 px-1 py-px text-[10px] font-medium uppercase tracking-wide text-slate-500">
            {status}
        </span>
    );
}

function ProfileLink({ profile, className = '' }) {
    if (!profile) return null;
    const title = profile.title || `Profile #${profile.post_id}`;
    return (
        <span className={`inline-flex min-w-0 items-center ${className}`}>
            {profile.url ? (
                <a
                    href={profile.url}
                    target="_blank"
                    rel="noreferrer"
                    className="truncate font-medium text-slate-800 underline decoration-slate-300 underline-offset-2 hover:text-teal-700 hover:decoration-teal-400"
                    title={`${title} — ${profile.url}`}
                >
                    {title}
                </a>
            ) : (
                <span className="truncate font-medium text-slate-800" title={title}>{title}</span>
            )}
            <StatusDot status={profile.status} />
        </span>
    );
}

/** Where the old URL goes: a destination with a consistent visual grammar. */
function Destination({ behaviour, emphasis = false }) {
    const state = behaviour?.state;
    const base = 'flex min-w-0 items-center gap-1.5 rounded-md border px-2 py-1 text-xs';

    if (state === 'redirect') {
        return (
            <div className={`${base} ${emphasis ? 'border-rose-200 bg-rose-50/60' : 'border-slate-200 bg-white'}`}>
                <span aria-hidden="true" className="text-slate-400">↪</span>
                <span className="sr-only">Redirects to</span>
                <ProfileLink profile={behaviour.profile} />
            </div>
        );
    }

    if (state === 'owned') {
        return (
            <div className={`${base} border-slate-200 bg-white`}>
                <span aria-hidden="true" className="text-emerald-500">●</span>
                <span className="sr-only">Opens its owner</span>
                <ProfileLink profile={behaviour.profile} />
            </div>
        );
    }

    return (
        <div className={`${base} border-dashed border-slate-300 bg-slate-50 text-slate-500`}>
            <span aria-hidden="true">∅</span>
            <span className="font-medium">404 — not found</span>
        </div>
    );
}

function CopyButton({ value, label = 'Copy URL' }) {
    const [copied, setCopied] = useState(false);
    return (
        <button
            type="button"
            onClick={async () => {
                try {
                    await navigator.clipboard.writeText(value);
                    setCopied(true);
                    window.setTimeout(() => setCopied(false), 1400);
                } catch {
                    // Clipboard can be unavailable on insecure origins; the URL stays selectable.
                }
            }}
            className="shrink-0 rounded px-1.5 py-0.5 text-[11px] font-semibold text-slate-400 transition hover:bg-slate-100 hover:text-slate-700 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-teal-500"
            aria-label={label}
        >
            {copied ? 'Copied' : 'Copy'}
        </button>
    );
}

function Panel({ title, subtitle, actions, children, className = '' }) {
    return (
        // `relative` keeps absolutely positioned sr-only labels inside the panel.
        <section className={`crm-surface relative overflow-hidden rounded-xl border border-slate-200 ${className}`}>
            {title ? (
                <header className="flex flex-wrap items-start justify-between gap-3 border-b border-slate-100 bg-slate-50/60 px-5 py-3.5">
                    <div className="min-w-0">
                        <h3 className="text-sm font-semibold text-slate-800">{title}</h3>
                        {subtitle ? <p className="mt-1 text-xs leading-relaxed text-slate-500">{subtitle}</p> : null}
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

// ─── Sections ────────────────────────────────────────────────────────────────

function HealthHeadline({ summary, marketName, isFetching, onRecheck }) {
    const kinds = summary?.kinds || {};
    const wrong = Number(kinds.wrong_target?.urls || 0);
    const total = Number(summary?.urls || 0);

    let tone = 'border-emerald-200 bg-gradient-to-r from-emerald-50 to-white';
    let icon = '✓';
    let iconTone = 'bg-emerald-600';
    let headline = 'Every old profile URL points where it should';
    let sub = 'Renamed profiles keep their old links, and no old link can land on the wrong profile.';

    if (wrong > 0) {
        tone = 'border-rose-200 bg-gradient-to-r from-rose-50 to-white';
        icon = '!';
        iconTone = 'bg-rose-600';
        headline = `${fmt(wrong)} old URL${wrong === 1 ? ' is' : 's are'} sending visitors to the wrong profile`;
        sub = `${fmt(total)} URL${total === 1 ? '' : 's'} need attention in total. The repair fixes all of them in one background run.`;
    } else if (total > 0) {
        tone = 'border-amber-200 bg-gradient-to-r from-amber-50 to-white';
        icon = '◐';
        iconTone = 'bg-amber-500';
        headline = `${fmt(total)} old URL${total === 1 ? ' needs' : 's need'} tidying`;
        sub = 'Nothing is misrouting right now, but these would the moment their current profile is renamed or removed.';
    }

    return (
        <div className={`flex flex-wrap items-center justify-between gap-4 rounded-xl border px-5 py-4 ${tone}`} aria-live="polite">
            <div className="flex min-w-0 items-start gap-3">
                <span className={`mt-0.5 inline-flex h-7 w-7 shrink-0 items-center justify-center rounded-full text-sm font-bold text-white ${iconTone}`} aria-hidden="true">
                    {icon}
                </span>
                <div className="min-w-0">
                    <p className="text-[15px] font-semibold leading-snug text-slate-900">{headline}</p>
                    <p className="mt-0.5 text-xs leading-relaxed text-slate-600">{sub}</p>
                </div>
            </div>
            <div className="flex items-center gap-3 text-xs text-slate-500">
                <span>
                    {marketName ? `${marketName} · ` : ''}
                    checked {relativeTime(summary?.checked_at) || '—'}
                </span>
                <button
                    type="button"
                    onClick={onRecheck}
                    disabled={isFetching}
                    className="rounded-lg border border-slate-300 bg-white px-3 py-1.5 font-semibold text-slate-700 transition hover:bg-slate-50 disabled:cursor-wait disabled:opacity-60 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-teal-500"
                >
                    {isFetching ? 'Checking…' : 'Check again'}
                </button>
            </div>
        </div>
    );
}

function ImpactStrip({ changes, aliases }) {
    const redirectsStopped = Number(changes?.redirect_to_404 || 0) + Number(changes?.retargeted || 0);
    const revived = Number(changes?.['404_to_redirect'] || 0);
    const protectedCount = Number(changes?.unchanged || 0);
    const items = [
        { value: redirectsStopped, label: 'wrong redirects stop', tone: 'text-rose-700' },
        { value: revived, label: 'dead links start working', tone: 'text-teal-700' },
        { value: protectedCount, label: 'URLs protected for later', tone: 'text-amber-700' },
        { value: aliases, label: 'stale claims removed', tone: 'text-slate-800' },
    ];

    return (
        <dl className="grid grid-cols-2 gap-x-6 gap-y-3 sm:grid-cols-4">
            {items.map((item) => (
                <div key={item.label}>
                    <dt className="sr-only">{item.label}</dt>
                    <dd className="flex items-baseline gap-1.5">
                        <span className={`text-lg font-semibold tabular-nums ${item.tone}`}>{fmt(item.value)}</span>
                        <span className="text-xs text-slate-500">{item.label}</span>
                    </dd>
                </div>
            ))}
        </dl>
    );
}

function UrlRow({ item, selectable = false, selected = false, onToggle }) {
    const kind = KINDS[item.kind] || KINDS.at_risk;
    const released = item.release || [];
    const path = urlPath(item.url);

    return (
        <li className={`flex gap-3 px-5 py-3.5 transition ${selected ? 'bg-teal-50/60' : 'hover:bg-slate-50/70'}`}>
            {selectable ? (
                <input
                    type="checkbox"
                    checked={selected}
                    onChange={() => onToggle(item)}
                    aria-label={`Select ${path}`}
                    className="mt-1 h-4 w-4 shrink-0 cursor-pointer rounded border-slate-300 text-teal-600 accent-teal-600 focus:ring-teal-500"
                />
            ) : null}
            <div className="grid min-w-0 flex-1 gap-3 lg:grid-cols-[minmax(0,1.1fr)_minmax(0,1.6fr)_minmax(0,1fr)] lg:items-center">
            <div className="min-w-0">
                <div className="flex items-center gap-1">
                    <a
                        href={item.url}
                        target="_blank"
                        rel="noreferrer"
                        className="truncate font-mono text-[13px] text-slate-900 hover:text-teal-700"
                        title={item.url}
                    >
                        {path}
                    </a>
                    <CopyButton value={item.url} />
                </div>
                <div className="mt-1">
                    <Pill tone={kind.chip}>{kind.short}</Pill>
                </div>
            </div>

            <div className="grid min-w-0 grid-cols-[minmax(0,1fr)_auto_minmax(0,1fr)] items-center gap-2">
                <div className="min-w-0">
                    <p className="mb-1 text-[10px] font-semibold uppercase tracking-[0.1em] text-slate-400">Today</p>
                    <Destination behaviour={item.before} emphasis={item.kind === 'wrong_target'} />
                </div>
                <span className="mt-4 text-slate-300" aria-hidden="true">→</span>
                <div className="min-w-0">
                    <p className="mb-1 text-[10px] font-semibold uppercase tracking-[0.1em] text-teal-700">After repair</p>
                    <Destination behaviour={item.after} />
                    {item.after?.state === 'not_found' && item.keep ? (
                        <p
                            className="mt-1 truncate text-[11px] text-slate-500"
                            title={`Reserved for ${item.keep.title}. The link opens it once the profile is live.`}
                        >
                            Reserved for <span className="font-medium text-slate-700">{item.keep.title}</span>
                            {item.keep.status && item.keep.status !== 'publish' ? ` (${item.keep.status})` : ''} · opens once live
                        </p>
                    ) : null}
                </div>
            </div>

            <div className="min-w-0 text-xs text-slate-600">
                <p className="mb-1 text-[10px] font-semibold uppercase tracking-[0.1em] text-slate-400">
                    Stops claiming this URL
                </p>
                <ul className="space-y-0.5">
                    {released.slice(0, 3).map((profile) => (
                        <li key={profile.post_id} className="flex min-w-0">
                            <ProfileLink profile={profile} />
                        </li>
                    ))}
                    {released.length > 3 ? <li className="text-slate-400">+{released.length - 3} more</li> : null}
                </ul>
                {item.kind === 'wrong_target' && item.trashed_owner ? (
                    <p className="mt-1 text-[11px] text-slate-400">Last used by “{item.trashed_owner.title}” (in trash)</p>
                ) : null}
            </div>
            </div>
        </li>
    );
}

function RunProgress({ run }) {
    const restoring = run.status === 'restoring';
    const total = restoring ? run.backup_count : run.target_urls;
    const done = restoring ? run.restored_count : run.urls_processed;
    const percent = total > 0 ? Math.min(100, Math.round((done / total) * 100)) : 0;

    return (
        <div className="rounded-xl border border-sky-200 bg-sky-50 px-5 py-4 text-sky-900" aria-live="polite">
            <div className="flex flex-wrap items-baseline justify-between gap-2">
                <p className="text-sm font-semibold">
                    {restoring ? 'Restoring the old URLs from the backup…' : 'Repairing old URLs in WordPress…'}
                </p>
                <p className="text-xs tabular-nums opacity-80">
                    {fmt(done)} of {fmt(total)} {restoring ? 'records' : 'URLs'}
                    {!restoring ? ` · ${fmt(run.aliases_released)} stale claims removed` : ''}
                </p>
            </div>
            <div
                className="mt-3 h-2 overflow-hidden rounded-full bg-sky-100"
                role="progressbar"
                aria-valuemin={0}
                aria-valuemax={100}
                aria-valuenow={percent}
                aria-label={restoring ? 'Restore progress' : 'Repair progress'}
            >
                <div className="h-full rounded-full bg-sky-600 transition-[width] duration-700 ease-out" style={{ width: `${Math.max(percent, run.status === 'queued' ? 3 : percent)}%` }} />
            </div>
            <p className="mt-2 text-[11px] opacity-70">You can leave this page — the run continues in the background.</p>
        </div>
    );
}

function RunHistory({ runs, onRestore, onDownload, downloadingId, busy }) {
    if (!runs.length) {
        return (
            <p className="px-5 py-6 text-center text-xs text-slate-500">
                No repairs have been run for this market yet.
            </p>
        );
    }

    return (
        <div className="relative overflow-x-auto">
            <table className="min-w-full text-left text-xs">
                <thead className="border-b border-slate-100 text-[10px] uppercase tracking-[0.1em] text-slate-400">
                    <tr>
                        <th scope="col" className="px-5 py-2 font-semibold">Started</th>
                        <th scope="col" className="px-3 py-2 font-semibold">By</th>
                        <th scope="col" className="px-3 py-2 font-semibold">Status</th>
                        <th scope="col" className="px-3 py-2 text-right font-semibold">URLs</th>
                        <th scope="col" className="px-3 py-2 font-semibold">Removed claims</th>
                        <th scope="col" className="px-5 py-2 text-right font-semibold"><span className="sr-only">Actions</span></th>
                    </tr>
                </thead>
                <tbody className="divide-y divide-slate-100">
                    {runs.map((run) => {
                        const status = RUN_STATUS[run.status] || RUN_STATUS.queued;
                        const byKind = run.released_by_kind || {};
                        return (
                            <tr key={run.id} className="align-top">
                                <td className="whitespace-nowrap px-5 py-3 text-slate-700" title={run.created_at ? new Date(run.created_at).toLocaleString() : ''}>
                                    {run.created_at ? new Date(run.created_at).toLocaleString(undefined, { dateStyle: 'medium', timeStyle: 'short' }) : '—'}
                                    <span className="block text-[11px] text-slate-400">
                                        {run.scope === 'selected' ? `${fmt(run.target_urls)} selected ${plural(run.target_urls, 'URL', 'URLs')}` : 'Whole market'}
                                    </span>
                                </td>
                                <td className="px-3 py-3 text-slate-600">
                                    {run.requested_by || '—'}
                                    {run.restored_by ? <span className="block text-[11px] text-slate-400">restored by {run.restored_by}</span> : null}
                                </td>
                                <td className="px-3 py-3">
                                    <Pill tone={status.tone}>{status.label}</Pill>
                                    {run.notes ? <p className="mt-1 max-w-xs whitespace-pre-line text-[11px] leading-snug text-slate-500">{run.notes}</p> : null}
                                </td>
                                <td className="px-3 py-3 text-right tabular-nums text-slate-700">{fmt(run.urls_processed)}</td>
                                <td className="px-3 py-3 text-slate-600">
                                    <span className="font-semibold tabular-nums text-slate-800">{fmt(run.aliases_released)}</span>
                                    <span className="ml-1.5 text-[11px] text-slate-400">
                                        {KIND_ORDER.filter((kind) => byKind[kind]).map((kind) => `${fmt(byKind[kind])} ${KINDS[kind].short.toLowerCase()}`).join(' · ')}
                                    </span>
                                </td>
                                <td className="whitespace-nowrap px-5 py-3 text-right">
                                    {run.backup_count > 0 ? (
                                        <button
                                            type="button"
                                            onClick={() => onDownload(run)}
                                            disabled={downloadingId === run.id}
                                            className="rounded-md px-2 py-1 font-semibold text-slate-600 transition hover:bg-slate-100 hover:text-slate-900 disabled:opacity-50 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-teal-500"
                                        >
                                            {downloadingId === run.id ? 'Preparing…' : 'Backup CSV'}
                                        </button>
                                    ) : null}
                                    {run.can_restore ? (
                                        <button
                                            type="button"
                                            onClick={() => onRestore(run)}
                                            disabled={busy}
                                            title={busy ? 'Wait for the current run to finish' : 'Put every removed claim back'}
                                            className="ml-1 rounded-md border border-slate-300 bg-white px-2 py-1 font-semibold text-slate-700 transition hover:border-amber-300 hover:bg-amber-50 hover:text-amber-800 disabled:cursor-not-allowed disabled:opacity-40 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-amber-500"
                                        >
                                            Restore
                                        </button>
                                    ) : null}
                                </td>
                            </tr>
                        );
                    })}
                </tbody>
            </table>
        </div>
    );
}

function LoadingState() {
    return (
        <div className="space-y-4" aria-busy="true" aria-label="Checking profile URLs">
            <div className="h-[72px] animate-pulse rounded-xl border border-slate-200 bg-slate-50" />
            <div className="grid gap-3 sm:grid-cols-3">
                {[0, 1, 2].map((index) => <div key={index} className="h-[104px] animate-pulse rounded-xl border border-slate-200 bg-slate-50" />)}
            </div>
            <div className="h-64 animate-pulse rounded-xl border border-slate-200 bg-slate-50" />
        </div>
    );
}

// ─── View ────────────────────────────────────────────────────────────────────

export default function ProfileUrlHealthView({ platformId, platforms = [], marketName }) {
    const toast = useToast();
    const queryClient = useQueryClient();
    const [selectedPlatform, setSelectedPlatform] = useState(platformId ? String(platformId) : '');
    const [kind, setKind] = useState('');
    const [page, setPage] = useState(1);
    // null, 'market' or 'selected': which repair the confirm dialog is for.
    const [confirmRepair, setConfirmRepair] = useState(null);
    // Chosen URLs by key, kept across pages and filters.
    const [selection, setSelection] = useState(() => new Map());
    const [restoreTarget, setRestoreTarget] = useState(null);
    const [downloadingId, setDownloadingId] = useState(null);

    useEffect(() => {
        if (platformId) setSelectedPlatform(String(platformId));
    }, [platformId]);

    useEffect(() => {
        setPage(1);
    }, [kind, selectedPlatform]);

    useEffect(() => {
        setSelection(new Map());
    }, [selectedPlatform]);

    const hasMarket = Boolean(selectedPlatform);
    const selectedMarketName = marketName
        || platforms.find((platform) => String(platform.id) === selectedPlatform)?.name
        || '';

    const healthQuery = useQuery({
        queryKey: ['profile-url-health', selectedPlatform, kind, page],
        enabled: hasMarket,
        placeholderData: (previous) => previous,
        queryFn: async () => {
            const { data } = await api.get('/crm/profile-url-health', {
                params: { platform_id: selectedPlatform, kind: kind || undefined, page, per_page: PER_PAGE },
            });
            return data;
        },
        // While a run is moving, keep its progress and the counts honest.
        refetchInterval: (query) => (query.state.data?.runs ?? []).some((run) => ACTIVE_STATUSES.has(run.status)) ? 3000 : false,
    });

    const data = healthQuery.data;
    const runs = data?.runs ?? [];
    const activeRun = runs.find((run) => ACTIVE_STATUSES.has(run.status)) || null;
    const summary = data?.audit?.summary;
    const items = data?.audit?.items ?? [];
    const total = Number(data?.audit?.total || 0);
    const totalUrls = Number(summary?.urls || 0);
    const pageCount = Math.max(1, Math.ceil(total / PER_PAGE));
    const canSelect = (summary?.capabilities || []).includes('scoped_repair');
    const selectedItems = useMemo(() => [...selection.values()], [selection]);
    const selectedImpact = useMemo(() => selectionImpact(selectedItems), [selectedItems]);
    const pageKeys = items.map(itemKey);
    const pageSelectedCount = pageKeys.filter((key) => selection.has(key)).length;
    const pageAllSelected = items.length > 0 && pageSelectedCount === items.length;

    const toggleItem = (item) => {
        setSelection((current) => {
            const next = new Map(current);
            const key = itemKey(item);
            if (next.has(key)) {
                next.delete(key);
            } else if (next.size >= MAX_SELECTION) {
                toast?.error?.(`You can repair up to ${fmt(MAX_SELECTION)} chosen URLs at a time.`);
                return current;
            } else {
                next.set(key, item);
            }
            return next;
        });
    };

    const togglePage = () => {
        setSelection((current) => {
            const next = new Map(current);
            if (pageAllSelected) {
                pageKeys.forEach((key) => next.delete(key));
                return next;
            }
            for (const item of items) {
                if (next.size >= MAX_SELECTION) {
                    toast?.error?.(`Selection is capped at ${fmt(MAX_SELECTION)} URLs.`);
                    break;
                }
                next.set(itemKey(item), item);
            }
            return next;
        });
    };

    // Tell the admin when a run they started finishes, once.
    const [watchedRunId, setWatchedRunId] = useState(null);
    useEffect(() => {
        if (!watchedRunId) return;
        const run = runs.find((candidate) => candidate.id === watchedRunId);
        if (!run || ACTIVE_STATUSES.has(run.status)) return;
        setWatchedRunId(null);
        if (run.status === 'completed') {
            toast?.success?.(`Repair finished — ${fmt(run.aliases_released)} stale claims removed across ${fmt(run.urls_processed)} URLs.`);
        } else if (run.status === 'restored') {
            toast?.success?.('Restore finished — every old URL is back as it was.');
        } else if (run.status === 'failed') {
            toast?.error?.(run.notes || 'The run stopped. Its backup is kept and it can be restored.');
        }
    }, [runs, toast, watchedRunId]);

    const startRepair = useMutation({
        mutationFn: async (targets) => {
            const { data: response } = await api.post('/crm/profile-url-health/runs', {
                platform_id: selectedPlatform,
                ...(targets ? { targets } : {}),
            });
            return response.data;
        },
        onSuccess: (run, targets) => {
            setConfirmRepair(null);
            setWatchedRunId(run.id);
            if (targets) setSelection(new Map());
            queryClient.invalidateQueries({ queryKey: ['profile-url-health', selectedPlatform] });
            toast?.success?.(targets
                ? `Repair of ${fmt(targets.length)} selected ${plural(targets.length, 'URL', 'URLs')} queued.`
                : 'Repair queued — progress updates below.');
        },
        onError: (error) => {
            setConfirmRepair(null);
            toast?.error?.(error?.response?.data?.message ?? 'Could not start the repair.');
        },
    });

    const selectedTargets = () => selectedItems.map((item) => ({ post_type: item.post_type, slug: item.slug }));

    const restoreRun = useMutation({
        mutationFn: async (runId) => {
            const { data: response } = await api.post(`/crm/profile-url-health/runs/${runId}/restore`);
            return response.data;
        },
        onSuccess: (run) => {
            setRestoreTarget(null);
            setWatchedRunId(run.id);
            queryClient.invalidateQueries({ queryKey: ['profile-url-health', selectedPlatform] });
            toast?.success?.('Restore queued.');
        },
        onError: (error) => {
            setRestoreTarget(null);
            toast?.error?.(error?.response?.data?.message ?? 'Could not restore this run.');
        },
    });

    const downloadBackup = async (run) => {
        setDownloadingId(run.id);
        try {
            const response = await api.get(`/crm/profile-url-health/runs/${run.id}/backup`, { responseType: 'blob' });
            const url = URL.createObjectURL(response.data);
            const link = document.createElement('a');
            link.href = url;
            link.download = `profile-url-repair-run-${run.id}.csv`;
            document.body.appendChild(link);
            link.click();
            link.remove();
            URL.revokeObjectURL(url);
        } catch {
            toast?.error?.('The backup could not be downloaded. Try again.');
        } finally {
            setDownloadingId(null);
        }
    };

    const kindCounts = useMemo(() => summary?.kinds || {}, [summary]);

    // ── Market picker (only when the page-level switcher has no market) ──
    const marketPicker = !platformId && platforms.length ? (
        <label className="flex items-center gap-2 text-xs text-slate-600">
            <span className="font-semibold">Market</span>
            <select
                value={selectedPlatform}
                onChange={(event) => setSelectedPlatform(event.target.value)}
                className="rounded-lg border border-slate-300 bg-white px-2.5 py-1.5 text-sm text-slate-800 focus:border-teal-500 focus:outline-none focus:ring-2 focus:ring-teal-200"
            >
                <option value="">Choose a market…</option>
                {platforms.map((platform) => (
                    <option key={platform.id} value={platform.id}>{platform.name}</option>
                ))}
            </select>
        </label>
    ) : null;

    if (!hasMarket) {
        return (
            <div className="space-y-4">
                {marketPicker}
                <Notice tone="slate" title="Choose a market to check its profile URLs">
                    Each market is its own WordPress site, so its old URLs are checked and repaired separately.
                </Notice>
            </div>
        );
    }

    if (healthQuery.isLoading) {
        return (
            <div className="space-y-4">
                {marketPicker}
                <LoadingState />
            </div>
        );
    }

    if (healthQuery.isError) {
        return (
            <div className="space-y-4">
                {marketPicker}
                <Notice
                    tone="rose"
                    title="The profile URL check could not run"
                    action={(
                        <button type="button" onClick={() => healthQuery.refetch()} className="rounded-lg border border-rose-300 bg-white px-3 py-1.5 text-xs font-semibold text-rose-800 hover:bg-rose-100">
                            Try again
                        </button>
                    )}
                >
                    {healthQuery.error?.response?.data?.message || 'Something went wrong while asking the market site.'}
                </Notice>
            </div>
        );
    }

    if (data && data.available === false) {
        const outdated = data.reason === 'plugin_outdated';
        return (
            <div className="space-y-4">
                {marketPicker}
                <Notice
                    tone={outdated ? 'amber' : 'rose'}
                    title={outdated ? `${selectedMarketName || 'This market'} needs a plugin update first` : `${selectedMarketName || 'The market site'} did not answer`}
                    action={outdated ? null : (
                        <button type="button" onClick={() => healthQuery.refetch()} className="rounded-lg border border-rose-300 bg-white px-3 py-1.5 text-xs font-semibold text-rose-800 hover:bg-rose-100">
                            Try again
                        </button>
                    )}
                >
                    {outdated
                        ? 'Upload exotic-crm-sync 1.3.13 or later to this market. The check, the repair and the fix that stops new CRM profiles from inheriting old URLs all ship in that version.'
                        : data.message}
                </Notice>
                {runs.length ? (
                    <Panel title="Previous runs">
                        <RunHistory runs={runs} onRestore={setRestoreTarget} onDownload={downloadBackup} downloadingId={downloadingId} busy />
                    </Panel>
                ) : null}
            </div>
        );
    }

    return (
        <div className="space-y-4">
            {marketPicker}

            <HealthHeadline
                summary={summary}
                marketName={selectedMarketName}
                isFetching={healthQuery.isFetching}
                onRecheck={() => healthQuery.refetch()}
            />

            {activeRun ? <RunProgress run={activeRun} /> : null}

            {totalUrls > 0 ? (
                <>
                    <div className="grid gap-3 sm:grid-cols-3">
                        {KIND_ORDER.map((key) => (
                            <MetricCard
                                key={key}
                                label={KINDS[key].label}
                                value={fmt(kindCounts[key]?.urls)}
                                hint={Number(kindCounts[key]?.urls || 0) > 0 ? KINDS[key].hint : 'None found'}
                                subHint={KINDS[key].blurb}
                                tone={Number(kindCounts[key]?.urls || 0) > 0 ? KINDS[key].tone : 'neutral'}
                                active={kind === key}
                                onClick={() => setKind((current) => (current === key ? '' : key))}
                            />
                        ))}
                    </div>

                    <Panel
                        title="What the repair changes"
                        subtitle="Only the old-URL records WordPress keeps for renamed profiles are touched. No profile, photo, text or current URL changes. Every removed record is backed up, and the whole run can be restored."
                        actions={(
                            <button
                                type="button"
                                onClick={() => setConfirmRepair('market')}
                                disabled={Boolean(activeRun) || startRepair.isPending}
                                title={activeRun ? 'A run is already in progress for this market' : undefined}
                                className="rounded-lg bg-teal-700 px-4 py-2 text-sm font-semibold text-white shadow-sm transition hover:bg-teal-800 disabled:cursor-not-allowed disabled:opacity-50 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-teal-600 focus-visible:ring-offset-2"
                            >
                                {activeRun ? 'Repair running…' : `Repair ${canSelect ? 'all ' : ''}${fmt(totalUrls)} URL${totalUrls === 1 ? '' : 's'}`}
                            </button>
                        )}
                    >
                        <div className="px-5 py-4">
                            <ImpactStrip changes={summary?.changes} aliases={summary?.aliases} />
                        </div>
                    </Panel>

                    <Panel
                        title="Affected URLs"
                        subtitle={canSelect
                            ? 'Where each old URL goes today, and where it goes after the repair. Tick URLs to repair only those. Worst cases first.'
                            : 'Where each old URL goes today, and where it goes after the repair. Worst cases first. Repairing chosen URLs needs exotic-crm-sync 1.3.14 on this market.'}
                        actions={(
                            <div className="inline-flex max-w-full overflow-x-auto rounded-lg border border-slate-200 bg-slate-50 p-0.5" role="group" aria-label="Filter by case">
                                {['', ...KIND_ORDER].map((key) => (
                                    <button
                                        key={key || 'all'}
                                        type="button"
                                        aria-pressed={kind === key}
                                        onClick={() => setKind(key)}
                                        className={`rounded-md px-2.5 py-1 text-xs font-semibold transition focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-teal-500 ${kind === key ? 'bg-white text-teal-700 shadow-sm' : 'text-slate-600 hover:text-slate-800'}`}
                                    >
                                        {key ? KINDS[key].short : 'All'}
                                        <span className="ml-1 font-normal text-slate-400">
                                            {fmt(key ? kindCounts[key]?.urls : totalUrls)}
                                        </span>
                                    </button>
                                ))}
                            </div>
                        )}
                    >
                        {items.length && canSelect ? (
                            <div className="flex items-center justify-between gap-3 border-b border-slate-100 px-5 py-2 text-xs text-slate-600">
                                <label className="inline-flex cursor-pointer items-center gap-3 font-semibold">
                                    <input
                                        type="checkbox"
                                        checked={pageAllSelected}
                                        ref={(element) => {
                                            if (element) element.indeterminate = pageSelectedCount > 0 && !pageAllSelected;
                                        }}
                                        onChange={togglePage}
                                        className="h-4 w-4 cursor-pointer rounded border-slate-300 text-teal-600 accent-teal-600 focus:ring-teal-500"
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
                        {items.length ? (
                            <ul className={`divide-y divide-slate-100 transition-opacity ${healthQuery.isFetching ? 'opacity-60' : ''}`}>
                                {items.map((item) => (
                                    <UrlRow
                                        key={itemKey(item)}
                                        item={item}
                                        selectable={canSelect}
                                        selected={selection.has(itemKey(item))}
                                        onToggle={toggleItem}
                                    />
                                ))}
                            </ul>
                        ) : (
                            <p className="px-5 py-8 text-center text-xs text-slate-500">No URLs in this case.</p>
                        )}
                        {total > PER_PAGE ? (
                            <footer className="flex items-center justify-between gap-3 border-t border-slate-100 px-5 py-3 text-xs text-slate-500">
                                <span className="tabular-nums">
                                    {fmt((page - 1) * PER_PAGE + 1)}–{fmt(Math.min(page * PER_PAGE, total))} of {fmt(total)}
                                </span>
                                <div className="flex gap-1">
                                    <button type="button" disabled={page <= 1} onClick={() => setPage((current) => current - 1)} className="rounded-md border border-slate-300 bg-white px-2.5 py-1 font-semibold text-slate-700 hover:bg-slate-50 disabled:opacity-40">
                                        Previous
                                    </button>
                                    <button type="button" disabled={page >= pageCount} onClick={() => setPage((current) => current + 1)} className="rounded-md border border-slate-300 bg-white px-2.5 py-1 font-semibold text-slate-700 hover:bg-slate-50 disabled:opacity-40">
                                        Next
                                    </button>
                                </div>
                            </footer>
                        ) : null}
                    </Panel>
                </>
            ) : !activeRun ? (
                <div className="flex flex-col items-center rounded-xl border border-dashed border-slate-200 bg-slate-50/50 px-6 py-10 text-center">
                    <p className="text-sm font-medium text-slate-700">Nothing to repair</p>
                    <p className="mt-1 max-w-md text-xs leading-relaxed text-slate-500">
                        New profiles created from the CRM now take over their name cleanly, and renames, trashing and deletes keep old URLs pointing at the right profile.
                    </p>
                </div>
            ) : null}

            {canSelect && selection.size > 0 ? (
                <div className="sticky bottom-4 z-20" role="region" aria-label="Selected URLs">
                    <div className="flex flex-wrap items-center justify-between gap-3 rounded-xl border border-teal-200 bg-white px-4 py-3 shadow-lg ring-1 ring-teal-100">
                        <div className="min-w-0 text-sm">
                            <p className="font-semibold text-slate-900">
                                {fmt(selection.size)} {plural(selection.size, 'URL', 'URLs')} selected
                            </p>
                            <p className="mt-0.5 text-xs text-slate-500">
                                {[
                                    selectedImpact.wrongStopped ? `${fmt(selectedImpact.wrongStopped)} stop misrouting` : null,
                                    selectedImpact.revived ? `${fmt(selectedImpact.revived)} start working` : null,
                                    selectedImpact.protectedCount ? `${fmt(selectedImpact.protectedCount)} protected for later` : null,
                                    `${fmt(selectedImpact.claims)} stale ${plural(selectedImpact.claims, 'claim', 'claims')} removed`,
                                ].filter(Boolean).join(' · ')}
                            </p>
                        </div>
                        <div className="flex items-center gap-2">
                            <button
                                type="button"
                                onClick={() => setSelection(new Map())}
                                className="rounded-lg px-3 py-2 text-xs font-semibold text-slate-600 transition hover:bg-slate-100 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-teal-500"
                            >
                                Clear
                            </button>
                            <button
                                type="button"
                                onClick={() => setConfirmRepair('selected')}
                                disabled={Boolean(activeRun) || startRepair.isPending}
                                title={activeRun ? 'A run is already in progress for this market' : undefined}
                                className="rounded-lg bg-teal-700 px-4 py-2 text-sm font-semibold text-white shadow-sm transition hover:bg-teal-800 disabled:cursor-not-allowed disabled:opacity-50 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-teal-600 focus-visible:ring-offset-2"
                            >
                                Repair selected
                            </button>
                        </div>
                    </div>
                </div>
            ) : null}

            <Panel title="Repair history" subtitle="Each run keeps a backup of what it removed. Restoring puts every record back exactly as it was.">
                <RunHistory
                    runs={runs}
                    onRestore={setRestoreTarget}
                    onDownload={downloadBackup}
                    downloadingId={downloadingId}
                    busy={Boolean(activeRun)}
                />
            </Panel>

            <ConfirmDialog
                open={confirmRepair === 'market'}
                title={`Repair ${canSelect ? 'all ' : ''}${fmt(totalUrls)} old URL${totalUrls === 1 ? '' : 's'} in ${selectedMarketName || 'this market'}?`}
                confirmLabel="Start repair"
                isPending={startRepair.isPending}
                onCancel={() => setConfirmRepair(null)}
                onConfirm={() => startRepair.mutate(null)}
            >
                <ul className="space-y-1.5 text-sm text-slate-700">
                    {Number(summary?.changes?.redirect_to_404 || 0) + Number(summary?.changes?.retargeted || 0) > 0 ? (
                        <li><span className="font-semibold tabular-nums text-rose-700">{fmt(Number(summary?.changes?.redirect_to_404 || 0) + Number(summary?.changes?.retargeted || 0))}</span> {plural(Number(summary?.changes?.redirect_to_404 || 0) + Number(summary?.changes?.retargeted || 0), 'old URL stops', 'old URLs stop')} sending visitors to the wrong profile.</li>
                    ) : null}
                    {Number(summary?.changes?.['404_to_redirect'] || 0) > 0 ? (
                        <li><span className="font-semibold tabular-nums text-teal-700">{fmt(summary?.changes?.['404_to_redirect'])}</span> {plural(summary?.changes?.['404_to_redirect'], 'dead link redirects to the profile that used it', 'dead links redirect to the profile that used them')} last.</li>
                    ) : null}
                    <li><span className="font-semibold tabular-nums">{fmt(summary?.aliases)}</span> stale old-URL {plural(summary?.aliases, 'record is', 'records are')} removed and backed up.</li>
                </ul>
                <p className="mt-3 text-xs leading-relaxed text-slate-500">
                    Runs in the background in small batches. No profile content changes, and you can restore the run from the history below.
                </p>
            </ConfirmDialog>

            <ConfirmDialog
                open={confirmRepair === 'selected'}
                title={`Repair ${fmt(selection.size)} selected ${plural(selection.size, 'URL', 'URLs')}?`}
                confirmLabel="Repair selected"
                isPending={startRepair.isPending}
                onCancel={() => setConfirmRepair(null)}
                onConfirm={() => startRepair.mutate(selectedTargets())}
            >
                <ul className="space-y-1.5 text-sm text-slate-700">
                    {selectedImpact.wrongStopped ? (
                        <li><span className="font-semibold tabular-nums text-rose-700">{fmt(selectedImpact.wrongStopped)}</span> {plural(selectedImpact.wrongStopped, 'URL stops', 'URLs stop')} sending visitors to the wrong profile.</li>
                    ) : null}
                    {selectedImpact.revived ? (
                        <li><span className="font-semibold tabular-nums text-teal-700">{fmt(selectedImpact.revived)}</span> {plural(selectedImpact.revived, 'dead link starts', 'dead links start')} redirecting to the profile that used it last.</li>
                    ) : null}
                    <li><span className="font-semibold tabular-nums">{fmt(selectedImpact.claims)}</span> stale old-URL {plural(selectedImpact.claims, 'record is', 'records are')} removed and backed up.</li>
                </ul>
                <ul className="mt-3 max-h-40 space-y-0.5 overflow-y-auto rounded-md border border-slate-100 bg-slate-50 px-3 py-2 font-mono text-[12px] text-slate-700">
                    {selectedItems.slice(0, 50).map((item) => <li key={itemKey(item)} className="truncate">{urlPath(item.url)}</li>)}
                    {selectedItems.length > 50 ? <li className="font-sans text-slate-400">+{fmt(selectedItems.length - 50)} more</li> : null}
                </ul>
                <p className="mt-3 text-xs leading-relaxed text-slate-500">
                    Only these URLs are touched. Their removed records are backed up, and the run can be restored from the history below.
                </p>
            </ConfirmDialog>

            <ConfirmDialog
                open={Boolean(restoreTarget)}
                title="Restore this repair run?"
                tone="warning"
                confirmLabel="Restore old URLs"
                isPending={restoreRun.isPending}
                onCancel={() => setRestoreTarget(null)}
                onConfirm={() => restoreTarget && restoreRun.mutate(restoreTarget.id)}
            >
                <p className="text-sm text-slate-700">
                    Puts back all <span className="font-semibold tabular-nums">{fmt(restoreTarget?.backup_count)}</span> old-URL records this run removed.
                    The URLs it fixed will behave as they did before, including the ones that sent visitors to the wrong profile.
                </p>
            </ConfirmDialog>
        </div>
    );
}
