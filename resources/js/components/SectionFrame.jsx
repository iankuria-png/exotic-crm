import React from 'react';

export default function SectionFrame({ title, subtitle, action, children, footer }) {
    return (
        <section className="rounded-lg border border-slate-200 bg-white shadow-sm">
            <header className="flex items-start justify-between gap-3 border-b border-slate-100 px-4 py-3.5">
                <div>
                    <h3 className="text-[1.08rem] leading-6 font-semibold tracking-tight text-slate-900">{title}</h3>
                    {subtitle ? <p className="mt-1 text-sm text-slate-500">{subtitle}</p> : null}
                </div>
                {action}
            </header>
            <div className="p-4">{children}</div>
            {footer ? <footer className="border-t border-slate-100 px-4 py-3">{footer}</footer> : null}
        </section>
    );
}
