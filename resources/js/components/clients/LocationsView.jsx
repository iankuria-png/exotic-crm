import React, { useEffect, useMemo, useState } from 'react';
import { keepPreviousData, useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import api from '../../services/api';
import { useToast } from '../ToastProvider';
import { InsightEmptyState } from '../shared/InsightStates';
import ClientMap from './locations/ClientMap';
import CitySpotlight from './locations/CitySpotlight';
import KpiCard from './locations/KpiCard';
import LocationTable from './locations/LocationTable';
import { BANDS, CHANNEL_META, PERIOD_OPTIONS } from './locations/locationMeta';

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

function compareValues(left, right, direction) {
    if (left === right) {
        return 0;
    }

    return direction === 'asc'
        ? (left > right ? 1 : -1)
        : (left < right ? 1 : -1);
}

function LocationsSkeleton() {
    return (
        <div className="space-y-4">
            <div className="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
                {Array.from({ length: 4 }).map((_, index) => (
                    <div key={index} className="h-28 animate-pulse rounded-xl border border-slate-200 bg-white shadow-sm" />
                ))}
            </div>
            <div className="grid gap-4 xl:grid-cols-12">
                <div className="h-[520px] animate-pulse rounded-xl border border-slate-200 bg-white shadow-sm xl:col-span-8" />
                <div className="h-[520px] animate-pulse rounded-xl border border-slate-200 bg-white shadow-sm xl:col-span-4" />
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
    const [sort, setSort] = useState({ key: 'client_count', direction: 'desc' });
    const [mobileView, setMobileView] = useState(() => (
        typeof window !== 'undefined' && window.innerWidth < 1280 ? 'list' : 'map'
    ));

    useEffect(() => {
        const onResize = () => {
            if (window.innerWidth >= 1280) {
                setMobileView('map');
            }
        };

        window.addEventListener('resize', onResize);
        return () => window.removeEventListener('resize', onResize);
    }, []);

    const range = useMemo(() => buildRange(period), [period]);

    const { data, isFetching, error } = useQuery({
        queryKey: ['client-locations', normalizedPlatformId, period],
        queryFn: () => api.get('/crm/clients/locations', {
            params: {
                platform_id: normalizedPlatformId,
                from: range.from,
                to: range.to,
            },
        }).then((response) => response.data),
        enabled: Boolean(normalizedPlatformId),
        placeholderData: keepPreviousData,
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

    const locations = data?.locations || [];
    const geocodeSummary = data?.geocode || null;

    useEffect(() => {
        if (!selectedCity || locations.some((location) => location.canonical_key === selectedCity)) {
            return;
        }

        setSelectedCity(null);
    }, [locations, selectedCity]);

    const sortedLocations = useMemo(() => {
        const rows = [...locations];

        rows.sort((left, right) => {
            if (sort.key === 'display_city') {
                return compareValues(left.display_city, right.display_city, sort.direction);
            }

            if (sort.key === 'views') {
                return compareValues(left.engagement?.views || 0, right.engagement?.views || 0, sort.direction);
            }

            if (sort.key === 'contact_rate') {
                return compareValues(left.engagement?.contact_rate || 0, right.engagement?.contact_rate || 0, sort.direction);
            }

            if (sort.key === 'performance') {
                return compareValues(left.performance?.index || -1, right.performance?.index || -1, sort.direction);
            }

            return compareValues(left[sort.key] || 0, right[sort.key] || 0, sort.direction);
        });

        return rows;
    }, [locations, sort]);

    const selectedLocation = useMemo(
        () => locations.find((location) => location.canonical_key === selectedCity) || null,
        [locations, selectedCity]
    );

    const scoredLocations = useMemo(
        () => locations.filter((location) => !['insufficient', 'unavailable'].includes(location.performance?.band)),
        [locations]
    );

    const topLocation = useMemo(() => (
        [...scoredLocations].sort((left, right) => (right.performance?.index || 0) - (left.performance?.index || 0))[0] || null
    ), [scoredLocations]);

    const weakLocations = useMemo(
        () => locations.filter((location) => location.performance?.band === 'weak'),
        [locations]
    );

    const bestChannelInsight = useMemo(() => {
        const byChannel = {};

        scoredLocations.forEach((location) => {
            if (!location.top_channel) {
                return;
            }

            if (!byChannel[location.top_channel]) {
                byChannel[location.top_channel] = [];
            }

            byChannel[location.top_channel].push(location);
        });

        const leaders = Object.entries(byChannel)
            .map(([channel, rows]) => ({
                channel,
                rows: rows.sort((left, right) => (right.performance?.index || 0) - (left.performance?.index || 0)),
            }))
            .sort((left, right) => right.rows.length - left.rows.length);

        if (leaders.length === 0) {
            return null;
        }

        const primary = leaders[0];
        const secondary = leaders[1] || null;
        const firstCities = primary.rows.slice(0, 2).map((location) => location.display_city).join(' and ');
        const secondCities = secondary
            ? `${CHANNEL_META[secondary.channel]?.label || secondary.channel} is also strong in ${secondary.rows.slice(0, 2).map((location) => location.display_city).join(' and ')}.`
            : '';

        return {
            title: `${CHANNEL_META[primary.channel]?.label || primary.channel} leads the strongest city intent.`,
            body: `${CHANNEL_META[primary.channel]?.label || primary.channel} wins most often in ${firstCities}.${secondCities ? ` ${secondCities}` : ''}`,
        };
    }, [scoredLocations]);

    const analyticsBanner = useMemo(() => {
        if (data?.analytics_status === 'unavailable') {
            return {
                className: 'rounded-lg border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-900',
                message: "Engagement data couldn't load from WordPress. Showing client counts only.",
            };
        }

        if (data?.analytics_status === 'partial') {
            return {
                className: 'rounded-lg border border-slate-200 bg-slate-50 px-4 py-3 text-sm text-slate-700',
                message: "Channel breakdown isn't available from this market yet. Showing views, contact rate, and performance.",
            };
        }

        return null;
    }, [data?.analytics_status]);

    if (!normalizedPlatformId) {
        return (
            <InsightEmptyState
                title="Pick a market to map"
                message="Engagement is tracked per market. Choose a market above."
            />
        );
    }

    if (isFetching && !data) {
        return <LocationsSkeleton />;
    }

    if (error) {
        return (
            <InsightEmptyState
                title="Locations couldn't load"
                message="The client locations workspace hit a problem while loading this market."
            />
        );
    }

    if (!locations.length) {
        return (
            <InsightEmptyState
                title="No located clients yet"
                message="Clients need a city before they can appear on the map or in the ranked table."
            />
        );
    }

    const totals = data?.totals || {};
    const mappedCities = locations.filter((location) => location.mapped).length;

    return (
        <div className="space-y-4">
            <section className="rounded-xl border border-slate-200 bg-white p-5 shadow-sm">
                <div className="flex flex-col gap-4 xl:flex-row xl:items-center xl:justify-between">
                    <div>
                        <div className="flex flex-wrap items-center gap-3">
                            <h2 className="text-sm font-semibold text-slate-800">Client locations {marketName ? `- ${marketName}` : ''}</h2>
                        </div>
                        <p className="mt-1 text-sm text-slate-500">Where your clients are and how each city performs.</p>
                    </div>

                    <div className="flex flex-wrap items-center gap-3">
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
                                className="inline-flex items-center gap-2 rounded-full border border-teal-700 bg-teal-700 px-3.5 py-1.5 text-xs font-semibold text-white transition hover:bg-teal-800 disabled:cursor-not-allowed disabled:opacity-60"
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

                        <div className="inline-flex items-center rounded-full border border-slate-200 bg-slate-50 p-1">
                            {PERIOD_OPTIONS.map((option) => (
                                <button
                                    key={option.key}
                                    type="button"
                                    onClick={() => setPeriod(option.key)}
                                    className={`rounded-full px-3 py-1 text-xs font-semibold transition ${
                                        period === option.key
                                            ? 'bg-teal-700 text-white'
                                            : 'border border-slate-300 bg-white text-slate-500'
                                    }`}
                                >
                                    {option.label}
                                </button>
                            ))}
                        </div>

                        <div className="inline-flex items-center rounded-full border border-slate-200 bg-slate-50 p-1 xl:hidden">
                            {['map', 'list'].map((view) => (
                                <button
                                    key={view}
                                    type="button"
                                    onClick={() => setMobileView(view)}
                                    className={`rounded-full px-3 py-1 text-xs font-semibold transition ${
                                        mobileView === view
                                            ? 'bg-teal-700 text-white'
                                            : 'border border-slate-300 bg-white text-slate-500'
                                    }`}
                                >
                                    {view === 'map' ? 'Map' : 'List'}
                                </button>
                            ))}
                        </div>
                    </div>
                </div>
            </section>

            {analyticsBanner ? <div className={analyticsBanner.className}>{analyticsBanner.message}</div> : null}

            <section className="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
                <KpiCard
                    label="Clients mapped"
                    value={Number(totals.mapped_client_count || 0).toLocaleString()}
                    hint={`of ${Number(totals.located_client_count || 0).toLocaleString()} located`}
                    dotClass="bg-teal-500"
                />
                <KpiCard
                    label="Cities tracked"
                    value={locations.length.toLocaleString()}
                    hint={`${mappedCities.toLocaleString()} on map`}
                    dotClass="bg-slate-400"
                />
                <KpiCard
                    label="Top city"
                    value={topLocation ? topLocation.display_city : 'None'}
                    hint={topLocation ? `${topLocation.performance?.index || 0} index` : 'No scored cities yet'}
                    dotClass={BANDS.strong.dot}
                    onClick={topLocation ? () => setSelectedCity(topLocation.canonical_key) : undefined}
                />
                <KpiCard
                    label="Cities to watch"
                    value={weakLocations.length.toLocaleString()}
                    hint="Weak performance band"
                    dotClass={BANDS.weak.dot}
                />
            </section>

            {bestChannelInsight ? (
                <div className="rounded-lg border border-teal-100 bg-teal-50/70 px-4 py-3 text-sm text-teal-900">
                    <p className="font-semibold">{bestChannelInsight.title}</p>
                    <p className="mt-1">{bestChannelInsight.body}</p>
                </div>
            ) : null}

            <div className="grid gap-4 xl:grid-cols-12">
                <div className={`${mobileView === 'list' ? 'hidden xl:block' : ''} xl:col-span-8`}>
                    <ClientMap
                        locations={locations}
                        selectedCity={selectedCity}
                        onSelectCity={setSelectedCity}
                    />
                </div>
                <div className="xl:col-span-4">
                    <CitySpotlight
                        selectedLocation={selectedLocation}
                        locations={locations}
                        onSelectCity={setSelectedCity}
                        platformId={normalizedPlatformId}
                        bestChannelInsight={bestChannelInsight}
                    />
                </div>
            </div>

            <div className={mobileView === 'map' ? 'hidden xl:block' : ''}>
                <LocationTable
                    locations={sortedLocations}
                    selectedCity={selectedCity}
                    onSelectCity={setSelectedCity}
                    sort={sort}
                    onSortChange={setSort}
                    platformId={normalizedPlatformId}
                />
            </div>
        </div>
    );
}
