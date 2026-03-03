import React, { useEffect, useRef, useState } from 'react';

const VARIANT_STYLES = {
    primary: 'bg-teal-700 text-white hover:bg-teal-800 focus-visible:ring-teal-600',
    warning: 'bg-amber-600 text-white hover:bg-amber-700 focus-visible:ring-amber-500',
    success: 'border border-emerald-400 bg-emerald-50 text-emerald-700 hover:bg-emerald-100 focus-visible:ring-emerald-500',
    danger: 'border border-rose-200 bg-rose-50 text-rose-700 hover:bg-rose-100 focus-visible:ring-rose-300',
    default: 'border border-slate-300 text-slate-700 hover:bg-slate-50 focus-visible:ring-slate-400',
};

const MENU_VARIANT_STYLES = {
    primary: 'text-teal-700 hover:bg-teal-50',
    warning: 'text-amber-700 hover:bg-amber-50',
    success: 'text-emerald-700 hover:bg-emerald-50',
    danger: 'text-rose-700 hover:bg-rose-50',
    default: 'text-slate-700 hover:bg-slate-50',
};

export default function RowActionMenu({ primaryAction, actions = [], badge }) {
    const [open, setOpen] = useState(false);
    const menuRef = useRef(null);
    const buttonRef = useRef(null);

    const visibleActions = actions.filter((a) => !a.hidden);

    useEffect(() => {
        if (!open) return;

        const handleClickOutside = (event) => {
            if (menuRef.current && !menuRef.current.contains(event.target) && !buttonRef.current?.contains(event.target)) {
                setOpen(false);
            }
        };

        const handleEscape = (event) => {
            if (event.key === 'Escape') setOpen(false);
        };

        document.addEventListener('mousedown', handleClickOutside);
        document.addEventListener('keydown', handleEscape);
        return () => {
            document.removeEventListener('mousedown', handleClickOutside);
            document.removeEventListener('keydown', handleEscape);
        };
    }, [open]);

    return (
        <div className="flex items-center gap-1.5">
            {primaryAction ? (
                <button
                    type="button"
                    onClick={(e) => { e.stopPropagation(); primaryAction.onClick?.(); }}
                    disabled={primaryAction.disabled}
                    className={`rounded-md px-2.5 py-1 text-xs font-semibold transition focus-visible:outline-none focus-visible:ring-2 disabled:cursor-not-allowed disabled:opacity-50 ${VARIANT_STYLES[primaryAction.variant || 'primary']}`}
                >
                    {primaryAction.label}
                </button>
            ) : null}

            {badge ? (
                <span className="inline-flex items-center rounded-full bg-emerald-50 px-2 py-0.5 text-[11px] font-semibold text-emerald-700">
                    {badge}
                </span>
            ) : null}

            {visibleActions.length > 0 ? (
                <div className="relative">
                    <button
                        ref={buttonRef}
                        type="button"
                        onClick={(e) => { e.stopPropagation(); setOpen((prev) => !prev); }}
                        className="flex h-7 w-7 items-center justify-center rounded-md border border-slate-200 text-slate-400 transition hover:border-slate-300 hover:bg-slate-50 hover:text-slate-600 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-slate-400"
                        aria-label="More actions"
                    >
                        <svg className="h-4 w-4" fill="currentColor" viewBox="0 0 20 20">
                            <path d="M10 6a2 2 0 110-4 2 2 0 010 4zm0 6a2 2 0 110-4 2 2 0 010 4zm0 6a2 2 0 110-4 2 2 0 010 4z" />
                        </svg>
                    </button>

                    {open ? (
                        <div
                            ref={menuRef}
                            className="absolute right-0 top-full z-50 mt-1 min-w-[160px] rounded-lg border border-slate-200 bg-white py-1 shadow-lg"
                        >
                            {visibleActions.map((action) => (
                                <button
                                    key={action.key || action.label}
                                    type="button"
                                    onClick={(e) => {
                                        e.stopPropagation();
                                        setOpen(false);
                                        action.onClick?.();
                                    }}
                                    disabled={action.disabled}
                                    className={`flex w-full items-center gap-2 px-3 py-1.5 text-left text-xs font-medium transition disabled:cursor-not-allowed disabled:opacity-50 ${MENU_VARIANT_STYLES[action.variant || 'default']}`}
                                >
                                    {action.label}
                                </button>
                            ))}
                        </div>
                    ) : null}
                </div>
            ) : null}
        </div>
    );
}
