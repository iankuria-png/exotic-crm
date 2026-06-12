import React, { useEffect, useMemo, useRef } from 'react';
import L from 'leaflet';
import 'leaflet/dist/leaflet.css';
import { BANDS } from './locationMeta';

function clamp(value, min, max) {
    return Math.min(max, Math.max(min, value));
}

function buildTooltipNode(location) {
    const wrapper = document.createElement('div');
    wrapper.className = 'space-y-1';

    const title = document.createElement('p');
    title.className = 'text-sm font-semibold text-slate-900';
    title.textContent = location.display_city;
    wrapper.appendChild(title);

    const clients = document.createElement('p');
    clients.className = 'text-xs text-slate-600';
    clients.textContent = `${Number(location.client_count || 0).toLocaleString()} clients`;
    wrapper.appendChild(clients);

    const views = document.createElement('p');
    views.className = 'text-xs text-slate-600';
    views.textContent = `${Number(location.engagement?.views || 0).toLocaleString()} views`;
    wrapper.appendChild(views);

    return wrapper;
}

export default function ClientMap({ locations, selectedCity, onSelectCity }) {
    const mapElementRef = useRef(null);
    const mapRef = useRef(null);
    const layerGroupRef = useRef(null);
    const markerMapRef = useRef(new Map());

    const mappableLocations = useMemo(
        () => locations.filter((location) => location.latitude != null && location.longitude != null),
        [locations]
    );

    useEffect(() => {
        if (!mapElementRef.current || mapRef.current) {
            return undefined;
        }

        const map = L.map(mapElementRef.current, {
            scrollWheelZoom: false,
            zoomControl: true,
        });

        L.tileLayer('https://{s}.basemaps.cartocdn.com/light_all/{z}/{x}/{y}{r}.png', {
            attribution: '&copy; OpenStreetMap &copy; CARTO',
            maxZoom: 19,
        }).addTo(map);

        map.setView([0, 20], 4);
        mapRef.current = map;

        // Leaflet computes pane sizes from the container's measured box. If the map
        // was created while the host had a stale/zero size (e.g. hidden behind the
        // mobile Map/List toggle, or before layout settled), tiles render blank or
        // misaligned. Recompute once the layer is painted, and whenever the host
        // resizes (toggle show/hide, viewport changes).
        const invalidate = () => map.invalidateSize();
        const raf = window.requestAnimationFrame(invalidate);

        let resizeObserver = null;
        if (typeof ResizeObserver !== 'undefined') {
            resizeObserver = new ResizeObserver(invalidate);
            resizeObserver.observe(mapElementRef.current);
        }

        return () => {
            window.cancelAnimationFrame(raf);
            if (resizeObserver) {
                resizeObserver.disconnect();
            }
            map.remove();
            mapRef.current = null;
            layerGroupRef.current = null;
            markerMapRef.current = new Map();
        };
    }, []);

    useEffect(() => {
        if (!mapRef.current) {
            return;
        }

        if (layerGroupRef.current) {
            layerGroupRef.current.removeFrom(mapRef.current);
        }

        const group = L.layerGroup();
        const markerMap = new Map();

        mappableLocations.forEach((location) => {
            const band = BANDS[location.performance?.band] || BANDS.insufficient;
            const selected = selectedCity === location.canonical_key;
            const marker = L.circleMarker([location.latitude, location.longitude], {
                radius: clamp(6 + (Math.sqrt(Number(location.client_count || 0)) * 1.6), 6, 34),
                color: '#ffffff',
                weight: selected ? 3 : 1.5,
                fillColor: band.marker,
                fillOpacity: selected ? 0.9 : 0.72,
            });

            marker.bindTooltip(buildTooltipNode(location));
            marker.on('click', () => onSelectCity(location.canonical_key));
            marker.addTo(group);
            markerMap.set(location.canonical_key, marker);
        });

        group.addTo(mapRef.current);
        layerGroupRef.current = group;
        markerMapRef.current = markerMap;

        if (mappableLocations.length > 0) {
            mapRef.current.fitBounds(group.getBounds(), { padding: [30, 30] });
        } else {
            mapRef.current.setView([0, 20], 4);
        }
    }, [mappableLocations, onSelectCity]);

    useEffect(() => {
        markerMapRef.current.forEach((marker, canonicalKey) => {
            const location = mappableLocations.find((entry) => entry.canonical_key === canonicalKey);
            if (!location) {
                return;
            }

            const band = BANDS[location.performance?.band] || BANDS.insufficient;
            const selected = selectedCity === canonicalKey;

            marker.setStyle({
                color: '#ffffff',
                weight: selected ? 3 : 1.5,
                fillColor: band.marker,
                fillOpacity: selected ? 0.9 : 0.72,
            });
        });

        if (!selectedCity || !mapRef.current) {
            return;
        }

        const selectedLocation = mappableLocations.find((location) => location.canonical_key === selectedCity);
        if (!selectedLocation) {
            return;
        }

        mapRef.current.flyTo([selectedLocation.latitude, selectedLocation.longitude], Math.max(mapRef.current.getZoom(), 8), {
            duration: 0.6,
        });
    }, [mappableLocations, selectedCity]);

    return (
        <section className="overflow-hidden rounded-xl border border-slate-200 bg-white shadow-sm">
            <div className="flex flex-wrap items-center justify-between gap-3 border-b border-slate-200 px-5 py-4">
                <div>
                    <h2 className="text-sm font-semibold text-slate-800">Client map</h2>
                    <p className="mt-1 text-sm text-slate-500">Bubble size reflects client count. Color reflects relative city performance.</p>
                </div>

                <div className="flex flex-wrap items-center gap-3 text-xs text-slate-500">
                    {Object.entries(BANDS).map(([key, meta]) => (
                        <span key={key} className="inline-flex items-center gap-1.5">
                            <span className={`h-2 w-2 rounded-full ${meta.dot}`} aria-hidden="true" />
                            <span>{meta.label}</span>
                        </span>
                    ))}
                    <span className="text-slate-400">Size = clients</span>
                </div>
            </div>

            <div ref={mapElementRef} className="crm-leaflet-host h-[520px] w-full bg-slate-100" />
        </section>
    );
}
