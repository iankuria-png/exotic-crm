import React, { useEffect, useMemo, useState } from 'react';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import api from '../../services/api';
import { useToast } from '../ToastProvider';
import { InsightEmptyState } from '../shared/InsightStates';
import ClientMap from './locations/ClientMap';
import CitySpotlight from './locations/CitySpotlight';
import KpiCard from './locations/KpiCard';
import LocationTable from './locations/LocationTable';
import SeoBoostWizard from './SeoBoostWizard';
import { PERIOD_OPTIONS } from './locations/locationMeta';
import LocationFilters from './locations/LocationFilters';
import { DEFAULT_FILTERS, buildLocationCsv, filterAndSortLocations, validateBounds } from './locations/locationInventory';

const EMPTY_LOCATIONS = [];

function formatDate(date) {
    const year = date.getFullYear();
    const month = String(date.getMonth() + 1).padStart(2, '0');
    const day = String(date.getDate()).padStart(2, '0');

    return `${year}-${month}-${day}`;
}

function buildRange(periodKey) {
    const option = PERIOD_OPTIONS.find((item) => item.key === periodKey) || PERIOD_OPTIONS[1];
    const to = new Date();
    const from = new Date();
    from.setDate(to.getDate() - (option.days - 1));

    return {
        from: formatDate(from),
        to: formatDate(to),
    };
}

function LocationsSkeleton() {
    return (
        <div className="min-w-0 space-y-4">
            <div className="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
                {Array.from({ length: 4 }).map((_, index) => (
                    <div key={index} className="h-28 animate-pulse rounded-xl border border-slate-200 bg-white shadow-sm" />
                ))}
            </div>
            <div className="h-80 animate-pulse rounded-xl border border-slate-200 bg-white shadow-sm" />
        </div>
    );
}

export default function LocationsView({ platformId, marketName }) {
    const normalizedPlatformId = platformId ? Number(platformId) : null;
    const queryClient = useQueryClient();
    const toast = useToast();
    const [period, setPeriod] = useState('30d');
    const [selectedCity, setSelectedCity] = useState(null);
    const [seoBoostOpen, setSeoBoostOpen] = useState(false);
    const [sort, setSort] = useState({ key: 'available_count', direction: 'asc' });
    const [filters, setFilters] = useState(DEFAULT_FILTERS);
    const [page, setPage] = useState(1);
    const [pageSize, setPageSize] = useState(25);
    const [showMap, setShowMap] = useState(false);
    useEffect(() => {
        setSelectedCity(null);
        setSeoBoostOpen(false);
        setFilters(DEFAULT_FILTERS);
        setSort({ key: 'available_count', direction: 'asc' });
        setPage(1);
    }, [normalizedPlatformId]);

    const range = useMemo(() => buildRange(period), [period]);

    const { data, isFetching, error, refetch } = useQuery({
        queryKey: ['client-locations', normalizedPlatformId, period],
        queryFn: () => api.get('/crm/clients/locations', {
            params: {
                platform_id: normalizedPlatformId,
                from: range.from,
                to: range.to,
            },
        }).then((response) => response.data),
        enabled: Boolean(normalizedPlatformId),
    });

    const geocodeMutation = useMutation({
        mutationFn: () => api.post('/crm/clients/locations/geocode', {
            platform_id: normalizedPlatformId,
        }).then((response) => response.data),
        onSuccess: (result) => {
            toast?.success?.(result?.message || 'Mapping started. Cities will appear shortly.');
            // Give the first batch a head start, then refetch so new pins show up.
            window.setTimeout(() => {
                queryClient.invalidateQueries({ queryKey: ['client-locations', normalizedPlatformId] });
            }, 8000);
        },
        onError: (mutationError) => {
            toast?.error?.(mutationError?.response?.data?.message || 'Could not start mapping.');
        },
    });

    // Never render/export one market's response beneath another market's heading.
    const currentData = Number(data?.platform_id) === normalizedPlatformId ? data : null;
    const locations = currentData?.locations || EMPTY_LOCATIONS;
    const geocodeSummary = currentData?.geocode || null;

    const countError = validateBounds(filters);
    const sortedLocations = useMemo(() => filterAndSortLocations(locations, filters, sort), [locations, filters, sort]);
    const selectedLocation = sortedLocations.find((location) => location.canonical_key === selectedCity) || null;
    const pageCount = Math.max(1, Math.ceil(sortedLocations.length / pageSize));
    const currentPage = Math.min(page, pageCount);
    const pageRows = sortedLocations.slice((currentPage - 1) * pageSize, currentPage * pageSize);
    const periodLabel = `last ${PERIOD_OPTIONS.find((option) => option.key === period)?.days || 30} days`;

    useEffect(() => { setPage(1); }, [filters, sort, pageSize, normalizedPlatformId]);
    useEffect(() => {
        if (selectedCity && !sortedLocations.some((location) => location.canonical_key === selectedCity)) setSelectedCity(null);
    }, [sortedLocations, selectedCity]);

    const resetFilters = () => {
        setFilters(DEFAULT_FILTERS);
        setSort({ key: 'available_count', direction: 'asc' });
    };
    const exportCsv = () => {
        if (countError || !sortedLocations.length || !currentData) return;
        const url = URL.createObjectURL(new Blob([buildLocationCsv(sortedLocations, range, marketName || String(normalizedPlatformId))], { type: 'text/csv;charset=utf-8;' }));
        const link = document.createElement('a');
        link.href = url;
        link.download = `location-inventory-${normalizedPlatformId}-${range.from}-${range.to}.csv`;
        link.click();
        window.setTimeout(() => URL.revokeObjectURL(url), 1000);
    };

    const analyticsBanner = useMemo(() => {
        if (currentData?.analytics_status === 'unavailable') {
            return {
                className: 'rounded-lg border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-900',
                message: "Engagement data couldn't load from WordPress. Showing client counts only.",
            };
        }

        if (currentData?.analytics_status === 'partial') {
            return {
                className: 'rounded-lg border border-slate-200 bg-slate-50 px-4 py-3 text-sm text-slate-700',
                message: "Channel breakdown isn't available from this market yet. Showing views, contact rate, and performance.",
            };
        }

        return null;
    }, [currentData?.analytics_status]);

    if (!normalizedPlatformId) {
        return (
            <InsightEmptyState
                title="Pick a market"
                message="Engagement is tracked per market. Choose a market above."
            />
        );
    }

    if (isFetching && !currentData) {
        return <LocationsSkeleton />;
    }

    if (error || !currentData) {
        return <div className="rounded-xl border border-rose-200 bg-white p-5">
            <h2 className="font-semibold text-slate-900">Locations couldn't load</h2>
            <p className="mt-1 text-sm text-slate-600">Try loading this market again.</p>
            <button type="button" onClick={() => refetch()} className="mt-3 min-h-11 rounded-lg bg-teal-700 px-4 font-semibold text-white focus-visible:ring-2 focus-visible:ring-teal-600">Retry locations</button>
        </div>;
    }

    if (!locations.length) {
        return (
            <InsightEmptyState
                title="No located clients yet"
                message="No cached client records with a city were found in this market."
            />
        );
    }

    const totals = currentData?.totals || {};
    const lowCount = locations.filter((location) => location.available_count <= 5).length;

    return (
        <div className="min-w-0 space-y-4">
            <section className="rounded-xl border border-slate-200 bg-white p-5 shadow-sm">
                <div className="flex flex-col gap-4 xl:flex-row xl:items-center xl:justify-between">
                    <div>
                        <div className="flex flex-wrap items-center gap-3">
                            <h2 className="text-sm font-semibold text-slate-800">Location inventory {marketName ? `- ${marketName}` : ''}</h2>
                        </div>
                        <p className="mt-1 text-sm text-slate-500">Find areas with the fewest available profiles. Available = Active + Unlockable.</p>
                    </div>

                    <div className="flex flex-wrap items-center gap-3">
                        <button
                            type="button"
                            onClick={() => setSeoBoostOpen(true)}
                            className="inline-flex min-h-11 items-center gap-2 rounded-full border border-teal-700 bg-teal-700 px-3.5 py-1.5 text-xs font-semibold text-white transition hover:bg-teal-800 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-teal-500"
                        >
                            <span aria-hidden="true">+</span>
                            SEO Boost
                        </button>

                        {geocodeSummary && geocodeSummary.pending > 0 ? (
                            <div className="inline-flex items-center gap-2 rounded-full border border-teal-200 bg-teal-50 px-3 py-1.5 text-xs font-semibold text-teal-800">
                                <span className="h-2 w-2 animate-pulse rounded-full bg-teal-600" aria-hidden="true" />
                                Mapping {geocodeSummary.pending.toLocaleString()} {geocodeSummary.pending === 1 ? 'city' : 'cities'}…
                                <button
                                    type="button"
                                    onClick={() => queryClient.invalidateQueries({ queryKey: ['client-locations', normalizedPlatformId] })}
                                    className="ml-1 rounded-full px-2 py-0.5 text-teal-700 underline-offset-2 hover:underline"
                                >
                                    Refresh
                                </button>
                            </div>
                        ) : geocodeSummary && geocodeSummary.unmapped_cities > 0 ? (
                            <button
                                type="button"
                                onClick={() => geocodeMutation.mutate()}
                                disabled={geocodeMutation.isPending}
                                title="Resolve this market's city names to map coordinates so they appear as pins"
                                className="inline-flex min-h-11 items-center gap-2 rounded-full border border-teal-700 bg-teal-700 px-3.5 py-1.5 text-xs font-semibold text-white transition hover:bg-teal-800 disabled:cursor-not-allowed disabled:opacity-60"
                            >
                                <svg className="h-3.5 w-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round" aria-hidden="true">
                                    <path d="M21 10c0 7-9 13-9 13s-9-6-9-13a9 9 0 0 1 18 0Z" />
                                    <circle cx="12" cy="10" r="3" />
                                </svg>
                                {geocodeMutation.isPending
                                    ? 'Starting…'
                                    : `Map ${geocodeSummary.unmapped_cities.toLocaleString()} ${geocodeSummary.unmapped_cities === 1 ? 'city' : 'cities'}`}
                            </button>
                        ) : null}

                        <div className="inline-flex flex-wrap items-center gap-1 rounded-lg border border-slate-200 bg-slate-50 p-1" aria-label="Engagement period"><span className="px-2 text-xs text-slate-500">Engagement</span>
                            {PERIOD_OPTIONS.map((option) => (
                                <button
                                    key={option.key}
                                    type="button"
                                    onClick={() => setPeriod(option.key)}
                                    aria-pressed={period === option.key}
                                    className={`min-h-11 rounded-lg px-3 py-1 text-xs font-semibold transition ${
                                        period === option.key
                                            ? 'bg-teal-700 text-white'
                                            : 'border border-slate-300 bg-white text-slate-500'
                                    }`}
                                >
                                    {option.label}
                                </button>
                            ))}
                        </div>

                    </div>
                </div>
            </section>

            {analyticsBanner ? <div className={analyticsBanner.className}>{analyticsBanner.message}</div> : null}

            <section className="grid gap-3 sm:grid-cols-2 xl:grid-cols-4" aria-label="Current market inventory">
                <KpiCard label="Available profiles" value={Number(totals.available_count || 0).toLocaleString()} hint="Active + Unlockable · current cache" dotClass="bg-teal-500" />
                <KpiCard label="Active" value={Number(totals.active_count || 0).toLocaleString()} hint="Published active profiles" dotClass="bg-emerald-500" />
                <KpiCard label="Unlockable" value={Number(totals.unlockable_count || 0).toLocaleString()} hint="Expired profiles visitors can unlock" dotClass="bg-amber-500" />
                <KpiCard label="Low inventory locations" value={lowCount.toLocaleString()} hint="5 or fewer available · includes zero" dotClass="bg-slate-400"
                    onClick={() => { setFilters({ ...DEFAULT_FILTERS, max: '5' }); setSort({ key: 'available_count', direction: 'asc' }); }} />
            </section>

            <section className="min-w-0 overflow-hidden rounded-xl border border-slate-200 bg-white shadow-sm" aria-label="Location inventory">
                <div className="px-4 py-4 sm:px-5">
                    <h2 className="text-base font-semibold text-slate-900">Find a location</h2>
                    <p className="mt-1 text-sm leading-relaxed text-slate-500">Current CRM cache · {locations.length.toLocaleString()} locations with client records. Cities with no cached clients are not included.</p>
                    <details className="mt-2 text-xs leading-relaxed text-slate-600">
                        <summary className="inline-flex min-h-11 cursor-pointer items-center rounded px-1 font-semibold text-teal-700 focus-visible:ring-2 focus-visible:ring-teal-600">How counts work</summary>
                        <p className="mt-1 max-w-3xl pb-2">Available is Active + Unlockable. Active follows the CRM's published active-profile rules. Unlockable means published expired profiles with a WordPress profile that visitors can unlock. Archived, private and draft profiles do not count as Unlockable. All clients includes every non-closed cached client in a location. Inventory is current; the period only changes engagement. Counts can lag WordPress sync and do not confirm advertiser availability.</p>
                    </details>
                </div>
                <LocationFilters filters={filters} onChange={setFilters} sort={sort} onSortChange={setSort} error={countError} onReset={resetFilters} />
                <div className="flex flex-wrap items-center justify-between gap-3 border-b border-slate-200 px-4 py-3 sm:px-5">
                    <p role="status" className="text-sm text-slate-600">{countError ? 'Check your count range' : `${sortedLocations.length.toLocaleString()} of ${locations.length.toLocaleString()} locations`}{isFetching ? ' · Refreshing engagement…' : ''}</p>
                    <button type="button" onClick={exportCsv} disabled={Boolean(countError) || !sortedLocations.length || isFetching}
                        className="min-h-11 rounded-lg border border-slate-300 px-3 text-sm font-semibold text-teal-700 hover:bg-teal-50 focus-visible:ring-2 focus-visible:ring-teal-600 disabled:cursor-not-allowed disabled:opacity-50">Export filtered CSV</button>
                </div>
                {sortedLocations.length ? <>
                    <LocationTable locations={pageRows} selectedCity={selectedCity} onSelectCity={setSelectedCity} sort={sort} onSortChange={setSort} platformId={normalizedPlatformId} periodLabel={periodLabel} />
                    <div className="flex flex-wrap items-center justify-between gap-3 border-t border-slate-200 px-4 py-3 sm:px-5">
                        <p className="text-xs text-slate-500">{((currentPage - 1) * pageSize + 1).toLocaleString()}–{Math.min(currentPage * pageSize, sortedLocations.length).toLocaleString()} of {sortedLocations.length.toLocaleString()}</p>
                        <div className="flex flex-wrap items-center gap-3">
                            <label className="flex items-center gap-2 text-xs text-slate-600">Per page<select value={pageSize} onChange={(event) => setPageSize(Number(event.target.value))} className="min-h-11 rounded-lg border border-slate-300 bg-white px-2 text-base">{[25, 50, 100].map((size) => <option key={size}>{size}</option>)}</select></label>
                            <button type="button" disabled={currentPage === 1} onClick={() => setPage(currentPage - 1)} className="min-h-11 rounded-lg border border-slate-300 px-3 text-sm disabled:opacity-40 focus-visible:ring-2 focus-visible:ring-teal-600">Previous</button>
                            <span className="text-xs text-slate-500">{currentPage} / {pageCount}</span>
                            <button type="button" disabled={currentPage === pageCount} onClick={() => setPage(currentPage + 1)} className="min-h-11 rounded-lg border border-slate-300 px-3 text-sm disabled:opacity-40 focus-visible:ring-2 focus-visible:ring-teal-600">Next</button>
                        </div>
                    </div>
                </> : <div className="px-5 py-10 text-center"><h3 className="font-semibold text-slate-800">{countError ? 'Adjust the count range' : 'No locations match'}</h3><p className="mt-2 text-sm text-slate-500">{countError || 'Try another search or clear the filters to see all cached locations.'}</p><button type="button" onClick={resetFilters} className="mt-3 min-h-11 rounded-lg px-3 font-semibold text-teal-700 focus-visible:ring-2 focus-visible:ring-teal-600">Reset location filters</button></div>}
            </section>

            <section className="rounded-xl border border-slate-200 bg-white p-4 shadow-sm sm:p-5">
                <button type="button" aria-expanded={showMap} aria-controls={showMap ? "location-map-panel" : undefined} onClick={() => setShowMap(!showMap)} className="flex min-h-11 w-full items-center justify-between gap-3 rounded text-left font-semibold text-slate-800 focus-visible:ring-2 focus-visible:ring-teal-600">
                    Map and engagement<span aria-hidden="true">{showMap ? '−' : '+'}</span>
                </button>
                <p className="mt-1 text-xs text-slate-500">Follows your filters · Engagement during {periodLabel} · Map size shows all clients</p>
                {showMap ? <div id="location-map-panel" className="mt-4 grid min-w-0 gap-4 xl:grid-cols-12">
                    <div className="min-w-0 xl:col-span-8"><ClientMap locations={sortedLocations} selectedCity={selectedCity} onSelectCity={setSelectedCity} /></div>
                    <div className="min-w-0 xl:col-span-4"><CitySpotlight selectedLocation={selectedLocation} locations={sortedLocations} onSelectCity={setSelectedCity} platformId={normalizedPlatformId} bestChannelInsight={null} /></div>
                </div> : null}
            </section>

            <SeoBoostWizard
                open={seoBoostOpen}
                onClose={() => setSeoBoostOpen(false)}
                platformId={normalizedPlatformId}
                marketName={marketName}
                locations={locations}
                selectedCity={selectedCity}
            />
        </div>
    );
}
