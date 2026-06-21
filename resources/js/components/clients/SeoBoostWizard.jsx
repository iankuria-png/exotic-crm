import React, { useEffect, useMemo, useState } from 'react';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import api from '../../services/api';
import { useToast } from '../ToastProvider';

function selectedPrice(product, productPriceId) {
    const prices = product?.active_prices || product?.activePrices || [];
    return prices.find((price) => Number(price.id) === Number(productPriceId)) || prices[0] || null;
}

function productLabel(product) {
    return product?.display_name || product?.name || `Product #${product?.id}`;
}

function cityTargetCount(location) {
    return Math.max(1, Math.min(8, Math.max(0, 5 - Number(location.active_count || 0)) || 2));
}

function defaultTargets(locations, selectedCity) {
    const selected = locations.find((location) => location.canonical_key === selectedCity);
    const source = selected
        ? [selected]
        : locations
            .filter((location) => location.performance?.band === 'weak')
            .sort((left, right) => (left.performance?.index || 0) - (right.performance?.index || 0))
            .slice(0, 3);

    return source.map((location) => ({
        canonical_key: location.canonical_key,
        display_city: location.display_city,
        target_count: cityTargetCount(location),
    }));
}

function compactTargets(targets) {
    return targets
        .filter((target) => target.canonical_key && target.display_city)
        .map((target) => ({
            canonical_key: target.canonical_key,
            display_city: target.display_city,
            target_count: Math.max(1, Number(target.target_count || 1)),
        }));
}

function StatusPill({ status }) {
    const normalized = String(status || 'draft');
    const classes = {
        active: 'border-emerald-200 bg-emerald-50 text-emerald-700',
        completed: 'border-slate-200 bg-slate-50 text-slate-600',
        activating: 'border-amber-200 bg-amber-50 text-amber-700',
        failed: 'border-rose-200 bg-rose-50 text-rose-700',
    };

    return (
        <span className={`inline-flex items-center rounded-full border px-2 py-0.5 text-[11px] font-semibold ${classes[normalized] || 'border-slate-200 bg-slate-50 text-slate-600'}`}>
            {normalized.replace(/_/g, ' ')}
        </span>
    );
}

function seoScoreLabel(score) {
    return score === null || score === undefined ? 'No score' : `${score}/100`;
}

function cityBandLabel(location) {
    const band = location?.performance?.band;
    if (!band || band === 'unavailable') return 'No data';
    if (band === 'insufficient') return 'Low sample';
    return band.charAt(0).toUpperCase() + band.slice(1);
}

export default function SeoBoostWizard({
    open,
    onClose,
    platformId,
    marketName,
    locations,
    selectedCity,
}) {
    const toast = useToast();
    const queryClient = useQueryClient();
    const [targets, setTargets] = useState([]);
    const [productId, setProductId] = useState('');
    const [productPriceId, setProductPriceId] = useState('');
    const [durationDays, setDurationDays] = useState(14);
    const [selectedClientIds, setSelectedClientIds] = useState([]);
    const [citySearch, setCitySearch] = useState('');
    const [replaceClientId, setReplaceClientId] = useState(null);
    const [pin, setPin] = useState('');
    const [notes, setNotes] = useState('SEO Boost from client locations map');

    const productsQuery = useQuery({
        queryKey: ['seo-boost-products', platformId],
        queryFn: () => api.get('/crm/products', { params: { platform_id: platformId } }).then((response) => response.data?.products || response.data || []),
        enabled: open && Boolean(platformId),
    });

    const batchesQuery = useQuery({
        queryKey: ['seo-boost-batches', platformId],
        queryFn: () => api.get('/crm/seo-boost/batches', { params: { platform_id: platformId, limit: 5 } }).then((response) => response.data),
        enabled: open && Boolean(platformId),
    });

    const products = Array.isArray(productsQuery.data) ? productsQuery.data : [];
    const selectedProduct = products.find((product) => Number(product.id) === Number(productId)) || null;
    const prices = selectedProduct?.active_prices || selectedProduct?.activePrices || [];
    const chosenPrice = selectedPrice(selectedProduct, productPriceId);
    const cityOptions = useMemo(() => {
        const rows = locations
            .filter((location) => location.canonical_key && location.display_city)
            .map((location) => ({
                ...location,
                searchText: `${location.display_city || ''} ${location.canonical_key || ''}`.toLowerCase(),
            }));

        rows.sort((left, right) => {
            const bandWeight = { weak: 0, moderate: 1, strong: 2, insufficient: 3, unavailable: 4 };
            const leftBand = bandWeight[left.performance?.band] ?? 5;
            const rightBand = bandWeight[right.performance?.band] ?? 5;
            if (leftBand !== rightBand) return leftBand - rightBand;
            return (left.display_city || '').localeCompare(right.display_city || '');
        });

        return rows;
    }, [locations]);

    useEffect(() => {
        if (!open) return;
        setTargets(defaultTargets(locations, selectedCity));
        setSelectedClientIds([]);
        setCitySearch('');
        setReplaceClientId(null);
        setProductId('');
        setProductPriceId('');
        setDurationDays(14);
        setPin('');
        setNotes('SEO Boost from client locations map');
    }, [open, locations, selectedCity]);

    useEffect(() => {
        if (!open || productId || products.length === 0) return;
        const first = products[0];
        setProductId(String(first.id));
        const firstPrice = selectedPrice(first, '');
        if (firstPrice) {
            setProductPriceId(String(firstPrice.id));
            setDurationDays(Number(firstPrice.duration_days || 14));
        }
    }, [open, productId, products]);

    useEffect(() => {
        if (!chosenPrice) return;
        setDurationDays(Number(chosenPrice.duration_days || 14));
    }, [chosenPrice]);

    const previewPayload = useMemo(() => ({
        platform_id: platformId,
        targets: compactTargets(targets),
        selected_client_ids: selectedClientIds,
        limit: 120,
    }), [platformId, targets, selectedClientIds]);

    const previewMutation = useMutation({
        mutationFn: () => api.post('/crm/seo-boost/preview', previewPayload).then((response) => response.data),
        onSuccess: (data) => {
            const selected = data?.selected_client_ids || [];
            setSelectedClientIds(selected);
            if (selected.length === 0) {
                toast?.info?.('No eligible inactive profiles found for those city targets.');
            }
        },
        onError: (error) => {
            toast?.error?.(error?.response?.data?.message || 'Could not preview SEO Boost candidates.');
        },
    });

    const createMutation = useMutation({
        mutationFn: () => api.post('/crm/seo-boost/batches', {
            platform_id: platformId,
            product_id: Number(productId),
            product_price_id: productPriceId ? Number(productPriceId) : null,
            duration_days: Number(durationDays || 14),
            targets: compactTargets(targets),
            selected_client_ids: selectedClientIds,
            free_trial_pin: pin,
            notes,
        }).then((response) => response.data),
        onSuccess: (data) => {
            toast?.success?.(data?.message || 'SEO Boost batch created.');
            queryClient.invalidateQueries({ queryKey: ['seo-boost-batches', platformId] });
            queryClient.invalidateQueries({ queryKey: ['client-locations', platformId] });
            onClose();
        },
        onError: (error) => {
            const message = error?.response?.data?.errors?.free_trial_pin
                ? error.response.data.errors.free_trial_pin[0]
                : error?.response?.data?.message || 'Could not create SEO Boost batch.';
            toast?.error?.(message);
        },
    });

    if (!open) return null;

    const preview = previewMutation.data;
    const candidates = preview?.candidates || [];
    const selectedSet = new Set(selectedClientIds.map(Number));
    const selectedTargetKeys = new Set(targets.map((target) => target.canonical_key));
    const targetSummaryByKey = new Map((preview?.targets || []).map((target) => [target.canonical_key, target]));
    const filteredCityOptions = cityOptions
        .filter((location) => {
            const needle = citySearch.trim().toLowerCase();
            return !needle || location.searchText.includes(needle);
        })
        .slice(0, 18);
    const selectedCandidates = selectedClientIds
        .map((id) => candidates.find((candidate) => Number(candidate.client_id) === Number(id)))
        .filter(Boolean);
    const targetRows = compactTargets(targets);
    const canPreview = targetRows.length > 0 && !previewMutation.isPending;
    const canCreate = selectedClientIds.length > 0
        && productId
        && durationDays
        && pin.trim().length >= 4
        && !createMutation.isPending;

    const resetCandidatePreview = () => {
        previewMutation.reset();
        setSelectedClientIds([]);
        setReplaceClientId(null);
    };

    const updateTarget = (canonicalKey, patch) => {
        resetCandidatePreview();
        setTargets((current) => current.map((target) => (
            target.canonical_key === canonicalKey ? { ...target, ...patch } : target
        )));
    };

    const removeTarget = (canonicalKey) => {
        resetCandidatePreview();
        setTargets((current) => current.filter((target) => target.canonical_key !== canonicalKey));
    };

    const toggleCityTarget = (location) => {
        resetCandidatePreview();
        setTargets((current) => {
            const exists = current.some((target) => target.canonical_key === location.canonical_key);
            if (exists) {
                return current.filter((target) => target.canonical_key !== location.canonical_key);
            }

            return [
                ...current,
                {
                    canonical_key: location.canonical_key,
                    display_city: location.display_city,
                    target_count: cityTargetCount(location),
                },
            ];
        });
    };

    const addWeakestCities = () => {
        resetCandidatePreview();
        setTargets((current) => {
            const existing = new Set(current.map((target) => target.canonical_key));
            const additions = cityOptions
                .filter((location) => !existing.has(location.canonical_key))
                .slice(0, 5)
                .map((location) => ({
                    canonical_key: location.canonical_key,
                    display_city: location.display_city,
                    target_count: cityTargetCount(location),
                }));

            return [...current, ...additions];
        });
    };

    const replaceCandidate = (clientId) => {
        const replacement = Number(clientId);
        const target = Number(replaceClientId);
        if (!target || replacement === target) {
            setReplaceClientId(null);
            return;
        }

        setSelectedClientIds((current) => {
            const next = current.map(Number);
            const targetIndex = next.findIndex((id) => id === target);
            const replacementIndex = next.findIndex((id) => id === replacement);
            if (targetIndex < 0) return current;
            if (replacementIndex >= 0) {
                [next[targetIndex], next[replacementIndex]] = [next[replacementIndex], next[targetIndex]];
                return next;
            }

            next[targetIndex] = replacement;
            return next;
        });
        setReplaceClientId(null);
    };

    const toggleCandidate = (clientId) => {
        const numeric = Number(clientId);
        if (replaceClientId && !selectedSet.has(numeric)) {
            replaceCandidate(numeric);
            return;
        }

        setSelectedClientIds((current) => (
            current.map(Number).includes(numeric)
                ? current.filter((id) => Number(id) !== numeric)
                : [...current, numeric]
        ));
        if (Number(replaceClientId) === numeric) {
            setReplaceClientId(null);
        }
    };

    const moveCandidate = (clientId, direction) => {
        setSelectedClientIds((current) => {
            const next = [...current];
            const index = next.findIndex((id) => Number(id) === Number(clientId));
            const target = index + direction;
            if (index < 0 || target < 0 || target >= next.length) return current;
            [next[index], next[target]] = [next[target], next[index]];
            return next;
        });
    };

    const shuffleCandidates = () => {
        setSelectedClientIds((current) => {
            const next = current.map(Number);
            for (let index = next.length - 1; index > 0; index -= 1) {
                const swapIndex = Math.floor(Math.random() * (index + 1));
                [next[index], next[swapIndex]] = [next[swapIndex], next[index]];
            }
            return next;
        });
        setReplaceClientId(null);
    };

    return (
        <div className="fixed inset-0 z-[120] flex items-center justify-center bg-slate-900/50 p-4" onClick={createMutation.isPending ? undefined : onClose}>
            <section
                role="dialog"
                aria-modal="true"
                aria-labelledby="seo-boost-title"
                className="flex max-h-[92vh] w-full max-w-6xl flex-col overflow-hidden rounded-xl bg-white shadow-2xl"
                onClick={(event) => event.stopPropagation()}
            >
                <header className="border-b border-slate-200 px-5 py-4">
                    <div className="flex flex-col gap-3 md:flex-row md:items-start md:justify-between">
                        <div>
                            <p className="text-[11px] font-semibold uppercase tracking-[0.16em] text-teal-700">SEO Boost</p>
                            <h2 id="seo-boost-title" className="mt-1 text-xl font-semibold text-slate-950">
                                Activate quality profiles for {marketName || 'this market'}
                            </h2>
                            <p className="mt-1 max-w-3xl text-sm text-slate-500">
                                Profiles activate as tracked free trials and expire through the existing subscription reconciliation.
                            </p>
                        </div>
                        <button
                            type="button"
                            onClick={onClose}
                            disabled={createMutation.isPending}
                            className="self-start rounded-md border border-slate-200 px-3 py-2 text-sm font-semibold text-slate-600 transition hover:bg-slate-50 disabled:opacity-50"
                        >
                            Close
                        </button>
                    </div>
                </header>

                <div className="grid min-h-0 flex-1 overflow-y-auto lg:grid-cols-[360px_minmax(0,1fr)]">
                    <aside className="border-b border-slate-200 bg-slate-50/70 p-5 lg:border-b-0 lg:border-r">
                        <div className="space-y-5">
                            <div>
                                <label className="text-xs font-semibold uppercase tracking-wide text-slate-500">Package</label>
                                <select
                                    value={productId}
                                    onChange={(event) => {
                                        const nextProductId = event.target.value;
                                        const nextProduct = products.find((product) => String(product.id) === nextProductId);
                                        const nextPrice = selectedPrice(nextProduct, '');
                                        setProductId(nextProductId);
                                        setProductPriceId(nextPrice ? String(nextPrice.id) : '');
                                        setDurationDays(Number(nextPrice?.duration_days || durationDays || 14));
                                    }}
                                    className="mt-1 w-full rounded-lg border border-slate-300 bg-white px-3 py-2 text-sm text-slate-800 focus:border-teal-500 focus:outline-none focus:ring-2 focus:ring-teal-100"
                                >
                                    {products.length === 0 ? <option value="">No products available</option> : null}
                                    {products.map((product) => (
                                        <option key={product.id} value={product.id}>{productLabel(product)}</option>
                                    ))}
                                </select>
                            </div>

                            <div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-1">
                                <div>
                                    <label className="text-xs font-semibold uppercase tracking-wide text-slate-500">Duration</label>
                                    {prices.length > 0 ? (
                                        <select
                                            value={productPriceId}
                                            onChange={(event) => setProductPriceId(event.target.value)}
                                            className="mt-1 w-full rounded-lg border border-slate-300 bg-white px-3 py-2 text-sm text-slate-800 focus:border-teal-500 focus:outline-none focus:ring-2 focus:ring-teal-100"
                                        >
                                            {prices.map((price) => (
                                                <option key={price.id} value={price.id}>
                                                    {price.duration_label || `${price.duration_days} days`} - {price.currency} {Number(price.price || 0).toLocaleString()}
                                                </option>
                                            ))}
                                        </select>
                                    ) : (
                                        <input
                                            type="number"
                                            min="1"
                                            max="90"
                                            value={durationDays}
                                            onChange={(event) => setDurationDays(event.target.value)}
                                            className="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2 text-sm focus:border-teal-500 focus:outline-none focus:ring-2 focus:ring-teal-100"
                                        />
                                    )}
                                </div>
                                <div>
                                    <label className="text-xs font-semibold uppercase tracking-wide text-slate-500">Days</label>
                                    <input
                                        type="number"
                                        min="1"
                                        max="90"
                                        value={durationDays}
                                        onChange={(event) => setDurationDays(event.target.value)}
                                        className="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2 text-sm focus:border-teal-500 focus:outline-none focus:ring-2 focus:ring-teal-100"
                                    />
                                </div>
                            </div>

                            <div>
                                <div className="flex items-center justify-between gap-3">
                                    <label className="text-xs font-semibold uppercase tracking-wide text-slate-500">City targets</label>
                                    <span className="text-xs text-slate-500">{targetRows.length} selected</span>
                                </div>
                                <div className="mt-2 rounded-lg border border-slate-200 bg-white p-3">
                                    <input
                                        type="search"
                                        value={citySearch}
                                        onChange={(event) => setCitySearch(event.target.value)}
                                        placeholder="Search cities"
                                        className="w-full rounded-md border border-slate-300 px-3 py-2 text-sm focus:border-teal-500 focus:outline-none focus:ring-2 focus:ring-teal-100"
                                    />
                                    <div className="mt-3 max-h-48 space-y-1 overflow-auto pr-1">
                                        {filteredCityOptions.length === 0 ? (
                                            <p className="px-2 py-4 text-center text-sm text-slate-500">No city matches.</p>
                                        ) : filteredCityOptions.map((location) => {
                                            const checked = selectedTargetKeys.has(location.canonical_key);

                                            return (
                                                <label key={location.canonical_key} className={`flex cursor-pointer items-center gap-3 rounded-md border px-3 py-2 text-sm transition ${checked ? 'border-teal-200 bg-teal-50 text-teal-900' : 'border-transparent hover:border-slate-200 hover:bg-slate-50'}`}>
                                                    <input
                                                        type="checkbox"
                                                        checked={checked}
                                                        onChange={() => toggleCityTarget(location)}
                                                        className="h-4 w-4 rounded border-slate-300 text-teal-700 focus:ring-teal-500"
                                                    />
                                                    <span className="min-w-0 flex-1">
                                                        <span className="block truncate font-semibold">{location.display_city}</span>
                                                        <span className="block text-xs text-slate-500">{cityBandLabel(location)} · {Number(location.client_count || 0).toLocaleString()} clients</span>
                                                    </span>
                                                </label>
                                            );
                                        })}
                                    </div>
                                    <div className="mt-3 grid grid-cols-2 gap-2">
                                        <button type="button" onClick={addWeakestCities} className="rounded-md border border-slate-200 px-3 py-2 text-xs font-semibold text-slate-600 transition hover:bg-slate-50">
                                            Add weakest 5
                                        </button>
                                        <button type="button" onClick={() => { resetCandidatePreview(); setTargets([]); }} className="rounded-md border border-slate-200 px-3 py-2 text-xs font-semibold text-slate-600 transition hover:bg-slate-50">
                                            Clear cities
                                        </button>
                                    </div>
                                </div>

                                <div className="mt-3 space-y-2">
                                    {targets.map((target) => (
                                        <div key={target.canonical_key} className="rounded-lg border border-slate-200 bg-white p-3">
                                            <div className="flex items-start justify-between gap-3">
                                                <div>
                                                    <p className="text-sm font-semibold text-slate-800">{target.display_city}</p>
                                                    {targetSummaryByKey.has(target.canonical_key) ? (
                                                        <p className="mt-0.5 text-xs text-slate-500">
                                                            {targetSummaryByKey.get(target.canonical_key).selected_count}/{target.target_count} matched
                                                        </p>
                                                    ) : null}
                                                </div>
                                                <button
                                                    type="button"
                                                    onClick={() => removeTarget(target.canonical_key)}
                                                    className="rounded-md px-2 py-1 text-xs font-semibold text-slate-500 hover:bg-slate-100"
                                                >
                                                    Remove
                                                </button>
                                            </div>
                                            {Number(targetSummaryByKey.get(target.canonical_key)?.shortfall || 0) > 0 ? (
                                                <div className="mt-3 rounded-md border border-amber-200 bg-amber-50 px-2 py-1 text-xs font-medium text-amber-800">
                                                    Short by {targetSummaryByKey.get(target.canonical_key).shortfall}
                                                </div>
                                            ) : null}
                                            <label className="mt-3 block text-xs font-semibold uppercase tracking-wide text-slate-500">Target activations</label>
                                            <input
                                                type="number"
                                                min="1"
                                                max="100"
                                                value={target.target_count}
                                                onChange={(event) => updateTarget(target.canonical_key, { target_count: event.target.value })}
                                                className="mt-1 w-full rounded-md border border-slate-300 px-3 py-2 text-sm focus:border-teal-500 focus:outline-none focus:ring-2 focus:ring-teal-100"
                                            />
                                        </div>
                                    ))}
                                </div>
                            </div>

                            <button
                                type="button"
                                onClick={() => previewMutation.mutate()}
                                disabled={!canPreview}
                                className="w-full rounded-lg bg-teal-700 px-4 py-2.5 text-sm font-semibold text-white transition hover:bg-teal-800 disabled:cursor-not-allowed disabled:opacity-60"
                            >
                                {previewMutation.isPending ? 'Building preview...' : 'Preview candidates'}
                            </button>

                            <div className="rounded-lg border border-slate-200 bg-white p-4">
                                <div className="flex items-center justify-between gap-3">
                                    <h3 className="text-sm font-semibold text-slate-800">Recent batches</h3>
                                    {batchesQuery.isFetching ? <span className="text-xs text-slate-400">Refreshing</span> : null}
                                </div>
                                <div className="mt-3 space-y-2">
                                    {(batchesQuery.data?.batches || []).length === 0 ? (
                                        <p className="text-sm text-slate-500">No SEO Boost batches yet.</p>
                                    ) : (batchesQuery.data?.batches || []).map((batch) => (
                                        <div key={batch.id} className="flex items-center justify-between gap-3 rounded-md border border-slate-100 px-3 py-2">
                                            <div>
                                                <p className="text-sm font-semibold text-slate-800">Batch #{batch.id}</p>
                                                <p className="text-xs text-slate-500">{batch.activated_count || 0}/{batch.selected_count || 0} activated</p>
                                            </div>
                                            <StatusPill status={batch.status} />
                                        </div>
                                    ))}
                                </div>
                            </div>
                        </div>
                    </aside>

                    <main className="min-w-0 p-5">
                        <div className="grid gap-3 sm:grid-cols-3">
                            <div className="rounded-lg border border-slate-200 p-4">
                                <p className="text-xs font-semibold uppercase tracking-wide text-slate-500">Target</p>
                                <p className="mt-1 text-2xl font-semibold text-slate-950">{preview?.target_count ?? targetRows.reduce((sum, row) => sum + Number(row.target_count || 0), 0)}</p>
                            </div>
                            <div className="rounded-lg border border-slate-200 p-4">
                                <p className="text-xs font-semibold uppercase tracking-wide text-slate-500">Eligible</p>
                                <p className="mt-1 text-2xl font-semibold text-slate-950">{preview?.eligible_count ?? '-'}</p>
                            </div>
                            <div className="rounded-lg border border-slate-200 p-4">
                                <p className="text-xs font-semibold uppercase tracking-wide text-slate-500">Selected</p>
                                <p className="mt-1 text-2xl font-semibold text-slate-950">{selectedClientIds.length}</p>
                            </div>
                        </div>

                        {preview?.targets?.some((target) => Number(target.shortfall || 0) > 0) ? (
                            <div className="mt-4 rounded-lg border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-900">
                                Some city targets have fewer eligible inactive profiles than requested. Adjust targets or activate the available profiles.
                            </div>
                        ) : null}

                        <div className="mt-5 grid gap-5 xl:grid-cols-[minmax(0,1fr)_320px]">
                            <section className="min-w-0 rounded-lg border border-slate-200">
                                <div className="border-b border-slate-200 px-4 py-3">
                                    <div className="flex flex-wrap items-center justify-between gap-2">
                                        <div>
                                            <h3 className="text-sm font-semibold text-slate-800">Candidate preview</h3>
                                            <p className="mt-1 text-sm text-slate-500">Best inactive profiles for the selected cities.</p>
                                        </div>
                                        {replaceClientId ? (
                                            <button type="button" onClick={() => setReplaceClientId(null)} className="rounded-md border border-amber-200 bg-amber-50 px-3 py-1.5 text-xs font-semibold text-amber-800">
                                                Cancel replace
                                            </button>
                                        ) : null}
                                    </div>
                                </div>
                                <div className="max-h-[430px] overflow-auto">
                                    {candidates.length === 0 ? (
                                        <div className="px-4 py-12 text-center text-sm text-slate-500">
                                            Run preview to see eligible profiles.
                                        </div>
                                    ) : (
                                        <div className="grid gap-3 p-3 lg:grid-cols-2">
                                            {candidates.map((candidate) => {
                                                const candidateId = Number(candidate.client_id);
                                                const selected = selectedSet.has(candidateId);
                                                const replacing = Boolean(replaceClientId) && !selected;

                                                return (
                                                    <article key={candidate.client_id} className={`rounded-lg border p-3 transition ${selected ? 'border-teal-300 bg-teal-50/70' : replacing ? 'border-amber-300 bg-amber-50/60' : 'border-slate-200 bg-white hover:border-teal-200'}`}>
                                                        <div className="flex items-start gap-3">
                                                            {candidate.display_image_url ? (
                                                                <img src={candidate.display_image_url} alt="" className="h-14 w-14 rounded-lg object-cover" />
                                                            ) : (
                                                                <div className="grid h-14 w-14 place-items-center rounded-lg bg-slate-100 text-sm font-semibold text-slate-500">
                                                                    {String(candidate.name || '?').slice(0, 1).toUpperCase()}
                                                                </div>
                                                            )}
                                                            <div className="min-w-0 flex-1">
                                                                <div className="flex items-start justify-between gap-2">
                                                                    <div className="min-w-0">
                                                                        <p className="truncate text-sm font-semibold text-slate-900">{candidate.name}</p>
                                                                        <p className="text-xs text-slate-500">#{candidate.client_id} · {candidate.display_city}</p>
                                                                    </div>
                                                                    <span className="shrink-0 rounded-full border border-teal-200 bg-white px-2 py-1 text-xs font-semibold text-teal-700">
                                                                        {seoScoreLabel(candidate.seo_score)}
                                                                    </span>
                                                                </div>
                                                                <div className="mt-2 flex flex-wrap gap-1.5">
                                                                    {(candidate.reasons || []).slice(0, 3).map((reason) => (
                                                                        <span key={reason} className="rounded-full border border-slate-200 bg-white px-2 py-0.5 text-[11px] font-medium text-slate-600">
                                                                            {reason}
                                                                        </span>
                                                                    ))}
                                                                </div>
                                                            </div>
                                                        </div>
                                                        <div className="mt-3 flex items-center justify-between gap-2">
                                                            <span className="text-xs font-medium text-slate-500">
                                                                {selected ? `Priority #${selectedClientIds.findIndex((id) => Number(id) === candidateId) + 1}` : `Rank #${candidate.rank || '-'}`}
                                                            </span>
                                                            <button
                                                                type="button"
                                                                onClick={() => toggleCandidate(candidate.client_id)}
                                                                className={`min-h-9 rounded-md px-3 text-xs font-semibold transition ${selected ? 'border border-slate-200 bg-white text-slate-600 hover:bg-slate-50' : replacing ? 'bg-amber-600 text-white hover:bg-amber-700' : 'bg-teal-700 text-white hover:bg-teal-800'}`}
                                                            >
                                                                {selected ? 'Remove' : replacing ? 'Replace' : 'Add'}
                                                            </button>
                                                        </div>
                                                    </article>
                                                );
                                            })}
                                        </div>
                                    )}
                                </div>
                            </section>

                            <aside className="rounded-lg border border-slate-200 p-4">
                                <div className="flex items-start justify-between gap-3">
                                    <div>
                                        <h3 className="text-sm font-semibold text-slate-800">Activation priority</h3>
                                        <p className="mt-1 text-sm text-slate-500">Top profiles activate first.</p>
                                    </div>
                                    {selectedCandidates.length > 1 ? (
                                        <button type="button" onClick={shuffleCandidates} className="rounded-md border border-slate-200 px-2.5 py-1.5 text-xs font-semibold text-slate-600 transition hover:bg-slate-50">
                                            Shuffle
                                        </button>
                                    ) : null}
                                </div>
                                <div className="mt-3 max-h-64 space-y-2 overflow-auto">
                                    {selectedCandidates.length === 0 ? (
                                        <p className="rounded-lg border border-dashed border-slate-200 px-3 py-6 text-center text-sm text-slate-500">No profiles selected.</p>
                                    ) : selectedCandidates.map((candidate, index) => (
                                        <div key={candidate.client_id} className={`rounded-lg border px-3 py-2 ${Number(replaceClientId) === Number(candidate.client_id) ? 'border-amber-300 bg-amber-50' : 'border-slate-200 bg-white'}`}>
                                            <div className="min-w-0">
                                                <p className="truncate text-sm font-semibold text-slate-800">{index + 1}. {candidate.name}</p>
                                                <p className="text-xs text-slate-500">{candidate.display_city} · SEO {seoScoreLabel(candidate.seo_score)}</p>
                                            </div>
                                            <div className="mt-2 grid grid-cols-2 gap-1.5">
                                                <button type="button" onClick={() => moveCandidate(candidate.client_id, -1)} disabled={index === 0} className="rounded border border-slate-200 px-2 py-1.5 text-xs font-semibold text-slate-600 disabled:opacity-40">Earlier</button>
                                                <button type="button" onClick={() => moveCandidate(candidate.client_id, 1)} disabled={index === selectedCandidates.length - 1} className="rounded border border-slate-200 px-2 py-1.5 text-xs font-semibold text-slate-600 disabled:opacity-40">Later</button>
                                                <button type="button" onClick={() => setReplaceClientId(candidate.client_id)} className="rounded border border-slate-200 px-2 py-1.5 text-xs font-semibold text-slate-600 hover:bg-slate-50">Replace</button>
                                                <button type="button" onClick={() => toggleCandidate(candidate.client_id)} className="rounded border border-rose-200 px-2 py-1.5 text-xs font-semibold text-rose-700 hover:bg-rose-50">Remove</button>
                                            </div>
                                        </div>
                                    ))}
                                </div>

                                <label className="mt-4 block text-xs font-semibold uppercase tracking-wide text-slate-500">Free-trial PIN</label>
                                <input
                                    type="password"
                                    inputMode="numeric"
                                    value={pin}
                                    onChange={(event) => setPin(event.target.value)}
                                    className="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2 text-sm focus:border-teal-500 focus:outline-none focus:ring-2 focus:ring-teal-100"
                                    placeholder="Enter PIN"
                                />

                                <label className="mt-3 block text-xs font-semibold uppercase tracking-wide text-slate-500">Notes</label>
                                <textarea
                                    value={notes}
                                    onChange={(event) => setNotes(event.target.value)}
                                    rows={3}
                                    className="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2 text-sm focus:border-teal-500 focus:outline-none focus:ring-2 focus:ring-teal-100"
                                />

                                <button
                                    type="button"
                                    onClick={() => createMutation.mutate()}
                                    disabled={!canCreate}
                                    className="mt-4 w-full rounded-lg bg-teal-700 px-4 py-2.5 text-sm font-semibold text-white transition hover:bg-teal-800 disabled:cursor-not-allowed disabled:opacity-60"
                                >
                                    {createMutation.isPending ? 'Activating...' : `Activate ${selectedClientIds.length} profiles`}
                                </button>
                                <p className="mt-2 text-xs text-slate-500">
                                    Expiry will be handled by the existing subscription reconciliation after {durationDays || '-'} days.
                                </p>
                            </aside>
                        </div>
                    </main>
                </div>
            </section>
        </div>
    );
}
