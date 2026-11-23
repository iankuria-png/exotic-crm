import React, { useEffect, useMemo, useState } from 'react';
import LocationPicker, { mergeLocationSuggestions } from './LocationPicker';
import { Spinner, useScrollLock } from './scraperUi';

const norm = (v) => String(v || '').normalize('NFD').replace(/\p{Mn}+/gu, '').toLowerCase().replace(/[^\p{L}\p{N}]+/gu, ' ').trim();

/**
 * Run options: preview vs import, and where — the whole site or only chosen places.
 * Opens prefilled with the site's default places (or a place picked from the overview).
 */
export default function RunDialog({ open, site, initialMode = 'dry_run', initialLocations = null, canManage, busy, onClose, onStart }) {
    const [mode, setMode] = useState(initialMode);
    const [scope, setScope] = useState('all');
    const [places, setPlaces] = useState([]);
    const [includeUnknown, setIncludeUnknown] = useState(false);
    const [saveDefault, setSaveDefault] = useState(false);
    useScrollLock(open);

    const defaults = site?.crawl_settings?.locations || site?.default_locations || [];

    useEffect(() => {
        if (!open || !site) return;
        const start = initialLocations ?? defaults;
        setMode(initialMode);
        setPlaces(start);
        setScope(start.length ? 'places' : 'all');
        setIncludeUnknown(!!site.crawl_settings?.include_unknown_location);
        setSaveDefault(false);
    }, [open, site?.id]); // eslint-disable-line react-hooks/exhaustive-deps

    useEffect(() => {
        const onKey = (e) => { if (e.key === 'Escape' && open) onClose(); };
        window.addEventListener('keydown', onKey);
        return () => window.removeEventListener('keydown', onKey);
    }, [open, onClose]);

    const suggestions = useMemo(
        () => mergeLocationSuggestions(site?.site_profile?.locations || [], site?.latest_run?.result?.locations || []),
        [site],
    );
    const pageRows = site?.site_profile?.counts?.rows || 0;
    const withLocation = site?.site_profile?.with_location ?? null;
    const estimate = useMemo(() => {
        if (scope !== 'places' || !places.length) return null;
        const counts = places.map((p) => suggestions.find((s) => norm(s.label) === norm(p))?.count || 0);
        return Math.min(pageRows || Infinity, counts.reduce((a, b) => a + b, 0));
    }, [scope, places, suggestions, pageRows]);

    const filtering = scope === 'places' && places.length > 0;
    const defaultChanged = JSON.stringify((filtering ? places : []).map(norm)) !== JSON.stringify(defaults.map(norm));

    if (!open || !site) return null;

    const submit = (e) => {
        e.preventDefault();
        onStart({
            mode,
            locations: filtering ? places : [],
            include_unknown_location: filtering && includeUnknown,
            save_as_default: canManage && saveDefault,
        });
    };

    return (
        <div className="fixed inset-0 z-50 flex items-end justify-center bg-slate-900/45 p-0 sm:items-center sm:p-4" onMouseDown={onClose}>
            <form role="dialog" aria-modal="true" aria-labelledby="run-dialog-title" onSubmit={submit} onMouseDown={(e) => e.stopPropagation()}
                className="flex max-h-[92vh] w-full max-w-xl flex-col overflow-hidden rounded-t-2xl bg-white shadow-2xl sm:rounded-xl">
                <header className="crm-panel-header">
                    <div>
                        <h3 id="run-dialog-title" className="crm-panel-title">Run {site.name}</h3>
                        <p className="crm-panel-subtitle">Choose what to collect. Nothing reaches the CRM until you import.</p>
                    </div>
                    <button type="button" className="crm-btn-secondary px-3 py-1.5" onClick={onClose}>Close</button>
                </header>

                <div className="flex-1 space-y-5 overflow-y-auto overscroll-contain px-4 py-4">
                    <fieldset>
                        <legend className="text-sm font-semibold text-slate-900">What should happen</legend>
                        <div className="mt-2 grid gap-2 sm:grid-cols-2">
                            {[
                                ['dry_run', 'Preview first', 'Collect profiles for you to review and pick.'],
                                ['import', 'Run & import', 'Every new matching profile becomes a lead.'],
                            ].map(([key, title, hint]) => (
                                <label key={key} className={`flex cursor-pointer flex-col rounded-lg border p-3 text-sm ${mode === key ? 'border-teal-600 bg-teal-50/60 ring-1 ring-teal-600' : 'border-slate-200 hover:bg-slate-50'}`}>
                                    <span className="flex items-center gap-2 font-semibold text-slate-900">
                                        <input type="radio" name="mode" value={key} checked={mode === key} onChange={() => setMode(key)} />
                                        {title}
                                    </span>
                                    <span className="mt-1 text-xs text-slate-500">{hint}</span>
                                </label>
                            ))}
                        </div>
                    </fieldset>

                    <fieldset>
                        <legend className="text-sm font-semibold text-slate-900">Where</legend>
                        <div className="mt-2 flex flex-wrap gap-2" role="radiogroup">
                            {[['all', 'Everywhere on this site'], ['places', 'Only these places']].map(([key, title]) => (
                                <label key={key} className={`inline-flex cursor-pointer items-center gap-2 rounded-full border px-3 py-1.5 text-sm font-medium ${scope === key ? 'border-slate-800 bg-slate-800 text-white' : 'border-slate-200 text-slate-700 hover:bg-slate-50'}`}>
                                    <input type="radio" name="scope" value={key} className="sr-only" checked={scope === key} onChange={() => setScope(key)} />
                                    {title}
                                </label>
                            ))}
                        </div>

                        {scope === 'places' ? (
                            <div className="mt-3 space-y-3">
                                <LocationPicker value={places} onChange={setPlaces} suggestions={suggestions} label="Places to keep" autoFocus={!places.length} />
                                <p className="text-xs text-slate-500" aria-live="polite">
                                    {filtering
                                        ? (estimate !== null && pageRows
                                            ? <>About <strong className="tabular-nums text-slate-800">{estimate}</strong> of {pageRows} profiles on the first page match. Other pages are filtered the same way.</>
                                            : 'Profiles are kept when their listed location mentions one of these places.')
                                        : 'Pick at least one place, or switch back to everywhere.'}
                                    {withLocation !== null && pageRows && withLocation < pageRows ? ` ${pageRows - withLocation} profiles on the first page list no location.` : ''}
                                </p>
                                {filtering ? (
                                    <label className="flex items-start gap-2 text-sm text-slate-700">
                                        <input type="checkbox" className="mt-0.5" checked={includeUnknown} onChange={(e) => setIncludeUnknown(e.target.checked)} />
                                        Also keep profiles that don’t list a location
                                    </label>
                                ) : null}
                                {suggestions.length === 0 ? (
                                    <p className="rounded-md bg-amber-50 px-3 py-2 text-xs text-amber-900">This site hasn’t shown any locations yet. Re-check it, or set a city rule in Configure, then places will be suggested here.</p>
                                ) : null}
                            </div>
                        ) : null}
                    </fieldset>

                    {canManage && (defaultChanged || saveDefault) ? (
                        <label className="flex items-start gap-2 rounded-lg border border-slate-200 bg-slate-50 px-3 py-2 text-sm text-slate-700">
                            <input type="checkbox" className="mt-0.5" checked={saveDefault} onChange={(e) => setSaveDefault(e.target.checked)} />
                            <span>
                                Make this the default for {site.name}
                                <span className="block text-xs text-slate-500">{filtering ? `Future runs start with ${places.join(', ')}.` : 'Future runs start with everywhere.'}</span>
                            </span>
                        </label>
                    ) : null}
                </div>

                <footer className="flex items-center justify-between gap-3 border-t border-slate-200 px-4 py-3">
                    <p className="min-w-0 truncate text-xs text-slate-500">{filtering ? `📍 ${places.join(', ')}` : 'All locations'}</p>
                    <div className="flex shrink-0 gap-2">
                        <button type="button" className="crm-btn-secondary" onClick={onClose}>Cancel</button>
                        <button type="submit" className="crm-btn-primary disabled:cursor-not-allowed disabled:opacity-50" disabled={busy || (scope === 'places' && !places.length)}>
                            {busy ? <><Spinner className="mr-2 h-4 w-4" />Starting…</> : mode === 'import' ? 'Start run & import' : 'Start preview'}
                        </button>
                    </div>
                </footer>
            </form>
        </div>
    );
}
