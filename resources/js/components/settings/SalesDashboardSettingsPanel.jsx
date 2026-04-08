import React from 'react';
import useSalesWidgetConfig, { useUpdateSalesWidgetConfig } from '../../hooks/useSalesWidgetConfig';
import { useToast } from '../ToastProvider';

function LoadingRows() {
    return (
        <div className="space-y-3 px-5 py-4">
            {Array.from({ length: 4 }).map((_, index) => (
                <div key={index} className="h-16 animate-pulse rounded-xl bg-slate-100" />
            ))}
        </div>
    );
}

export default function SalesDashboardSettingsPanel() {
    const toast = useToast();
    const { config, defaults, editable, labels, isLoading, error } = useSalesWidgetConfig();
    const updateMutation = useUpdateSalesWidgetConfig();
    const enabledCount = Object.values(config).filter(Boolean).length;

    const saveConfig = (nextConfig) => {
        updateMutation.mutate(nextConfig, {
            onSuccess: () => {
                toast.success('Sales dashboard view updated.');
            },
            onError: (mutationError) => {
                toast.error(mutationError?.response?.data?.message || 'Unable to update sales dashboard view.');
            },
        });
    };

    const toggle = (key) => {
        saveConfig({
            ...config,
            [key]: !config[key],
        });
    };

    const reset = () => {
        saveConfig(defaults);
    };

    return (
        <section className="rounded-lg border border-slate-200 bg-white shadow-sm">
            <header className="flex flex-wrap items-start justify-between gap-3 border-b border-slate-100 px-5 py-4">
                <div>
                    <div className="flex flex-wrap items-center gap-2">
                        <h3 className="text-lg font-semibold text-slate-900">Sales Dashboard View</h3>
                        <span className="rounded-full border border-teal-200 bg-teal-50 px-2.5 py-1 text-[11px] font-semibold text-teal-700">
                            {enabledCount} active blocks
                        </span>
                    </div>
                    <p className="mt-1 text-sm text-slate-500">
                        Controls the shared sales command center. These toggles affect every sales user, not just your personal dashboard.
                    </p>
                </div>
                <button
                    type="button"
                    onClick={reset}
                    disabled={!editable || updateMutation.isPending}
                    className="rounded-md border border-slate-300 bg-white px-3 py-1.5 text-sm font-semibold text-slate-700 transition hover:bg-slate-50 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-slate-400 disabled:cursor-not-allowed disabled:opacity-60"
                >
                    Reset sales defaults
                </button>
            </header>

            {isLoading ? <LoadingRows /> : null}

            {!isLoading && error ? (
                <div className="px-5 py-5">
                    <div className="rounded-xl border border-rose-200 bg-rose-50 px-4 py-3 text-sm text-rose-700">
                        {error?.response?.data?.message || 'Sales dashboard settings are unavailable right now.'}
                    </div>
                </div>
            ) : null}

            {!isLoading && !error ? (
                <div className="divide-y divide-slate-100">
                    {Object.entries(labels).map(([key, meta]) => (
                        <label key={key} className="flex cursor-pointer items-center justify-between gap-4 px-5 py-4 transition hover:bg-slate-50">
                            <div className="max-w-xl">
                                <p className="text-sm font-medium text-slate-900">{meta.name}</p>
                                <p className="mt-1 text-xs text-slate-500">{meta.description}</p>
                            </div>
                            <button
                                type="button"
                                role="switch"
                                aria-checked={config[key]}
                                onClick={() => toggle(key)}
                                disabled={!editable || updateMutation.isPending}
                                className={`relative inline-flex h-6 w-11 shrink-0 items-center rounded-full transition-colors focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-teal-600 focus-visible:ring-offset-2 disabled:cursor-not-allowed disabled:opacity-60 ${config[key] ? 'bg-teal-600' : 'bg-slate-200'}`}
                            >
                                <span
                                    className={`inline-block h-4 w-4 rounded-full bg-white shadow-sm transition-transform ${config[key] ? 'translate-x-6' : 'translate-x-1'}`}
                                />
                            </button>
                        </label>
                    ))}
                </div>
            ) : null}
        </section>
    );
}
