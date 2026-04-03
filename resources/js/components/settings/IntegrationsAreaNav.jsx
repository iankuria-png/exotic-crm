import React from 'react';

export default function IntegrationsAreaNav({
    integrationArea,
    integrationAreas,
    onSelect,
}) {
    return (
        <section className="crm-surface p-2">
            <div className="flex flex-wrap gap-2">
                {integrationAreas.map((area) => (
                    <button
                        key={area.id}
                        type="button"
                        onClick={() => onSelect(area.id)}
                        aria-pressed={integrationArea === area.id}
                        className={`min-h-11 rounded-lg px-3 py-2 text-left transition focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-teal-500 focus-visible:ring-offset-2 ${
                            integrationArea === area.id
                                ? 'bg-white text-slate-900 ring-1 ring-slate-200 shadow-sm'
                                : 'text-slate-600 hover:bg-slate-100 hover:text-slate-800'
                        }`}
                    >
                        <p className="text-sm font-semibold">{area.label}</p>
                        <p className="text-[11px] text-slate-500">{area.hint}</p>
                    </button>
                ))}
            </div>
        </section>
    );
}
