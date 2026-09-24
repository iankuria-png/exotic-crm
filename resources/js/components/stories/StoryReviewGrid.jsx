import React, { useEffect, useMemo, useState } from 'react';
import { Link } from 'react-router-dom';
import { useQuery, useQueryClient } from '@tanstack/react-query';
import api from '../../services/api';
import ConfirmDialog from '../ConfirmDialog';
import { StoryMedia, VISIBILITY_CHIP, chip, formatAgo, formatDateTime, formatLeft } from '../clients/ClientStoriesTab';

const LIVE_FILTERS = [
    { key: 'all', label: 'All' },
    { key: 'unreviewed', label: 'Not reviewed' },
    { key: 'approved', label: 'Approved' },
    { key: 'hidden', label: 'Hidden' },
];

const DONE_COPY = {
    approve: (n) => `${n} ${n === 1 ? 'story' : 'stories'} approved.`,
    hide: (n) => `${n} hidden from visitors; their likes left this week's total.`,
    expire: (n) => `${n} ended. WordPress removes them within the hour.`,
    delete: (n) => `${n} deleted.`,
};

function ProfileLine({ story }) {
    const profile = story.profile || {};
    const name = profile.title || 'Unknown advertiser';
    return (
        <div className="flex min-w-0 items-center gap-2">
            <span className="shrink-0 rounded-full bg-gradient-to-tr from-amber-400 via-rose-500 to-fuchsia-600 p-[2px]">
                {profile.image ? (
                    <img src={profile.image} alt="" className="h-7 w-7 rounded-full border-2 border-white object-cover" loading="lazy" />
                ) : (
                    <span className="flex h-7 w-7 items-center justify-center rounded-full border-2 border-white bg-slate-200 text-[11px] font-semibold text-slate-600">{name.charAt(0)}</span>
                )}
            </span>
            <div className="min-w-0">
                {story.client_id ? (
                    <Link to={`/clients/${story.client_id}?tab=stories`} className="block truncate text-sm font-semibold text-slate-900 hover:text-teal-700" title="Open the client's Stories tab">{name}</Link>
                ) : (
                    <span className="block truncate text-sm font-semibold text-slate-900">{name}</span>
                )}
                <span className="flex items-center gap-1 text-[11px] text-slate-500">
                    {profile.visible === false ? <span className="text-amber-700">Profile hidden</span> : null}
                    {profile.url ? <a href={profile.url} target="_blank" rel="noopener noreferrer" className="hover:text-teal-700">View on site ↗</a> : null}
                </span>
            </div>
        </div>
    );
}

function ReviewCard({ story, selected, onToggle, canModerate, busyAction, result, onAction }) {
    const visibility = VISIBILITY_CHIP[story.visibility] || VISIBILITY_CHIP.hidden;
    const lifetime = Math.max(1, (Number(story.expires_at_local) - Number(story.created_at_local)) || 1);
    const remaining = Math.min(100, Math.max(0, (Number(story.seconds_left || 0) / lifetime) * 100));
    const busy = Boolean(busyAction);
    const approved = story.visibility === 'live' && story.review_state === 'approved';

    const onKeyDown = (event) => {
        if (!canModerate || busy || event.target !== event.currentTarget) return;
        const key = event.key.toLowerCase();
        if (key === 'a' && !approved) onAction('approve', [story]);
        if (key === 'h' && story.visibility !== 'hidden') onAction('hide', [story]);
        if (key === 'e') onAction('expire', [story]);
        if (key === 'x' || key === 'delete') onAction('delete', [story]);
        if (key === ' ') {
            event.preventDefault();
            onToggle(story.id);
        }
    };

    return (
        <article
            tabIndex={0}
            onKeyDown={onKeyDown}
            aria-label={`Story by ${story.profile?.title || 'advertiser'}`}
            className={`group overflow-hidden rounded-xl border bg-white outline-none transition focus-visible:ring-2 focus-visible:ring-teal-500 ${selected ? 'border-teal-500 ring-1 ring-teal-500' : 'border-slate-200 hover:border-slate-300'}`}
        >
            <div className="flex items-center justify-between gap-2 px-3 py-2.5">
                <ProfileLine story={story} />
                {canModerate ? (
                    <input
                        type="checkbox"
                        className="h-4 w-4 shrink-0 rounded border-slate-300 text-teal-600 focus:ring-teal-500"
                        checked={selected}
                        onChange={() => onToggle(story.id)}
                        aria-label="Select story"
                    />
                ) : null}
            </div>
            <div className={`relative aspect-[9/16] bg-slate-900 ${story.visibility === 'hidden' ? 'opacity-60' : ''}`}>
                <StoryMedia story={story} />
                <div className="pointer-events-none absolute inset-x-2 top-2 flex flex-wrap gap-1">
                    <span className={chip(visibility.className)}>{visibility.label}</span>
                    {story.visibility === 'live' && story.review_state === 'unreviewed' ? <span className={chip('bg-white/90 text-slate-600 ring-slate-200')}>Unreviewed</span> : null}
                    {story.posted_via === 'crm' ? <span className={chip('bg-sky-50 text-sky-700 ring-sky-200')} title={story.posted_by ? `Posted by ${story.posted_by}` : undefined}>By support</span> : null}
                    {story.group_id && story.group_size > 1 ? <span className={chip('bg-black/60 text-white ring-white/30')}>Part {story.group_part} of {story.group_size}</span> : null}
                </div>
                <div className="pointer-events-none absolute inset-x-0 bottom-0 h-1 bg-white/20">
                    <div className="h-full bg-teal-400" style={{ width: `${remaining}%` }} />
                </div>
            </div>
            <div className="space-y-2 p-3">
                <div className="flex items-center justify-between text-xs text-slate-500">
                    <span title={formatDateTime(story.created_at)}>{formatAgo(story.created_at)}</span>
                    <span className="font-medium text-slate-700" title={`Expires ${formatDateTime(story.expires_at)}`}>{formatLeft(story.seconds_left)}</span>
                </div>
                <div className="flex items-center gap-3 text-xs text-slate-600">
                    <span><strong className="text-slate-900">{Number(story.view_count || 0).toLocaleString()}</strong> views</span>
                    <span><strong className="text-slate-900">{Number(story.like_count || 0).toLocaleString()}</strong> likes</span>
                </div>
                {story.caption ? <p className="line-clamp-2 text-xs text-slate-600">{story.caption}</p> : null}
                {canModerate ? (
                    <div className="grid grid-cols-4 gap-1 pt-1">
                        <button type="button" title="Approve (A)" className="crm-btn-secondary justify-center px-1 py-1 text-xs" disabled={busy || approved} onClick={() => onAction('approve', [story])}>
                            {busyAction === 'approve' ? '…' : approved ? '✓' : 'Approve'}
                        </button>
                        <button type="button" title="Hide (H)" className="crm-btn-secondary justify-center px-1 py-1 text-xs" disabled={busy || story.visibility === 'hidden'} onClick={() => onAction('hide', [story])}>
                            {busyAction === 'hide' ? '…' : 'Hide'}
                        </button>
                        <button type="button" title="End now (E)" className="crm-btn-secondary justify-center px-1 py-1 text-xs" disabled={busy} onClick={() => onAction('expire', [story])}>
                            {busyAction === 'expire' ? '…' : 'End'}
                        </button>
                        <button type="button" title="Delete (X)" className="inline-flex items-center justify-center rounded-md border border-rose-200 bg-white px-1 py-1 text-xs font-semibold text-rose-700 hover:bg-rose-50 disabled:opacity-50" disabled={busy} onClick={() => onAction('delete', [story])}>
                            {busyAction === 'delete' ? '…' : 'Delete'}
                        </button>
                    </div>
                ) : null}
                {result ? <p role="status" className={`rounded-md px-2 py-1 text-xs ${result.ok ? 'bg-emerald-50 text-emerald-700' : 'bg-rose-50 text-rose-700'}`}>{result.message}</p> : null}
            </div>
        </article>
    );
}

export default function StoryReviewGrid({ platformId, mode, canModerate, onChanged }) {
    const queryClient = useQueryClient();
    const [filter, setFilter] = useState('all');
    const [search, setSearch] = useState('');
    const [debounced, setDebounced] = useState('');
    const [selected, setSelected] = useState(() => new Set());
    const [busy, setBusy] = useState({});
    const [results, setResults] = useState({});
    const [banner, setBanner] = useState(null);
    const [confirm, setConfirm] = useState(null);
    const [reason, setReason] = useState('');

    const state = mode === 'review' ? 'unreviewed' : filter;

    useEffect(() => {
        const timer = setTimeout(() => setDebounced(search.trim()), 300);
        return () => clearTimeout(timer);
    }, [search]);

    useEffect(() => {
        setSelected(new Set());
        setResults({});
    }, [platformId, state]);

    const listQuery = useQuery({
        queryKey: ['stories-list', platformId, state, debounced],
        queryFn: () => api.get(`/crm/stories/${platformId}/stories`, { params: { state, search: debounced || undefined } }).then((r) => r.data),
        enabled: Boolean(platformId),
        refetchInterval: 60_000,
        refetchOnWindowFocus: true,
    });
    const stories = useMemo(() => listQuery.data?.stories || [], [listQuery.data]);
    const selectedStories = stories.filter((story) => selected.has(story.id));

    const toggle = (id) => setSelected((current) => {
        const next = new Set(current);
        if (next.has(id)) next.delete(id); else next.add(id);
        return next;
    });

    const run = async (action, targets, withReason = null) => {
        const ids = targets.map((story) => story.id);
        setBusy((current) => ({ ...current, ...Object.fromEntries(ids.map((id) => [id, action])) }));
        setBanner(null);
        try {
            const { data } = await api.post(`/crm/stories/${platformId}/moderate`, { action, story_ids: ids, reason: withReason || undefined });
            const failed = (data.results || []).filter((row) => !row.ok);
            setBanner({ ok: failed.length === 0, message: failed.length ? `${DONE_COPY[action](data.done)} ${failed.length} could not be changed (already gone?).` : DONE_COPY[action](data.done) });
            setSelected(new Set());
            queryClient.invalidateQueries({ queryKey: ['stories-list', platformId] });
            onChanged?.();
        } catch (error) {
            const message = error?.response?.data?.message || 'WordPress could not update these stories.';
            if (ids.length === 1) {
                setResults((current) => ({ ...current, [ids[0]]: { ok: false, message } }));
            } else {
                setBanner({ ok: false, message });
            }
        } finally {
            setBusy((current) => {
                const next = { ...current };
                ids.forEach((id) => delete next[id]);
                return next;
            });
        }
    };

    const requestAction = (action, targets) => {
        if (action === 'delete') {
            setReason('');
            setConfirm(targets);
            return;
        }
        run(action, targets);
    };

    const allSelected = stories.length > 0 && selected.size === stories.length;

    return (
        <div className="space-y-4">
            <div className="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
                {mode === 'live' ? (
                    <div className="inline-flex flex-wrap gap-1 rounded-lg bg-slate-100 p-0.5" role="tablist" aria-label="Filter stories">
                        {LIVE_FILTERS.map((item) => (
                            <button key={item.key} type="button" role="tab" aria-selected={filter === item.key} onClick={() => setFilter(item.key)}
                                className={`rounded-md px-3 py-1.5 text-sm font-medium transition ${filter === item.key ? 'bg-white text-slate-900 shadow-sm' : 'text-slate-500 hover:text-slate-700'}`}>
                                {item.label}
                            </button>
                        ))}
                    </div>
                ) : (
                    <p className="max-w-2xl text-sm text-slate-500">These stories are already live. Approve the ones that meet the guidelines; hide or delete anything that doesn't. Hidden or deleted stories stop counting towards the weekly reward.</p>
                )}
                <div className="flex items-center gap-2">
                    <input
                        type="search"
                        className="crm-input w-full text-sm sm:w-56"
                        placeholder="Search advertiser"
                        value={search}
                        onChange={(event) => setSearch(event.target.value)}
                        aria-label="Search by advertiser name"
                    />
                    {listQuery.isFetching && !listQuery.isLoading ? <span className="h-4 w-4 animate-spin rounded-full border-2 border-teal-600 border-t-transparent" aria-label="Refreshing" /> : null}
                </div>
            </div>

            {banner ? <p role="status" className={`rounded-lg px-3 py-2 text-sm ${banner.ok ? 'bg-emerald-50 text-emerald-800' : 'bg-rose-50 text-rose-700'}`}>{banner.message}</p> : null}

            {listQuery.isLoading ? (
                <div className="grid grid-cols-2 gap-3 sm:grid-cols-3 lg:grid-cols-4 xl:grid-cols-5">
                    {[0, 1, 2, 3, 4].map((key) => <div key={key} className="aspect-[9/16] animate-pulse rounded-xl bg-slate-100" />)}
                </div>
            ) : listQuery.isError ? (
                <div className="rounded-xl border border-rose-200 bg-rose-50 p-6 text-center">
                    <p className="text-sm font-semibold text-rose-800">Could not load stories.</p>
                    <p className="mt-1 text-sm text-rose-700">{listQuery.error?.response?.data?.message || 'The market site did not respond.'}</p>
                    <button type="button" className="crm-btn-secondary mt-3" onClick={() => listQuery.refetch()}>Try again</button>
                </div>
            ) : stories.length === 0 ? (
                <div className="rounded-xl border border-dashed border-slate-300 bg-white p-10 text-center">
                    <p className="text-base font-semibold text-slate-800">{mode === 'review' ? (debounced ? 'No unreviewed stories match.' : 'All caught up') : 'No stories in this view.'}</p>
                    <p className="mt-1 text-sm text-slate-500">{mode === 'review' ? 'Every live story has been reviewed. New stories appear here as soon as they are posted.' : 'Try another filter.'}</p>
                </div>
            ) : (
                <>
                    {canModerate ? (
                        <div className="flex items-center justify-between text-xs text-slate-500">
                            <label className="inline-flex items-center gap-2">
                                <input type="checkbox" className="h-4 w-4 rounded border-slate-300 text-teal-600" checked={allSelected} onChange={() => setSelected(allSelected ? new Set() : new Set(stories.map((story) => story.id)))} />
                                Select all {stories.length}
                            </label>
                            <span className="hidden md:inline">Keyboard: focus a card, then <kbd className="rounded border border-slate-300 bg-white px-1">A</kbd> approve · <kbd className="rounded border border-slate-300 bg-white px-1">H</kbd> hide · <kbd className="rounded border border-slate-300 bg-white px-1">E</kbd> end · <kbd className="rounded border border-slate-300 bg-white px-1">X</kbd> delete · <kbd className="rounded border border-slate-300 bg-white px-1">Space</kbd> select</span>
                        </div>
                    ) : null}
                    <div className="grid grid-cols-2 gap-3 sm:grid-cols-3 lg:grid-cols-4 xl:grid-cols-5">
                        {stories.map((story) => (
                            <ReviewCard
                                key={story.id}
                                story={story}
                                selected={selected.has(story.id)}
                                onToggle={toggle}
                                canModerate={canModerate}
                                busyAction={busy[story.id]}
                                result={results[story.id]}
                                onAction={requestAction}
                            />
                        ))}
                    </div>
                </>
            )}

            {canModerate && selectedStories.length > 0 ? (
                <div className="sticky bottom-4 z-20 mx-auto flex w-fit flex-wrap items-center gap-2 rounded-xl border border-slate-200 bg-white/95 px-4 py-2.5 shadow-lg backdrop-blur">
                    <span className="mr-2 text-sm font-semibold text-slate-800">{selectedStories.length} selected</span>
                    <button type="button" className="crm-btn-secondary" onClick={() => requestAction('approve', selectedStories)}>Approve</button>
                    <button type="button" className="crm-btn-secondary" onClick={() => requestAction('hide', selectedStories)}>Hide</button>
                    <button type="button" className="crm-btn-secondary" onClick={() => requestAction('expire', selectedStories)}>End now</button>
                    <button type="button" className="inline-flex items-center rounded-md border border-rose-200 bg-white px-3 py-1.5 text-sm font-semibold text-rose-700 hover:bg-rose-50" onClick={() => requestAction('delete', selectedStories)}>Delete</button>
                    <button type="button" className="ml-1 text-sm text-slate-500 hover:text-slate-700" onClick={() => setSelected(new Set())}>Clear</button>
                </div>
            ) : null}

            <ConfirmDialog
                open={Boolean(confirm)}
                title={confirm && confirm.length > 1 ? `Delete ${confirm.length} stories?` : 'Delete this story?'}
                message="Removed from the site immediately and their likes leave this week's total. This cannot be undone."
                confirmLabel="Delete"
                tone="danger"
                onCancel={() => setConfirm(null)}
                onConfirm={() => {
                    const targets = confirm;
                    setConfirm(null);
                    run('delete', targets, reason.trim() || null);
                }}
            >
                <label htmlFor="stories-delete-reason" className="mt-3 block text-xs font-semibold text-slate-700">Reason (optional, recorded in each client's timeline)</label>
                <textarea id="stories-delete-reason" className="crm-input mt-1 min-h-[56px] w-full text-sm" maxLength={500} value={reason} onChange={(event) => setReason(event.target.value)} />
            </ConfirmDialog>
        </div>
    );
}
