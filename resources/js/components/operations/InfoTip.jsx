import React, { useId, useState } from 'react';

/**
 * A small "what does this mean" affordance.
 *
 * Opens on hover AND on focus, and is reachable by keyboard, so the
 * explanation is not mouse-only. The text is the same either way — this is for
 * defining vocabulary the board otherwise assumes you already have.
 */
export default function InfoTip({ label, children, align = 'left' }) {
    const [open, setOpen] = useState(false);
    const id = useId();

    return (
        <span className="relative inline-flex">
            <button
                type="button"
                aria-label={label ? `What is ${label}?` : 'More information'}
                aria-describedby={open ? id : undefined}
                aria-expanded={open}
                onMouseEnter={() => setOpen(true)}
                onMouseLeave={() => setOpen(false)}
                onFocus={() => setOpen(true)}
                onBlur={() => setOpen(false)}
                onClick={(e) => { e.stopPropagation(); setOpen((v) => !v); }}
                className="inline-flex h-3.5 w-3.5 items-center justify-center rounded-full border border-slate-300 text-[9px] font-bold leading-none text-slate-500 hover:border-slate-400 hover:text-slate-700 focus:outline-none focus:ring-2 focus:ring-teal-300"
            >
                ?
            </button>
            {open ? (
                <span
                    id={id}
                    role="tooltip"
                    className={`absolute bottom-full z-30 mb-1.5 w-64 rounded-lg border border-slate-200 bg-white p-2.5 text-[12px] font-normal leading-snug text-slate-600 shadow-lg ${align === 'right' ? 'right-0' : 'left-0'}`}
                >
                    {children}
                </span>
            ) : null}
        </span>
    );
}
