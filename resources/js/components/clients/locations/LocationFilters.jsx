import React from 'react';
import { BANDS } from './locationMeta';
import { COUNT_OPTIONS } from './locationInventory';

const control = 'min-h-11 w-full min-w-0 rounded-lg border border-slate-300 bg-white px-3 py-2 text-base text-slate-800 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-teal-600';
const button = 'min-h-11 rounded-lg border px-3 py-2 text-sm font-semibold focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-teal-600';

export default function LocationFilters({ filters, onChange, sort, onSortChange, error, onReset }) {
    const update = (patch) => onChange({ ...filters, ...patch });
    const preset = (key) => {
        const counts = key === 'low' ? { basis: 'available_count', min: '', max: '5', inventory: 'all' }
            : key === 'inactive' ? { basis: 'active_count', min: '', max: '0', inventory: 'all' }
                : { basis: 'unlockable_count', min: '1', max: '', inventory: 'unlockable_only' };
        update(counts);
        onSortChange({ key: counts.basis, direction: 'asc' });
    };
    const presets = [
        ['low', 'Low inventory · 5 or fewer', filters.basis === 'available_count' && filters.min === '' && filters.max === '5'],
        ['inactive', 'No active profiles', filters.basis === 'active_count' && filters.max === '0'],
        ['unlockable', 'Unlockable only', filters.inventory === 'unlockable_only'],
    ];

    return (
        <div className="space-y-4 border-b border-slate-200 bg-slate-50/60 p-4 sm:p-5">
            <div className="grid min-w-0 gap-3 sm:grid-cols-2 xl:grid-cols-12">
                <label className="min-w-0 text-xs font-semibold text-slate-600 xl:col-span-4">
                    Search locations
                    <input type="search" value={filters.search} onChange={(event) => update({ search: event.target.value })}
                        placeholder="Search a city or area…" className={`${control} mt-1.5`} />
                </label>
                <label className="min-w-0 text-xs font-semibold text-slate-600 xl:col-span-2">
                    Count basis
                    <select value={filters.basis} onChange={(event) => {
                        update({ basis: event.target.value, inventory: 'all' });
                        onSortChange({ key: event.target.value, direction: 'asc' });
                    }} className={`${control} mt-1.5`}>
                        {COUNT_OPTIONS.map((option) => <option key={option.key} value={option.key}>{option.label}</option>)}
                    </select>
                </label>
                <div className="grid grid-cols-2 gap-3 xl:col-span-3">
                    {['min', 'max'].map((key) => (
                        <label key={key} className="min-w-0 text-xs font-semibold text-slate-600">
                            {key === 'min' ? 'Minimum count' : 'Maximum count'}
                            <input type="text" inputMode="numeric" value={filters[key]} placeholder="Any"
                                aria-invalid={Boolean(error)} aria-describedby={error ? 'location-count-error' : undefined}
                                onChange={(event) => update({ [key]: event.target.value })} className={`${control} mt-1.5`} />
                        </label>
                    ))}
                </div>
                <label className="min-w-0 text-xs font-semibold text-slate-600 xl:col-span-3">
                    Sort locations
                    <select value={`${sort.key}:${sort.direction}`} onChange={(event) => {
                        const [key, direction] = event.target.value.split(':');
                        onSortChange({ key, direction });
                    }} className={`${control} mt-1.5`}>
                        {[...COUNT_OPTIONS, { key: 'display_city', label: 'Location' }, { key: 'verified_count', label: 'Verified' },
                            { key: 'views', label: 'Views' }, { key: 'profile_unique_visits', label: 'Profile-unique visits' },
                            { key: 'contact_rate', label: 'Contact rate' }, { key: 'performance', label: 'Performance index' }].flatMap((option) =>
                            ['asc', 'desc'].map((direction) => <option key={`${option.key}:${direction}`} value={`${option.key}:${direction}`}>
                                {option.label} · {option.key === 'display_city' ? (direction === 'asc' ? 'A–Z' : 'Z–A') : (direction === 'asc' ? 'fewest first' : 'highest first')}
                            </option>))}
                    </select>
                </label>
            </div>
            {error ? <p id="location-count-error" role="alert" className="text-sm text-rose-700">{error}</p> : null}
            <div className="flex flex-wrap items-center gap-2">
                {presets.map(([key, label, active]) => <button key={key} type="button" aria-pressed={active}
                    onClick={() => preset(key)} className={`${button} ${active ? 'border-teal-700 bg-teal-700 text-white' : 'border-slate-300 bg-white text-slate-700 hover:border-teal-600'}`}>{label}</button>)}
                <button type="button" onClick={onReset} className={`${button} border-transparent text-teal-700 hover:bg-teal-50`}>Clear filters</button>
            </div>
            <div className="flex flex-wrap gap-3">
                <label className="flex flex-wrap items-center gap-2 text-xs font-semibold text-slate-600">
                    Map status
                    <select value={filters.mapped} onChange={(event) => update({ mapped: event.target.value })} className={`${control} !w-auto`}>
                        <option value="all">All locations</option><option value="mapped">Mapped</option><option value="unmapped">Not mapped</option>
                    </select>
                </label>
                <label className="flex flex-wrap items-center gap-2 text-xs font-semibold text-slate-600">
                    Performance
                    <select value={filters.band} onChange={(event) => update({ band: event.target.value })} className={`${control} !w-auto`}>
                        <option value="all">All bands</option>
                        {Object.entries(BANDS).map(([key, band]) => <option key={key} value={key}>{band.label}</option>)}
                    </select>
                </label>
                {filters.inventory === 'unlockable_only' ? <p className="self-center text-xs text-teal-800">Active = 0 and Unlockable ≥ 1</p> : null}
            </div>
        </div>
    );
}
