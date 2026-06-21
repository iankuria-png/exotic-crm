import React, { useEffect, useId, useMemo, useRef, useState } from 'react';

function decodeHtmlEntities(value) {
    const raw = String(value ?? '');
    if (!raw || typeof document === 'undefined') {
        return raw;
    }

    const textarea = document.createElement('textarea');
    textarea.innerHTML = raw;
    return textarea.value;
}

function normalizeOption(option = {}) {
    return {
        ...option,
        label: decodeHtmlEntities(option.label),
        inputLabel: decodeHtmlEntities(option.inputLabel ?? option.label),
        secondaryLabel: decodeHtmlEntities(option.secondaryLabel ?? ''),
        badge: decodeHtmlEntities(option.badge ?? ''),
        searchText: decodeHtmlEntities(option.searchText ?? ''),
    };
}

function flattenOptions(groups) {
    const items = [];
    groups.forEach((group) => {
        const options = Array.isArray(group?.options) ? group.options : [];
        options.forEach((option) => {
            items.push({
                ...normalizeOption(option),
                groupLabel: group.label || '',
            });
        });
    });

    return items;
}

export default function Combobox({
    label = null,
    value,
    onChange,
    groups = [],
    placeholder = 'Select an option',
    searchPlaceholder = 'Search…',
    emptyMessage = 'No matches found.',
    loading = false,
    disabled = false,
    allowClear = true,
    hint = null,
    className = '',
    inputRef: externalInputRef = null,
    openOnFocus = true,
    blurOnSelect = true,
}) {
    const rootRef = useRef(null);
    const inputRef = useRef(null);
    const listboxId = useId();
    const labelId = useId();
    const [open, setOpen] = useState(false);
    const [query, setQuery] = useState('');
    const [activeIndex, setActiveIndex] = useState(0);

    const normalizedGroups = useMemo(() => {
        return groups.map((group) => ({
            ...group,
            options: (group.options || []).map((option) => normalizeOption(option)),
        }));
    }, [groups]);

    const flatOptions = useMemo(() => flattenOptions(normalizedGroups), [normalizedGroups]);
    const selectedOption = flatOptions.find((option) => String(option.value) === String(value)) || null;

    const filteredGroups = useMemo(() => {
        const needle = query.trim().toLowerCase();
        if (!needle) {
            return normalizedGroups;
        }

        return normalizedGroups
            .map((group) => ({
                ...group,
                options: (group.options || []).filter((option) => {
                    const haystack = `${option.label || ''} ${option.secondaryLabel || ''} ${option.searchText || ''}`.toLowerCase();
                    return haystack.includes(needle);
                }),
            }))
            .filter((group) => (group.options || []).length > 0);
    }, [normalizedGroups, query]);

    const filteredOptions = useMemo(() => flattenOptions(filteredGroups), [filteredGroups]);

    useEffect(() => {
        if (!open) {
            return undefined;
        }

        const handlePointer = (event) => {
            if (rootRef.current && !rootRef.current.contains(event.target)) {
                setOpen(false);
                setQuery('');
            }
        };

        document.addEventListener('mousedown', handlePointer);
        return () => document.removeEventListener('mousedown', handlePointer);
    }, [open]);

    useEffect(() => {
        if (!open) {
            return;
        }

        setActiveIndex(0);
    }, [open, query]);

    const commit = (option) => {
        onChange?.(option?.value ?? null, option || null);
        setOpen(false);
        setQuery('');
        if (blurOnSelect) {
            requestAnimationFrame(() => inputRef.current?.blur());
        }
    };

    const selectedLabel = selectedOption?.inputLabel || selectedOption?.label || '';
    const displayValue = open
        ? (query !== '' ? query : selectedLabel)
        : selectedLabel;

    return (
        <div ref={rootRef} className={`space-y-1 ${className}`}>
            {label ? (
                <label id={labelId} className="block text-xs font-semibold uppercase tracking-[0.08em] text-slate-500">
                    {label}
                </label>
            ) : null}
            <div className="relative">
                <div className={`flex items-center rounded-xl border border-slate-200 bg-white shadow-sm transition focus-within:border-teal-500 focus-within:ring-2 focus-within:ring-teal-100 ${disabled ? 'bg-slate-100 opacity-70' : ''}`}>
                    <input
                        ref={(node) => {
                            inputRef.current = node;
                            if (externalInputRef) {
                                externalInputRef.current = node;
                            }
                        }}
                        type="text"
                        value={displayValue}
                        onFocus={() => {
                            if (!disabled) {
                                if (openOnFocus) {
                                    setOpen(true);
                                }
                                if (selectedLabel && query === '') {
                                    requestAnimationFrame(() => inputRef.current?.select());
                                }
                            }
                        }}
                        onClick={() => {
                            if (!disabled) {
                                setOpen(true);
                            }
                        }}
                        onChange={(event) => {
                            setQuery(event.target.value);
                            if (!open) {
                                setOpen(true);
                            }
                        }}
                        onKeyDown={(event) => {
                            if (event.key === 'ArrowDown') {
                                event.preventDefault();
                                setOpen(true);
                                setActiveIndex((current) => Math.min(current + 1, Math.max(0, filteredOptions.length - 1)));
                            } else if (event.key === 'ArrowUp') {
                                event.preventDefault();
                                setActiveIndex((current) => Math.max(0, current - 1));
                            } else if (event.key === 'Enter') {
                                if (open && filteredOptions[activeIndex]) {
                                    event.preventDefault();
                                    commit(filteredOptions[activeIndex]);
                                }
                            } else if (event.key === 'Escape') {
                                setOpen(false);
                                setQuery('');
                            }
                        }}
                        placeholder={selectedOption ? selectedLabel : placeholder}
                        className="crm-input min-h-[46px] flex-1 border-0 bg-transparent pr-10 shadow-none focus:ring-0"
                        aria-autocomplete="list"
                        aria-controls={listboxId}
                        aria-activedescendant={open && filteredOptions[activeIndex] ? `${listboxId}-${filteredOptions[activeIndex].value}` : undefined}
                        aria-expanded={open}
                        aria-labelledby={label ? labelId : undefined}
                        disabled={disabled}
                    />
                    {allowClear && selectedOption && !disabled ? (
                        <button
                            type="button"
                            onClick={() => commit(null)}
                            className="mr-2 rounded-full p-1 text-slate-400 transition hover:bg-slate-100 hover:text-slate-700"
                            aria-label={`Clear ${label || 'selection'}`}
                        >
                            <svg viewBox="0 0 20 20" className="h-4 w-4 fill-current" aria-hidden="true">
                                <path d="M5.2 5.2a.75.75 0 011.06 0L10 8.94l3.74-3.74a.75.75 0 111.06 1.06L11.06 10l3.74 3.74a.75.75 0 01-1.06 1.06L10 11.06l-3.74 3.74a.75.75 0 01-1.06-1.06L8.94 10 5.2 6.26a.75.75 0 010-1.06z" />
                            </svg>
                        </button>
                    ) : null}
                </div>

                {open ? (
                    <div className="absolute z-30 mt-2 w-full overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-2xl">
                        <div className="border-b border-slate-100 px-3 py-2 text-[11px] font-semibold uppercase tracking-[0.12em] text-slate-400">
                            {loading ? 'Loading' : searchPlaceholder}
                        </div>
                        <div id={listboxId} role="listbox" className="max-h-72 overflow-auto py-2">
                            {loading ? (
                                <div className="px-3 py-6 text-sm text-slate-500">Loading options…</div>
                            ) : filteredOptions.length === 0 ? (
                                <div className="px-3 py-6 text-sm text-slate-500">{emptyMessage}</div>
                            ) : filteredGroups.map((group) => (
                                <div key={group.label || 'default'} className="px-2 pb-2 last:pb-0">
                                    {group.label ? (
                                        <div className="px-2 py-1 text-[11px] font-semibold uppercase tracking-[0.12em] text-slate-400">
                                            {group.label}
                                        </div>
                                    ) : null}
                                    {(group.options || []).map((option) => {
                                        const index = filteredOptions.findIndex((candidate) => String(candidate.value) === String(option.value));
                                        const active = index === activeIndex;
                                        const selected = String(option.value) === String(value);

                                        return (
                                            <button
                                                key={option.value}
                                                id={`${listboxId}-${option.value}`}
                                                type="button"
                                                role="option"
                                                aria-selected={selected}
                                                onMouseDown={(event) => event.preventDefault()}
                                                onMouseEnter={() => setActiveIndex(index)}
                                                onClick={() => commit(option)}
                                                className={`flex w-full items-start justify-between gap-3 rounded-xl px-3 py-2 text-left text-sm transition ${active ? 'bg-teal-50 text-teal-800' : 'text-slate-700 hover:bg-slate-50'} ${selected ? 'font-semibold' : ''}`}
                                            >
                                                <span className="min-w-0 flex-1">
                                                    <span className="block truncate">{option.label}</span>
                                                    {option.secondaryLabel ? (
                                                        <span className={`mt-0.5 block truncate text-xs ${active ? 'text-teal-700/80' : 'text-slate-500'}`}>
                                                            {option.secondaryLabel}
                                                        </span>
                                                    ) : null}
                                                </span>
                                                <span className="flex shrink-0 items-center gap-2">
                                                    {option.badge ? (
                                                        <span className={`rounded-full px-2 py-0.5 text-[10px] font-semibold uppercase tracking-[0.14em] ${active ? 'bg-white/80 text-teal-700' : 'bg-slate-100 text-slate-500'}`}>
                                                            {option.badge}
                                                        </span>
                                                    ) : null}
                                                    {selected ? <span className="text-[11px] uppercase tracking-[0.12em] text-teal-600">Selected</span> : null}
                                                </span>
                                            </button>
                                        );
                                    })}
                                </div>
                            ))}
                        </div>
                    </div>
                ) : null}
            </div>
            {hint ? <p className="text-xs text-slate-500">{hint}</p> : null}
        </div>
    );
}
