import React, { useEffect, useMemo, useRef, useState } from 'react';
import { useQuery } from '@tanstack/react-query';
import api from '../../services/api';
import ConfirmDialog from '../ConfirmDialog';

function Section({ title, description, children }) {
    return (
        <section className="grid gap-4 border-b border-slate-100 px-5 py-5 last:border-b-0 md:grid-cols-[220px_1fr]">
            <div>
                <h3 className="text-sm font-semibold text-slate-900">{title}</h3>
                {description ? <p className="mt-1 text-xs leading-relaxed text-slate-500">{description}</p> : null}
            </div>
            <div className="space-y-4">{children}</div>
        </section>
    );
}

function Toggle({ checked, onChange, label, hint, disabled }) {
    return (
        <label className={`flex items-start gap-3 ${disabled ? 'opacity-60' : 'cursor-pointer'}`}>
            <button
                type="button"
                role="switch"
                aria-checked={checked}
                disabled={disabled}
                onClick={() => onChange(!checked)}
                className={`relative mt-0.5 inline-flex h-5 w-9 shrink-0 rounded-full transition ${checked ? 'bg-teal-600' : 'bg-slate-300'}`}
            >
                <span className={`absolute top-0.5 h-4 w-4 rounded-full bg-white shadow transition ${checked ? 'left-[18px]' : 'left-0.5'}`} />
            </button>
            <span>
                <span className="block text-sm font-medium text-slate-800">{label}</span>
                {hint ? <span className="block text-xs text-slate-500">{hint}</span> : null}
            </span>
        </label>
    );
}

function Segmented({ value, options, onChange, disabled, format = (v) => v }) {
    return (
        <div className="inline-flex flex-wrap rounded-lg bg-slate-100 p-0.5">
            {options.map((option) => (
                <button key={option} type="button" disabled={disabled} onClick={() => onChange(option)} aria-pressed={value === option}
                    className={`rounded-md px-3 py-1.5 text-sm font-medium transition ${value === option ? 'bg-white text-slate-900 shadow-sm' : 'text-slate-500 hover:text-slate-700'}`}>
                    {format(option)}
                </button>
            ))}
        </div>
    );
}

function NumberField({ label, value, onChange, min, max, suffix, disabled, hint }) {
    return (
        <label className="block text-xs font-semibold text-slate-700">
            {label}
            <span className="mt-1 flex items-center gap-2">
                <input type="number" className="crm-input w-28 text-sm" min={min} max={max} value={value} disabled={disabled}
                    onChange={(event) => onChange(event.target.value === '' ? '' : Number(event.target.value))} />
                {suffix ? <span className="text-sm font-normal text-slate-500">{suffix}</span> : null}
            </span>
            {hint ? <span className="mt-1 block text-[11px] font-normal text-slate-500">{hint}</span> : null}
        </label>
    );
}

export default function StorySettingsPanel({ platformId, canEdit, onSaved }) {
    // Unsaved edits layered over the saved values; null means no edits.
    const [draft, setDraft] = useState(null);
    const [avatarFile, setAvatarFile] = useState(null);
    const [avatarPreview, setAvatarPreview] = useState('');
    const [saving, setSaving] = useState(false);
    const [feedback, setFeedback] = useState(null);
    const [errors, setErrors] = useState({});
    const [confirmOff, setConfirmOff] = useState(false);
    const fileRef = useRef(null);

    const query = useQuery({
        queryKey: ['stories-settings', platformId],
        queryFn: () => api.get(`/crm/stories/${platformId}/settings`).then((r) => r.data),
        enabled: Boolean(platformId),
        refetchOnWindowFocus: false,
    });
    const saved = query.data?.settings;
    const options = query.data?.options || {};

    const form = draft || saved || null;

    useEffect(() => {
        setDraft(null);
        setAvatarFile(null);
    }, [saved]);

    useEffect(() => {
        if (!avatarFile) { setAvatarPreview(''); return undefined; }
        const url = URL.createObjectURL(avatarFile);
        setAvatarPreview(url);
        return () => URL.revokeObjectURL(url);
    }, [avatarFile]);

    const dirtyKeys = useMemo(() => {
        if (!form || !saved) return [];
        return Object.keys(form).filter((key) => key !== 'house_avatar_url' && form[key] !== saved[key]).concat(avatarFile ? ['house_avatar_file'] : []);
    }, [form, saved, avatarFile]);

    if (query.isLoading) {
        return <div className="h-96 animate-pulse rounded-xl bg-slate-100" />;
    }
    if (query.isError) {
        return <div className="rounded-xl border border-rose-200 bg-rose-50 p-6 text-center text-sm text-rose-700">{query.error?.response?.data?.message || 'Could not load settings.'}</div>;
    }
    if (query.data?.available === false) {
        return <div className="rounded-xl border border-slate-200 bg-white p-8 text-center text-sm text-slate-600">This market's theme predates stories, so there are no settings yet.</div>;
    }

    if (!form) {
        return <div className="h-96 animate-pulse rounded-xl bg-slate-100" />;
    }

    const locked = !canEdit || query.data?.can_save === false;
    const set = (key, value) => setDraft((current) => ({ ...(current || saved), [key]: value }));

    const save = async () => {
        setSaving(true);
        setFeedback(null);
        setErrors({});
        const body = new FormData();
        dirtyKeys.filter((key) => key !== 'house_avatar_file').forEach((key) => {
            const value = form[key];
            body.append(key, typeof value === 'boolean' ? (value ? '1' : '0') : String(value ?? ''));
        });
        if (avatarFile) body.append('house_avatar_file', avatarFile);
        try {
            await api.post(`/crm/stories/${platformId}/settings`, body);
            setFeedback({ ok: true, message: 'Settings saved. New stories use them straight away.' });
            await query.refetch();
            onSaved?.();
        } catch (error) {
            const data = error?.response?.data || {};
            setErrors(data.errors || {});
            setFeedback({ ok: false, message: data.message || 'WordPress could not save the settings.' });
        } finally {
            setSaving(false);
        }
    };

    const requestSave = () => {
        if (saved?.enabled && form.enabled === false) {
            setConfirmOff(true);
            return;
        }
        save();
    };

    const err = (key) => (errors[key] ? <span className="block text-xs text-rose-600">{errors[key][0]}</span> : null);

    return (
        <div className="space-y-4">
            {locked ? (
                <p className="rounded-lg bg-slate-100 px-3 py-2 text-sm text-slate-600">
                    {query.data?.can_save === false ? 'This market\'s theme needs the latest escortwp-child update before settings can be changed from the CRM.' : 'Read-only: only admins can change story settings.'}
                </p>
            ) : null}
            {feedback ? <p role="status" className={`rounded-lg px-3 py-2 text-sm ${feedback.ok ? 'bg-emerald-50 text-emerald-800' : 'bg-rose-50 text-rose-700'}`}>{feedback.message}</p> : null}

            <div className="crm-surface overflow-hidden">
                <Section title="Availability" description="Shows story rings in the Online strip, on the Africa page and on profiles. Only active, public, paid-up advertisers can post.">
                    <Toggle checked={Boolean(form.enabled)} onChange={(v) => set('enabled', v)} disabled={locked} label="Enable stories on this market" hint={form.enabled ? 'Visitors see stories now.' : 'Nothing is shown and advertisers cannot post.'} />
                    <NumberField label="Live stories per advertiser" value={form.max_active} min={options.max_active?.min ?? 1} max={options.max_active?.max ?? 50} onChange={(v) => set('max_active', v)} disabled={locked} />
                    {err('max_active')}
                </Section>

                <Section title="Durations" description="Longer videos are never cut: advertisers choose which part plays, or split the video into several stories. Videos up to 100 MB are accepted.">
                    <div>
                        <p className="mb-1 text-xs font-semibold text-slate-700">Length of one video story</p>
                        <Segmented value={form.clip_seconds} options={options.clip_seconds || [15, 30, 60]} onChange={(v) => set('clip_seconds', v)} disabled={locked} format={(v) => `${v}s`} />
                    </div>
                    <NumberField label="Most stories from one video" value={form.max_split} min={1} max={options.max_split?.max ?? 8} onChange={(v) => set('max_split', v)} disabled={locked} hint={Number(form.max_split) === 1 ? 'No splitting: one story per video.' : `Up to ${form.max_split} stories of ${form.clip_seconds}s each.`} />
                    <div>
                        <p className="mb-1 text-xs font-semibold text-slate-700">Photo stories stay on screen for</p>
                        <Segmented value={form.photo_seconds} options={options.photo_seconds || [3, 5, 7, 10, 15]} onChange={(v) => set('photo_seconds', v)} disabled={locked} format={(v) => `${v}s`} />
                    </div>
                    <div>
                        <p className="mb-1 text-xs font-semibold text-slate-700">Stories stay live for</p>
                        <Segmented value={form.lifetime_hours} options={options.lifetime_hours || [12, 24, 48]} onChange={(v) => set('lifetime_hours', v)} disabled={locked} format={(v) => `${v} hours`} />
                        <p className="mt-1 text-[11px] text-slate-500">Applies to new stories. Brand stories keep their own live period.</p>
                    </div>
                </Section>

                <Section title="Moderation">
                    {[['after', 'Publish first, review after (recommended)', 'Stories go live immediately and wait in the Review tab.'], ['before', 'Hold for approval', 'Stories stay hidden until approved in the Review tab.']].map(([value, label, hint]) => (
                        <label key={value} className={`flex items-start gap-3 rounded-lg border p-3 ${form.moderation === value ? 'border-teal-600 bg-teal-50/50' : 'border-slate-200'} ${locked ? 'opacity-60' : 'cursor-pointer'}`}>
                            <input type="radio" name="moderation" className="mt-1 text-teal-600" checked={form.moderation === value} onChange={() => set('moderation', value)} disabled={locked} />
                            <span><span className="block text-sm font-medium text-slate-800">{label}</span><span className="block text-xs text-slate-500">{hint}</span></span>
                        </label>
                    ))}
                </Section>

                <Section title="Hottest this week" description="Runs after the week closes (Monday 00:00, site time). Paid packages are never changed; the reward is a separate VVIP placement.">
                    <Toggle checked={Boolean(form.auto_award)} onChange={(v) => set('auto_award', v)} disabled={locked} label="Award the weekly winner automatically" />
                    <div className="flex flex-wrap gap-6">
                        <NumberField label="Minimum likes to win" value={form.min_likes} min={1} max={10000} onChange={(v) => set('min_likes', v)} disabled={locked} />
                        <NumberField label="VVIP placement" value={form.reward_days} min={1} max={30} suffix="days" onChange={(v) => set('reward_days', v)} disabled={locked} />
                    </div>
                </Section>

                <Section title="Brand ring" description="Brand stories (adverts, announcements, polls) play behind one ring at the front of the Online strip.">
                    <div className="flex items-center gap-4">
                        <span className="rounded-full bg-gradient-to-tr from-amber-400 via-rose-500 to-fuchsia-600 p-[3px]">
                            {avatarPreview || form.house_avatar_url
                                ? <img src={avatarPreview || form.house_avatar_url} alt="Ring image" className="h-16 w-16 rounded-full border-[3px] border-white object-cover" />
                                : <span className="block h-16 w-16 rounded-full border-[3px] border-white bg-slate-200" />}
                        </span>
                        <div className="space-y-1">
                            <p className="text-sm font-semibold text-slate-800">{form.house_label || 'Exotic'}</p>
                            {!locked ? (
                                <div className="flex gap-3 text-xs font-semibold">
                                    <button type="button" className="text-teal-700 hover:underline" onClick={() => fileRef.current?.click()}>Choose image</button>
                                    {(form.house_avatar || avatarFile) ? <button type="button" className="text-slate-500 hover:text-slate-700" onClick={() => { setAvatarFile(null); set('house_avatar', 0); set('house_avatar_url', ''); }}>Use site icon</button> : null}
                                </div>
                            ) : null}
                            <input ref={fileRef} type="file" accept="image/jpeg,image/png,image/webp" className="hidden" onChange={(e) => { setAvatarFile(e.target.files?.[0] || null); e.target.value = ''; }} />
                            {err('house_avatar_file')}
                        </div>
                    </div>
                    <label className="block text-xs font-semibold text-slate-700">Name under the ring
                        <input className="crm-input mt-1 w-full max-w-xs text-sm" maxLength={24} placeholder="Exotic" value={form.house_label} onChange={(e) => set('house_label', e.target.value)} disabled={locked} />
                        <span className="mt-1 block text-[11px] font-normal text-slate-500">Short: most site titles are too long for the ring. Empty uses “Exotic”.</span>
                    </label>
                </Section>
            </div>

            {!locked && dirtyKeys.length > 0 ? (
                <div className="sticky bottom-4 z-20 mx-auto flex w-fit items-center gap-3 rounded-xl border border-slate-200 bg-white/95 px-4 py-2.5 shadow-lg backdrop-blur">
                    <span className="text-sm text-slate-700">{dirtyKeys.length} unsaved {dirtyKeys.length === 1 ? 'change' : 'changes'}</span>
                    <button type="button" className="crm-btn-secondary" onClick={() => { setDraft(null); setAvatarFile(null); setErrors({}); }} disabled={saving}>Discard</button>
                    <button type="button" className="inline-flex items-center rounded-md bg-teal-700 px-4 py-2 text-sm font-semibold text-white hover:bg-teal-800 disabled:opacity-50" onClick={requestSave} disabled={saving}>{saving ? 'Saving…' : 'Save settings'}</button>
                </div>
            ) : null}

            <ConfirmDialog
                open={confirmOff}
                title="Switch stories off on this market?"
                message="Story rings disappear for visitors and advertisers can no longer post. Existing stories are kept and come back if you switch stories on again before they expire."
                confirmLabel="Switch off and save"
                tone="warning"
                onCancel={() => setConfirmOff(false)}
                onConfirm={() => { setConfirmOff(false); save(); }}
            />
        </div>
    );
}
