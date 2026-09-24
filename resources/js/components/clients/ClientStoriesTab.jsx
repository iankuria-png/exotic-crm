import React, { useEffect, useRef, useState } from 'react';
import { useMutation, useQueryClient } from '@tanstack/react-query';
import api from '../../services/api';
import ConfirmDialog from '../ConfirmDialog';
import StoryComposerModal from './StoryComposerModal';

// Stories live on WordPress and expire hourly, so everything here is read live
// through /crm/clients/{id}/stories and refetched after every action.

const UNAVAILABLE_COPY = {
    disabled: 'Stories are off on this market. They can be switched on in that site\'s wp-admin → Stories.',
    theme_unsupported: 'This market\'s theme predates stories, so there is nothing to manage yet.',
    plugin_outdated: 'This market runs an older CRM sync plugin without story controls. Upload exotic-crm-sync 1.3.11 or later.',
};

const CAN_POST_COPY = {
    blocked: 'Paused by support',
    needs_payment: 'Needs payment',
    expired: 'Profile expired',
    private: 'Profile is private',
    not_active: 'Profile not active',
    not_published: 'Profile not published',
    not_advertiser: 'Not an advertiser account',
    unknown: 'Cannot post',
};

const VISIBILITY_CHIP = {
    live: { label: 'Live', className: 'bg-emerald-50 text-emerald-700 ring-emerald-200' },
    pending: { label: 'Awaiting approval', className: 'bg-amber-50 text-amber-800 ring-amber-200' },
    hidden: { label: 'Hidden', className: 'bg-slate-100 text-slate-600 ring-slate-300' },
};

function chip(className) {
    return `inline-flex items-center gap-1 rounded-full px-2 py-0.5 text-xs font-semibold ring-1 ring-inset ${className}`;
}

function formatAgo(iso) {
    if (!iso) return '—';
    const seconds = Math.max(0, (Date.now() - new Date(iso).getTime()) / 1000);
    if (seconds < 60) return 'just now';
    if (seconds < 3600) return `${Math.floor(seconds / 60)}m ago`;
    if (seconds < 86400) return `${Math.floor(seconds / 3600)}h ago`;
    return `${Math.floor(seconds / 86400)}d ago`;
}

function formatLeft(seconds) {
    const value = Number(seconds || 0);
    if (value <= 0) return 'ending';
    if (value < 3600) return `${Math.max(1, Math.floor(value / 60))}m left`;
    return `${Math.floor(value / 3600)}h ${Math.floor((value % 3600) / 60)}m left`;
}

function formatDateTime(iso) {
    return iso ? new Date(iso).toLocaleString([], { dateStyle: 'medium', timeStyle: 'short' }) : '—';
}

function errorMessage(error, fallback) {
    return error?.response?.data?.message || fallback;
}

function StoryMedia({ story }) {
    const [playing, setPlaying] = useState(false);
    const still = story.media_type === 'video' ? story.poster_url : (story.thumb_url || story.media_url);

    if (story.media_type === 'video' && playing) {
        const start = Number(story.trim_start || 0);
        return (
            // preload="none" + explicit click: videos can be 100 MB, never autoplay a list.
            <video
                className="h-full w-full bg-black object-contain"
                src={start > 0 ? `${story.media_url}#t=${start}` : story.media_url}
                controls
                autoPlay
                playsInline
                preload="none"
            />
        );
    }

    return (
        <button
            type="button"
            className="group relative block h-full w-full"
            onClick={() => story.media_type === 'video' && story.media_url && setPlaying(true)}
            disabled={story.media_type !== 'video'}
            aria-label={story.media_type === 'video' ? 'Play story video' : undefined}
        >
            {still ? (
                <img src={still} alt="Story" loading="lazy" decoding="async" className="h-full w-full object-cover" />
            ) : (
                <span className="flex h-full w-full items-center justify-center bg-gradient-to-b from-slate-700 to-slate-900 text-xs text-slate-300">
                    {story.media_type === 'video' ? 'Video story' : 'No preview'}
                </span>
            )}
            {story.media_type === 'video' ? (
                <span className="absolute inset-0 flex items-center justify-center">
                    <span className="flex h-11 w-11 items-center justify-center rounded-full bg-black/55 text-lg text-white ring-1 ring-white/40 transition group-hover:scale-105">▶</span>
                </span>
            ) : null}
        </button>
    );
}

function StoryCard({ story, canManage, pendingAction, result, onAction, onDelete }) {
    const visibility = VISIBILITY_CHIP[story.visibility] || VISIBILITY_CHIP.hidden;
    const lifetime = Math.max(1, (Number(story.expires_at_local) - Number(story.created_at_local)) || 1);
    const remaining = Math.min(100, Math.max(0, (Number(story.seconds_left || 0) / lifetime) * 100));
    const busy = Boolean(pendingAction);
    const approved = story.visibility === 'live' && story.review_state === 'approved';

    return (
        <article className={`overflow-hidden rounded-lg border bg-white ${story.visibility === 'hidden' ? 'border-dashed border-slate-300' : 'border-slate-200'}`}>
            <div className={`relative aspect-[9/16] bg-slate-900 ${story.visibility === 'hidden' ? 'opacity-60' : ''}`}>
                <StoryMedia story={story} />
                <div className="pointer-events-none absolute inset-x-2 top-2 flex flex-wrap gap-1">
                    <span className={chip(visibility.className)}>{visibility.label}</span>
                    {story.visibility === 'live' && story.review_state === 'unreviewed' ? (
                        <span className={chip('bg-white/90 text-slate-600 ring-slate-200')}>Unreviewed</span>
                    ) : null}
                    {story.posted_via === 'crm' ? (
                        <span className={chip('bg-sky-50 text-sky-700 ring-sky-200')} title={story.posted_by ? `Posted by ${story.posted_by}` : 'Posted from the CRM'}>By support</span>
                    ) : null}
                    {story.group_id && story.group_size > 1 ? (
                        <span className={chip('bg-black/60 text-white ring-white/30')}>Part {story.group_part} of {story.group_size}</span>
                    ) : null}
                </div>
                {/* Time left, draining like the story ring on the site. */}
                <div className="pointer-events-none absolute inset-x-0 bottom-0 h-1 bg-white/20" title={formatLeft(story.seconds_left)}>
                    <div className="h-full bg-teal-400" style={{ width: `${remaining}%` }} />
                </div>
            </div>

            <div className="space-y-2 p-3">
                <div className="flex items-center justify-between text-xs text-slate-500">
                    <span title={formatDateTime(story.created_at)}>Posted {formatAgo(story.created_at)}</span>
                    <span title={`Expires ${formatDateTime(story.expires_at)}`} className="font-medium text-slate-700">{formatLeft(story.seconds_left)}</span>
                </div>
                <div className="flex items-center gap-3 text-xs text-slate-600">
                    <span><strong className="text-slate-900">{Number(story.view_count || 0).toLocaleString()}</strong> views</span>
                    <span><strong className="text-slate-900">{Number(story.like_count || 0).toLocaleString()}</strong> likes</span>
                    {story.likes_revoked ? <span className="text-amber-700" title="Likes are removed from this week's total while hidden">likes revoked</span> : null}
                </div>
                {story.caption ? <p className="line-clamp-2 text-xs text-slate-600">{story.caption}</p> : null}

                {canManage ? (
                    <div className="grid grid-cols-2 gap-1.5 pt-1">
                        <button type="button" className="crm-btn-secondary justify-center px-2 py-1 text-xs" disabled={busy || approved} onClick={() => onAction(story, 'approve')}>
                            {pendingAction === 'approve' ? 'Approving…' : approved ? 'Approved' : 'Approve'}
                        </button>
                        <button type="button" className="crm-btn-secondary justify-center px-2 py-1 text-xs" disabled={busy || story.visibility === 'hidden'} onClick={() => onAction(story, 'hide')}>
                            {pendingAction === 'hide' ? 'Hiding…' : 'Hide'}
                        </button>
                        <button type="button" className="crm-btn-secondary justify-center px-2 py-1 text-xs" disabled={busy} onClick={() => onAction(story, 'expire')} title="End the story now without deleting it">
                            {pendingAction === 'expire' ? 'Ending…' : 'End now'}
                        </button>
                        <button type="button" className="inline-flex items-center justify-center rounded-md border border-rose-200 bg-white px-2 py-1 text-xs font-semibold text-rose-700 transition hover:bg-rose-50 disabled:cursor-not-allowed disabled:opacity-50" disabled={busy} onClick={() => onDelete(story)}>
                            {pendingAction === 'delete' ? 'Deleting…' : 'Delete'}
                        </button>
                    </div>
                ) : null}

                {result ? (
                    <p role="status" className={`rounded-md px-2 py-1 text-xs ${result.ok ? 'bg-emerald-50 text-emerald-700' : 'bg-rose-50 text-rose-700'}`}>{result.message}</p>
                ) : null}
            </div>
        </article>
    );
}

function PostingControl({ data, clientId, canManage, onDone }) {
    const [open, setOpen] = useState(false);
    const [reason, setReason] = useState('');
    const [feedback, setFeedback] = useState(null);
    const blocked = Boolean(data.posting_blocked);
    const block = data.posting_block || {};

    const mutation = useMutation({
        mutationFn: (payload) => api.post(`/crm/clients/${clientId}/stories/posting`, payload).then((r) => r.data),
        onSuccess: (result) => {
            setOpen(false);
            setReason('');
            setFeedback({
                ok: true,
                message: result.posting_blocked
                    ? (result.block_enforced ? 'Posting paused. The advertiser now sees "paused by support".' : 'Pause saved, but this market\'s theme does not enforce it yet.')
                    : 'Posting resumed.',
            });
            onDone();
        },
        onError: (error) => setFeedback({ ok: false, message: errorMessage(error, 'Could not update story posting.') }),
    });

    return (
        <div className="space-y-2">
            {blocked ? (
                <div className="rounded-md border border-rose-200 bg-rose-50/60 p-3 text-sm">
                    <p className="font-semibold text-rose-800">Posting paused by support</p>
                    <p className="mt-1 text-rose-700">{block.reason || 'No reason recorded.'}</p>
                    <p className="mt-1 text-xs text-rose-600">
                        {block.blocked_by ? `${block.blocked_by} · ` : ''}{formatDateTime(block.blocked_at)}
                    </p>
                    {data.block_enforced === false ? (
                        <p className="mt-2 text-xs font-semibold text-amber-800">This market's theme does not enforce the pause yet. Upload the escortwp-child update with the plugin.</p>
                    ) : null}
                </div>
            ) : null}

            {canManage && !open ? (
                <button
                    type="button"
                    className={blocked ? 'crm-btn-secondary' : 'inline-flex items-center rounded-md border border-rose-200 bg-white px-3 py-1.5 text-sm font-semibold text-rose-700 transition hover:bg-rose-50 disabled:opacity-50'}
                    disabled={mutation.isPending}
                    onClick={() => (blocked ? mutation.mutate({ blocked: false }) : setOpen(true))}
                >
                    {blocked ? (mutation.isPending ? 'Resuming…' : 'Resume posting') : 'Pause posting'}
                </button>
            ) : null}

            {canManage && open ? (
                <form
                    className="space-y-2 rounded-md border border-slate-200 bg-slate-50 p-3"
                    onSubmit={(event) => {
                        event.preventDefault();
                        if (reason.trim()) mutation.mutate({ blocked: true, reason: reason.trim() });
                    }}
                >
                    <label htmlFor="story-pause-reason" className="block text-xs font-semibold text-slate-700">Why pause this advertiser's stories? <span className="text-rose-600">*</span></label>
                    <textarea
                        id="story-pause-reason"
                        className="crm-input min-h-[64px] w-full text-sm"
                        maxLength={500}
                        value={reason}
                        onChange={(event) => setReason(event.target.value)}
                        placeholder="Shown to staff in the timeline; the advertiser sees 'paused by support'."
                        autoFocus
                    />
                    <div className="flex gap-2">
                        <button type="submit" className="inline-flex items-center rounded-md bg-rose-700 px-3 py-1.5 text-sm font-semibold text-white hover:bg-rose-800 disabled:opacity-50" disabled={!reason.trim() || mutation.isPending}>
                            {mutation.isPending ? 'Pausing…' : 'Pause posting'}
                        </button>
                        <button type="button" className="crm-btn-secondary" onClick={() => { setOpen(false); setReason(''); }} disabled={mutation.isPending}>Cancel</button>
                    </div>
                </form>
            ) : null}

            {feedback ? (
                <p role="status" className={`text-xs ${feedback.ok ? 'text-emerald-700' : 'text-rose-700'}`}>{feedback.message}</p>
            ) : null}
        </div>
    );
}

export default function ClientStoriesTab({ clientId, data, isLoading, error, isFetching, onRefresh, composeRequest = 0 }) {
    const queryClient = useQueryClient();
    const [pending, setPending] = useState({});
    const [results, setResults] = useState({});
    const [confirmDelete, setConfirmDelete] = useState(null);
    const [deleteReason, setDeleteReason] = useState('');
    const [composerOpen, setComposerOpen] = useState(false);
    const [postedNotice, setPostedNotice] = useState('');
    const handledRequest = useRef(0);

    // The profile header's "Add story" badge opens the composer once the
    // stories payload says posting is possible.
    useEffect(() => {
        if (composeRequest > handledRequest.current && data?.enabled && data?.can_manage && data?.can_create) {
            handledRequest.current = composeRequest;
            setComposerOpen(true);
        }
    }, [composeRequest, data]);

    const refresh = () => {
        onRefresh();
        queryClient.invalidateQueries({ queryKey: ['client-timeline', String(clientId)] });
    };

    const runAction = async (story, action, reason = null) => {
        setPending((current) => ({ ...current, [story.id]: action }));
        setResults((current) => ({ ...current, [story.id]: null }));
        try {
            const url = action === 'expire'
                ? `/crm/clients/${clientId}/stories/${story.id}/expire`
                : `/crm/clients/${clientId}/stories/${story.id}/moderate`;
            await api.post(url, action === 'expire' ? { reason } : { action, reason });
            const done = { approve: 'Approved.', hide: 'Hidden from visitors; this week\'s likes revoked.', expire: 'Ended. WordPress removes it within the hour.', delete: 'Deleted.' };
            setResults((current) => ({ ...current, [story.id]: { ok: true, message: done[action] } }));
            refresh();
        } catch (err) {
            setResults((current) => ({ ...current, [story.id]: { ok: false, message: errorMessage(err, 'WordPress could not update this story.') } }));
        } finally {
            setPending((current) => ({ ...current, [story.id]: null }));
        }
    };

    if (isLoading) {
        return (
            <section className="crm-surface p-4">
                <div className="mb-4 h-5 w-40 animate-pulse rounded bg-slate-200" />
                <div className="grid grid-cols-2 gap-3 sm:grid-cols-3 lg:grid-cols-5">
                    {[0, 1, 2, 3, 4].map((key) => <div key={key} className="aspect-[9/16] animate-pulse rounded-lg bg-slate-100" />)}
                </div>
            </section>
        );
    }

    if (error) {
        return (
            <section className="crm-surface p-8 text-center">
                <p className="text-sm font-semibold text-slate-800">Could not load stories from WordPress.</p>
                <p className="mt-1 text-sm text-slate-500">{errorMessage(error, 'The market site did not respond.')}</p>
                <button type="button" className="crm-btn-secondary mt-4" onClick={onRefresh}>Try again</button>
            </section>
        );
    }

    if (!data?.enabled) {
        return (
            <section className="crm-surface p-8 text-center">
                <p className="text-sm font-semibold text-slate-800">Stories are off on this market</p>
                <p className="mx-auto mt-1 max-w-lg text-sm text-slate-500">{UNAVAILABLE_COPY[data?.unavailable_reason] || 'Stories are not available for this profile.'}</p>
            </section>
        );
    }

    const stories = data.stories || [];
    const counts = data.counts || {};
    const canManage = Boolean(data.can_manage);
    const week = data.week;
    const hottest = data.hottest_until ? new Date(data.hottest_until) : null;
    const rewards = data.rewards || [];
    const liveCount = Number(counts.live || 0) + Number(counts.pending || 0);
    const createBlockedReason = !data.can_create
        ? 'This market\'s theme needs the latest escortwp-child update before the CRM can post stories.'
        : null;

    return (
        <div className="space-y-4">
            <section className="crm-surface overflow-hidden">
                <header className="crm-panel-header">
                    <div>
                        <h3 className="crm-panel-title">Stories</h3>
                        <p className="crm-panel-subtitle">Live from the market site. Stories expire on their own; nothing here is cached.</p>
                    </div>
                    <div className="flex items-center gap-2">
                        <button type="button" className="crm-btn-secondary" onClick={onRefresh} disabled={isFetching}>
                            {isFetching ? 'Refreshing…' : 'Refresh'}
                        </button>
                        {canManage ? (
                            <button
                                type="button"
                                className="inline-flex items-center gap-1.5 rounded-md bg-teal-700 px-3 py-2 text-sm font-semibold text-white transition hover:bg-teal-800 disabled:cursor-not-allowed disabled:opacity-50"
                                onClick={() => { setPostedNotice(''); setComposerOpen(true); }}
                                disabled={Boolean(createBlockedReason)}
                                title={createBlockedReason || 'Post a story on this client\'s behalf'}
                            >
                                <span aria-hidden="true">＋</span> Add story
                            </button>
                        ) : null}
                    </div>
                </header>
                {createBlockedReason && canManage ? (
                    <p className="border-b border-amber-100 bg-amber-50 px-4 py-2 text-xs text-amber-800">{createBlockedReason}</p>
                ) : null}
                {postedNotice ? (
                    <p role="status" className="border-b border-emerald-100 bg-emerald-50 px-4 py-2 text-sm text-emerald-800">{postedNotice}</p>
                ) : null}
                <div className="grid gap-4 p-4 md:grid-cols-[1fr_1fr_1.2fr]">
                    <div className="space-y-2">
                        <p className="text-[11px] font-semibold uppercase tracking-[0.12em] text-slate-500">Posting</p>
                        <div className="flex flex-wrap gap-1.5">
                            <span className={chip('bg-teal-50 text-teal-700 ring-teal-200')}>Stories on</span>
                            {data.can_post
                                ? <span className={chip('bg-emerald-50 text-emerald-700 ring-emerald-200')}>Can post</span>
                                : <span className={chip(data.can_post_reason === 'blocked' ? 'bg-rose-50 text-rose-700 ring-rose-200' : 'bg-amber-50 text-amber-800 ring-amber-200')}>Can't post · {CAN_POST_COPY[data.can_post_reason] || CAN_POST_COPY.unknown}</span>}
                        </div>
                        <p className="text-xs text-slate-500">{counts.live || 0} live · {counts.hidden || 0} hidden{counts.pending ? ` · ${counts.pending} awaiting approval` : ''}{counts.unreviewed ? ` · ${counts.unreviewed} unreviewed` : ''}</p>
                    </div>
                    <div className="space-y-2">
                        <p className="text-[11px] font-semibold uppercase tracking-[0.12em] text-slate-500">This week</p>
                        <p className="text-2xl font-semibold text-slate-900">
                            {Number(week?.likes || 0).toLocaleString()} <span className="text-sm font-medium text-slate-500">likes</span>
                        </p>
                        <div className="flex flex-wrap gap-1.5">
                            {week?.rank ? <span className={chip('bg-slate-50 text-slate-700 ring-slate-200')}>Rank #{week.rank}</span> : <span className="text-xs text-slate-500">Not ranked yet</span>}
                            {hottest ? <span className={chip('bg-rose-50 text-rose-700 ring-rose-200')} title={`Until ${formatDateTime(data.hottest_until)}`}>🔥 Hottest this week</span> : null}
                        </div>
                    </div>
                    <PostingControl data={data} clientId={clientId} canManage={canManage} onDone={refresh} />
                </div>
                {rewards.length > 0 ? (
                    <div className="border-t border-slate-100 px-4 py-3 text-xs text-slate-600">
                        <span className="font-semibold text-slate-700">Weekly rewards:</span>{' '}
                        {rewards.map((reward) => `${reward.week} (${reward.likes} likes, ${reward.status || reward.mode})`).join(' · ')}
                    </div>
                ) : null}
                {!canManage ? (
                    <p className="border-t border-slate-100 px-4 py-2 text-xs text-slate-500">Read-only: only admin, sub-admin and sales users can moderate stories.</p>
                ) : null}
            </section>

            {stories.length > 0 ? (
                <section className="grid grid-cols-2 gap-3 sm:grid-cols-3 lg:grid-cols-5">
                    {stories.map((story) => (
                        <StoryCard
                            key={story.id}
                            story={story}
                            canManage={canManage}
                            pendingAction={pending[story.id]}
                            result={results[story.id]}
                            onAction={runAction}
                            onDelete={(target) => { setDeleteReason(''); setConfirmDelete(target); }}
                        />
                    ))}
                </section>
            ) : (
                <section className="crm-surface p-8 text-center text-sm text-slate-500">
                    No live stories right now. Stories disappear automatically when they expire.
                    {canManage && !createBlockedReason ? (
                        <button type="button" className="mx-auto mt-3 block font-semibold text-teal-700 hover:underline" onClick={() => setComposerOpen(true)}>
                            Add the first story
                        </button>
                    ) : null}
                </section>
            )}

            <StoryComposerModal
                open={composerOpen}
                clientId={clientId}
                limits={data.limits}
                liveCount={liveCount}
                onClose={() => setComposerOpen(false)}
                onPosted={(result) => {
                    const count = (result?.story_ids || []).length || 1;
                    setComposerOpen(false);
                    setPostedNotice(count > 1 ? `${count} stories posted and live.` : 'Story posted and live.');
                    refresh();
                }}
            />

            <ConfirmDialog
                open={Boolean(confirmDelete)}
                title="Delete this story?"
                message="It is removed from the site immediately and its likes leave this week's total. This cannot be undone."
                confirmLabel="Delete story"
                tone="danger"
                onCancel={() => setConfirmDelete(null)}
                onConfirm={() => {
                    const target = confirmDelete;
                    setConfirmDelete(null);
                    runAction(target, 'delete', deleteReason.trim() || null);
                }}
            >
                <label htmlFor="story-delete-reason" className="mt-3 block text-xs font-semibold text-slate-700">Reason (optional, recorded in the timeline)</label>
                <textarea
                    id="story-delete-reason"
                    className="crm-input mt-1 min-h-[56px] w-full text-sm"
                    maxLength={500}
                    value={deleteReason}
                    onChange={(event) => setDeleteReason(event.target.value)}
                />
            </ConfirmDialog>
        </div>
    );
}
