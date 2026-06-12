import React from 'react';
import { useNavigate } from 'react-router-dom';
import BandBadge from './BandBadge';
import ChannelMixBar from './ChannelMixBar';

function formatPercent(value) {
    const parsed = Number(value);
    if (!Number.isFinite(parsed)) {
        return '0.0%';
    }

    return `${parsed.toFixed(1)}%`;
}

export default function LocationTable({
    locations,
    selectedCity,
    onSelectCity,
    sort,
    onSortChange,
    platformId,
}) {
    const navigate = useNavigate();

    const sortableColumns = new Set(['client_count', 'views', 'contact_rate', 'performance']);

    const toggleSort = (key) => {
        if (!sortableColumns.has(key)) {
            return;
        }

        if (sort.key === key) {
            onSortChange({
                key,
                direction: sort.direction === 'desc' ? 'asc' : 'desc',
            });
            return;
        }

        onSortChange({
            key,
            direction: key === 'display_city' ? 'asc' : 'desc',
        });
    };

    const openClients = (canonicalKey) => {
        const params = new URLSearchParams();
        params.set('tab', 'all');
        params.set('city_key', canonicalKey);
        if (platformId) {
            params.set('platform_id', String(platformId));
        }
        navigate(`/clients?${params.toString()}`);
    };

    return (
        <section className="rounded-xl border border-slate-200 bg-white shadow-sm">
            <div className="border-b border-slate-200 px-5 py-4">
                <h2 className="text-sm font-semibold text-slate-800">All locations</h2>
                <p className="mt-1 text-sm text-slate-500">Every city, including those not yet on the map.</p>
            </div>

            <div className="overflow-x-auto">
                <table className="min-w-full divide-y divide-slate-200 text-sm">
                    <thead className="bg-slate-50 text-left text-xs font-semibold uppercase tracking-[0.12em] text-slate-500">
                        <tr>
                            <th className="px-4 py-3">City</th>
                            {[
                                ['client_count', 'Clients'],
                                ['active_count', 'Active'],
                                ['views', 'Views'],
                                ['profile_unique_visits', 'Profile-unique'],
                                ['contact_rate', 'Contact rate'],
                                ['mix', 'Mix'],
                                ['performance', 'Index'],
                            ].map(([key, label]) => (
                                <th key={key} className="px-4 py-3">
                                    {sortableColumns.has(key) ? (
                                        <button
                                            type="button"
                                            onClick={() => toggleSort(key)}
                                            className="inline-flex items-center gap-1 text-left transition hover:text-slate-700"
                                        >
                                            <span>{label}</span>
                                            {sort.key === key ? <span>{sort.direction === 'desc' ? 'v' : '^'}</span> : null}
                                        </button>
                                    ) : label}
                                </th>
                            ))}
                            <th className="px-4 py-3" />
                        </tr>
                    </thead>
                    <tbody className="divide-y divide-slate-100">
                        {locations.map((location) => {
                            const engagement = location.engagement || {};
                            const selected = selectedCity === location.canonical_key;

                            return (
                                <tr
                                    key={location.canonical_key}
                                    role="button"
                                    tabIndex={0}
                                    aria-pressed={selected}
                                    onClick={() => onSelectCity(location.canonical_key)}
                                    onKeyDown={(event) => {
                                        if (event.key === 'Enter' || event.key === ' ') {
                                            event.preventDefault();
                                            onSelectCity(location.canonical_key);
                                        }
                                    }}
                                    className={`cursor-pointer transition hover:bg-slate-50 ${selected ? 'bg-teal-50/60' : ''}`}
                                >
                                    <td className="px-4 py-3">
                                        <div className="flex items-center gap-2">
                                            <span className="text-slate-400" aria-hidden="true">+</span>
                                            <div>
                                                <p className="font-semibold text-slate-800">{location.display_city}</p>
                                                {!location.mapped ? <p className="text-[10px] uppercase tracking-wide text-slate-400">Off-map</p> : null}
                                            </div>
                                        </div>
                                    </td>
                                    <td className="px-4 py-3 crm-mono text-slate-700">{location.client_count.toLocaleString()}</td>
                                    <td className="px-4 py-3 crm-mono text-slate-700">{location.active_count.toLocaleString()}</td>
                                    <td className="px-4 py-3 crm-mono text-slate-700">{Number(engagement.views || 0).toLocaleString()}</td>
                                    <td className="px-4 py-3 crm-mono text-slate-700">
                                        {engagement.profile_unique_visits == null
                                            ? <span className="text-slate-400">Unavailable</span>
                                            : Number(engagement.profile_unique_visits).toLocaleString()}
                                    </td>
                                    <td className="px-4 py-3 crm-mono text-slate-700">{formatPercent(engagement.contact_rate || 0)}</td>
                                    <td className="px-4 py-3">
                                        <ChannelMixBar channels={location.channels} compact />
                                    </td>
                                    <td className="px-4 py-3">
                                        <BandBadge band={location.performance?.band} />
                                    </td>
                                    <td className="px-4 py-3 text-right">
                                        <button
                                            type="button"
                                            onClick={(event) => {
                                                event.stopPropagation();
                                                openClients(location.canonical_key);
                                            }}
                                            className="text-xs font-semibold text-teal-700 transition hover:text-teal-800 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-teal-500"
                                        >
                                            View {'->'}
                                        </button>
                                    </td>
                                </tr>
                            );
                        })}
                    </tbody>
                </table>
            </div>
        </section>
    );
}
