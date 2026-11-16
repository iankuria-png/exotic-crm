import React, { useState } from 'react';
import { useNavigate } from 'react-router-dom';
import BandBadge from './BandBadge';
import ChannelMixBar from './ChannelMixBar';
import { formatMetric } from './locationInventory';

const columns = [
    ['display_city', 'Location'], ['available_count', 'Available'], ['active_count', 'Active'],
    ['unlockable_count', 'Unlockable'], ['client_count', 'All clients'],
];
const button = 'inline-flex min-h-11 items-center gap-2 rounded-md px-2 text-sm font-semibold focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-teal-600';

export default function LocationTable({ locations, selectedCity, onSelectCity, sort, onSortChange, platformId, periodLabel }) {
    const navigate = useNavigate();
    const [expanded, setExpanded] = useState(new Set());
    const toggleDetails = (key) => {
        setExpanded((current) => {
            const next = new Set(current);
            if (next.has(key)) next.delete(key); else next.add(key);
            return next;
        });
        onSelectCity(key);
    };
    const toggleSort = (key) => onSortChange({ key, direction: sort.key === key && sort.direction === 'asc' ? 'desc' : 'asc' });
    const openClients = (canonicalKey) => {
        const params = new URLSearchParams({ tab: 'all', city_key: canonicalKey, platform_id: String(platformId) });
        navigate(`/clients?${params.toString()}`);
    };

    return (
        <div className="relative overflow-x-auto" role="region" aria-label="Location inventory table" tabIndex={0}>
            <table className="min-w-full divide-y divide-slate-200 text-sm">
                <caption className="sr-only">Current cached location inventory. Available is Active plus Unlockable. Expand details for engagement during {periodLabel}.</caption>
                <thead className="bg-slate-50 text-left text-xs font-semibold text-slate-600">
                    <tr>
                        {columns.map(([key, label]) => <th key={key} scope="col" className="whitespace-nowrap px-3 py-2 sm:px-4"
                            aria-sort={sort.key === key ? (sort.direction === 'asc' ? 'ascending' : 'descending') : 'none'}>
                            <button type="button" onClick={() => toggleSort(key)} className={`${button} ${sort.key === key ? 'text-teal-800' : 'text-slate-600'}`}>
                                {label}<span aria-hidden="true" className="text-xs">{sort.key === key ? (sort.direction === 'asc' ? '↑' : '↓') : '↕'}</span>
                            </button>
                        </th>)}
                        <th scope="col" className="px-4 py-2"><span className="sr-only">Location actions</span></th>
                    </tr>
                </thead>
                <tbody className="divide-y divide-slate-100">
                    {locations.map((location) => {
                        const key = location.canonical_key;
                        const selected = selectedCity === key;
                        const open = expanded.has(key);
                        const detailId = `location-details-${encodeURIComponent(key)}`;
                        return <React.Fragment key={key}>
                            <tr className={selected ? 'bg-teal-50/60' : 'hover:bg-slate-50/60'}>
                                <th scope="row" className="min-w-48 px-3 py-3 text-left font-normal sm:px-4">
                                    <button type="button" onClick={() => onSelectCity(key)} className={`${button} text-left text-slate-900`} aria-pressed={selected}>
                                        {location.display_city}
                                    </button>
                                    <p className="px-2 text-xs text-slate-500">{location.mapped ? 'Mapped' : 'Not mapped'}{location.available_count === 0 ? ' · No available profiles' : ''}</p>
                                </th>
                                <td className="px-5 py-3 text-lg font-semibold tabular-nums text-teal-800">{formatMetric(location.available_count)}</td>
                                <td className="px-5 py-3 tabular-nums text-slate-700">{formatMetric(location.active_count)}</td>
                                <td className="px-5 py-3 tabular-nums text-slate-700">{formatMetric(location.unlockable_count)}</td>
                                <td className="px-5 py-3 tabular-nums text-slate-500">{formatMetric(location.client_count)}</td>
                                <td className="px-3 py-3">
                                    <div className="flex items-center justify-end gap-2 whitespace-nowrap">
                                        <button type="button" aria-expanded={open} aria-controls={open ? detailId : undefined}
                                            aria-label={`Details for ${location.display_city}`} onClick={() => toggleDetails(key)} className={`${button} text-slate-600`}>
                                            Details <span aria-hidden="true">{open ? '−' : '+'}</span>
                                        </button>
                                        <button type="button" onClick={() => openClients(key)} className={`${button} text-teal-700`}
                                            aria-label={`View clients in ${location.display_city}`}>View clients <span aria-hidden="true">→</span></button>
                                    </div>
                                </td>
                            </tr>
                            {open ? <tr id={detailId} className="bg-slate-50/70"><td colSpan={6} className="px-5 py-4">
                                <div className="flex flex-wrap items-start gap-x-8 gap-y-4">
                                    <div><p className="text-xs font-semibold text-slate-500">Verified · current cache</p><p className="mt-1 font-semibold">{formatMetric(location.verified_count)}</p></div>
                                    {[
                                        ['Views', location.engagement?.views, false],
                                        ['Profile-unique visits', location.engagement?.profile_unique_visits, false],
                                        ['Contact rate', location.engagement?.contact_rate, true],
                                    ].map(([label, value, percent]) => <div key={label}><p className="text-xs font-semibold text-slate-500">{label} · {periodLabel}</p><p className="mt-1 font-semibold tabular-nums">{formatMetric(value, percent)}</p></div>)}
                                    <div><p className="text-xs font-semibold text-slate-500">Performance index · {periodLabel}</p><div className="mt-1 flex items-center gap-2"><span>{formatMetric(location.performance?.index)}</span><BandBadge band={location.performance?.band} /></div></div>
                                    <div className="min-w-40 max-w-xs"><p className="mb-2 text-xs font-semibold text-slate-500">Contact channel mix · {periodLabel}</p><ChannelMixBar channels={location.channels} compact /></div>
                                </div>
                                <p className="mt-4 text-xs leading-relaxed text-slate-500">Profile-unique visits sum unique visits per profile, not unique people across the city. Performance compares views, contact rate and client count; a weak band does not mean low inventory. Verified profiles can belong to any inventory state.</p>
                            </td></tr> : null}
                        </React.Fragment>;
                    })}
                </tbody>
            </table>
        </div>
    );
}
