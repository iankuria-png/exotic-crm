import React, { useEffect, useMemo, useState } from 'react';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import api from '../../services/api';
import { useToast } from '../ToastProvider';

function flattenLocations(payload) {
    const raw = Array.isArray(payload?.locations?.locations)
        ? payload.locations.locations
        : (payload?.locations || payload || []);
    if (Array.isArray(raw)) {
        return raw.flatMap((entry) => {
            const children = entry.children || entry.cities || entry.locations || [];
            if (Array.isArray(children) && children.length) {
                return children.map((child) => ({
                    region_id: Number(entry.id || entry.term_id || entry.region_id || 0) || null,
                    city_id: Number(child.id || child.term_id || child.city_id || 0) || null,
                    region_name: entry.name || entry.label || entry.region_name || '',
                    city_name: child.name || child.label || child.city_name || '',
                }));
            }

            return [{
                region_id: Number(entry.region_id || entry.parent_id || 0) || null,
                city_id: Number(entry.city_id || entry.id || entry.term_id || 0) || null,
                region_name: entry.region_name || entry.region || '',
                city_name: entry.city_name || entry.name || entry.label || '',
            }];
        }).filter((entry) => entry.region_id || entry.city_id || entry.region_name || entry.city_name);
    }

    return [];
}

function defaultTarget(location, count = 10) {
    return {
        region_id: location?.region_id || null,
        city_id: location?.city_id || null,
        region_name: location?.region_name || null,
        city_name: location?.city_name || null,
        target_count: count,
    };
}

function locationLabel(location) {
    return [location?.city_name, location?.region_name].filter(Boolean).join(', ') || 'Destination location';
}

function stepClass(active) {
    return active ? 'border-teal-300 bg-teal-50 text-teal-800' : 'border-slate-200 bg-white text-slate-600';
}

export default function PbnSeedWizard({ open, onClose, site, platforms = [], onQueued, defaultNotes = 'Manual PBN seed from Settings' }) {
    const toast = useToast();
    const queryClient = useQueryClient();
    const [step, setStep] = useState('setup');
    const [sourcePlatformIds, setSourcePlatformIds] = useState([]);
    const [targetCount, setTargetCount] = useState(50);
    const [targets, setTargets] = useState([]);
    const [selectedClientIds, setSelectedClientIds] = useState([]);
    const [duplicateAcknowledged, setDuplicateAcknowledged] = useState(false);
    const [notes, setNotes] = useState(defaultNotes);

    const locationsQuery = useQuery({
        queryKey: ['settings-pbn-site-locations', site?.id],
        queryFn: () => api.get(`/crm/settings/integrations/pbn-sites/${site.id}/locations`).then((response) => response.data),
        enabled: open && Boolean(site?.id),
    });
    const locations = useMemo(() => flattenLocations(locationsQuery.data), [locationsQuery.data]);

    useEffect(() => {
        if (!open || !site) return;
        const defaults = (site.source_platform_ids || []).slice(0, 2);
        setSourcePlatformIds(defaults.length ? defaults : (site.default_source_platform_id ? [site.default_source_platform_id] : []));
        setTargetCount(50);
        setTargets([]);
        setSelectedClientIds([]);
        setDuplicateAcknowledged(false);
        setNotes(defaultNotes);
        setStep('setup');
    }, [defaultNotes, open, site]);

    useEffect(() => {
        if (!open || targets.length || !locations.length) return;
        setTargets(locations.slice(0, 3).map((location) => defaultTarget(location, 15)));
    }, [open, locations, targets.length]);

    const compactTargets = useMemo(() => targets
        .filter((target) => target.region_id || target.city_id || target.region_name || target.city_name)
        .map((target) => ({
            ...target,
            target_count: Math.max(1, Number(target.target_count || 1)),
        }))
        .slice(0, 40), [targets]);

    const payload = useMemo(() => ({
        source_platform_ids: sourcePlatformIds.map(Number),
        target_count: Math.max(1, Math.min(200, Number(targetCount || 1))),
        targets: compactTargets,
        selected_client_ids: selectedClientIds,
        duplicate_acknowledged: duplicateAcknowledged,
        notes,
    }), [sourcePlatformIds, targetCount, compactTargets, selectedClientIds, duplicateAcknowledged, notes]);

    const previewMutation = useMutation({
        mutationFn: () => api.post(`/crm/settings/integrations/pbn-sites/${site.id}/preview`, payload).then((response) => response.data),
        onSuccess: (data) => {
            setSelectedClientIds(data?.selected_client_ids || []);
            setDuplicateAcknowledged(false);
            setStep('preview');
        },
        onError: (error) => {
            toast.error(error?.response?.data?.message || 'Could not preview PBN candidates.');
        },
    });

    const queueMutation = useMutation({
        mutationFn: () => api.post(`/crm/settings/integrations/pbn-sites/${site.id}/batches`, {
            ...payload,
            preview_token: previewMutation.data?.preview_token,
        }).then((response) => response.data),
        onSuccess: (data) => {
            toast.success(data?.message || 'PBN seed batch queued.');
            queryClient.invalidateQueries({ queryKey: ['settings-pbn-sites'] });
            onQueued?.(data);
            onClose();
        },
        onError: (error) => {
            toast.error(error?.response?.data?.message || 'Could not queue PBN seed batch.');
        },
    });

    if (!open || !site) return null;

    const preview = previewMutation.data;
    const candidates = preview?.candidates || [];
    const warnings = preview?.warnings || [];
    const hasDuplicateWarnings = warnings.some((warning) => warning.type === 'duplicates');
    const selectedSet = new Set(selectedClientIds.map(Number));
    const availableSources = platforms.filter((platform) => (site.source_platform_ids || []).map(String).includes(String(platform.platform_id)));
    const canPreview = sourcePlatformIds.length > 0 && compactTargets.length > 0 && Number(targetCount) > 0 && !previewMutation.isPending;
    const canQueue = Boolean(preview?.preview_token)
        && selectedClientIds.length > 0
        && (!hasDuplicateWarnings || duplicateAcknowledged)
        && !queueMutation.isPending;

    const resetPreview = () => {
        previewMutation.reset();
        setSelectedClientIds([]);
        setDuplicateAcknowledged(false);
    };
    const toggleSource = (platformId) => {
        resetPreview();
        setSourcePlatformIds((current) => {
            const set = new Set(current.map(Number));
            if (set.has(Number(platformId))) {
                set.delete(Number(platformId));
            } else if (set.size < 5) {
                set.add(Number(platformId));
            }
            return Array.from(set);
        });
    };
    const addTarget = (location) => {
        resetPreview();
        setTargets((current) => {
            const exists = current.some((target) => String(target.city_id || target.city_name) === String(location.city_id || location.city_name));
            return exists || current.length >= 40 ? current : [...current, defaultTarget(location, Math.max(1, Math.ceil(Number(targetCount || 1) / Math.max(1, current.length + 1))))];
        });
    };
    const removeTarget = (index) => {
        resetPreview();
        setTargets((current) => current.filter((_, rowIndex) => rowIndex !== index));
    };
    const updateTarget = (index, value) => {
        resetPreview();
        setTargets((current) => current.map((target, rowIndex) => rowIndex === index ? { ...target, target_count: value } : target));
    };
    const toggleCandidate = (clientId) => {
        setSelectedClientIds((current) => {
            const set = new Set(current.map(Number));
            if (set.has(Number(clientId))) {
                set.delete(Number(clientId));
            } else if (set.size < 200) {
                set.add(Number(clientId));
            }
            return Array.from(set);
        });
    };

    return (
        <div className="fixed inset-0 z-50 flex items-stretch justify-center bg-slate-900/45 p-0 md:p-4" role="dialog" aria-modal="true">
            <div className="crm-surface flex h-full w-full flex-col overflow-hidden md:max-h-[92vh] md:max-w-6xl">
                <header className="crm-panel-header">
                    <div>
                        <h3 className="crm-panel-title">Seed {site.name}</h3>
                        <p className="crm-panel-subtitle">{site.domain} • {selectedClientIds.length} selected</p>
                    </div>
                    <button type="button" className="crm-btn-secondary min-h-11 px-3 py-2" onClick={onClose}>Close</button>
                </header>

                <div className="border-b border-slate-100 p-3">
                    <div className="flex gap-2 overflow-x-auto">
                        {[
                            ['setup', '1 Setup'],
                            ['locations', '2 Locations'],
                            ['preview', '3 Preview'],
                            ['queue', '4 Queue'],
                        ].map(([id, label]) => (
                            <button key={id} type="button" onClick={() => setStep(id)} className={`min-h-11 shrink-0 rounded-md border px-3 py-2 text-sm font-semibold ${stepClass(step === id)}`}>
                                {label}
                            </button>
                        ))}
                    </div>
                </div>

                <div className="flex-1 overflow-y-auto p-4">
                    {step === 'setup' ? (
                        <div className="grid gap-4 lg:grid-cols-12">
                            <section className="rounded-lg border border-slate-200 bg-white p-3 lg:col-span-7">
                                <p className="text-sm font-semibold text-slate-900">Source Markets</p>
                                <div className="mt-3 grid gap-2 sm:grid-cols-2">
                                    {availableSources.map((platform) => (
                                        <label key={platform.platform_id} className="flex min-h-11 items-center gap-2 rounded-md border border-slate-200 bg-slate-50 px-3 py-2 text-sm">
                                            <input type="checkbox" checked={sourcePlatformIds.map(Number).includes(Number(platform.platform_id))} onChange={() => toggleSource(platform.platform_id)} className="h-4 w-4 rounded border-slate-300 text-teal-700 focus:ring-teal-200" />
                                            <span>
                                                <span className="block font-medium text-slate-900">{platform.platform_name}</span>
                                                <span className="block text-xs text-slate-500">{platform.country || platform.domain}</span>
                                            </span>
                                        </label>
                                    ))}
                                </div>
                            </section>
                            <section className="rounded-lg border border-slate-200 bg-white p-3 lg:col-span-5">
                                <label className="text-sm font-semibold text-slate-900" htmlFor="pbn-target-count">Target count</label>
                                <input id="pbn-target-count" type="number" min="1" max="200" value={targetCount} onChange={(event) => { resetPreview(); setTargetCount(event.target.value); }} className="crm-input mt-2" />
                                <label className="mt-3 flex min-h-11 items-center gap-2 text-sm text-slate-700">
                                    <input type="checkbox" checked={duplicateAcknowledged} onChange={(event) => setDuplicateAcknowledged(event.target.checked)} className="h-4 w-4 rounded border-slate-300 text-teal-700 focus:ring-teal-200" />
                                    Accept duplicate warnings and skip same-site duplicates
                                </label>
                            </section>
                        </div>
                    ) : null}

                    {step === 'locations' ? (
                        <div className="grid gap-4 xl:grid-cols-12">
                            <section className="rounded-lg border border-slate-200 bg-white p-3 xl:col-span-5">
                                <p className="text-sm font-semibold text-slate-900">Destination Locations</p>
                                <div className="mt-3 max-h-80 space-y-2 overflow-y-auto">
                                    {locationsQuery.isLoading ? <p className="text-sm text-slate-500">Loading locations...</p> : null}
                                    {!locationsQuery.isLoading && locations.length === 0 ? <p className="rounded-md border border-dashed border-slate-200 bg-slate-50 px-3 py-4 text-sm text-slate-500">No destination locations returned.</p> : null}
                                    {locations.slice(0, 80).map((location, index) => (
                                        <button key={`${location.city_id}-${location.city_name}-${index}`} type="button" onClick={() => addTarget(location)} className="min-h-11 w-full rounded-md border border-slate-200 bg-slate-50 px-3 py-2 text-left text-sm font-medium text-slate-700 hover:border-slate-300">
                                            {locationLabel(location)}
                                        </button>
                                    ))}
                                </div>
                            </section>
                            <section className="rounded-lg border border-slate-200 bg-white p-3 xl:col-span-7">
                                <p className="text-sm font-semibold text-slate-900">Target Split</p>
                                <div className="mt-3 space-y-2">
                                    {targets.map((target, index) => (
                                        <div key={`${target.city_id}-${index}`} className="grid gap-2 rounded-md border border-slate-200 bg-slate-50 p-2 sm:grid-cols-[1fr_110px_auto]">
                                            <p className="self-center text-sm font-medium text-slate-800">{locationLabel(target)}</p>
                                            <input type="number" min="1" max="200" value={target.target_count} onChange={(event) => updateTarget(index, event.target.value)} className="crm-input" />
                                            <button type="button" onClick={() => removeTarget(index)} className="crm-btn-secondary min-h-11 px-3 py-2">Remove</button>
                                        </div>
                                    ))}
                                </div>
                            </section>
                        </div>
                    ) : null}

                    {step === 'preview' ? (
                        <div className="space-y-4">
                            <div className="grid gap-2 sm:grid-cols-4">
                                {[
                                    ['Target', preview?.target_count || targetCount],
                                    ['Eligible', preview?.eligible_count || 0],
                                    ['Selected', selectedClientIds.length],
                                    ['Warnings', warnings.length],
                                ].map(([label, value]) => (
                                    <div key={label} className="rounded-md border border-slate-200 bg-white px-3 py-2">
                                        <p className="text-[11px] font-semibold uppercase text-slate-500">{label}</p>
                                        <p className="mt-1 text-xl font-semibold text-slate-900">{value}</p>
                                    </div>
                                ))}
                            </div>
                            {warnings.map((warning) => (
                                <p key={warning.type} className="rounded-md border border-amber-200 bg-amber-50 px-3 py-2 text-sm text-amber-800">{warning.message}</p>
                            ))}
                            <section className="rounded-lg border border-slate-200 bg-white">
                                <div className="border-b border-slate-100 px-3 py-2">
                                    <p className="text-sm font-semibold text-slate-900">Candidate Preview</p>
                                </div>
                                <div className="grid gap-2 p-3 md:grid-cols-2 xl:grid-cols-3">
                                    {candidates.length === 0 ? <p className="text-sm text-slate-500">Run preview to see eligible candidates.</p> : null}
                                    {candidates.map((candidate) => (
                                        <button key={candidate.client_id} type="button" onClick={() => toggleCandidate(candidate.client_id)} className={`min-h-11 rounded-md border px-3 py-2 text-left transition ${selectedSet.has(Number(candidate.client_id)) ? 'border-teal-300 bg-teal-50' : 'border-slate-200 bg-white hover:border-slate-300'}`}>
                                            <div className="flex items-start justify-between gap-2">
                                                <div>
                                                    <p className="text-sm font-semibold text-slate-900">{candidate.name}</p>
                                                    <p className="text-xs text-slate-500">{candidate.source_platform_name} • {candidate.city || 'No city'}</p>
                                                </div>
                                                <span className="rounded-md bg-slate-100 px-2 py-0.5 text-[11px] font-semibold text-slate-600">{candidate.seo_score ?? '—'}</span>
                                            </div>
                                            {candidate.duplicate_state !== 'none' ? <p className="mt-1 text-xs text-amber-700">{candidate.duplicate_state.replaceAll('_', ' ')}</p> : null}
                                        </button>
                                    ))}
                                </div>
                            </section>
                        </div>
                    ) : null}

                    {step === 'queue' ? (
                        <section className="rounded-lg border border-slate-200 bg-white p-3">
                            <p className="text-sm font-semibold text-slate-900">Queue Review</p>
                            <p className="mt-1 text-sm text-slate-600">{selectedClientIds.length} profiles will be queued for {site.name}. Same-site duplicates are skipped after acknowledgement.</p>
                            <textarea rows={3} value={notes} onChange={(event) => setNotes(event.target.value)} className="crm-input mt-3" />
                            {hasDuplicateWarnings && !duplicateAcknowledged ? (
                                <p className="mt-3 rounded-md border border-amber-200 bg-amber-50 px-3 py-2 text-sm text-amber-800">Acknowledge duplicate warnings before queueing.</p>
                            ) : null}
                        </section>
                    ) : null}
                </div>

                <footer className="flex flex-wrap items-center justify-between gap-2 border-t border-slate-100 p-4">
                    <button type="button" className="crm-btn-secondary min-h-11 px-3 py-2" onClick={() => setStep(step === 'setup' ? 'locations' : step === 'locations' ? 'preview' : step === 'preview' ? 'queue' : 'setup')}>
                        Next step
                    </button>
                    <div className="flex flex-wrap gap-2">
                        <button type="button" className="crm-btn-secondary min-h-11 px-3 py-2" onClick={() => previewMutation.mutate()} disabled={!canPreview}>
                            {previewMutation.isPending ? 'Previewing...' : 'Preview candidates'}
                        </button>
                        <button type="button" className="crm-btn-primary min-h-11 px-3 py-2 disabled:cursor-not-allowed disabled:opacity-60" onClick={() => queueMutation.mutate()} disabled={!canQueue}>
                            {queueMutation.isPending ? 'Queueing...' : `Queue ${selectedClientIds.length} profiles`}
                        </button>
                    </div>
                </footer>
            </div>
        </div>
    );
}
