import React, { useEffect, useMemo, useState } from 'react';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import api from '../../services/api';
import { useToast } from '../ToastProvider';

const MAX_TARGETS = 40;

// Auto Optimize's own ledger reports roughly a tenth of a cent per generated
// bio. Used only to show an order-of-magnitude estimate before queueing — the
// real per-item cost is recorded on the batch once it runs.
const BIO_COST_PER_PROFILE_USD = 0.001;

const DEFAULT_POLICY = {
    badges: { featured_pct: 10, premium_pct: 25, verified_pct: 0 },
    bio: { mode: 'rewrite', on_failure: 'verbatim' },
    main_image: { mode: 'rotate' },
    expiry: { mode: 'window', min_days: 30, max_days: 90 },
    release: { mode: 'immediate', per_period: 10 },
};

function mergePolicy(sitePolicy) {
    const source = sitePolicy || {};

    return {
        badges: { ...DEFAULT_POLICY.badges, ...(source.badges || {}) },
        bio: { ...DEFAULT_POLICY.bio, ...(source.bio || {}) },
        main_image: { ...DEFAULT_POLICY.main_image, ...(source.main_image || {}) },
        expiry: { ...DEFAULT_POLICY.expiry, ...(source.expiry || {}) },
        release: { ...DEFAULT_POLICY.release, ...(source.release || {}) },
    };
}

function profilesFor(percent, total) {
    return Math.floor((Math.max(0, Math.min(100, Number(percent) || 0)) * total) / 100);
}

function trickleSummary(release, total) {
    if (release.mode === 'immediate' || total < 1) return 'All profiles are created as soon as the batch is queued.';

    const perPeriod = Math.max(1, Number(release.per_period) || 1);
    const periods = Math.ceil(total / perPeriod);
    const unit = release.mode === 'daily' ? 'day' : 'hour';
    if (periods <= 1) return `All ${total} profiles land in the first ${unit}.`;

    return `${perPeriod} per ${unit} · finishes about ${periods - 1} ${unit}${periods - 1 === 1 ? '' : 's'} after the batch starts.`;
}

function normalizeLocationTree(payload) {
    const raw = Array.isArray(payload?.locations?.locations)
        ? payload.locations.locations
        : (payload?.locations || payload || []);
    if (!Array.isArray(raw)) return [];

    return raw.map((entry) => {
        const regionId = Number(entry.id || entry.term_id || entry.region_id || 0) || null;
        const regionName = entry.name || entry.label || entry.region_name || entry.region || '';
        const children = entry.children || entry.cities || entry.locations || [];

        if (Array.isArray(children) && children.length) {
            return {
                region_id: regionId,
                region_name: regionName,
                cities: children.map((child) => ({
                    region_id: regionId,
                    region_name: regionName,
                    city_id: Number(child.id || child.term_id || child.city_id || 0) || null,
                    city_name: child.name || child.label || child.city_name || '',
                })),
            };
        }

        // Flat payloads (no nesting): the entry is itself a leaf destination.
        const flatRegionId = Number(entry.region_id || entry.parent_id || 0) || null;
        if (flatRegionId) {
            return {
                region_id: flatRegionId,
                region_name: entry.region_name || entry.region || '',
                cities: [{
                    region_id: flatRegionId,
                    region_name: entry.region_name || entry.region || '',
                    city_id: Number(entry.city_id || entry.id || entry.term_id || 0) || null,
                    city_name: entry.city_name || entry.name || entry.label || '',
                }],
            };
        }

        return { region_id: regionId, region_name: regionName, cities: [] };
    }).filter((group) => group.region_id || group.region_name || group.cities.length);
}

function targetKey(location) {
    return `${location?.region_id || location?.region_name || ''}::${location?.city_id || location?.city_name || ''}`;
}

function matchesQuery(haystack, needle) {
    return String(haystack || '').toLowerCase().includes(needle);
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
    if (!location?.city_name && location?.region_name) {
        return `All of ${location.region_name}`;
    }

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
    const [locationQuery, setLocationQuery] = useState('');
    const [policy, setPolicy] = useState(() => mergePolicy(site?.copy_policy));

    const locationsQuery = useQuery({
        queryKey: ['settings-pbn-site-locations', site?.id],
        queryFn: () => api.get(`/crm/settings/integrations/pbn-sites/${site.id}/locations`).then((response) => response.data),
        enabled: open && Boolean(site?.id),
    });
    const locationGroups = useMemo(() => normalizeLocationTree(locationsQuery.data), [locationsQuery.data]);
    const locations = useMemo(
        () => locationGroups.flatMap((group) => (group.cities.length
            ? group.cities
            : [{ region_id: group.region_id, region_name: group.region_name, city_id: null, city_name: '' }])),
        [locationGroups],
    );
    const totalDestinations = useMemo(
        () => locationGroups.reduce((sum, group) => sum + Math.max(1, group.cities.length), 0),
        [locationGroups],
    );
    const filteredGroups = useMemo(() => {
        const needle = locationQuery.trim().toLowerCase();
        if (!needle) return locationGroups;

        return locationGroups
            .map((group) => {
                if (matchesQuery(group.region_name, needle)) return group;
                const cities = group.cities.filter((city) => matchesQuery(city.city_name, needle));
                return cities.length ? { ...group, cities } : null;
            })
            .filter(Boolean);
    }, [locationGroups, locationQuery]);
    const filteredCount = useMemo(
        () => filteredGroups.reduce((sum, group) => sum + Math.max(1, group.cities.length), 0),
        [filteredGroups],
    );

    useEffect(() => {
        if (!open || !site) return;
        const defaults = (site.source_platform_ids || []).slice(0, 2);
        setSourcePlatformIds(defaults.length ? defaults : (site.default_source_platform_id ? [site.default_source_platform_id] : []));
        setTargetCount(50);
        setTargets([]);
        setSelectedClientIds([]);
        setDuplicateAcknowledged(false);
        setNotes(defaultNotes);
        setLocationQuery('');
        setPolicy(mergePolicy(site.copy_policy));
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
        copy_policy: policy,
        notes,
    }), [sourcePlatformIds, targetCount, compactTargets, selectedClientIds, duplicateAcknowledged, policy, notes]);

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

    const updatePolicy = (section, key, value) => {
        resetPreview();
        setPolicy((current) => ({ ...current, [section]: { ...current[section], [key]: value } }));
    };
    const plannedTotal = Math.max(0, Math.min(200, Number(targetCount) || 0));
    const badgeCounts = useMemo(() => ({
        featured: profilesFor(policy.badges.featured_pct, plannedTotal),
        premium: profilesFor(policy.badges.premium_pct, plannedTotal),
        verified: profilesFor(policy.badges.verified_pct, plannedTotal),
    }), [policy.badges, plannedTotal]);
    const badgeOversubscribed = badgeCounts.featured + badgeCounts.premium > plannedTotal;
    const estimatedBioCost = policy.bio.mode === 'rewrite' ? plannedTotal * BIO_COST_PER_PROFILE_USD : 0;

    const selectedTargetKeys = useMemo(() => new Set(targets.map((target) => targetKey(target))), [targets]);
    const allocatedTotal = useMemo(
        () => targets.reduce((sum, target) => sum + Math.max(0, Number(target.target_count) || 0), 0),
        [targets],
    );

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
            const exists = current.some((target) => targetKey(target) === targetKey(location));
            if (exists) {
                return current.filter((target) => targetKey(target) !== targetKey(location));
            }
            if (current.length >= MAX_TARGETS) {
                toast.error(`Destination targets are capped at ${MAX_TARGETS}.`);
                return current;
            }
            return [...current, defaultTarget(location, Math.max(1, Math.ceil(Number(targetCount || 1) / Math.max(1, current.length + 1))))];
        });
    };
    const clearTargets = () => {
        resetPreview();
        setTargets([]);
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

                            <section className="rounded-lg border border-slate-200 bg-white p-3 lg:col-span-12">
                                <div className="flex flex-wrap items-center justify-between gap-2">
                                    <p className="text-sm font-semibold text-slate-900">Seed Policy</p>
                                    <span className="text-[11px] text-slate-500">Applied per profile and recorded on the batch</span>
                                </div>

                                <div className="mt-3 grid gap-4 lg:grid-cols-3">
                                    <div>
                                        <p className="text-xs font-semibold uppercase tracking-wide text-slate-500">Badge mix</p>
                                        {[
                                            ['featured_pct', 'VIP (featured)', badgeCounts.featured, 'Enters the destination VIP pool, which is queried separately from the basic pool.'],
                                            ['premium_pct', 'Premium', badgeCounts.premium, 'Enters the Premium pool.'],
                                            ['verified_pct', 'Verified', badgeCounts.verified, 'Asserts a KYC check the seeded profile has not been through.'],
                                        ].map(([key, label, count, hint]) => (
                                            <div key={key} className="mt-3">
                                                <div className="flex items-center justify-between text-sm text-slate-800">
                                                    <label htmlFor={`policy-${key}`} className="font-medium">{label}</label>
                                                    <span className="text-xs text-slate-500">{policy.badges[key]}% · {count} profile{count === 1 ? '' : 's'}</span>
                                                </div>
                                                <input
                                                    id={`policy-${key}`}
                                                    type="range"
                                                    min="0"
                                                    max="100"
                                                    value={policy.badges[key]}
                                                    onChange={(event) => updatePolicy('badges', key, Number(event.target.value))}
                                                    className="mt-1 w-full accent-teal-700"
                                                />
                                                <p className="text-[11px] text-slate-500">{hint}</p>
                                            </div>
                                        ))}
                                        {badgeOversubscribed ? (
                                            <p className="mt-2 rounded-md border border-amber-200 bg-amber-50 px-2 py-1 text-[11px] text-amber-800">
                                                VIP and Premium together exceed the batch. VIP is filled first and Premium takes what is left.
                                            </p>
                                        ) : null}
                                    </div>

                                    <div className="space-y-3">
                                        <div>
                                            <label className="text-xs font-semibold uppercase tracking-wide text-slate-500" htmlFor="policy-bio-mode">Bio</label>
                                            <select id="policy-bio-mode" value={policy.bio.mode} onChange={(event) => updatePolicy('bio', 'mode', event.target.value)} className="crm-input mt-1">
                                                <option value="rewrite">Rewrite for this site</option>
                                                <option value="verbatim">Copy the source bio</option>
                                            </select>
                                            {policy.bio.mode === 'rewrite' ? (
                                                <>
                                                    <select
                                                        aria-label="What happens when bio generation fails"
                                                        value={policy.bio.on_failure}
                                                        onChange={(event) => updatePolicy('bio', 'on_failure', event.target.value)}
                                                        className="crm-input mt-2"
                                                    >
                                                        <option value="verbatim">On failure · fall back to the source bio</option>
                                                        <option value="attention">On failure · hold the profile for review</option>
                                                    </select>
                                                    <p className="mt-1 text-[11px] text-slate-500">
                                                        Uses the same generator as Auto Optimize. Estimated spend for {plannedTotal} profiles: ${estimatedBioCost.toFixed(2)}.
                                                    </p>
                                                </>
                                            ) : (
                                                <p className="mt-1 text-[11px] text-amber-700">Every site will carry a byte-identical copy of the source text.</p>
                                            )}
                                        </div>

                                        <div>
                                            <label className="text-xs font-semibold uppercase tracking-wide text-slate-500" htmlFor="policy-image-mode">Main image</label>
                                            <select id="policy-image-mode" value={policy.main_image.mode} onChange={(event) => updatePolicy('main_image', 'mode', event.target.value)} className="crm-input mt-1">
                                                <option value="rotate">Rotate to an alternative</option>
                                                <option value="source">Keep the source lead photo</option>
                                            </select>
                                            <p className="mt-1 text-[11px] text-slate-500">Rotation honours the market's minimum image dimensions.</p>
                                        </div>
                                    </div>

                                    <div className="space-y-3">
                                        <div>
                                            <label className="text-xs font-semibold uppercase tracking-wide text-slate-500" htmlFor="policy-expiry-mode">Profiles expire</label>
                                            <select id="policy-expiry-mode" value={policy.expiry.mode} onChange={(event) => updatePolicy('expiry', 'mode', event.target.value)} className="crm-input mt-1">
                                                <option value="window">Spread across a window</option>
                                                <option value="fixed">A fixed number of days</option>
                                                <option value="none">Never</option>
                                            </select>
                                            {policy.expiry.mode !== 'none' ? (
                                                <div className="mt-2 flex items-center gap-2">
                                                    <input
                                                        type="number" min="1" max="3650"
                                                        aria-label={policy.expiry.mode === 'window' ? 'Minimum days' : 'Days until expiry'}
                                                        value={policy.expiry.min_days}
                                                        onChange={(event) => updatePolicy('expiry', 'min_days', Number(event.target.value))}
                                                        className="crm-input"
                                                    />
                                                    {policy.expiry.mode === 'window' ? (
                                                        <>
                                                            <span className="text-xs text-slate-500">to</span>
                                                            <input
                                                                type="number" min="1" max="3650"
                                                                aria-label="Maximum days"
                                                                value={policy.expiry.max_days}
                                                                onChange={(event) => updatePolicy('expiry', 'max_days', Number(event.target.value))}
                                                                className="crm-input"
                                                            />
                                                        </>
                                                    ) : null}
                                                    <span className="whitespace-nowrap text-xs text-slate-500">days</span>
                                                </div>
                                            ) : null}
                                            {policy.expiry.mode === 'window' && Number(policy.expiry.max_days) < Number(policy.expiry.min_days) ? (
                                                <p className="mt-1 text-[11px] text-rose-700">The window's end must not be before its start.</p>
                                            ) : null}
                                        </div>

                                        <div>
                                            <label className="text-xs font-semibold uppercase tracking-wide text-slate-500" htmlFor="policy-release-mode">Release</label>
                                            <select id="policy-release-mode" value={policy.release.mode} onChange={(event) => updatePolicy('release', 'mode', event.target.value)} className="crm-input mt-1">
                                                <option value="immediate">All at once</option>
                                                <option value="hourly">Trickle hourly</option>
                                                <option value="daily">Trickle daily</option>
                                            </select>
                                            {policy.release.mode !== 'immediate' ? (
                                                <input
                                                    type="number" min="1" max="200"
                                                    aria-label="Profiles per period"
                                                    value={policy.release.per_period}
                                                    onChange={(event) => updatePolicy('release', 'per_period', Number(event.target.value))}
                                                    className="crm-input mt-2"
                                                />
                                            ) : null}
                                            <p className="mt-1 text-[11px] text-slate-500">{trickleSummary(policy.release, plannedTotal)}</p>
                                        </div>
                                    </div>
                                </div>
                            </section>
                        </div>
                    ) : null}

                    {step === 'locations' ? (
                        <div className="grid gap-4 xl:grid-cols-12">
                            <section className="rounded-lg border border-slate-200 bg-white p-3 xl:col-span-5">
                                <div className="flex flex-wrap items-center justify-between gap-2">
                                    <p className="text-sm font-semibold text-slate-900">Destination Locations</p>
                                    <span className="rounded-md bg-slate-100 px-2 py-0.5 text-[11px] font-semibold text-slate-600">
                                        {locationQuery.trim() ? `${filteredCount} of ${totalDestinations}` : `${totalDestinations} available`}
                                    </span>
                                </div>
                                <div className="relative mt-3">
                                    <input
                                        type="search"
                                        value={locationQuery}
                                        onChange={(event) => setLocationQuery(event.target.value)}
                                        className="crm-input pr-16"
                                        placeholder="Search city or region..."
                                        aria-label="Search destination locations"
                                    />
                                    {locationQuery ? (
                                        <button type="button" onClick={() => setLocationQuery('')} className="absolute inset-y-0 right-2 my-auto h-7 rounded-md px-2 text-xs font-semibold text-slate-500 hover:bg-slate-100 hover:text-slate-700">
                                            Clear
                                        </button>
                                    ) : null}
                                </div>

                                <div className="mt-3 max-h-96 space-y-3 overflow-y-auto pr-1">
                                    {locationsQuery.isLoading ? (
                                        <div className="space-y-2" aria-busy="true">
                                            {[0, 1, 2, 3, 4].map((row) => (
                                                <div key={row} className="h-11 animate-pulse rounded-md bg-slate-100" />
                                            ))}
                                        </div>
                                    ) : null}

                                    {!locationsQuery.isLoading && locationsQuery.isError ? (
                                        <div className="rounded-md border border-rose-200 bg-rose-50 px-3 py-4 text-sm text-rose-800">
                                            <p className="font-semibold">Could not load destination locations.</p>
                                            <p className="mt-1 text-xs">{locationsQuery.error?.response?.data?.message || 'The PBN site REST endpoint did not respond.'}</p>
                                            <button type="button" onClick={() => locationsQuery.refetch()} className="crm-btn-secondary mt-3 min-h-11 px-3 py-2">Retry</button>
                                        </div>
                                    ) : null}

                                    {!locationsQuery.isLoading && !locationsQuery.isError && totalDestinations === 0 ? (
                                        <div className="rounded-md border border-dashed border-slate-200 bg-slate-50 px-3 py-4 text-sm text-slate-600">
                                            <p className="font-semibold text-slate-800">No destination locations returned.</p>
                                            <p className="mt-1 text-xs">{site.domain} answered but its <code>escorts-from</code> taxonomy came back empty. Run Network Check on the site and confirm the exotic-crm-sync plugin version is current.</p>
                                        </div>
                                    ) : null}

                                    {!locationsQuery.isLoading && !locationsQuery.isError && totalDestinations > 0 && filteredCount === 0 ? (
                                        <div className="rounded-md border border-dashed border-slate-200 bg-slate-50 px-3 py-4 text-sm text-slate-600">
                                            <p>No location matches &ldquo;{locationQuery.trim()}&rdquo;.</p>
                                            <button type="button" onClick={() => setLocationQuery('')} className="crm-btn-secondary mt-3 min-h-11 px-3 py-2">Clear search</button>
                                        </div>
                                    ) : null}

                                    {filteredGroups.map((group) => {
                                        const regionTarget = { region_id: group.region_id, region_name: group.region_name, city_id: null, city_name: null };
                                        const regionSelected = selectedTargetKeys.has(targetKey(regionTarget));

                                        return (
                                            <div key={`${group.region_id}-${group.region_name}`} className="rounded-md border border-slate-200">
                                                <button
                                                    type="button"
                                                    onClick={() => addTarget(regionTarget)}
                                                    aria-pressed={regionSelected}
                                                    className={`flex min-h-11 w-full items-center justify-between gap-2 rounded-t-md px-3 py-2 text-left transition ${regionSelected ? 'bg-teal-50 text-teal-900' : 'bg-slate-50 text-slate-800 hover:bg-slate-100'}`}
                                                >
                                                    <span className="text-sm font-semibold">{group.region_name || 'Unnamed region'}</span>
                                                    <span className="text-[11px] font-semibold uppercase text-slate-500">
                                                        {regionSelected ? 'Region added' : `${group.cities.length} ${group.cities.length === 1 ? 'city' : 'cities'}`}
                                                    </span>
                                                </button>
                                                {group.cities.length ? (
                                                    <div className="space-y-1 border-t border-slate-100 p-2">
                                                        {group.cities.map((city) => {
                                                            const selected = selectedTargetKeys.has(targetKey(city));

                                                            return (
                                                                <button
                                                                    key={`${city.city_id}-${city.city_name}`}
                                                                    type="button"
                                                                    onClick={() => addTarget(city)}
                                                                    aria-pressed={selected}
                                                                    className={`flex min-h-11 w-full items-center justify-between gap-2 rounded-md border px-3 py-2 text-left text-sm font-medium transition ${selected ? 'border-teal-300 bg-teal-50 text-teal-900' : 'border-slate-200 bg-white text-slate-700 hover:border-slate-300'}`}
                                                                >
                                                                    <span>{city.city_name || 'Unnamed city'}</span>
                                                                    {selected ? <span className="text-[11px] font-semibold uppercase text-teal-700">Added</span> : null}
                                                                </button>
                                                            );
                                                        })}
                                                    </div>
                                                ) : null}
                                            </div>
                                        );
                                    })}
                                </div>
                            </section>
                            <section className="rounded-lg border border-slate-200 bg-white p-3 xl:col-span-7">
                                <div className="flex flex-wrap items-center justify-between gap-2">
                                    <p className="text-sm font-semibold text-slate-900">Target Split</p>
                                    <div className="flex items-center gap-2">
                                        <span className="rounded-md bg-slate-100 px-2 py-0.5 text-[11px] font-semibold text-slate-600">{targets.length}/{MAX_TARGETS} targets</span>
                                        {targets.length ? (
                                            <button type="button" onClick={clearTargets} className="rounded-md px-2 py-1 text-xs font-semibold text-slate-500 hover:bg-slate-100 hover:text-slate-700">Clear all</button>
                                        ) : null}
                                    </div>
                                </div>
                                {targets.length ? (
                                    <p className={`mt-2 text-xs ${allocatedTotal > 200 ? 'text-rose-700' : 'text-slate-500'}`}>
                                        {allocatedTotal} profiles allocated across {targets.length} {targets.length === 1 ? 'destination' : 'destinations'} (batch cap 200).
                                    </p>
                                ) : null}
                                <div className="mt-3 space-y-2">
                                    {targets.length === 0 ? (
                                        <p className="rounded-md border border-dashed border-slate-200 bg-slate-50 px-3 py-4 text-sm text-slate-500">
                                            Pick destinations on the left. Choose a region to spread across the whole district, or a city for a precise placement.
                                        </p>
                                    ) : null}
                                    {targets.map((target, index) => (
                                        <div key={`${targetKey(target)}-${index}`} className="grid gap-2 rounded-md border border-slate-200 bg-slate-50 p-2 sm:grid-cols-[1fr_110px_auto]">
                                            <p className="self-center text-sm font-medium text-slate-800">{locationLabel(target)}</p>
                                            <input type="number" min="1" max="200" value={target.target_count} onChange={(event) => updateTarget(index, event.target.value)} className="crm-input" aria-label={`Profiles for ${locationLabel(target)}`} />
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
