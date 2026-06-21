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
        target_count: Math.max(1, Math.min(8, Math.max(0, 5 - Number(location.active_count || 0)) || 2)),
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

    useEffect(() => {
        if (!open) return;
        setTargets(defaultTargets(locations, selectedCity));
        setSelectedClientIds([]);
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

    const updateTarget = (canonicalKey, patch) => {
        setTargets((current) => current.map((target) => (
            target.canonical_key === canonicalKey ? { ...target, ...patch } : target
        )));
    };

    const removeTarget = (canonicalKey) => {
        setTargets((current) => current.filter((target) => target.canonical_key !== canonicalKey));
    };

    const toggleCandidate = (clientId) => {
        const numeric = Number(clientId);
        setSelectedClientIds((current) => (
            current.map(Number).includes(numeric)
                ? current.filter((id) => Number(id) !== numeric)
                : [...current, numeric]
        ));
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
                                <div className="mt-2 space-y-2">
                                    {targets.map((target) => (
                                        <div key={target.canonical_key} className="rounded-lg border border-slate-200 bg-white p-3">
                                            <div className="flex items-start justify-between gap-3">
                                                <div>
                                                    <p className="text-sm font-semibold text-slate-800">{target.display_city}</p>
                                                </div>
                                                <button
                                                    type="button"
                                                    onClick={() => removeTarget(target.canonical_key)}
                                                    className="rounded-md px-2 py-1 text-xs font-semibold text-slate-500 hover:bg-slate-100"
                                                >
                                                    Remove
                                                </button>
                                            </div>
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
                                    <h3 className="text-sm font-semibold text-slate-800">Candidate preview</h3>
                                    <p className="mt-1 text-sm text-slate-500">Ranked by SEO quality score, verification, media, and recency.</p>
                                </div>
                                <div className="max-h-[430px] overflow-auto">
                                    {candidates.length === 0 ? (
                                        <div className="px-4 py-12 text-center text-sm text-slate-500">
                                            Run preview to see eligible profiles.
                                        </div>
                                    ) : (
                                        <table className="min-w-full divide-y divide-slate-200 text-sm">
                                            <thead className="sticky top-0 bg-slate-50 text-left text-xs font-semibold uppercase tracking-wide text-slate-500">
                                                <tr>
                                                    <th className="px-3 py-3">Use</th>
                                                    <th className="px-3 py-3">Profile</th>
                                                    <th className="px-3 py-3">City</th>
                                                    <th className="px-3 py-3">SEO</th>
                                                    <th className="px-3 py-3">Signals</th>
                                                </tr>
                                            </thead>
                                            <tbody className="divide-y divide-slate-100">
                                                {candidates.map((candidate) => (
                                                    <tr key={candidate.client_id} className={selectedSet.has(Number(candidate.client_id)) ? 'bg-teal-50/50' : 'bg-white'}>
                                                        <td className="px-3 py-3">
                                                            <input
                                                                type="checkbox"
                                                                checked={selectedSet.has(Number(candidate.client_id))}
                                                                onChange={() => toggleCandidate(candidate.client_id)}
                                                                className="h-4 w-4 rounded border-slate-300 text-teal-700 focus:ring-teal-500"
                                                                aria-label={`Use ${candidate.name}`}
                                                            />
                                                        </td>
                                                        <td className="px-3 py-3">
                                                            <div className="flex min-w-[180px] items-center gap-3">
                                                                {candidate.display_image_url ? (
                                                                    <img src={candidate.display_image_url} alt="" className="h-10 w-10 rounded-full object-cover" />
                                                                ) : (
                                                                    <div className="grid h-10 w-10 place-items-center rounded-full bg-slate-100 text-xs font-semibold text-slate-500">
                                                                        {String(candidate.name || '?').slice(0, 1).toUpperCase()}
                                                                    </div>
                                                                )}
                                                                <div>
                                                                    <p className="font-semibold text-slate-900">{candidate.name}</p>
                                                                    <p className="text-xs text-slate-500">#{candidate.client_id}</p>
                                                                </div>
                                                            </div>
                                                        </td>
                                                        <td className="px-3 py-3 text-slate-600">{candidate.display_city}</td>
                                                        <td className="px-3 py-3">
                                                            <span className="rounded-full border border-teal-200 bg-teal-50 px-2 py-1 text-xs font-semibold text-teal-700">
                                                                {candidate.seo_score ?? '-'} / 100
                                                            </span>
                                                        </td>
                                                        <td className="px-3 py-3">
                                                            <div className="flex max-w-[280px] flex-wrap gap-1.5">
                                                                {(candidate.reasons || []).map((reason) => (
                                                                    <span key={reason} className="rounded-full border border-slate-200 bg-white px-2 py-0.5 text-[11px] font-medium text-slate-600">
                                                                        {reason}
                                                                    </span>
                                                                ))}
                                                            </div>
                                                        </td>
                                                    </tr>
                                                ))}
                                            </tbody>
                                        </table>
                                    )}
                                </div>
                            </section>

                            <aside className="rounded-lg border border-slate-200 p-4">
                                <h3 className="text-sm font-semibold text-slate-800">Activation order</h3>
                                <p className="mt-1 text-sm text-slate-500">Manual override applies to this list.</p>
                                <div className="mt-3 max-h-64 space-y-2 overflow-auto">
                                    {selectedCandidates.length === 0 ? (
                                        <p className="rounded-lg border border-dashed border-slate-200 px-3 py-6 text-center text-sm text-slate-500">No profiles selected.</p>
                                    ) : selectedCandidates.map((candidate, index) => (
                                        <div key={candidate.client_id} className="flex items-center justify-between gap-3 rounded-lg border border-slate-200 px-3 py-2">
                                            <div className="min-w-0">
                                                <p className="truncate text-sm font-semibold text-slate-800">{index + 1}. {candidate.name}</p>
                                                <p className="text-xs text-slate-500">{candidate.display_city} · SEO {candidate.seo_score ?? '-'}</p>
                                            </div>
                                            <div className="flex items-center gap-1">
                                                <button type="button" onClick={() => moveCandidate(candidate.client_id, -1)} disabled={index === 0} className="rounded border border-slate-200 px-2 py-1 text-xs font-semibold text-slate-600 disabled:opacity-40">Up</button>
                                                <button type="button" onClick={() => moveCandidate(candidate.client_id, 1)} disabled={index === selectedCandidates.length - 1} className="rounded border border-slate-200 px-2 py-1 text-xs font-semibold text-slate-600 disabled:opacity-40">Down</button>
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
