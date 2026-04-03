import React from 'react';

function statusChip(status) {
    if (['connected', 'healthy', 'success'].includes(status)) return 'bg-emerald-50 text-emerald-700 ring-emerald-200';
    if (['configured_disabled', 'partial', 'degraded', 'pending', 'queued', 'running'].includes(status)) return 'bg-amber-50 text-amber-700 ring-amber-200';
    if (['completed'].includes(status)) return 'bg-emerald-50 text-emerald-700 ring-emerald-200';
    if (['deferred', 'unknown'].includes(status)) return 'bg-slate-100 text-slate-700 ring-slate-300';
    return 'bg-rose-50 text-rose-700 ring-rose-200';
}

export default function IntegrationsOverviewPanel({
    isLoading,
    serviceRows,
}) {
    return (
        <section className="crm-surface overflow-hidden">
            <header className="crm-panel-header">
                <div>
                    <h3 className="crm-panel-title">Service Integrations</h3>
                    <p className="crm-panel-subtitle">Live status for SMS, payment, and deferred email channels.</p>
                </div>
            </header>
            <div className="divide-y divide-slate-100">
                {isLoading ? (
                    <p className="p-4 text-sm text-slate-500">Loading service health...</p>
                ) : serviceRows.map((service) => (
                    <div key={service.key} className="flex flex-wrap items-center justify-between gap-3 p-4">
                        <div>
                            <p className="text-sm font-semibold text-slate-900">{service.label}</p>
                            <p className="text-xs text-slate-500">{service.detail}</p>
                        </div>
                        <span className={`inline-flex items-center rounded-md px-2.5 py-0.5 text-xs font-medium ring-1 ring-inset ${statusChip(service.status)}`}>
                            {service.status.replaceAll('_', ' ')}
                        </span>
                    </div>
                ))}
            </div>
        </section>
    );
}
