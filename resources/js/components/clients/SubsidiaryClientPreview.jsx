import React from 'react';

export default function SubsidiaryClientPreview({
    isLoading = false,
    error = null,
    preview = null,
    selectedClientName = '',
    onUseDifferent = null,
    onConfirmCreate = null,
    createConfirmed = false,
}) {
    if (isLoading) {
        return (
            <div className="rounded-md border border-slate-200 bg-white p-3">
                <div className="h-3 w-32 animate-pulse rounded bg-slate-200" />
                <div className="mt-2 h-3 w-48 animate-pulse rounded bg-slate-100" />
            </div>
        );
    }

    if (error) {
        return (
            <div className="rounded-md border border-rose-200 bg-rose-50 p-3 text-sm text-rose-700">
                {error}
            </div>
        );
    }

    if (!preview) {
        return (
            <div className="rounded-md border border-slate-200 bg-white p-3 text-sm text-slate-500">
                Choose a subsidiary market to preview the client match.
            </div>
        );
    }

    if (preview.match) {
        return (
            <div className="rounded-md border border-emerald-200 bg-emerald-50 p-3">
                <div className="flex items-start justify-between gap-3">
                    <div className="min-w-0">
                        <p className="text-sm font-semibold text-emerald-900">
                            Matched: {selectedClientName || preview.match.name || 'Client'}
                        </p>
                        <p className="mt-1 truncate text-xs text-emerald-700">
                            {preview.match.phone_normalized || 'No phone'} · {preview.match.deal_count || 0} deals
                            {preview.match.last_seen ? ` · last seen ${preview.match.last_seen}` : ''}
                        </p>
                        {preview.match.has_active_trial ? (
                            <p className="mt-1 text-xs text-amber-700">Active trial already exists; it will be linked instead of duplicated.</p>
                        ) : null}
                    </div>
                    <button type="button" onClick={onUseDifferent} className="shrink-0 text-xs font-semibold text-teal-700 hover:text-teal-800">
                        Use different
                    </button>
                </div>
            </div>
        );
    }

    return (
        <div className="rounded-md border border-sky-200 bg-sky-50 p-3">
            <p className="text-sm font-semibold text-sky-900">Will create new WordPress profile</p>
            <p className="mt-1 text-xs text-sky-700">
                {preview.will_create?.name || 'Client'} · {preview.will_create?.phone_normalized || 'No phone'}
            </p>
            <label className="mt-3 flex items-center gap-2 text-xs font-medium text-sky-800">
                <input
                    type="checkbox"
                    checked={createConfirmed}
                    onChange={(event) => onConfirmCreate?.(event.target.checked)}
                    className="h-4 w-4 rounded border-sky-300 text-sky-600 focus:ring-sky-200"
                />
                Create this subsidiary client
            </label>
        </div>
    );
}
