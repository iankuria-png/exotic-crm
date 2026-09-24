import React, { useEffect, useRef, useState } from 'react';
import { useQuery } from '@tanstack/react-query';
import api from '../../services/api';
import ConfirmDialog from '../ConfirmDialog';
import { chip, formatDateTime, formatLeft } from '../clients/ClientStoriesTab';

// Same gradients as the site's story viewer (escortwp-child css/stories.css).
export const BRAND_THEMES = {
    crimson: 'linear-gradient(160deg, #8b0a1a 0%, #2a0409 100%)',
    gold: 'linear-gradient(160deg, #e6c46a 0%, #7a5a14 100%)',
    midnight: 'linear-gradient(160deg, #1d2140 0%, #07080f 100%)',
    rose: 'linear-gradient(160deg, #dd2a7b 0%, #4a0b2c 100%)',
};

const KIND_LABEL = { announcement: 'Announcement', advert: 'Sponsored', poll: 'Poll' };
const EMPTY_FORM = { kind: 'announcement', headline: '', body: '', theme: 'crimson', cta_label: '', cta_url: '', days: 1, seconds: 8, options: ['', ''] };

function PhonePreview({ form, brand, mediaUrl, mediaIsVideo }) {
    const isPoll = form.kind === 'poll';
    return (
        <div className="relative mx-auto aspect-[9/16] w-56 overflow-hidden rounded-[28px] border-[6px] border-slate-900 shadow-xl" style={{ background: BRAND_THEMES[form.theme] || BRAND_THEMES.crimson }}>
            {mediaUrl ? (
                mediaIsVideo
                    ? <video src={mediaUrl} className="absolute inset-0 h-full w-full object-cover opacity-70" muted playsInline preload="metadata" />
                    : <img src={mediaUrl} alt="" className="absolute inset-0 h-full w-full object-cover opacity-70" />
            ) : null}
            <div className="absolute inset-0 bg-gradient-to-b from-black/30 via-transparent to-black/60" />
            <div className="absolute inset-x-3 top-3 h-0.5 rounded-full bg-white/30"><div className="h-full w-1/3 rounded-full bg-white" /></div>
            <div className="absolute left-3 top-6 flex items-center gap-2">
                <span className="rounded-full bg-gradient-to-tr from-amber-400 via-rose-500 to-fuchsia-600 p-[2px]">
                    {brand?.avatar ? <img src={brand.avatar} alt="" className="h-7 w-7 rounded-full border-2 border-black object-cover" /> : <span className="block h-7 w-7 rounded-full border-2 border-black bg-white" />}
                </span>
                <span className="text-xs font-semibold text-white drop-shadow">{brand?.label || 'Exotic'}</span>
                <span className="rounded bg-white/20 px-1.5 py-0.5 text-[9px] font-semibold uppercase tracking-wider text-white">{KIND_LABEL[form.kind]}</span>
            </div>
            <div className="absolute inset-x-4 bottom-6 space-y-2 text-white">
                <p className="text-lg font-bold leading-tight drop-shadow">{form.headline || (isPoll ? 'Your question' : 'Your headline')}</p>
                {form.body ? <p className="text-xs leading-snug text-white/85">{form.body}</p> : null}
                {isPoll ? (
                    <div className="space-y-1.5 pt-1">
                        {form.options.filter((o) => o.trim()).map((option, index) => (
                            <span key={index} className="block rounded-md border border-white/30 bg-white/15 px-3 py-2 text-xs font-semibold">{option}</span>
                        ))}
                    </div>
                ) : null}
                {!isPoll && form.cta_label ? <span className="mt-1 block rounded-full bg-white py-2 text-center text-xs font-bold text-slate-900">{form.cta_label}</span> : null}
            </div>
        </div>
    );
}

function BrandComposer({ open, platformId, brand, onClose, onCreated }) {
    const [form, setForm] = useState(EMPTY_FORM);
    const [file, setFile] = useState(null);
    const [preview, setPreview] = useState('');
    const [errors, setErrors] = useState({});
    const [message, setMessage] = useState('');
    const [submitting, setSubmitting] = useState(false);
    const fileRef = useRef(null);

    useEffect(() => {
        if (open) {
            setForm(EMPTY_FORM);
            setFile(null);
            setErrors({});
            setMessage('');
        }
    }, [open]);

    useEffect(() => {
        if (!file) { setPreview(''); return undefined; }
        const url = URL.createObjectURL(file);
        setPreview(url);
        return () => URL.revokeObjectURL(url);
    }, [file]);

    if (!open) return null;

    const set = (key, value) => setForm((current) => ({ ...current, [key]: value }));
    const isPoll = form.kind === 'poll';
    const isAdvert = form.kind === 'advert';

    const submit = async (event) => {
        event.preventDefault();
        setSubmitting(true);
        setErrors({});
        setMessage('');
        const body = new FormData();
        ['kind', 'headline', 'body', 'theme', 'days', 'seconds'].forEach((key) => body.append(key, String(form[key] ?? '')));
        if (!isPoll && (form.cta_label || form.cta_url)) {
            body.append('cta_label', form.cta_label);
            body.append('cta_url', form.cta_url);
        }
        if (isPoll) form.options.forEach((option, index) => body.append(`options[${index}]`, option));
        if (file) body.append('file', file);
        try {
            await api.post(`/crm/stories/${platformId}/brand`, body);
            onCreated();
        } catch (error) {
            const data = error?.response?.data || {};
            setErrors(data.errors || {});
            setMessage(data.message || 'WordPress could not post the brand story.');
        } finally {
            setSubmitting(false);
        }
    };

    const fieldError = (key) => (errors[key] ? <span className="mt-1 block text-xs text-rose-600">{errors[key][0]}</span> : null);

    return (
        <div className="fixed inset-0 z-50 flex items-end justify-center bg-slate-900/50 sm:items-center sm:p-4" role="presentation" onMouseDown={(event) => event.target === event.currentTarget && !submitting && onClose()}>
            <form onSubmit={submit} role="dialog" aria-modal="true" aria-labelledby="brand-composer-title" className="flex max-h-[94vh] w-full max-w-4xl flex-col overflow-hidden rounded-t-xl bg-white shadow-xl sm:rounded-xl">
                <header className="flex items-start justify-between border-b border-slate-200 px-5 py-4">
                    <div>
                        <h2 id="brand-composer-title" className="text-base font-semibold text-slate-900">New brand story</h2>
                        <p className="text-xs text-slate-500">Plays behind the {brand?.label || 'brand'} ring at the front of the Online strip.</p>
                    </div>
                    <button type="button" className="rounded-md p-1 text-slate-400 hover:bg-slate-100" onClick={onClose} aria-label="Close" disabled={submitting}>✕</button>
                </header>
                <div className="grid min-h-0 flex-1 overflow-y-auto md:grid-cols-[1fr_280px]">
                    <div className="space-y-4 p-5">
                        <div className="grid grid-cols-3 gap-2" role="radiogroup" aria-label="Kind">
                            {[['announcement', 'Announcement', 'News for visitors'], ['advert', 'Advert', 'Promote a link'], ['poll', 'Poll', 'Ask a question']].map(([key, label, hint]) => (
                                <button key={key} type="button" role="radio" aria-checked={form.kind === key} onClick={() => set('kind', key)}
                                    className={`rounded-lg border p-3 text-left transition ${form.kind === key ? 'border-teal-600 bg-teal-50 ring-1 ring-teal-600' : 'border-slate-200 hover:border-slate-300'}`}>
                                    <span className="block text-sm font-semibold text-slate-900">{label}</span>
                                    <span className="block text-xs text-slate-500">{hint}</span>
                                </button>
                            ))}
                        </div>
                        <label className="block text-xs font-semibold text-slate-700">{isPoll ? 'Question' : 'Headline'} <span className="text-rose-600">*</span>
                            <input className="crm-input mt-1 w-full text-sm" maxLength={90} value={form.headline} onChange={(e) => set('headline', e.target.value)} required />
                            {fieldError('headline')}
                        </label>
                        <label className="block text-xs font-semibold text-slate-700">Text <span className="font-normal text-slate-400">(optional)</span>
                            <textarea className="crm-input mt-1 min-h-[60px] w-full text-sm" maxLength={240} value={form.body} onChange={(e) => set('body', e.target.value)} />
                        </label>
                        {isPoll ? (
                            <fieldset>
                                <legend className="text-xs font-semibold text-slate-700">Options (2–4) <span className="text-rose-600">*</span></legend>
                                <div className="mt-1 space-y-1.5">
                                    {form.options.map((option, index) => (
                                        <div key={index} className="flex gap-2">
                                            <input className="crm-input w-full text-sm" maxLength={40} value={option} placeholder={`Option ${index + 1}`}
                                                onChange={(e) => set('options', form.options.map((o, i) => (i === index ? e.target.value : o)))} />
                                            {form.options.length > 2 ? <button type="button" className="px-2 text-slate-400 hover:text-rose-600" aria-label="Remove option" onClick={() => set('options', form.options.filter((_, i) => i !== index))}>✕</button> : null}
                                        </div>
                                    ))}
                                    {form.options.length < 4 ? <button type="button" className="text-sm font-semibold text-teal-700 hover:underline" onClick={() => set('options', [...form.options, ''])}>+ Add option</button> : null}
                                </div>
                                {fieldError('options')}
                            </fieldset>
                        ) : (
                            <div className="grid gap-3 sm:grid-cols-2">
                                <label className="block text-xs font-semibold text-slate-700">Button label {isAdvert ? <span className="text-rose-600">*</span> : <span className="font-normal text-slate-400">(optional)</span>}
                                    <input className="crm-input mt-1 w-full text-sm" maxLength={28} value={form.cta_label} onChange={(e) => set('cta_label', e.target.value)} required={isAdvert} />
                                    {fieldError('cta_label')}
                                </label>
                                <label className="block text-xs font-semibold text-slate-700">Link {isAdvert ? <span className="text-rose-600">*</span> : null}
                                    <input type="url" className="crm-input mt-1 w-full text-sm" placeholder="https://" value={form.cta_url} onChange={(e) => set('cta_url', e.target.value)} required={isAdvert} />
                                    {fieldError('cta_url')}
                                </label>
                            </div>
                        )}
                        <div>
                            <span className="text-xs font-semibold text-slate-700">Background</span>
                            <div className="mt-1 flex items-center gap-2">
                                {Object.entries(BRAND_THEMES).map(([key, gradient]) => (
                                    <button key={key} type="button" title={key} aria-label={`${key} theme`} aria-pressed={form.theme === key} onClick={() => set('theme', key)}
                                        className={`h-9 w-9 rounded-full ring-offset-2 transition ${form.theme === key ? 'ring-2 ring-teal-600' : 'hover:scale-105'}`} style={{ background: gradient }} />
                                ))}
                                <span className="mx-2 h-6 w-px bg-slate-200" />
                                <button type="button" className="crm-btn-secondary text-xs" onClick={() => fileRef.current?.click()}>{file ? 'Change media' : 'Add photo or video'}</button>
                                {file ? <button type="button" className="text-xs text-slate-500 hover:text-rose-600" onClick={() => setFile(null)}>Remove</button> : null}
                                <input ref={fileRef} type="file" className="hidden" accept="image/jpeg,image/png,image/webp,video/mp4,video/webm,video/quicktime" onChange={(e) => { setFile(e.target.files?.[0] || null); e.target.value = ''; }} />
                            </div>
                            {fieldError('file')}
                        </div>
                        <div className="grid gap-3 sm:grid-cols-2">
                            <label className="block text-xs font-semibold text-slate-700">Live for
                                <select className="crm-input mt-1 w-full text-sm" value={form.days} onChange={(e) => set('days', Number(e.target.value))}>
                                    {[1, 2, 3, 5, 7, 10, 14].map((d) => <option key={d} value={d}>{d} {d === 1 ? 'day' : 'days'}</option>)}
                                </select>
                            </label>
                            <label className="block text-xs font-semibold text-slate-700">On screen for
                                <select className="crm-input mt-1 w-full text-sm" value={form.seconds} onChange={(e) => set('seconds', Number(e.target.value))}>
                                    {[5, 8, 10, 12, 15, 20].map((s) => <option key={s} value={s}>{s} seconds{isPoll && s < 10 ? ' (polls use 10)' : ''}</option>)}
                                </select>
                            </label>
                        </div>
                        {message ? <p role="alert" className="rounded-md bg-rose-50 p-2 text-sm text-rose-700">{message}</p> : null}
                    </div>
                    <div className="border-t border-slate-100 bg-slate-50 p-5 md:border-l md:border-t-0">
                        <p className="mb-3 text-center text-[11px] font-semibold uppercase tracking-[0.14em] text-slate-400">Preview</p>
                        <PhonePreview form={form} brand={brand} mediaUrl={preview} mediaIsVideo={file ? file.type.startsWith('video/') : false} />
                    </div>
                </div>
                <footer className="flex justify-end gap-2 border-t border-slate-200 bg-slate-50 px-5 py-3">
                    <button type="button" className="crm-btn-secondary" onClick={onClose} disabled={submitting}>Cancel</button>
                    <button type="submit" className="inline-flex items-center rounded-md bg-teal-700 px-4 py-2 text-sm font-semibold text-white hover:bg-teal-800 disabled:opacity-50" disabled={submitting || !form.headline.trim()}>
                        {submitting ? 'Posting…' : 'Post brand story'}
                    </button>
                </footer>
            </form>
        </div>
    );
}

export default function BrandStoriesPanel({ platformId, canManage, composeRequest = 0, onComposeConsumed, onChanged }) {
    const [composerOpen, setComposerOpen] = useState(false);
    const [confirm, setConfirm] = useState(null);
    const [feedback, setFeedback] = useState(null);
    const [pending, setPending] = useState(false);

    const query = useQuery({
        queryKey: ['stories-brand', platformId],
        queryFn: () => api.get(`/crm/stories/${platformId}/brand`).then((r) => r.data),
        enabled: Boolean(platformId),
        refetchInterval: 60_000,
    });

    // One-shot request from the page header's "+ Brand story" button.
    useEffect(() => {
        if (composeRequest > 0 && canManage) {
            setComposerOpen(true);
            onComposeConsumed?.();
        }
    }, [composeRequest, canManage, onComposeConsumed]);

    const data = query.data || {};
    const stories = data.stories || [];

    const act = async () => {
        const target = confirm;
        setPending(true);
        try {
            await api.post(`/crm/stories/${platformId}/brand/${target.story.id}/${target.type === 'end' ? 'end' : 'delete'}`);
            setFeedback({ ok: true, message: target.type === 'end' ? 'Brand story ended.' : 'Brand story deleted.' });
            query.refetch();
            onChanged?.();
        } catch (error) {
            setFeedback({ ok: false, message: error?.response?.data?.message || 'WordPress could not update the brand story.' });
        } finally {
            setPending(false);
            setConfirm(null);
        }
    };

    return (
        <div className="space-y-4">
            <div className="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
                <p className="max-w-2xl text-sm text-slate-500">Brand stories lead the Online strip behind the {data.brand?.label || 'brand'} ring. Use them for adverts, announcements and polls. They are posted as the brand, not an advertiser, and keep their own live period.</p>
                {canManage ? <button type="button" className="inline-flex items-center gap-1.5 rounded-md bg-teal-700 px-3 py-2 text-sm font-semibold text-white hover:bg-teal-800" onClick={() => setComposerOpen(true)}>＋ New brand story</button> : null}
            </div>
            {feedback ? <p role="status" className={`rounded-lg px-3 py-2 text-sm ${feedback.ok ? 'bg-emerald-50 text-emerald-800' : 'bg-rose-50 text-rose-700'}`}>{feedback.message}</p> : null}

            {query.isLoading ? (
                <div className="grid gap-3 md:grid-cols-2 xl:grid-cols-3">{[0, 1, 2].map((k) => <div key={k} className="h-44 animate-pulse rounded-xl bg-slate-100" />)}</div>
            ) : query.isError ? (
                <div className="rounded-xl border border-rose-200 bg-rose-50 p-6 text-center text-sm text-rose-700">{query.error?.response?.data?.message || 'Could not load brand stories.'} <button type="button" className="ml-2 font-semibold underline" onClick={() => query.refetch()}>Retry</button></div>
            ) : stories.length === 0 ? (
                <div className="rounded-xl border border-dashed border-slate-300 bg-white p-10 text-center">
                    <p className="text-base font-semibold text-slate-800">No brand stories yet</p>
                    <p className="mt-1 text-sm text-slate-500">Announce an offer, promote verification or ask visitors a quick question.</p>
                    {canManage ? <button type="button" className="mt-3 font-semibold text-teal-700 hover:underline" onClick={() => setComposerOpen(true)}>Create the first one</button> : null}
                </div>
            ) : (
                <div className="grid gap-3 md:grid-cols-2 xl:grid-cols-3">
                    {stories.map((story) => (
                        <article key={story.id} className={`flex overflow-hidden rounded-xl border bg-white ${story.live ? 'border-slate-200' : 'border-dashed border-slate-300 opacity-75'}`}>
                            <div className="relative w-24 shrink-0" style={{ background: BRAND_THEMES[story.theme] || BRAND_THEMES.crimson }}>
                                {story.thumb_url ? <img src={story.thumb_url} alt="" className="absolute inset-0 h-full w-full object-cover opacity-70" loading="lazy" /> : null}
                                {story.media_type === 'video' ? <span className="absolute inset-0 flex items-center justify-center text-white">▶</span> : null}
                            </div>
                            <div className="min-w-0 flex-1 space-y-2 p-3">
                                <div className="flex flex-wrap items-center gap-1.5">
                                    <span className={chip('bg-slate-100 text-slate-700 ring-slate-300')}>{KIND_LABEL[story.kind] || story.kind}</span>
                                    <span className={chip(story.live ? 'bg-emerald-50 text-emerald-700 ring-emerald-200' : 'bg-slate-100 text-slate-500 ring-slate-300')}>{story.live ? formatLeft(story.seconds_left) : 'Ended'}</span>
                                </div>
                                <p className="line-clamp-2 text-sm font-semibold text-slate-900">{story.headline}</p>
                                {story.poll ? (
                                    <div className="space-y-1">
                                        {story.poll.options.map((option) => {
                                            const share = story.poll.total > 0 ? Math.round((option.votes / story.poll.total) * 100) : 0;
                                            return (
                                                <div key={option.id} className="relative overflow-hidden rounded bg-slate-100 px-2 py-1 text-xs">
                                                    <div className="absolute inset-y-0 left-0 bg-teal-100" style={{ width: `${share}%` }} />
                                                    <span className="relative flex justify-between"><span className="truncate">{option.label}</span><span className="font-semibold tabular-nums">{share}%</span></span>
                                                </div>
                                            );
                                        })}
                                    </div>
                                ) : null}
                                <p className="text-xs text-slate-500">
                                    {Number(story.view_count || 0).toLocaleString()} views
                                    {story.cta_label ? ` · ${Number(story.cta_clicks || 0).toLocaleString()} clicks on “${story.cta_label}”` : ''}
                                    {story.poll ? ` · ${story.poll.total} votes` : ''}
                                </p>
                                <p className="text-[11px] text-slate-400" title={`Expires ${formatDateTime(story.expires_at)}`}>Posted {formatDateTime(story.created_at)}</p>
                                {canManage ? (
                                    <div className="flex gap-3 pt-1 text-xs font-semibold">
                                        {story.live ? <button type="button" className="text-slate-600 hover:text-slate-900" onClick={() => setConfirm({ type: 'end', story })}>End now</button> : null}
                                        <button type="button" className="text-rose-700 hover:underline" onClick={() => setConfirm({ type: 'delete', story })}>Delete</button>
                                    </div>
                                ) : null}
                            </div>
                        </article>
                    ))}
                </div>
            )}

            <BrandComposer
                open={composerOpen}
                platformId={platformId}
                brand={data.brand}
                onClose={() => setComposerOpen(false)}
                onCreated={() => {
                    setComposerOpen(false);
                    setFeedback({ ok: true, message: 'Brand story posted and live.' });
                    query.refetch();
                    onChanged?.();
                }}
            />

            <ConfirmDialog
                open={Boolean(confirm)}
                title={confirm?.type === 'end' ? 'End this brand story now?' : 'Delete this brand story?'}
                message={confirm?.type === 'end' ? 'It leaves the brand ring immediately. Its results stay here until WordPress clears ended stories.' : 'It is removed with its results. Library media is kept.'}
                confirmLabel={confirm?.type === 'end' ? 'End now' : 'Delete'}
                tone={confirm?.type === 'end' ? 'warning' : 'danger'}
                isPending={pending}
                onCancel={() => setConfirm(null)}
                onConfirm={act}
            />
        </div>
    );
}
