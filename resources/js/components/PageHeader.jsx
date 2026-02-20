import React from 'react';

export default function PageHeader({ title, subtitle, actions }) {
    return (
        <section className="crm-surface px-5 py-5">
            <div className="flex flex-col gap-3 lg:flex-row lg:items-end lg:justify-between">
                <div>
                    <h2 className="crm-page-title">{title}</h2>
                    {subtitle ? <p className="crm-page-subtitle">{subtitle}</p> : null}
                </div>
                {actions ? <div className="flex flex-wrap items-center gap-2">{actions}</div> : null}
            </div>
        </section>
    );
}
