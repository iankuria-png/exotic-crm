import React, { useEffect, useMemo, useRef, useState } from 'react';
import { useMutation } from '@tanstack/react-query';
import api from '../../services/api';
import { flaggedPlatformLabel } from '../../utils/flags';
import ProbeSummary from './ProbeSummary';
import { HealthChip, Spinner, errorMessage, useScrollLock } from './scraperUi';

/**
 * Paste a listing address → the CRM checks it (tech, blockers, template, sample) →
 * confirm name/market/compliance → save. Library suggestions prefill the address.
 */
export default function AddSiteDrawer({ open, onClose, platforms, library, defaultPlatformId, onCreated, toast }) {
    const [url, setUrl] = useState('');
    const [platformId, setPlatformId] = useState('');
    const [name, setName] = useState('');
    const [ackRobots, setAckRobots] = useState(false);
    const [ackTos, setAckTos] = useState(false);
    const [profile, setProfile] = useState(null);
    const inputRef = useRef(null);
    useScrollLock(open);

    useEffect(() => {
        if (!open) return;
        setPlatformId(String(defaultPlatformId || platforms?.[0]?.id || ''));
        setTimeout(() => inputRef.current?.focus(), 50);
    }, [open]); // eslint-disable-line react-hooks/exhaustive-deps

    useEffect(() => {
        if (!open) {
            setUrl(''); setName(''); setProfile(null); setAckRobots(false); setAckTos(false);
        }
    }, [open]);

    useEffect(() => {
        const onKey = (e) => { if (e.key === 'Escape' && open) onClose(); };
        window.addEventListener('keydown', onKey);
        return () => window.removeEventListener('keydown', onKey);
    }, [open, onClose]);

    const probe = useMutation({
        mutationFn: () => api.post('/crm/scraper/probe', { url: url.trim(), platform_id: Number(platformId) }).then((r) => r.data.profile),
        onSuccess: (p) => {
            setProfile(p);
            if (!name) {
                try { setName(p.title?.split(/[|–-]/)[0]?.trim().slice(0, 80) || new URL(url).hostname.replace(/^www\./, '')); } catch { /* ignore */ }
            }
        },
        onError: (e) => toast.error(errorMessage(e, 'Could not check that address.')),
    });

    const save = useMutation({
        mutationFn: () => api.post('/crm/scraper/sites', {
            platform_id: Number(platformId),
            name: name.trim() || undefined,
            source_url: url.trim(),
            template_key: profile?.template?.key || null,
            parser_rules: !profile?.template && profile?.detected_rules?.rules ? profile.detected_rules.rules : undefined,
            compliance_ack_robots: ackRobots,
            compliance_ack_tos: ackTos,
            site_profile: profile || undefined,
        }).then((r) => r.data.site),
        onSuccess: (site) => {
            toast.success(`${site.name} added.`);
            onCreated(site);
        },
        onError: (e) => toast.error(errorMessage(e, 'Could not save the site.')),
    });

    const suggestions = useMemo(() => (library || []).filter((p) => !p.added), [library]);
    const validUrl = /^https?:\/\/[^\s]+\.[^\s]+/i.test(url.trim());

    if (!open) return null;

    return (
        <div className="fixed inset-0 z-50 flex justify-end bg-slate-900/40" onMouseDown={onClose}>
            <aside
                role="dialog"
                aria-modal="true"
                aria-labelledby="add-site-title"
                className="flex h-full w-full max-w-2xl flex-col bg-white shadow-2xl"
                onMouseDown={(e) => e.stopPropagation()}
            >
                <header className="flex items-start justify-between gap-3 border-b border-slate-200 px-5 py-4">
                    <div>
                        <h3 id="add-site-title" className="crm-panel-title">Add a site</h3>
                        <p className="crm-panel-subtitle">Paste a listing page. The CRM checks it before you save anything.</p>
                    </div>
                    <button type="button" className="crm-btn-secondary px-3 py-1.5" onClick={onClose}>Close</button>
                </header>

                <div className="flex-1 space-y-5 overflow-y-auto overscroll-contain px-5 py-4">
                    <form
                        className="space-y-3"
                        onSubmit={(e) => { e.preventDefault(); if (validUrl && platformId) probe.mutate(); }}
                    >
                        <label className="block">
                            <span className="text-sm font-medium text-slate-700">Listing page address</span>
                            <div className="mt-1 flex gap-2">
                                <input
                                    ref={inputRef}
                                    type="url"
                                    inputMode="url"
                                    className="crm-input"
                                    placeholder="https://example.com/escorts/kenya/nairobi/"
                                    value={url}
                                    onChange={(e) => { setUrl(e.target.value); setProfile(null); }}
                                />
                                <button type="submit" className="crm-btn-primary shrink-0 disabled:cursor-not-allowed disabled:opacity-50" disabled={!validUrl || !platformId || probe.isPending}>
                                    {probe.isPending ? <><Spinner className="mr-2 h-4 w-4" />Checking…</> : 'Check site'}
                                </button>
                            </div>
                        </label>
                        <label className="block sm:w-72">
                            <span className="text-sm font-medium text-slate-700">Market (phone prefix and lead owner)</span>
                            <select className="crm-select-enhanced mt-1" value={platformId} onChange={(e) => setPlatformId(e.target.value)}>
                                {(platforms || []).map((p) => <option key={p.id} value={p.id}>{flaggedPlatformLabel({ name: p.name, country: p.country })} (+{p.phone_prefix})</option>)}
                            </select>
                        </label>
                    </form>

                    {!profile && !probe.isPending && suggestions.length > 0 ? (
                        <section>
                            <h4 className="text-xs font-semibold uppercase tracking-[0.08em] text-slate-500">Known directories</h4>
                            <ul className="mt-2 divide-y divide-slate-100 rounded-lg border border-slate-200">
                                {suggestions.map((s) => (
                                    <li key={s.key}>
                                        <button
                                            type="button"
                                            className="flex w-full items-center justify-between gap-3 px-3 py-2.5 text-left hover:bg-slate-50 focus-visible:bg-slate-50 focus-visible:outline-none"
                                            onClick={() => { setUrl(s.source_url); setName(s.name); setProfile(null); }}
                                        >
                                            <span className="min-w-0">
                                                <span className="block truncate text-sm font-medium text-slate-900">{s.name}</span>
                                                <span className="crm-mono block truncate text-xs text-slate-500">{s.host}</span>
                                            </span>
                                            <span className="shrink-0 text-xs text-slate-500">{s.traffic_band === 'high' ? 'High traffic' : 'Medium traffic'}</span>
                                        </button>
                                    </li>
                                ))}
                            </ul>
                            <p className="mt-2 text-xs text-slate-500">Choosing one fills the address; press “Check site” to see if it still works.</p>
                        </section>
                    ) : null}

                    {probe.isPending ? (
                        <div className="space-y-3" aria-live="polite">
                            <p className="flex items-center gap-2 text-sm text-slate-600"><Spinner />Reading the page, robots.txt and technology…</p>
                            <div className="h-24 animate-pulse rounded-xl bg-slate-100 motion-reduce:animate-none" />
                            <div className="h-16 animate-pulse rounded-xl bg-slate-100 motion-reduce:animate-none" />
                        </div>
                    ) : null}

                    {profile ? (
                        <>
                            <ProbeSummary profile={profile} compact />
                            <section className="space-y-3 rounded-xl border border-slate-200 p-4">
                                <div className="flex items-center justify-between">
                                    <h4 className="text-sm font-semibold text-slate-900">Save this site</h4>
                                    <HealthChip status={profile.verdict} />
                                </div>
                                <label className="block">
                                    <span className="text-sm font-medium text-slate-700">Name</span>
                                    <input className="crm-input mt-1" value={name} onChange={(e) => setName(e.target.value)} maxLength={255} />
                                </label>
                                <label className="flex items-start gap-2 text-sm text-slate-700">
                                    <input type="checkbox" className="mt-0.5" checked={ackRobots} onChange={(e) => setAckRobots(e.target.checked)} />
                                    I checked robots.txt{profile.robots?.allowed === false ? ' (it currently disallows this page; runs stay blocked unless you capture pages yourself)' : ''}.
                                </label>
                                <label className="flex items-start gap-2 text-sm text-slate-700">
                                    <input type="checkbox" className="mt-0.5" checked={ackTos} onChange={(e) => setAckTos(e.target.checked)} />
                                    I checked the site’s terms allow collecting public contact details for outreach.
                                </label>
                                <p className="text-xs text-slate-500">{profile.verdict === 'blocked' ? 'You can still save a blocked site and use an override from its page.' : 'Runs need both checks.'}</p>
                            </section>
                        </>
                    ) : null}
                </div>

                <footer className="flex items-center justify-end gap-2 border-t border-slate-200 px-5 py-3">
                    <button type="button" className="crm-btn-secondary" onClick={onClose}>Cancel</button>
                    <button type="button" className="crm-btn-primary disabled:cursor-not-allowed disabled:opacity-50" disabled={!profile || save.isPending || profile.verdict === 'error'} onClick={() => save.mutate()}>
                        {save.isPending ? 'Saving…' : 'Save site'}
                    </button>
                </footer>
            </aside>
        </div>
    );
}
