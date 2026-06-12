import React, { useMemo } from 'react';
import { useNavigate } from 'react-router-dom';
import BandBadge from './BandBadge';
import ChannelMixBar from './ChannelMixBar';
import { CHANNEL_META, CHANNEL_ORDER } from './locationMeta';

function formatPercent(value) {
    const parsed = Number(value);
    if (!Number.isFinite(parsed)) {
        return '0.0%';
    }

    return `${parsed.toFixed(1)}%`;
}

function formatCount(value) {
    return Number(value || 0).toLocaleString();
}

function SummaryRow({ label, value }) {
    return (
        <div className="rounded-lg border border-slate-100 bg-slate-50/70 px-3 py-3">
            <p className="text-[11px] font-semibold uppercase tracking-wide text-slate-500">{label}</p>
            <p className="mt-1 crm-mono text-lg font-semibold text-slate-900">{value}</p>
        </div>
    );
}

export default function CitySpotlight({
    selectedLocation,
    locations,
    onSelectCity,
    platformId,
    bestChannelInsight,
}) {
    const navigate = useNavigate();

    const ranked = useMemo(() => {
        const scored = locations.filter((location) => {
            const band = location.performance?.band;
            return band && band !== 'insufficient' && band !== 'unavailable';
        });

        return {
            top: [...scored]
                .sort((left, right) => (right.performance?.index || 0) - (left.performance?.index || 0))
                .slice(0, 3),
            watch: [...scored]
                .sort((left, right) => (left.performance?.index || 0) - (right.performance?.index || 0))
                .slice(0, 3),
        };
    }, [locations]);

    const openClients = (canonicalKey) => {
        const params = new URLSearchParams();
        params.set('tab', 'all');
        params.set('city_key', canonicalKey);
        if (platformId) {
            params.set('platform_id', String(platformId));
        }
        navigate(`/clients?${params.toString()}`);
    };

    if (!selectedLocation) {
        return (
            <aside className="rounded-xl border border-slate-200 bg-white shadow-sm">
                <div className="border-b border-slate-200 px-5 py-4">
                    <h2 className="text-sm font-semibold text-slate-800">Top and watch cities</h2>
                    <p className="mt-1 text-sm text-slate-500">Pick a city from the map or table, or start from the strongest and weakest signals.</p>
                </div>

                <div className="space-y-5 px-5 py-5">
                    <div>
                        <h3 className="text-sm font-semibold text-slate-800">Top performers</h3>
                        <div className="mt-3 space-y-2">
                            {ranked.top.length > 0 ? ranked.top.map((location) => (
                                <button
                                    key={location.canonical_key}
                                    type="button"
                                    onClick={() => onSelectCity(location.canonical_key)}
                                    className="flex w-full items-center justify-between rounded-lg border border-slate-200 px-3 py-3 text-left transition hover:border-slate-300 hover:bg-slate-50"
                                >
                                    <div>
                                        <p className="font-semibold text-slate-800">{location.display_city}</p>
                                        <p className="text-xs text-slate-500">{formatCount(location.client_count)} clients</p>
                                    </div>
                                    <BandBadge band={location.performance?.band} />
                                </button>
                            )) : <p className="text-sm text-slate-400">No scored cities yet.</p>}
                        </div>
                    </div>

                    <div>
                        <h3 className="text-sm font-semibold text-slate-800">Cities to watch</h3>
                        <div className="mt-3 space-y-2">
                            {ranked.watch.length > 0 ? ranked.watch.map((location) => (
                                <button
                                    key={location.canonical_key}
                                    type="button"
                                    onClick={() => onSelectCity(location.canonical_key)}
                                    className="flex w-full items-center justify-between rounded-lg border border-slate-200 px-3 py-3 text-left transition hover:border-slate-300 hover:bg-slate-50"
                                >
                                    <div>
                                        <p className="font-semibold text-slate-800">{location.display_city}</p>
                                        <p className="text-xs text-slate-500">{formatPercent(location.engagement?.contact_rate || 0)} contact rate</p>
                                    </div>
                                    <BandBadge band={location.performance?.band} />
                                </button>
                            )) : <p className="text-sm text-slate-400">No scored cities yet.</p>}
                        </div>
                    </div>

                    {bestChannelInsight ? (
                        <div className="rounded-lg border border-teal-100 bg-teal-50/70 px-4 py-3 text-sm text-teal-900">
                            <p className="font-semibold">{bestChannelInsight.title}</p>
                            <p className="mt-1">{bestChannelInsight.body}</p>
                        </div>
                    ) : null}
                </div>
            </aside>
        );
    }

    const engagement = selectedLocation.engagement || {};

    return (
        <aside className="rounded-xl border border-slate-200 bg-white shadow-sm">
            <div className="border-b border-slate-200 px-5 py-4">
                <div className="flex flex-wrap items-center gap-3">
                    <h2 className="text-sm font-semibold text-slate-800">{selectedLocation.display_city}</h2>
                    <BandBadge band={selectedLocation.performance?.band} />
                </div>
                {!selectedLocation.mapped ? <p className="mt-2 text-xs text-slate-400">Not on the map yet.</p> : null}
            </div>

            <div className="space-y-5 px-5 py-5">
                <div className="grid gap-3 sm:grid-cols-3 xl:grid-cols-1">
                    <SummaryRow label="Total clients" value={formatCount(selectedLocation.client_count)} />
                    <SummaryRow label="Active" value={formatCount(selectedLocation.active_count)} />
                    <SummaryRow label="Verified" value={formatCount(selectedLocation.verified_count)} />
                </div>

                <div>
                    <h3 className="text-sm font-semibold text-slate-800">Engagement</h3>
                    <div className="mt-3 grid gap-3 sm:grid-cols-3 xl:grid-cols-1">
                        <SummaryRow label="Views" value={formatCount(engagement.views)} />
                        <SummaryRow
                            label="Profile-unique visits"
                            value={engagement.profile_unique_visits == null ? 'Unavailable' : formatCount(engagement.profile_unique_visits)}
                        />
                        <SummaryRow label="Contact rate" value={formatPercent(engagement.contact_rate || 0)} />
                    </div>
                </div>

                <div>
                    <div className="flex items-center justify-between gap-3">
                        <h3 className="text-sm font-semibold text-slate-800">Channel mix</h3>
                        {selectedLocation.top_channel ? (
                            <span className={`text-xs font-semibold ${CHANNEL_META[selectedLocation.top_channel]?.text || 'text-slate-500'}`}>
                                {CHANNEL_META[selectedLocation.top_channel]?.label} leads
                            </span>
                        ) : null}
                    </div>
                    <div className="mt-3">
                        <ChannelMixBar channels={selectedLocation.channels} />
                    </div>
                    {selectedLocation.channels && !selectedLocation.top_channel ? (
                        <p className="mt-2 text-xs text-slate-400">No clear winner in contact intent.</p>
                    ) : null}
                    {!selectedLocation.channels ? <p className="mt-2 text-xs text-slate-400">Channels unavailable for this market right now.</p> : null}
                </div>

                <button
                    type="button"
                    onClick={() => openClients(selectedLocation.canonical_key)}
                    className="inline-flex items-center gap-2 text-sm font-semibold text-teal-700 transition hover:text-teal-800 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-teal-500"
                >
                    View {selectedLocation.client_count.toLocaleString()} clients {'->'}
                </button>
            </div>
        </aside>
    );
}
