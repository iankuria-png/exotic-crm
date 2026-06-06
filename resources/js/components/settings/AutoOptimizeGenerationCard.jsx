import React from 'react';

/**
 * Per-market generation config card, adapted from BioGenerationDefaultsCard.
 * Shows inherited value hint when the per-market value is not set.
 */

const LANGUAGE_OPTIONS = [
    { code: 'en', label: 'English',    flag: '🇬🇧' },
    { code: 'fr', label: 'French',     flag: '🇫🇷' },
    { code: 'pt', label: 'Portuguese', flag: '🇵🇹' },
    { code: 'sw', label: 'Swahili',    flag: '🇰🇪' },
];

const CONTACT_OPTIONS = [
    { key: 'whatsapp', label: 'WhatsApp' },
    { key: 'phone',    label: 'Phone' },
    { key: 'both',     label: 'Both' },
    { key: 'none',     label: 'None' },
];

function Field({ label, help, children }) {
    return (
        <div className="space-y-1">
            <label className="block text-xs font-semibold text-slate-600">{label}</label>
            {help && <p className="text-[11px] text-slate-400">{help}</p>}
            {children}
        </div>
    );
}

function InheritNote({ value, globalValue }) {
    if (value != null) return null;
    return <span className="ml-2 text-[10px] text-slate-400">(Inheriting: {String(globalValue ?? '—')})</span>;
}

export default function AutoOptimizeGenerationCard({ value = {}, globalDefaults = {}, onChange }) {
    const update = (key, val) => onChange({ ...value, [key]: val });

    // Effective value: per-market if set, else inherit from global
    const eff = (key) => value[key] ?? globalDefaults[key];

    return (
        <div className="space-y-4 rounded-lg border border-slate-200 bg-slate-50 p-4">
            <h3 className="text-sm font-semibold text-slate-700">Bio generation overrides
                <span className="ml-2 text-[11px] font-normal text-slate-400">(leave blank to inherit global SEO Engine defaults)</span>
            </h3>

            {/* Language */}
            <Field label="Default language" help="Language for generated bios in this market.">
                <div className="flex flex-wrap gap-2">
                    {LANGUAGE_OPTIONS.map(({ code, label, flag }) => (
                        <button
                            key={code}
                            type="button"
                            onClick={() => update('language', code)}
                            className={`flex items-center gap-1.5 rounded-md border px-2.5 py-1.5 text-xs font-medium transition ${
                                eff('language') === code
                                    ? 'border-teal-400 bg-teal-50 text-teal-700'
                                    : 'border-slate-200 bg-white text-slate-600 hover:border-slate-300'
                            }`}
                        >
                            <span aria-hidden="true">{flag}</span> {label}
                        </button>
                    ))}
                </div>
            </Field>

            {/* Respect existing language */}
            <label className="flex items-center gap-2 text-xs font-medium text-slate-600">
                <input
                    type="checkbox"
                    checked={eff('respect_existing_language') ?? true}
                    onChange={(e) => update('respect_existing_language', e.target.checked)}
                    className="h-3.5 w-3.5 rounded text-teal-600"
                />
                Auto-detect existing bio language and preserve it
            </label>

            {/* Tone */}
            <Field label="Tone" help="Descriptive writing tone for generated bios.">
                <input
                    type="text"
                    value={eff('tone') ?? ''}
                    onChange={(e) => update('tone', e.target.value || null)}
                    placeholder={globalDefaults.tone ?? 'e.g. simple, direct, local classified profile copy'}
                    className="crm-input w-full text-xs"
                    maxLength={255}
                />
                <InheritNote value={value.tone} globalValue={globalDefaults.tone} />
            </Field>

            {/* Word range */}
            <div className="grid grid-cols-3 gap-3">
                <Field label="Min words">
                    <input type="number" min={20} max={500} value={eff('min_words') ?? ''}
                        onChange={(e) => update('min_words', e.target.value ? Number(e.target.value) : null)}
                        placeholder={String(globalDefaults.min_words ?? 55)}
                        className="crm-input w-full text-xs" />
                </Field>
                <Field label="Max words">
                    <input type="number" min={40} max={700} value={eff('max_words') ?? ''}
                        onChange={(e) => update('max_words', e.target.value ? Number(e.target.value) : null)}
                        placeholder={String(globalDefaults.max_words ?? 95)}
                        className="crm-input w-full text-xs" />
                </Field>
                <Field label="Max chars">
                    <input type="number" min={200} max={5000} value={eff('max_characters') ?? ''}
                        onChange={(e) => update('max_characters', e.target.value ? Number(e.target.value) : null)}
                        placeholder={String(globalDefaults.max_characters ?? 750)}
                        className="crm-input w-full text-xs" />
                </Field>
            </div>

            {/* Inclusions */}
            <div className="flex flex-wrap gap-4">
                {[
                    { key: 'include_location', label: 'Include location' },
                    { key: 'include_services', label: 'Include services' },
                    { key: 'include_contact', label: 'Include contact' },
                ].map(({ key, label }) => (
                    <label key={key} className="flex items-center gap-2 text-xs font-medium text-slate-600">
                        <input
                            type="checkbox"
                            checked={eff(key) ?? true}
                            onChange={(e) => update(key, e.target.checked)}
                            className="h-3.5 w-3.5 rounded text-teal-600"
                        />
                        {label}
                    </label>
                ))}
            </div>

            {/* Contact channel */}
            <Field label="Contact channel">
                <div className="flex flex-wrap gap-2">
                    {CONTACT_OPTIONS.map(({ key, label }) => (
                        <button key={key} type="button"
                            onClick={() => update('contact_channel', key)}
                            className={`rounded-md border px-2.5 py-1 text-xs font-medium transition ${
                                eff('contact_channel') === key
                                    ? 'border-teal-400 bg-teal-50 text-teal-700'
                                    : 'border-slate-200 bg-white text-slate-600 hover:border-slate-300'
                            }`}
                        >
                            {label}
                        </button>
                    ))}
                </div>
            </Field>

            {/* Custom prompt */}
            <Field label="Custom prompt" help="Appended to every bio prompt for market-specific instructions (max 2000 chars).">
                <textarea
                    rows={2}
                    value={eff('custom_prompt') ?? ''}
                    onChange={(e) => update('custom_prompt', e.target.value || null)}
                    placeholder={globalDefaults.custom_prompt || 'Extra instructions appended to every prompt…'}
                    className="crm-input w-full text-xs"
                    maxLength={2000}
                />
            </Field>
        </div>
    );
}
