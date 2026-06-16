import React, { useMemo } from 'react';
import { useQuery } from '@tanstack/react-query';
import api from '../../../services/api';
import Combobox from '../../shared/Combobox';

function sortByName(items) {
    return [...items].sort((left, right) => String(left?.name || '').localeCompare(String(right?.name || '')));
}

export default function RegionCitySelect({
    platformId,
    regionId,
    cityId,
    onChange,
    disabled = false,
    className = '',
    legacyCityHint = null,
}) {
    const locationsQuery = useQuery({
        queryKey: ['wp-profile-locations', platformId],
        queryFn: () => api.get(`/crm/platforms/${platformId}/locations`).then((response) => response.data),
        enabled: Boolean(platformId),
        staleTime: 15 * 60 * 1000,
    });

    const locations = useMemo(() => sortByName(locationsQuery.data?.locations || []), [locationsQuery.data?.locations]);
    const selectedRegion = locations.find((region) => String(region.id) === String(regionId)) || null;
    const cityOptions = useMemo(() => sortByName(selectedRegion?.cities || []), [selectedRegion?.cities]);

    const regionGroups = useMemo(() => [
        {
            label: 'Regions',
            options: locations.map((region) => ({
                value: region.id,
                label: region.name,
                inputLabel: region.name,
                secondaryLabel: region.slug ? `Slug: ${region.slug}` : '',
                searchText: `${region.name} ${region.slug || ''}`,
            })),
        },
    ], [locations]);

    const cityGroups = useMemo(() => [
        {
            label: selectedRegion?.name ? `${selectedRegion.name} cities` : 'Cities',
            options: cityOptions.map((city) => ({
                value: city.id,
                label: city.name,
                inputLabel: city.name,
                secondaryLabel: city.slug ? `Slug: ${city.slug}` : '',
                searchText: `${city.name} ${city.slug || ''}`,
            })),
        },
    ], [cityOptions, selectedRegion?.name]);

    const cityHint = legacyCityHint || (selectedRegion ? 'Search within the selected region.' : 'Select a region to unlock cities.');

    return (
        <div className={`grid gap-3 md:grid-cols-2 ${className}`}>
            <Combobox
                label="Region"
                value={regionId || ''}
                onChange={(value) => onChange?.({
                    region_id: value ? Number(value) : null,
                    city_id: null,
                })}
                groups={regionGroups}
                placeholder="Choose region"
                searchPlaceholder="Search regions"
                loading={locationsQuery.isLoading}
                disabled={disabled || !platformId}
                emptyMessage={locationsQuery.isError ? 'Could not load regions. Retry in a moment.' : 'No regions found.'}
                hint={!platformId ? 'Choose a market first.' : 'Start with the wider region, then narrow down to a city.'}
            />
            <Combobox
                label="City"
                value={cityId || ''}
                onChange={(value) => onChange?.({
                    region_id: regionId ? Number(regionId) : null,
                    city_id: value ? Number(value) : null,
                })}
                groups={cityGroups}
                placeholder={selectedRegion ? 'Choose city' : 'Select region first'}
                searchPlaceholder="Search cities"
                loading={locationsQuery.isLoading}
                disabled={disabled || !platformId || !selectedRegion}
                emptyMessage={selectedRegion ? 'No cities found in this region.' : 'Select a region to see cities.'}
                hint={cityHint}
            />
        </div>
    );
}
