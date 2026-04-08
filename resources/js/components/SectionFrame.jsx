import React from 'react';

export default function SectionFrame({
    title,
    subtitle,
    action,
    children,
    footer,
    className = '',
    headerClassName = '',
    contentClassName = '',
    footerClassName = '',
    titleClassName = '',
    subtitleClassName = '',
}) {
    return (
        <section className={`rounded-lg border border-slate-200 bg-white shadow-sm ${className}`}>
            <header className={`flex items-start justify-between gap-3 border-b border-slate-100 px-4 py-3.5 ${headerClassName}`}>
                <div>
                    <h3 className={`text-[1.08rem] leading-6 font-semibold tracking-tight text-slate-900 ${titleClassName}`}>{title}</h3>
                    {subtitle ? <p className={`mt-1 text-sm text-slate-500 ${subtitleClassName}`}>{subtitle}</p> : null}
                </div>
                {action}
            </header>
            <div className={`p-4 ${contentClassName}`}>{children}</div>
            {footer ? <footer className={`border-t border-slate-100 px-4 py-3 ${footerClassName}`}>{footer}</footer> : null}
        </section>
    );
}
