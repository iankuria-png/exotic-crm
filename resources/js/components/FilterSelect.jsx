import React from 'react';

export default function FilterSelect({ label, value, onChange, options, className = '' }) {
    const isActive = value !== '' && value !== undefined && value !== null;

    return (
        <div className={`flex flex-col gap-1 ${className}`}>
            {label ? (
                <span className="text-[10px] font-semibold uppercase tracking-[0.1em] text-slate-400">
                    {label}
                </span>
            ) : null}
            <div className="relative">
                <select
                    value={value}
                    onChange={onChange}
                    className={`crm-select-enhanced ${isActive ? 'border-teal-400 bg-teal-50/40 text-teal-800' : ''}`}
                >
                    {options.map((opt) => (
                        <option key={opt.value} value={opt.value}>
                            {opt.label}
                        </option>
                    ))}
                </select>
                <svg
                    className="pointer-events-none absolute right-2.5 top-1/2 h-4 w-4 -translate-y-1/2 text-slate-400"
                    fill="none"
                    stroke="currentColor"
                    viewBox="0 0 24 24"
                    aria-hidden="true"
                >
                    <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M19.5 8.25l-7.5 7.5-7.5-7.5" />
                </svg>
                {isActive ? (
                    <span className="absolute right-8 top-1/2 h-1.5 w-1.5 -translate-y-1/2 rounded-full bg-teal-500" aria-hidden="true" />
                ) : null}
            </div>
        </div>
    );
}
