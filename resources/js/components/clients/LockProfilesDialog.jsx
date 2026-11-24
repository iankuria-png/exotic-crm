import React, { useCallback, useEffect, useMemo, useRef, useState } from 'react';
import { useQuery, useQueryClient } from '@tanstack/react-query';
import api from '../../services/api';
import { lockReasonLabel } from '../../utils/profileLock';

export function LockIcon({ className = 'h-3.5 w-3.5', open = false }) {
    return (
        <svg className={className} fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
            {open ? (
                <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M8 11V7a4 4 0 017.874-1M6 11h12a2 2 0 012 2v6a2 2 0 01-2 2H6a2 2 0 01-2-2v-6a2 2 0 012-2zm6 4v2" />
            ) : (
                <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2zm10-10V7a4 4 0 00-8 0v4h8z" />
            )}
        </svg>
    );
}

const IMPACT_TILES = [
    { key: 'lock', label: 'Will be locked', hint: 'Public now — contacts get hidden', tone: 'border-amber-200 bg-amber-50 text-amber-900' },
    { key: 'republish', label: 'Goes public, locked', hint: 'Offline now — published as Unlockable', tone: 'border-violet-200 bg-violet-50 text-violet-900' },
    { key: 'already_locked', label: 'Already locked', hint: 'Skipped — nothing to change', tone: 'border-slate-200 bg-slate-50 text-slate-700' },
    { key: 'blocked', label: "Can't be locked", hint: 'Skipped — see why below', tone: 'border-rose-200 bg-rose-50 text-rose-800' },
];

const ACTION_BADGES = {
    lock: { label: 'Lock', tone: 'bg-amber-100 text-amber-800' },
    lock_private: { label: 'Lock, stay private', tone: 'bg-teal-100 text-teal-800' },
    republish: { label: 'Publish locked', tone: 'bg-violet-100 text-violet-800' },
    already_locked: { label: 'Already locked', tone: 'bg-slate-100 text-slate-600' },
    blocked: { label: "Can't lock", tone: 'bg-rose-100 text-rose-700' },
    locked: { label: 'Locked', tone: 'bg-emerald-100 text-emerald-800' },
    republished: { label: 'Republished locked', tone: 'bg-emerald-100 text-emerald-800' },
    skipped: { label: 'Skipped', tone: 'bg-slate-100 text-slate-600' },
    failed: { label: 'Failed', tone: 'bg-rose-100 text-rose-700' },
};

function chunk(list, size) {
    const out = [];
    for (let index = 0; index < list.length; index += size) {
        out.push(list.slice(index, index + size));
    }
    return out;
}

function errorMessage(error, fallback) {
    return error?.response?.data?.message || fallback;
}

function useLockReasons(enabled) {
    return useQuery({
        queryKey: ['client-lock-reasons'],
        queryFn: () => api.get('/crm/clients/lock-reasons').then((response) => response.data?.reasons || []),
        staleTime: 60 * 60 * 1000,
        enabled,
    });
}

function ActionBadge({ action }) {
    const badge = ACTION_BADGES[action] || ACTION_BADGES.skipped;
    return (
        <span className={`inline-flex shrink-0 rounded px-1.5 py-0.5 text-[10px] font-semibold ${badge.tone}`}>
            {badge.label}
        </span>
    );
}

/**
 * Lock one or many profiles: hold them Expired — published and indexed,
 * contacts hidden — with a reason. Previews what each profile will do, then
 * locks in small slices with live progress so large selections never time out.
 *
 * `clients` is [{ id, name }]. One client renders the single-profile flow.
 */
export default function LockProfilesDialog({ open, clients = [], onClose, onCompleted, defaultReasonCode = '' }) {
    const queryClient = useQueryClient();
    const isSingle = clients.length === 1;
    const clientIds = useMemo(
        () => clients.map((client) => Number(client.id)).filter((clientId) => clientId > 0),
        [clients],
    );

    const reasonsQuery = useLockReasons(open);
    const previewQuery = useQuery({
        queryKey: ['client-lock-preview', clientIds],
        queryFn: () => api.post('/crm/clients/bulk-lock/preview', { client_ids: clientIds }).then((response) => response.data),
        enabled: open && clientIds.length > 0,
        staleTime: 0,
        gcTime: 0,
    });

    const [reasonCode, setReasonCode] = useState(defaultReasonCode);
    const [note, setNote] = useState('');
    const [showDetails, setShowDetails] = useState(false);
    const [phase, setPhase] = useState('configure'); // configure | running | done
    const [progress, setProgress] = useState({ done: 0, total: 0 });
    const [results, setResults] = useState([]);
    const [runError, setRunError] = useState(null);
    const stopRequested = useRef(false);
    const firstReasonRef = useRef(null);

    useEffect(() => {
        if (!open) {
            setReasonCode(defaultReasonCode);
            setNote('');
            setShowDetails(false);
            setPhase('configure');
            setProgress({ done: 0, total: 0 });
            setResults([]);
            setRunError(null);
            stopRequested.current = false;
            return undefined;
        }

        const timer = setTimeout(() => firstReasonRef.current?.focus(), 40);
        const handleEscape = (event) => {
            if (event.key === 'Escape' && phase !== 'running') {
                event.preventDefault();
                onClose?.();
            }
        };
        window.addEventListener('keydown', handleEscape);
        return () => {
            clearTimeout(timer);
            window.removeEventListener('keydown', handleEscape);
        };
    }, [open, phase, onClose]);

    const reasons = reasonsQuery.data || [];
    const selectedReason = reasons.find((reason) => reason.code === reasonCode);
    const noteRequired = selectedReason?.requires_note === true;
    const preview = previewQuery.data;
    const summary = preview?.summary || {};
    const rows = preview?.rows || [];
    const sliceLimit = Number(preview?.slice_limit || 10);
    const actionableIds = rows
        .filter((row) => ['lock', 'lock_private', 'republish'].includes(row.action))
        .map((row) => row.id);
    const skippedRows = rows.filter((row) => row.action === 'blocked' || row.action === 'already_locked');
    const canSubmit = phase === 'configure'
        && actionableIds.length > 0
        && reasonCode
        && (!noteRequired || note.trim().length > 0);

    const runLock = useCallback(async (ids) => {
        stopRequested.current = false;
        setRunError(null);
        setPhase('running');
        setProgress({ done: 0, total: ids.length });

        const collected = [];
        for (const slice of chunk(ids, sliceLimit)) {
            if (stopRequested.current) {
                break;
            }
            try {
                const { data } = await api.post('/crm/clients/bulk-lock', {
                    client_ids: slice,
                    reason_code: reasonCode,
                    note: note.trim() || null,
                });
                collected.push(...(data?.results || []));
            } catch (error) {
                const message = errorMessage(error, 'The lock request failed. Retry the remaining profiles.');
                const names = new Map(rows.map((row) => [row.id, row.name]));
                collected.push(...slice.map((clientId) => ({
                    client_id: clientId,
                    name: names.get(clientId) || `#${clientId}`,
                    action: 'failed',
                    message,
                })));
                if (error?.response?.status === 422 || error?.response?.status === 403) {
                    setRunError(message);
                    break;
                }
            }
            setResults([...collected]);
            setProgress((current) => ({ ...current, done: Math.min(current.total, current.done + slice.length) }));
        }

        setResults([...collected]);
        setPhase('done');
        queryClient.invalidateQueries({ queryKey: ['clients'] });
        clientIds.forEach((clientId) => queryClient.invalidateQueries({ queryKey: ['client', String(clientId)] }));

        const tally = collected.reduce((acc, row) => {
            const bucket = row.action === 'locked' || row.action === 'republished' ? 'locked' : row.action;
            acc[bucket] = (acc[bucket] || 0) + 1;
            return acc;
        }, {});
        onCompleted?.({ locked: tally.locked || 0, skipped: tally.skipped || 0, failed: tally.failed || 0 });
    }, [clientIds, note, onCompleted, queryClient, reasonCode, rows, sliceLimit]);

    if (!open) {
        return null;
    }

    const lockedCount = results.filter((row) => row.action === 'locked' || row.action === 'republished').length;
    const failedRows = results.filter((row) => row.action === 'failed');
    const resultSkipped = results.filter((row) => row.action === 'skipped');
    const percent = progress.total > 0 ? Math.round((progress.done / progress.total) * 100) : 0;
    const singleRow = isSingle ? rows[0] : null;
    const title = isSingle
        ? `Lock ${clients[0]?.name || 'this profile'}`
        : `Lock ${clients.length.toLocaleString()} profiles`;

    return (
        <div
            className="fixed inset-0 z-[110] flex items-center justify-center bg-slate-900/45 p-4"
            onClick={phase === 'running' ? undefined : onClose}
        >
            <div
                role="dialog"
                aria-modal="true"
                aria-labelledby="lock-profiles-title"
                className="flex max-h-[92vh] w-full max-w-2xl flex-col overflow-hidden rounded-lg border border-slate-200 bg-white shadow-xl"
                onClick={(event) => event.stopPropagation()}
            >
                <header className="flex items-start gap-3 border-b border-slate-100 px-5 py-4">
                    <span className="mt-0.5 inline-flex h-9 w-9 shrink-0 items-center justify-center rounded-full bg-amber-100 text-amber-700">
                        <LockIcon className="h-[18px] w-[18px]" />
                    </span>
                    <div className="min-w-0">
                        <h3 id="lock-profiles-title" className="truncate text-base font-semibold text-slate-900">{title}</h3>
                        <p className="mt-0.5 text-sm text-slate-500">
                            Contacts and editing are restricted. Review the visibility shown below before locking.
                        </p>
                    </div>
                </header>

                <div className="min-h-0 flex-1 space-y-5 overflow-y-auto px-5 py-4">
                    {phase === 'configure' ? (
                        <>
                            {previewQuery.isLoading ? (
                                <div className="grid grid-cols-2 gap-2 sm:grid-cols-3" aria-busy="true">
                                    {IMPACT_TILES.map((tile) => (
                                        <div key={tile.key} className="h-[74px] animate-pulse rounded-lg bg-slate-100" />
                                    ))}
                                </div>
                            ) : previewQuery.isError ? (
                                <div className="flex items-center justify-between gap-3 rounded-lg border border-rose-200 bg-rose-50 px-3 py-2.5 text-sm text-rose-800">
                                    <span>{errorMessage(previewQuery.error, 'Could not check the selected profiles.')}</span>
                                    <button type="button" className="crm-btn-secondary px-3 py-1 text-xs" onClick={() => previewQuery.refetch()}>Retry</button>
                                </div>
                            ) : isSingle && singleRow ? (
                                <div className={`rounded-lg border px-3 py-2.5 text-sm ${
                                    singleRow.action === 'blocked' ? 'border-rose-200 bg-rose-50 text-rose-800'
                                        : singleRow.action === 'already_locked' ? 'border-slate-200 bg-slate-50 text-slate-700'
                                            : singleRow.action === 'republish' ? 'border-violet-200 bg-violet-50 text-violet-900'
                                                : 'border-amber-200 bg-amber-50 text-amber-900'
                                }`}>
                                    <div className="flex items-center gap-2">
                                        <ActionBadge action={singleRow.action} />
                                        <span className="font-medium">
                                            {singleRow.action === 'lock' && 'Public now. Locking hides contacts straight away.'}
                                            {singleRow.action === 'lock_private' && 'The profile stays private. No subscription is needed to lock it.'}
                                            {singleRow.action === 'republish' && 'Offline now. Locking publishes it as an Unlockable page with contacts hidden.'}
                                            {(singleRow.action === 'blocked' || singleRow.action === 'already_locked') && singleRow.reason}
                                        </span>
                                    </div>
                                    {singleRow.has_paid_plan && (singleRow.action === 'lock' || singleRow.action === 'republish') ? (
                                        <p className="mt-1.5 text-xs">
                                            Has a running paid plan. The plan keeps running while locked; a new payment or Unlock brings it back live.
                                        </p>
                                    ) : null}
                                </div>
                            ) : (
                                <>
                                    <div className="grid grid-cols-2 gap-2 sm:grid-cols-3">
                                        {IMPACT_TILES.map((tile) => (
                                            <div key={tile.key} className={`rounded-lg border px-3 py-2.5 ${tile.tone} ${Number(summary[tile.key] || 0) === 0 ? 'opacity-50' : ''}`}>
                                                <p className="text-[11px] font-semibold uppercase tracking-wide opacity-80">{tile.label}</p>
                                                <p className="mt-0.5 text-xl font-semibold tabular-nums">{Number(summary[tile.key] || 0).toLocaleString()}</p>
                                                <p className="mt-0.5 text-[11px] leading-tight opacity-75">{tile.hint}</p>
                                            </div>
                                        ))}
                                    </div>
                                    {Number(summary.paid || 0) > 0 ? (
                                        <p className="rounded-md bg-sky-50 px-3 py-2 text-xs text-sky-900">
                                            <strong>{Number(summary.paid).toLocaleString()}</strong> {Number(summary.paid) === 1 ? 'has' : 'have'} a running paid plan. Plans keep running while locked; a new payment or Unlock brings them back live.
                                        </p>
                                    ) : null}
                                    {skippedRows.length > 0 ? (
                                        <div>
                                            <button
                                                type="button"
                                                onClick={() => setShowDetails((current) => !current)}
                                                className="text-xs font-semibold text-teal-700 hover:text-teal-800"
                                                aria-expanded={showDetails}
                                            >
                                                {showDetails ? 'Hide' : 'Show'} the {skippedRows.length.toLocaleString()} skipped profile{skippedRows.length === 1 ? '' : 's'}
                                            </button>
                                            {showDetails ? (
                                                <ul className="mt-2 max-h-44 divide-y divide-slate-100 overflow-y-auto rounded-md border border-slate-200">
                                                    {skippedRows.map((row) => (
                                                        <li key={row.id} className="flex items-start justify-between gap-3 px-3 py-2 text-xs">
                                                            <span className="min-w-0">
                                                                <span className="font-medium text-slate-800">{row.name || `#${row.id}`}</span>
                                                                {row.city ? <span className="text-slate-400"> · {row.city}</span> : null}
                                                                <span className="block text-slate-500">{row.reason}</span>
                                                            </span>
                                                            <ActionBadge action={row.action} />
                                                        </li>
                                                    ))}
                                                </ul>
                                            ) : null}
                                        </div>
                                    ) : null}
                                </>
                            )}

                            {actionableIds.length > 0 || previewQuery.isLoading ? (
                                <>
                                    <fieldset>
                                        <legend className="text-xs font-semibold uppercase tracking-wide text-slate-600">Why are you locking {isSingle ? 'it' : 'them'}?</legend>
                                        {reasonsQuery.isError ? (
                                            <p className="mt-2 text-xs text-rose-600">Lock reasons failed to load. Close and try again.</p>
                                        ) : null}
                                        <div className="mt-2 grid grid-cols-1 gap-2 sm:grid-cols-2">
                                            {(reasonsQuery.isLoading ? Array.from({ length: 6 }) : reasons).map((reason, index) => (
                                                reason ? (
                                                    <label
                                                        key={reason.code}
                                                        className={`flex cursor-pointer items-start gap-2.5 rounded-lg border px-3 py-2.5 transition ${
                                                            reasonCode === reason.code
                                                                ? 'border-amber-400 bg-amber-50 ring-1 ring-amber-400'
                                                                : 'border-slate-200 hover:border-slate-300 hover:bg-slate-50'
                                                        }`}
                                                    >
                                                        <input
                                                            ref={index === 0 ? firstReasonRef : undefined}
                                                            type="radio"
                                                            name="lock-reason"
                                                            value={reason.code}
                                                            checked={reasonCode === reason.code}
                                                            onChange={() => setReasonCode(reason.code)}
                                                            className="mt-0.5 h-4 w-4 border-slate-300 text-amber-600 focus:ring-amber-500"
                                                        />
                                                        <span>
                                                            <span className="block text-sm font-medium text-slate-900">{reason.label}</span>
                                                            <span className="block text-xs text-slate-500">{reason.description}</span>
                                                        </span>
                                                    </label>
                                                ) : (
                                                    <div key={`reason-skeleton-${index}`} className="h-[58px] animate-pulse rounded-lg bg-slate-100" />
                                                )
                                            ))}
                                        </div>
                                    </fieldset>

                                    <div>
                                        <label htmlFor="lock-note" className="block text-xs font-semibold uppercase tracking-wide text-slate-600">
                                            Note {noteRequired ? <span className="text-rose-600">*</span> : <span className="font-normal normal-case text-slate-400">(optional, kept in the timeline)</span>}
                                        </label>
                                        <textarea
                                            id="lock-note"
                                            rows={2}
                                            maxLength={500}
                                            value={note}
                                            onChange={(event) => setNote(event.target.value)}
                                            placeholder={noteRequired ? 'Required — what happened?' : 'e.g. Waiting for new ID photos from the advertiser.'}
                                            className="mt-1 w-full rounded-md border border-slate-300 px-3 py-2 text-sm focus:border-amber-500 focus:outline-none focus:ring-1 focus:ring-amber-500"
                                        />
                                    </div>
                                </>
                            ) : null}
                        </>
                    ) : null}

                    {phase === 'running' ? (
                        <div className="space-y-3 py-2" aria-live="polite">
                            <div className="flex items-baseline justify-between text-sm">
                                <span className="font-medium text-slate-800">
                                    Locking {Math.min(progress.done + 1, progress.total).toLocaleString()} of {progress.total.toLocaleString()}…
                                </span>
                                <span className="tabular-nums text-slate-500">{percent}%</span>
                            </div>
                            <div className="h-2 overflow-hidden rounded-full bg-slate-100">
                                <div className="h-full rounded-full bg-amber-500 transition-all duration-500" style={{ width: `${Math.max(percent, 4)}%` }} />
                            </div>
                            <p className="text-xs text-slate-500">
                                {lockedCount.toLocaleString()} locked so far{failedRows.length ? ` · ${failedRows.length} failed` : ''}. Each profile is written to WordPress and re-checked, so this takes a few seconds per profile. Keep this window open.
                            </p>
                        </div>
                    ) : null}

                    {phase === 'done' ? (
                        <div className="space-y-3">
                            <div className={`flex items-center gap-3 rounded-lg border px-4 py-3 ${failedRows.length ? 'border-amber-200 bg-amber-50' : 'border-emerald-200 bg-emerald-50'}`}>
                                <span className={`inline-flex h-8 w-8 items-center justify-center rounded-full ${failedRows.length ? 'bg-amber-100 text-amber-700' : 'bg-emerald-100 text-emerald-700'}`}>
                                    <LockIcon className="h-4 w-4" />
                                </span>
                                <div className="text-sm">
                                    <p className="font-semibold text-slate-900">
                                        {lockedCount.toLocaleString()} profile{lockedCount === 1 ? '' : 's'} locked
                                    </p>
                                    <p className="text-slate-600">
                                        {[
                                            skippedRows.length ? `${skippedRows.length} not eligible or already locked` : null,
                                            resultSkipped.length ? `${resultSkipped.length} skipped` : null,
                                            failedRows.length ? `${failedRows.length} failed` : null,
                                            progress.done < progress.total ? `${progress.total - progress.done} not started` : null,
                                        ].filter(Boolean).join(' · ') || `Reason: ${lockReasonLabel(reasonCode)}`}
                                    </p>
                                </div>
                            </div>
                            {runError ? <p className="rounded-md bg-rose-50 px-3 py-2 text-xs text-rose-700">{runError}</p> : null}
                            {failedRows.length || resultSkipped.length ? (
                                <ul className="max-h-56 divide-y divide-slate-100 overflow-y-auto rounded-md border border-slate-200">
                                    {[...failedRows, ...resultSkipped].map((row) => (
                                        <li key={`${row.client_id}-${row.action}`} className="flex items-start justify-between gap-3 px-3 py-2 text-xs">
                                            <span className="min-w-0">
                                                <span className="font-medium text-slate-800">{row.name || `#${row.client_id}`}</span>
                                                <span className="block text-slate-500">{row.message}</span>
                                            </span>
                                            <ActionBadge action={row.action} />
                                        </li>
                                    ))}
                                </ul>
                            ) : null}
                        </div>
                    ) : null}
                </div>

                <footer className="flex items-center justify-between gap-2 border-t border-slate-100 bg-slate-50/60 px-5 py-3">
                    <span className="text-xs text-slate-500">
                        {phase === 'configure' && actionableIds.length > 0 ? 'Logged to the timeline and audit trail.' : ''}
                    </span>
                    <div className="flex items-center gap-2">
                        {phase === 'configure' ? (
                            <>
                                <button type="button" onClick={onClose} className="crm-btn-secondary">Cancel</button>
                                <button
                                    type="button"
                                    disabled={!canSubmit}
                                    onClick={() => runLock(actionableIds)}
                                    className="inline-flex items-center gap-1.5 rounded-md bg-slate-900 px-4 py-2 text-sm font-semibold text-white shadow-sm transition hover:bg-slate-800 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-slate-500 focus-visible:ring-offset-1 disabled:cursor-not-allowed disabled:opacity-50"
                                >
                                    <LockIcon />
                                    {isSingle ? 'Lock profile' : `Lock ${actionableIds.length.toLocaleString()} profile${actionableIds.length === 1 ? '' : 's'}`}
                                </button>
                            </>
                        ) : null}
                        {phase === 'running' ? (
                            <button
                                type="button"
                                onClick={() => { stopRequested.current = true; }}
                                className="crm-btn-secondary"
                            >
                                Stop after this batch
                            </button>
                        ) : null}
                        {phase === 'done' ? (
                            <>
                                {failedRows.length > 0 ? (
                                    <button
                                        type="button"
                                        onClick={() => runLock(failedRows.map((row) => row.client_id))}
                                        className="crm-btn-secondary"
                                    >
                                        Retry {failedRows.length} failed
                                    </button>
                                ) : null}
                                <button type="button" onClick={onClose} className="crm-btn-primary">Done</button>
                            </>
                        ) : null}
                    </div>
                </footer>
            </div>
        </div>
    );
}

/**
 * Lift a staff lock from one profile. With a running paid plan it goes live
 * again; without one only the hold is lifted and it stays Expired until payment.
 */
export function UnlockProfileDialog({ open, client, onClose, onUnlocked }) {
    const queryClient = useQueryClient();
    const [note, setNote] = useState('');
    const [pending, setPending] = useState(false);
    const [error, setError] = useState(null);

    useEffect(() => {
        if (!open) {
            setNote('');
            setPending(false);
            setError(null);
        }
    }, [open]);

    if (!open || !client) {
        return null;
    }

    // Mirrors the server's "running paid plan": active, dated, not an internal SEO boost.
    const activeDeal = client.active_deal && client.active_deal.origin !== 'seo_boost' ? client.active_deal : null;
    const expiresAt = activeDeal?.expires_at ? new Date(activeDeal.expires_at) : null;
    const goesLive = Boolean(activeDeal && expiresAt && expiresAt > new Date());

    const submit = async () => {
        setPending(true);
        setError(null);
        try {
            const { data } = await api.post(`/crm/clients/${client.id}/unlock`, { note: note.trim() || null });
            queryClient.invalidateQueries({ queryKey: ['client', String(client.id)] });
            queryClient.invalidateQueries({ queryKey: ['clients'] });
            onUnlocked?.(data);
        } catch (requestError) {
            setError(errorMessage(requestError, 'Unlock failed. Please retry.'));
            setPending(false);
        }
    };

    return (
        <div className="fixed inset-0 z-[110] flex items-center justify-center bg-slate-900/45 p-4" onClick={pending ? undefined : onClose}>
            <div
                role="dialog"
                aria-modal="true"
                aria-labelledby="unlock-profile-title"
                className="w-full max-w-md overflow-hidden rounded-lg border border-slate-200 bg-white shadow-xl"
                onClick={(event) => event.stopPropagation()}
            >
                <header className="flex items-start gap-3 border-b border-slate-100 px-5 py-4">
                    <span className="mt-0.5 inline-flex h-9 w-9 shrink-0 items-center justify-center rounded-full bg-teal-50 text-teal-700">
                        <LockIcon open className="h-[18px] w-[18px]" />
                    </span>
                    <div>
                        <h3 id="unlock-profile-title" className="text-base font-semibold text-slate-900">Unlock {client.name || 'profile'}</h3>
                        <p className="mt-0.5 text-sm text-slate-500">
                            Locked for <strong className="font-medium text-slate-700">{lockReasonLabel(client.profile_lock_reason_code)}</strong>
                            {client.profile_locked_at ? ` on ${new Date(client.profile_locked_at).toLocaleDateString(undefined, { day: 'numeric', month: 'short', year: 'numeric' })}` : ''}.
                        </p>
                    </div>
                </header>
                <div className="space-y-4 px-5 py-4">
                    {client.profile_lock_reason_note ? (
                        <blockquote className="border-l-2 border-amber-300 pl-3 text-sm italic text-slate-600">{client.profile_lock_reason_note}</blockquote>
                    ) : null}
                    <div className={`rounded-lg border px-3 py-2.5 text-sm ${goesLive ? 'border-emerald-200 bg-emerald-50 text-emerald-900' : 'border-slate-200 bg-slate-50 text-slate-700'}`}>
                        {goesLive
                            ? `Goes live again on its ${activeDeal.product?.name || activeDeal.plan_label || activeDeal.plan_type || 'paid'} plan until ${expiresAt.toLocaleDateString(undefined, { day: 'numeric', month: 'short', year: 'numeric' })}. Contacts come back straight away.`
                            : client.profile_status === 'private'
                                ? 'The profile stays private and restricted. Unlocking only lifts the staff hold.'
                                : 'No running paid plan, so it stays Expired (contacts hidden) until the client pays. Unlocking only lifts the staff hold.'}
                    </div>
                    <div>
                        <label htmlFor="unlock-note" className="block text-xs font-semibold uppercase tracking-wide text-slate-600">
                            Note <span className="font-normal normal-case text-slate-400">(optional)</span>
                        </label>
                        <textarea
                            id="unlock-note"
                            rows={2}
                            maxLength={500}
                            value={note}
                            onChange={(event) => setNote(event.target.value)}
                            placeholder="e.g. ID photos verified."
                            className="mt-1 w-full rounded-md border border-slate-300 px-3 py-2 text-sm focus:border-teal-500 focus:outline-none focus:ring-1 focus:ring-teal-500"
                        />
                    </div>
                    {error ? <p className="rounded-md bg-rose-50 px-3 py-2 text-xs text-rose-700">{error}</p> : null}
                </div>
                <footer className="flex items-center justify-end gap-2 border-t border-slate-100 bg-slate-50/60 px-5 py-3">
                    <button type="button" onClick={onClose} disabled={pending} className="crm-btn-secondary disabled:opacity-50">Cancel</button>
                    <button type="button" onClick={submit} disabled={pending} className="crm-btn-primary inline-flex items-center gap-1.5 disabled:opacity-60">
                        <LockIcon open />
                        {pending ? 'Unlocking…' : 'Unlock profile'}
                    </button>
                </footer>
            </div>
        </div>
    );
}
