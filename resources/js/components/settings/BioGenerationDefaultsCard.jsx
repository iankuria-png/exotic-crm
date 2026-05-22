import React, { useMemo, useState } from 'react';

/**
 * Editorial defaults for SEO bio generation.
 *
 * Replaces freeform text inputs with curated presets, sliders, and grouped
 * controls. The shape of `value` exactly matches the backend `generation`
 * payload — this component is presentation-only, the parent owns state.
 */

const TONE_PRESETS = [
    { key: 'simple_direct', label: 'Simple & direct', value: 'simple, direct, local classified profile copy', hint: 'Tight, factual, no fluff' },
    { key: 'sensual_evocative', label: 'Sensual & evocative', value: 'sensual, evocative, hints of mystery without being explicit', hint: 'Lush sensory cues' },
    { key: 'playful_flirty', label: 'Playful & flirty', value: 'playful, flirty, light teasing energy', hint: 'Cheeky, light' },
    { key: 'warm_friendly', label: 'Warm & friendly', value: 'warm, friendly, conversational neighbour energy', hint: 'Approachable, soft' },
    { key: 'classy_elegant', label: 'Classy & elegant', value: 'classy, elegant, refined without being stuffy', hint: 'Polished but not corporate' },
    { key: 'unique_quirky', label: 'Unique & quirky', value: 'unique, quirky, with a distinctive voice', hint: 'Stand-out personality' },
];

const TEMPERAMENT_LEVELS = [
    { level: 1, label: 'Reserved & quiet',         value: 'reserved and quietly confident' },
    { level: 2, label: 'Subtle confidence',        value: 'subtly confident, understated' },
    { level: 3, label: 'Calmly confident',         value: 'calmly confident, grounded' },
    { level: 4, label: 'Steady & sure',            value: 'steady and self-assured' },
    { level: 5, label: 'Confident but measured',   value: 'confident but not exaggerated' },
    { level: 6, label: 'Self-assured',             value: 'assertive and self-assured' },
    { level: 7, label: 'Bold & direct',            value: 'bold and direct' },
    { level: 8, label: 'Confidently playful',      value: 'confidently playful and provocative' },
    { level: 9, label: 'Bold & unfiltered',        value: 'bold, unfiltered, unapologetic' },
    { level: 10, label: 'Dramatic & striking',     value: 'dramatic, striking, theatrical' },
];

const CONTACT_OPTIONS = [
    { key: 'whatsapp', label: 'WhatsApp' },
    { key: 'phone',    label: 'Phone' },
    { key: 'both',     label: 'Phone & WhatsApp' },
    { key: 'none',     label: 'None' },
];

function tonePresetForValue(value) {
    return TONE_PRESETS.find((preset) => preset.value === value);
}

function temperamentLevelForValue(value) {
    return TEMPERAMENT_LEVELS.find((preset) => preset.value === value);
}

export default function BioGenerationDefaultsCard({ value, onChange }) {
    const tonePreset = tonePresetForValue(value.tone);
    const temperamentMatch = temperamentLevelForValue(value.temperament);
    const [customTone, setCustomTone] = useState(!tonePreset);
    const [customTemperament, setCustomTemperament] = useState(!temperamentMatch);

    const temperamentLevel = useMemo(() => {
        if (temperamentMatch) return temperamentMatch.level;
        // best-effort: keep slider at the middle when user has typed a custom value
        return 5;
    }, [temperamentMatch]);

    const update = (field, val) => onChange({ ...value, [field]: val });

    const selectTonePreset = (presetValue) => {
        update('tone', presetValue);
        setCustomTone(false);
    };

    const selectTemperamentLevel = (level) => {
        const match = TEMPERAMENT_LEVELS.find((p) => p.level === level);
        if (match) {
            update('temperament', match.value);
            setCustomTemperament(false);
        }
    };

    const wordSpread = Math.max(0, (value.max_words || 0) - (value.min_words || 0));

    return (
        <section className="crm-surface p-6">
            <header className="flex flex-wrap items-start justify-between gap-3">
                <div>
                    <h3 className="text-base font-semibold text-slate-900">Bio generation defaults</h3>
                    <p className="mt-1 max-w-2xl text-sm text-slate-600">
                        These guide every generated bio. Agents can override on a single draft via the “Bio options” toggle on the Generate button.
                    </p>
                </div>
            </header>

            {/* ─── Tone preset chips ──────────────────────────────────────────── */}
            <div className="mt-6 space-y-2">
                <div className="flex items-center justify-between gap-2">
                    <label className="text-xs font-semibold uppercase tracking-[0.08em] text-slate-500">Tone</label>
                    <button
                        type="button"
                        onClick={() => setCustomTone((v) => !v)}
                        className="text-xs font-medium text-teal-700 underline decoration-dotted underline-offset-4 hover:text-teal-900"
                    >
                        {customTone ? 'Use a preset' : 'Custom tone…'}
                    </button>
                </div>
                {!customTone ? (
                    <div className="grid grid-cols-2 gap-2 sm:grid-cols-3">
                        {TONE_PRESETS.map((preset) => {
                            const active = preset.value === value.tone;
                            return (
                                <button
                                    key={preset.key}
                                    type="button"
                                    onClick={() => selectTonePreset(preset.value)}
                                    className={`group rounded-xl border px-3 py-2.5 text-left transition ${
                                        active
                                            ? 'border-teal-400 bg-teal-50 ring-1 ring-teal-300'
                                            : 'border-slate-200 bg-white hover:border-slate-300 hover:bg-slate-50'
                                    }`}
                                >
                                    <div className="text-sm font-semibold text-slate-900">{preset.label}</div>
                                    <div className="mt-0.5 text-[11px] leading-tight text-slate-500">{preset.hint}</div>
                                </button>
                            );
                        })}
                    </div>
                ) : (
                    <input
                        type="text"
                        value={value.tone}
                        onChange={(e) => update('tone', e.target.value)}
                        placeholder="Describe a custom tone (e.g., reserved, professional, with a hint of mystery)…"
                        className="w-full rounded-lg border-slate-300 text-sm focus:border-teal-500 focus:ring-teal-500"
                    />
                )}
            </div>

            {/* ─── Temperament slider ─────────────────────────────────────────── */}
            <div className="mt-6 space-y-2">
                <div className="flex items-center justify-between gap-2">
                    <label className="text-xs font-semibold uppercase tracking-[0.08em] text-slate-500">Temperament</label>
                    <button
                        type="button"
                        onClick={() => setCustomTemperament((v) => !v)}
                        className="text-xs font-medium text-teal-700 underline decoration-dotted underline-offset-4 hover:text-teal-900"
                    >
                        {customTemperament ? 'Use the slider' : 'Custom temperament…'}
                    </button>
                </div>
                {!customTemperament ? (
                    <div className="rounded-xl border border-slate-200 bg-slate-50 p-4">
                        <div className="flex items-baseline justify-between">
                            <span className="text-[11px] font-medium uppercase tracking-[0.06em] text-slate-400">Reserved</span>
                            <span className="text-sm font-semibold text-slate-900">
                                {TEMPERAMENT_LEVELS.find((p) => p.level === temperamentLevel)?.label || ''}
                            </span>
                            <span className="text-[11px] font-medium uppercase tracking-[0.06em] text-slate-400">Bold</span>
                        </div>
                        <input
                            type="range"
                            min={1}
                            max={10}
                            step={1}
                            value={temperamentLevel}
                            onChange={(e) => selectTemperamentLevel(Number(e.target.value))}
                            className="mt-2 w-full accent-teal-600"
                            aria-label="Temperament level"
                        />
                        <div className="mt-1 flex justify-between text-[10px] text-slate-400">
                            {TEMPERAMENT_LEVELS.map((p) => (
                                <span key={p.level} className={p.level === temperamentLevel ? 'font-semibold text-teal-700' : ''}>
                                    {p.level}
                                </span>
                            ))}
                        </div>
                        <p className="mt-2 text-xs text-slate-500">
                            Prompt phrase: <span className="font-medium text-slate-700">“{value.temperament}”</span>
                        </p>
                    </div>
                ) : (
                    <input
                        type="text"
                        value={value.temperament}
                        onChange={(e) => update('temperament', e.target.value)}
                        placeholder="Custom temperament phrase…"
                        className="w-full rounded-lg border-slate-300 text-sm focus:border-teal-500 focus:ring-teal-500"
                    />
                )}
            </div>

            {/* ─── Length controls ────────────────────────────────────────────── */}
            <div className="mt-6">
                <label className="text-xs font-semibold uppercase tracking-[0.08em] text-slate-500">Length</label>
                <div className="mt-2 rounded-xl border border-slate-200 bg-slate-50 p-4">
                    <div className="flex flex-wrap items-end gap-4">
                        <NumberStepper
                            label="Min words"
                            value={value.min_words}
                            min={25}
                            max={value.max_words - 5}
                            step={5}
                            onChange={(v) => update('min_words', Math.min(v, value.max_words - 5))}
                        />
                        <div className="flex flex-1 flex-col items-center text-xs text-slate-500">
                            <span className="font-semibold text-slate-700">{wordSpread} word spread</span>
                            <span className="text-[10px] uppercase tracking-[0.1em]">Target range</span>
                        </div>
                        <NumberStepper
                            label="Max words"
                            value={value.max_words}
                            min={value.min_words + 5}
                            max={700}
                            step={5}
                            onChange={(v) => update('max_words', Math.max(v, value.min_words + 5))}
                        />
                    </div>
                    <div className="mt-4 grid grid-cols-1 gap-3 sm:grid-cols-2">
                        <NumberStepper
                            label="Character ceiling"
                            value={value.max_characters}
                            min={200}
                            max={5000}
                            step={50}
                            onChange={(v) => update('max_characters', v)}
                            hint="Hard truncation if the model overshoots"
                        />
                        <NumberStepper
                            label="Services to mention"
                            value={value.max_services}
                            min={0}
                            max={20}
                            step={1}
                            onChange={(v) => update('max_services', v)}
                            hint="0 = let the model decide"
                        />
                    </div>
                </div>
            </div>

            {/* ─── Inclusions ─────────────────────────────────────────────────── */}
            <div className="mt-6">
                <label className="text-xs font-semibold uppercase tracking-[0.08em] text-slate-500">What to mention</label>
                <div className="mt-2 grid grid-cols-1 gap-2 sm:grid-cols-3">
                    <ToggleCard
                        label="Location"
                        description="Mention city / neighborhood"
                        checked={!!value.include_location}
                        onChange={(checked) => update('include_location', checked)}
                    />
                    <ToggleCard
                        label="Services"
                        description="List up to N specialities"
                        checked={!!value.include_services}
                        onChange={(checked) => update('include_services', checked)}
                    />
                    <ToggleCard
                        label="Contact"
                        description="Reference the contact button"
                        checked={!!value.include_contact}
                        onChange={(checked) => update('include_contact', checked)}
                    />
                </div>
                <div className="mt-3">
                    <label className="text-[11px] font-medium uppercase tracking-[0.06em] text-slate-500">Preferred contact channel</label>
                    <div className="mt-1 inline-flex flex-wrap gap-1 rounded-lg border border-slate-200 bg-white p-1">
                        {CONTACT_OPTIONS.map((opt) => {
                            const active = value.contact_channel === opt.key;
                            return (
                                <button
                                    key={opt.key}
                                    type="button"
                                    onClick={() => update('contact_channel', opt.key)}
                                    disabled={!value.include_contact}
                                    className={`rounded-md px-3 py-1.5 text-xs font-semibold transition disabled:cursor-not-allowed disabled:opacity-50 ${
                                        active
                                            ? 'bg-teal-600 text-white shadow-sm'
                                            : 'text-slate-600 hover:bg-slate-100'
                                    }`}
                                >
                                    {opt.label}
                                </button>
                            );
                        })}
                    </div>
                </div>
            </div>

            {/* ─── Custom prompt guardrail ────────────────────────────────────── */}
            <div className="mt-6">
                <label className="text-xs font-semibold uppercase tracking-[0.08em] text-slate-500">Custom prompt guardrail</label>
                <p className="mt-1 text-xs text-slate-500">Appended to every bio prompt. Use it for market quirks, brand voice rules, or things to avoid.</p>
                <textarea
                    rows={3}
                    value={value.custom_prompt}
                    onChange={(e) => update('custom_prompt', e.target.value)}
                    className="mt-2 w-full rounded-lg border-slate-300 text-sm focus:border-teal-500 focus:ring-teal-500"
                    placeholder="Example: Avoid luxury wording. Mention Nairobi naturally. Keep it simple and direct."
                />
            </div>
        </section>
    );
}

function NumberStepper({ label, value, min, max, step = 1, onChange, hint = null }) {
    const decrement = () => onChange(Math.max(min, value - step));
    const increment = () => onChange(Math.min(max, value + step));
    return (
        <div className="flex flex-col">
            <label className="text-[11px] font-medium uppercase tracking-[0.06em] text-slate-500">{label}</label>
            <div className="mt-1 flex items-center overflow-hidden rounded-lg border border-slate-300 bg-white">
                <button
                    type="button"
                    onClick={decrement}
                    disabled={value <= min}
                    className="px-2 py-1.5 text-slate-500 hover:bg-slate-50 disabled:cursor-not-allowed disabled:opacity-30"
                    aria-label={`Decrease ${label}`}
                >
                    −
                </button>
                <input
                    type="number"
                    min={min}
                    max={max}
                    step={step}
                    value={value}
                    onChange={(e) => onChange(Number(e.target.value))}
                    className="w-16 border-0 bg-transparent text-center text-sm font-semibold text-slate-900 focus:outline-none focus:ring-0"
                />
                <button
                    type="button"
                    onClick={increment}
                    disabled={value >= max}
                    className="px-2 py-1.5 text-slate-500 hover:bg-slate-50 disabled:cursor-not-allowed disabled:opacity-30"
                    aria-label={`Increase ${label}`}
                >
                    +
                </button>
            </div>
            {hint ? <p className="mt-1 text-[10px] text-slate-400">{hint}</p> : null}
        </div>
    );
}

function ToggleCard({ label, description, checked, onChange }) {
    return (
        <label
            className={`flex cursor-pointer items-start gap-2 rounded-lg border px-3 py-2.5 transition ${
                checked ? 'border-teal-300 bg-teal-50' : 'border-slate-200 bg-white hover:bg-slate-50'
            }`}
        >
            <input
                type="checkbox"
                checked={checked}
                onChange={(e) => onChange(e.target.checked)}
                className="mt-0.5 h-4 w-4 rounded text-teal-600 focus:ring-teal-500 border-slate-300"
            />
            <div>
                <div className="text-sm font-semibold text-slate-900">{label}</div>
                <div className="text-[11px] leading-tight text-slate-500">{description}</div>
            </div>
        </label>
    );
}
