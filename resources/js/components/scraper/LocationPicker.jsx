import React, { useId, useMemo, useRef, useState } from 'react';

const norm = (v) => String(v || '').normalize('NFD').replace(/\p{Mn}+/gu, '').toLowerCase().replace(/[^\p{L}\p{N}]+/gu, ' ').trim();

/**
 * Multi-select of places: chips for what's chosen, a type-ahead that suggests places
 * found on the site (with how many profiles mention each), and free text for anything else.
 */
export default function LocationPicker({ value = [], onChange, suggestions = [], disabled = false, label = 'Places', autoFocus = false }) {
    const [text, setText] = useState('');
    const [open, setOpen] = useState(false);
    const [active, setActive] = useState(0);
    const [showAll, setShowAll] = useState(false);
    const inputRef = useRef(null);
    const listId = useId();

    const chosen = useMemo(() => new Set(value.map(norm)), [value]);
    const counts = useMemo(() => Object.fromEntries(suggestions.map((s) => [norm(s.label), s.count])), [suggestions]);
    const matches = useMemo(() => {
        const q = norm(text);
        return suggestions.filter((s) => !chosen.has(norm(s.label)) && (!q || norm(s.label).includes(q))).slice(0, 8);
    }, [suggestions, text, chosen]);

    const add = (place) => {
        const clean = String(place || '').replace(/\s+/g, ' ').trim();
        if (!clean || chosen.has(norm(clean)) || value.length >= 20) return;
        onChange([...value, clean]);
        setText('');
        setActive(0);
        inputRef.current?.focus();
    };
    const remove = (place) => onChange(value.filter((v) => norm(v) !== norm(place)));

    const onKeyDown = (e) => {
        if (e.key === 'ArrowDown') { e.preventDefault(); setOpen(true); setActive((i) => Math.min(i + 1, Math.max(matches.length - 1, 0))); }
        else if (e.key === 'ArrowUp') { e.preventDefault(); setActive((i) => Math.max(i - 1, 0)); }
        else if (e.key === 'Enter' || e.key === ',') {
            e.preventDefault();
            const pick = open ? matches[active] : null;
            if (pick && (!norm(text) || norm(pick.label).startsWith(norm(text)))) add(pick.label);
            else if (text.trim()) add(text);
        } else if (e.key === 'Backspace' && !text && value.length) remove(value[value.length - 1]);
        else if (e.key === 'Escape' && open && (matches.length > 0 || text.trim())) {
            // Close only the list; the surrounding dialog stays open.
            e.stopPropagation();
            setOpen(false);
        }
    };

    const quick = suggestions.filter((s) => !chosen.has(norm(s.label)));
    const visibleQuick = showAll ? quick.slice(0, 30) : quick.slice(0, 10);

    return (
        <div className="space-y-2">
            <div
                className={`relative flex min-h-[2.5rem] flex-wrap items-center gap-1.5 rounded-lg border bg-white px-2 py-1.5 ${disabled ? 'border-slate-200 bg-slate-50' : 'border-slate-300 focus-within:border-teal-600 focus-within:ring-2 focus-within:ring-teal-100'}`}
                onClick={() => inputRef.current?.focus()}
            >
                {value.map((place) => (
                    <span key={place} className="inline-flex items-center gap-1 rounded-md bg-teal-700 py-0.5 pl-2 pr-1 text-xs font-semibold text-white">
                        <span aria-hidden="true">📍</span>{place}
                        {counts[norm(place)] ? <span className="tabular-nums text-teal-100">{counts[norm(place)]}</span> : null}
                        {!disabled ? (
                            <button type="button" className="ml-0.5 rounded px-1 text-teal-100 hover:bg-teal-800 hover:text-white focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-white" aria-label={`Remove ${place}`} onClick={(e) => { e.stopPropagation(); remove(place); }}>×</button>
                        ) : null}
                    </span>
                ))}
                <input
                    ref={inputRef}
                    type="text"
                    role="combobox"
                    aria-label={label}
                    aria-expanded={open && matches.length > 0}
                    aria-controls={listId}
                    aria-autocomplete="list"
                    autoFocus={autoFocus}
                    disabled={disabled}
                    className="min-w-[10rem] flex-1 border-0 bg-transparent px-1 py-0.5 text-sm text-slate-800 placeholder:text-slate-400 focus:outline-none focus:ring-0"
                    placeholder={value.length ? 'Add another place…' : 'Type a place, e.g. Westlands'}
                    value={text}
                    onChange={(e) => { setText(e.target.value); setOpen(true); setActive(0); }}
                    onBlur={() => setTimeout(() => setOpen(false), 120)}
                    onKeyDown={onKeyDown}
                />
                {open && !disabled && (matches.length > 0 || text.trim()) ? (
                    <ul id={listId} role="listbox" className="absolute left-0 right-0 top-full z-20 mt-1 max-h-64 overflow-y-auto rounded-lg border border-slate-200 bg-white py-1 shadow-lg">
                        {matches.map((s, i) => (
                            <li key={s.label} role="option" aria-selected={i === active}>
                                <button type="button" onMouseDown={(e) => e.preventDefault()} onClick={() => add(s.label)} onMouseEnter={() => setActive(i)}
                                    className={`flex w-full items-center justify-between px-3 py-1.5 text-left text-sm ${i === active ? 'bg-teal-50 text-teal-900' : 'text-slate-700'}`}>
                                    <span>{s.label}</span>
                                    <span className="tabular-nums text-xs text-slate-500">{s.count} profile{s.count === 1 ? '' : 's'}</span>
                                </button>
                            </li>
                        ))}
                        {text.trim() && !matches.some((s) => norm(s.label) === norm(text)) ? (
                            <li role="option" aria-selected={false}>
                                <button type="button" onMouseDown={(e) => e.preventDefault()} onClick={() => add(text)} className="w-full px-3 py-1.5 text-left text-sm text-slate-600 hover:bg-slate-50">
                                    Use “{text.trim()}”
                                </button>
                            </li>
                        ) : null}
                    </ul>
                ) : null}
            </div>
            {!disabled && visibleQuick.length > 0 ? (
                <div className="flex flex-wrap items-center gap-1.5">
                    <span className="text-[11px] font-semibold uppercase tracking-[0.08em] text-slate-500">On this site</span>
                    {visibleQuick.map((s) => (
                        <button key={s.label} type="button" onClick={() => add(s.label)}
                            className="inline-flex items-center gap-1 rounded-full border border-slate-200 bg-white px-2 py-0.5 text-xs text-slate-700 hover:border-teal-600 hover:text-teal-800 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-teal-600">
                            + {s.label} <span className="tabular-nums text-slate-400">{s.count}</span>
                        </button>
                    ))}
                    {quick.length > 10 ? (
                        <button type="button" className="text-xs font-semibold text-teal-700 hover:underline" onClick={() => setShowAll((v) => !v)}>
                            {showAll ? 'Fewer' : `${Math.min(quick.length, 30) - 10} more`}
                        </button>
                    ) : null}
                </div>
            ) : null}
        </div>
    );
}

export function mergeLocationSuggestions(...lists) {
    const map = new Map();
    lists.flat().filter(Boolean).forEach((s) => {
        const key = norm(s.label);
        if (!key) return;
        const prev = map.get(key);
        if (!prev || s.count > prev.count) map.set(key, { label: s.label, count: s.count });
    });
    return [...map.values()].sort((a, b) => b.count - a.count);
}
