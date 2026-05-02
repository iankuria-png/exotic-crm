import React, { useEffect } from 'react';

export default function FaqFlyoutPanel({
    open,
    onClose,
    title,
    subtitle,
    children,
    footer = null,
    widthClassName = 'max-w-lg',
}) {
    useEffect(() => {
        if (!open) {
            return undefined;
        }

        const handleKeyDown = (event) => {
            if (event.key === 'Escape') {
                onClose?.();
            }
        };

        window.addEventListener('keydown', handleKeyDown);
        return () => window.removeEventListener('keydown', handleKeyDown);
    }, [open, onClose]);

    if (!open) {
        return null;
    }

    return (
        <div className="fixed inset-0 z-[110] bg-slate-950/30 backdrop-blur-[2px]" onClick={onClose}>
            <div className="absolute inset-x-4 top-20 mx-auto lg:left-auto lg:right-6 lg:mx-0">
                <section
                    className={`w-full ${widthClassName} overflow-hidden rounded-[28px] border border-slate-200 bg-white shadow-[0_28px_70px_rgba(15,23,42,0.18)]`}
                    onClick={(event) => event.stopPropagation()}
                >
                    <header className="border-b border-slate-100 px-5 py-4">
                        <div className="flex items-start justify-between gap-4">
                            <div>
                                <h3 className="text-xl font-semibold tracking-tight text-slate-950">{title}</h3>
                                {subtitle ? <p className="mt-1 text-sm leading-6 text-slate-500">{subtitle}</p> : null}
                            </div>
                            <button
                                type="button"
                                onClick={onClose}
                                className="inline-flex h-10 w-10 items-center justify-center rounded-xl border border-slate-200 text-slate-500 transition hover:border-slate-300 hover:bg-slate-50 hover:text-slate-700"
                                aria-label="Close panel"
                            >
                                <svg className="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
                                    <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="m6 6 12 12M18 6 6 18" />
                                </svg>
                            </button>
                        </div>
                    </header>

                    <div className="max-h-[calc(100vh-8.5rem)] overflow-y-auto px-5 py-5">
                        {children}
                    </div>

                    {footer ? <div className="border-t border-slate-100 px-5 py-4">{footer}</div> : null}
                </section>
            </div>
        </div>
    );
}
