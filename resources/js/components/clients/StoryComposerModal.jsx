import React, { useEffect, useMemo, useRef, useState } from 'react';
import { useQuery } from '@tanstack/react-query';
import api from '../../services/api';

// Support posts a story on the advertiser's behalf, either from the profile's
// own media (the file stays in the gallery when the story ends) or from a new
// upload (removed with the story, like the advertiser's own uploads).

const IMAGE_MAX = 15 * 1024 * 1024;
const VIDEO_MAX = 100 * 1024 * 1024;
const ACCEPT = 'image/jpeg,image/png,image/webp,video/mp4,video/webm,video/quicktime';

const INELIGIBLE_COPY = {
    needs_payment: 'The profile needs payment, so visitors cannot see stories yet.',
    expired: 'The profile has expired, so visitors cannot see stories.',
    private: 'The profile is private, so visitors cannot see stories.',
    not_active: 'The profile is not active, so visitors cannot see stories.',
    not_published: 'The profile is not published, so visitors cannot see stories.',
    not_advertiser: 'This WordPress account is not an advertiser account.',
};

function isVideoMime(mime) {
    return String(mime || '').startsWith('video/');
}

function formatSeconds(value) {
    const total = Math.max(0, Number(value) || 0);
    const minutes = Math.floor(total / 60);
    const seconds = Math.floor(total % 60);
    return `${minutes}:${String(seconds).padStart(2, '0')}`;
}

function formatBytes(bytes) {
    if (!bytes) return '';
    return bytes > 1024 * 1024 ? `${(bytes / 1024 / 1024).toFixed(1)} MB` : `${Math.round(bytes / 1024)} KB`;
}

function describeError(error) {
    const data = error?.response?.data || {};
    if (data.code === 'not_eligible') {
        return INELIGIBLE_COPY[data.data?.reason] || data.message || 'This profile cannot show stories right now.';
    }
    const fieldError = data.errors ? Object.values(data.errors).flat()[0] : null;
    return fieldError || data.message || 'WordPress could not post the story.';
}

export default function StoryComposerModal({ open, clientId, limits, liveCount = 0, onClose, onPosted }) {
    const clip = Number(limits?.clip_seconds || 15);
    const maxParts = Math.max(1, Number(limits?.max_parts || 1));
    const maxActive = Number(limits?.max_active || 0);
    const slotsLeft = maxActive > 0 ? Math.max(0, maxActive - liveCount) : Infinity;

    const [source, setSource] = useState('media');
    const [selected, setSelected] = useState(null);
    const [file, setFile] = useState(null);
    const [filePreview, setFilePreview] = useState('');
    const [caption, setCaption] = useState('');
    const [start, setStart] = useState(0);
    const [parts, setParts] = useState(1);
    const [duration, setDuration] = useState(0);
    const [progress, setProgress] = useState(null);
    const [submitting, setSubmitting] = useState(false);
    const [error, setError] = useState('');
    const videoRef = useRef(null);
    const fileInputRef = useRef(null);

    const { data: mediaData, isLoading: mediaLoading, error: mediaError } = useQuery({
        queryKey: ['client-media', String(clientId)],
        queryFn: () => api.get(`/crm/clients/${clientId}/media`).then((r) => r.data),
        enabled: open,
        retry: false,
        refetchOnWindowFocus: false,
    });
    const media = useMemo(
        () => (mediaData?.data || []).filter((item) => /^(image|video)\//.test(String(item.mime_type || ''))),
        [mediaData],
    );

    useEffect(() => {
        if (!open) return undefined;
        setSource('media');
        setSelected(null);
        setFile(null);
        setCaption('');
        setStart(0);
        setParts(1);
        setDuration(0);
        setError('');
        setProgress(null);
        const onKey = (event) => {
            if (event.key === 'Escape' && !submitting) onClose();
        };
        window.addEventListener('keydown', onKey);
        return () => window.removeEventListener('keydown', onKey);
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [open]);

    useEffect(() => {
        if (!file) {
            setFilePreview('');
            return undefined;
        }
        const url = URL.createObjectURL(file);
        setFilePreview(url);
        return () => URL.revokeObjectURL(url);
    }, [file]);

    if (!open) return null;

    const chosen = source === 'media'
        ? (selected ? { url: selected.url, isVideo: isVideoMime(selected.mime_type), label: selected.title || selected.filename } : null)
        : (file ? { url: filePreview, isVideo: isVideoMime(file.type), label: file.name } : null);
    const isVideo = Boolean(chosen?.isVideo);
    const remaining = duration > 0 ? Math.max(0, duration - start) : clip * maxParts;
    const possibleParts = Math.max(1, Math.min(maxParts, Math.ceil(remaining / clip) || 1));
    const effectiveParts = isVideo ? Math.min(parts, possibleParts) : 1;
    const overLimit = effectiveParts > slotsLeft;
    const canSubmit = Boolean(chosen) && !submitting && !overLimit && (!isVideo || duration === 0 || start < duration - 1);

    const chooseFile = (next) => {
        setError('');
        if (!next) return;
        const video = isVideoMime(next.type);
        if (!video && !String(next.type).startsWith('image/')) {
            setError('Choose a JPEG, PNG or WEBP photo, or an MP4, WEBM or MOV video.');
            return;
        }
        if (next.size > (video ? VIDEO_MAX : IMAGE_MAX)) {
            setError(video ? 'Videos must be 100 MB or smaller.' : 'Photos must be 15 MB or smaller.');
            return;
        }
        setFile(next);
        setStart(0);
        setParts(1);
        setDuration(0);
    };

    const submit = async () => {
        if (!canSubmit) return;
        setSubmitting(true);
        setError('');
        const form = new FormData();
        if (source === 'media') {
            form.append('attachment_id', String(selected.id));
        } else {
            form.append('file', file);
        }
        if (caption.trim()) form.append('caption', caption.trim());
        if (isVideo) {
            form.append('start', String(Math.round(start * 10) / 10));
            form.append('parts', String(effectiveParts));
        }
        try {
            const { data } = await api.post(`/crm/clients/${clientId}/stories`, form, {
                onUploadProgress: source === 'upload'
                    ? (event) => event.total && setProgress(Math.round((event.loaded / event.total) * 100))
                    : undefined,
            });
            onPosted(data);
        } catch (err) {
            setError(describeError(err));
        } finally {
            setSubmitting(false);
            setProgress(null);
        }
    };

    return (
        <div className="fixed inset-0 z-50 flex items-end justify-center bg-slate-900/50 p-0 sm:items-center sm:p-4" role="presentation" onMouseDown={(event) => event.target === event.currentTarget && !submitting && onClose()}>
            <div role="dialog" aria-modal="true" aria-labelledby="story-composer-title" className="flex max-h-[92vh] w-full max-w-3xl flex-col overflow-hidden rounded-t-xl bg-white shadow-xl sm:rounded-xl">
                <header className="flex items-start justify-between gap-4 border-b border-slate-200 px-5 py-4">
                    <div>
                        <h2 id="story-composer-title" className="text-base font-semibold text-slate-900">Add a story for this client</h2>
                        <p className="mt-0.5 text-xs text-slate-500">
                            Posted as the advertiser, live straight away for {limits?.lifetime_hours || 24} hours and marked approved.
                            {maxActive > 0 ? ` ${liveCount} of ${maxActive} live slots used.` : ''}
                        </p>
                    </div>
                    <button type="button" className="rounded-md p-1 text-slate-400 hover:bg-slate-100 hover:text-slate-600" onClick={onClose} disabled={submitting} aria-label="Close">
                        <svg className="h-5 w-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M6 18L18 6M6 6l12 12" /></svg>
                    </button>
                </header>

                <div className="grid min-h-0 flex-1 gap-0 overflow-y-auto md:grid-cols-[1.25fr_1fr]">
                    <div className="border-b border-slate-100 p-5 md:border-b-0 md:border-r">
                        <div className="mb-3 inline-flex rounded-lg bg-slate-100 p-0.5 text-sm" role="tablist">
                            {[['media', 'Profile media'], ['upload', 'Upload new']].map(([key, label]) => (
                                <button
                                    key={key}
                                    type="button"
                                    role="tab"
                                    aria-selected={source === key}
                                    className={`rounded-md px-3 py-1.5 font-medium transition ${source === key ? 'bg-white text-slate-900 shadow-sm' : 'text-slate-500 hover:text-slate-700'}`}
                                    onClick={() => { setSource(key); setError(''); setStart(0); setParts(1); setDuration(0); }}
                                    disabled={submitting}
                                >
                                    {label}
                                </button>
                            ))}
                        </div>

                        {source === 'media' ? (
                            mediaLoading ? (
                                <div className="grid grid-cols-3 gap-2 sm:grid-cols-4">
                                    {[0, 1, 2, 3, 4, 5, 6, 7].map((key) => <div key={key} className="aspect-square animate-pulse rounded-md bg-slate-100" />)}
                                </div>
                            ) : mediaError ? (
                                <p className="rounded-md bg-rose-50 p-3 text-sm text-rose-700">Could not load this profile's media. Try uploading a new file instead.</p>
                            ) : media.length === 0 ? (
                                <div className="rounded-md border border-dashed border-slate-300 p-6 text-center text-sm text-slate-500">
                                    This profile has no photos or videos yet.
                                    <button type="button" className="mt-2 block w-full font-semibold text-teal-700 hover:underline" onClick={() => setSource('upload')}>Upload a new file</button>
                                </div>
                            ) : (
                                <div className="grid max-h-[46vh] grid-cols-3 gap-2 overflow-y-auto pr-1 sm:grid-cols-4">
                                    {media.map((item) => {
                                        const active = selected?.id === item.id;
                                        const video = isVideoMime(item.mime_type);
                                        return (
                                            <button
                                                key={item.id}
                                                type="button"
                                                onClick={() => { setSelected(item); setStart(0); setParts(1); setDuration(0); setError(''); }}
                                                className={`relative aspect-square overflow-hidden rounded-md bg-slate-900 ring-offset-2 transition ${active ? 'ring-2 ring-teal-600' : 'hover:opacity-90'}`}
                                                aria-pressed={active}
                                                title={item.title || item.filename}
                                            >
                                                {video ? (
                                                    <span className="flex h-full w-full flex-col items-center justify-center gap-1 bg-gradient-to-b from-slate-700 to-slate-900 text-white">
                                                        <span className="text-lg">▶</span>
                                                        <span className="w-full truncate px-1 text-[10px] text-slate-300">{item.filename}</span>
                                                    </span>
                                                ) : (
                                                    <img src={item.url} alt={item.alt_text || ''} loading="lazy" decoding="async" className="h-full w-full object-cover" />
                                                )}
                                                {item.is_main ? <span className="absolute left-1 top-1 rounded bg-black/60 px-1 text-[10px] font-semibold text-white">Main</span> : null}
                                                {active ? <span className="absolute right-1 top-1 flex h-5 w-5 items-center justify-center rounded-full bg-teal-600 text-[11px] text-white">✓</span> : null}
                                            </button>
                                        );
                                    })}
                                </div>
                            )
                        ) : (
                            <div
                                className="flex min-h-[180px] flex-col items-center justify-center rounded-md border-2 border-dashed border-slate-300 bg-slate-50 p-6 text-center"
                                onDragOver={(event) => event.preventDefault()}
                                onDrop={(event) => { event.preventDefault(); chooseFile(event.dataTransfer.files?.[0]); }}
                            >
                                <p className="text-sm font-medium text-slate-700">{file ? file.name : 'Drop a photo or video here'}</p>
                                <p className="mt-1 text-xs text-slate-500">{file ? formatBytes(file.size) : 'Photos up to 15 MB · videos up to 100 MB'}</p>
                                <button type="button" className="crm-btn-secondary mt-3" onClick={() => fileInputRef.current?.click()} disabled={submitting}>
                                    {file ? 'Choose another file' : 'Choose file'}
                                </button>
                                <input ref={fileInputRef} type="file" accept={ACCEPT} className="hidden" onChange={(event) => { chooseFile(event.target.files?.[0]); event.target.value = ''; }} />
                                <p className="mt-3 text-[11px] text-slate-400">Photo location data is removed on upload. New files are deleted when the story ends.</p>
                            </div>
                        )}
                    </div>

                    <div className="space-y-4 p-5">
                        <div className="mx-auto aspect-[9/16] w-40 overflow-hidden rounded-lg bg-slate-900">
                            {chosen ? (
                                chosen.isVideo ? (
                                    <video
                                        ref={videoRef}
                                        key={chosen.url}
                                        src={chosen.url}
                                        className="h-full w-full object-cover"
                                        controls
                                        muted
                                        playsInline
                                        preload="metadata"
                                        onLoadedMetadata={(event) => setDuration(Number(event.currentTarget.duration) || 0)}
                                    />
                                ) : (
                                    <img src={chosen.url} alt="Story preview" className="h-full w-full object-cover" />
                                )
                            ) : (
                                <span className="flex h-full items-center justify-center px-3 text-center text-xs text-slate-400">Choose a photo or video</span>
                            )}
                        </div>

                        {isVideo ? (
                            <div className="space-y-2 rounded-md bg-slate-50 p-3">
                                <div className="flex items-end gap-2">
                                    <label className="flex-1 text-xs font-semibold text-slate-700">
                                        Start at (seconds)
                                        <input
                                            type="number"
                                            min={0}
                                            step={0.5}
                                            max={duration > 0 ? Math.max(0, duration - 1) : undefined}
                                            className="crm-input mt-1 w-full text-sm"
                                            value={start}
                                            onChange={(event) => setStart(Math.max(0, Number(event.target.value) || 0))}
                                        />
                                    </label>
                                    <button type="button" className="crm-btn-secondary whitespace-nowrap px-2 py-1.5 text-xs" onClick={() => setStart(Math.round((videoRef.current?.currentTime || 0) * 2) / 2)}>
                                        Use current point
                                    </button>
                                </div>
                                {maxParts > 1 ? (
                                    <label className="block text-xs font-semibold text-slate-700">
                                        Stories from this video
                                        <select className="crm-input mt-1 w-full text-sm" value={effectiveParts} onChange={(event) => setParts(Number(event.target.value))}>
                                            {Array.from({ length: possibleParts }, (_, index) => index + 1).map((count) => (
                                                <option key={count} value={count}>
                                                    {count === 1 ? `1 story (${clip}s)` : `${count} stories of ${clip}s, played in order`}
                                                </option>
                                            ))}
                                        </select>
                                    </label>
                                ) : null}
                                <p className="text-[11px] text-slate-500">
                                    Plays {formatSeconds(start)}–{formatSeconds(Math.min(duration || Infinity, start + clip * effectiveParts))}
                                    {duration > 0 ? ` of ${formatSeconds(duration)}` : ''}. The file is not cut; each story plays its section.
                                </p>
                            </div>
                        ) : null}

                        <label className="block text-xs font-semibold text-slate-700">
                            Caption <span className="font-normal text-slate-400">(optional)</span>
                            <textarea
                                className="crm-input mt-1 min-h-[64px] w-full text-sm"
                                maxLength={220}
                                value={caption}
                                onChange={(event) => setCaption(event.target.value)}
                                placeholder="Shown on the story"
                            />
                            <span className="mt-0.5 block text-right text-[11px] font-normal text-slate-400">{caption.length}/220</span>
                        </label>

                        {overLimit ? (
                            <p className="rounded-md bg-amber-50 p-2 text-xs text-amber-800">
                                {slotsLeft === 0
                                    ? `This profile already has ${liveCount} of ${maxActive} live stories. Delete or end one first.`
                                    : `Only ${slotsLeft} live story slot${slotsLeft === 1 ? '' : 's'} left. Choose fewer parts.`}
                            </p>
                        ) : null}
                        {error ? <p role="alert" className="rounded-md bg-rose-50 p-2 text-xs text-rose-700">{error}</p> : null}
                    </div>
                </div>

                <footer className="flex items-center justify-between gap-3 border-t border-slate-200 bg-slate-50 px-5 py-3">
                    <span className="truncate text-xs text-slate-500">{chosen ? chosen.label : 'Nothing selected'}</span>
                    <div className="flex shrink-0 gap-2">
                        <button type="button" className="crm-btn-secondary" onClick={onClose} disabled={submitting}>Cancel</button>
                        <button
                            type="button"
                            className="inline-flex items-center rounded-md bg-teal-700 px-4 py-2 text-sm font-semibold text-white transition hover:bg-teal-800 disabled:cursor-not-allowed disabled:opacity-50"
                            onClick={submit}
                            disabled={!canSubmit}
                        >
                            {submitting
                                ? (progress !== null && progress < 100 ? `Uploading ${progress}%…` : 'Posting…')
                                : effectiveParts > 1 ? `Post ${effectiveParts} stories` : 'Post story'}
                        </button>
                    </div>
                </footer>
            </div>
        </div>
    );
}
