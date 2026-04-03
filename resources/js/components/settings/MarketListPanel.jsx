import React from 'react';

export default function MarketListPanel({
    formatDateTime,
    isLoading,
    platformRows,
    selectedPlatformId,
    setSelectedPlatformId,
    statusChip,
}) {
    if (isLoading) {
        return <p className="text-sm text-slate-500">Loading market profiles...</p>;
    }

    if (platformRows.length === 0) {
        return <p className="rounded-md border border-dashed border-slate-300 bg-slate-50 px-3 py-6 text-sm text-slate-500">No market profiles configured.</p>;
    }

    return (
        <div className="space-y-2">
            {platformRows.map((platform) => {
                const isSelected = platform.platform_id === selectedPlatformId;
                const packageIncomplete = !platform.package_setup?.can_go_live;

                return (
                    <button
                        key={platform.platform_id}
                        type="button"
                        onClick={() => setSelectedPlatformId(platform.platform_id)}
                        className={`w-full rounded-lg border px-3 py-3 text-left transition ${isSelected ? 'border-teal-300 bg-teal-50/40' : 'border-slate-200 bg-white hover:border-slate-300'}`}
                    >
                        <div className="flex items-start justify-between gap-2">
                            <div>
                                <p className="text-sm font-semibold text-slate-900">{platform.platform_name}</p>
                                <p className="text-xs text-slate-500">{platform.country || '—'} • {platform.domain || 'No domain'}</p>
                            </div>
                            <span className={`inline-flex items-center rounded-md px-2 py-0.5 text-xs font-medium ring-1 ring-inset ${statusChip(platform.wp_sync?.status || 'pending')}`}>
                                {(platform.wp_sync?.status || 'pending').replaceAll('_', ' ')}
                            </span>
                        </div>
                        <div className="mt-2 flex items-center justify-between text-xs text-slate-500">
                            <span>Last sync: {formatDateTime(platform.sync?.last_synced_at)}</span>
                            <span className="font-medium">{(platform.sync?.last_status || 'unknown').replaceAll('_', ' ')}</span>
                        </div>
                        <p className={`mt-1 text-xs ${packageIncomplete ? 'text-amber-700' : 'text-emerald-700'}`}>
                            {packageIncomplete ? 'Package setup incomplete' : 'Package catalog ready'}
                        </p>
                    </button>
                );
            })}
        </div>
    );
}
