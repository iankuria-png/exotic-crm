import React from 'react';
import ConfirmDialog from '../ConfirmDialog';

const INACTIVITY_OPTIONS = [90, 180, 270, 365, 730];

export default function SmartDeleteDialog({
    open,
    mode,
    platformOptions = [],
    selectedCount = 0,
    filters,
    preview,
    confirmText,
    reason,
    previewPending,
    deletePending,
    lockMarket = false,
    lockOffline = false,
    onCancel,
    onFiltersChange,
    onConfirmTextChange,
    onReasonChange,
    onPreview,
    onConfirm,
}) {
    if (!open) return null;

    const confirmDisabled = previewPending
        || deletePending
        || !preview
        || Number(preview.total_count || 0) === 0
        || Boolean(preview.capped)
        || confirmText.trim() !== 'DELETE'
        || !reason.trim();

    const setFilter = (key, value) => onFiltersChange((current) => ({ ...current, [key]: value }));

    return (
        <ConfirmDialog
            open={open}
            title={mode === 'smart' ? 'Smart Delete Clients' : 'Delete Selected Clients'}
            message={mode === 'smart'
                ? 'Configure safeguards, preview the exact impact, then type DELETE. Every row is checked again immediately before deletion.'
                : 'Review the deletion impact for the selected clients before removing them from CRM.'}
            confirmLabel={deletePending ? 'Deleting...' : 'Delete clients'}
            tone="danger"
            onCancel={onCancel}
            onConfirm={onConfirm}
            confirmDisabled={confirmDisabled}
            isPending={deletePending}
        >
            <div className="space-y-4">
                {mode === 'smart' ? (
                    <div className="grid gap-3 md:grid-cols-2">
                        <label className="block">
                            <span className="mb-1 block text-xs font-semibold uppercase tracking-[0.08em] text-slate-500">Market</span>
                            <select
                                value={filters.platform_id}
                                disabled={lockMarket}
                                onChange={(event) => setFilter('platform_id', event.target.value)}
                                className="crm-select w-full disabled:bg-slate-100"
                            >
                                {!lockMarket ? <option value="">All accessible markets</option> : null}
                                {platformOptions.map((platform) => (
                                    <option key={platform.platform_id} value={platform.platform_id}>
                                        {platform.platform_name || platform.name}
                                    </option>
                                ))}
                            </select>
                        </label>

                        <label className="block">
                            <span className="mb-1 block text-xs font-semibold uppercase tracking-[0.08em] text-slate-500">Inactive for</span>
                            <select
                                value={filters.inactive_days}
                                onChange={(event) => setFilter('inactive_days', event.target.value)}
                                className="crm-select w-full"
                            >
                                {INACTIVITY_OPTIONS.map((days) => (
                                    <option key={days} value={days}>{days === 365 ? '12 months' : days === 730 ? '24 months' : `${days / 30} months`}</option>
                                ))}
                            </select>
                        </label>

                        <SafetyCheck
                            checked={Boolean(filters.offline_only)}
                            disabled={lockOffline}
                            label="Offline profiles only"
                            onChange={(checked) => setFilter('offline_only', checked)}
                        />
                        <SafetyCheck
                            checked={Boolean(filters.include_never_seen)}
                            label="Include never-seen profiles old enough for this window"
                            onChange={(checked) => setFilter('include_never_seen', checked)}
                        />
                        <SafetyCheck
                            checked={Boolean(filters.has_no_chat)}
                            label="No support chat"
                            onChange={(checked) => setFilter('has_no_chat', checked)}
                        />
                        <SafetyCheck
                            checked={Boolean(filters.has_no_subscription_or_payment)}
                            label="No subscriptions or payments"
                            onChange={(checked) => setFilter('has_no_subscription_or_payment', checked)}
                        />
                        {!lockOffline ? (
                            <SafetyCheck
                                checked={Boolean(filters.seo_placeholders)}
                                label="SEO placeholders only"
                                tone="danger"
                                onChange={(checked) => onFiltersChange((current) => ({
                                    ...current,
                                    seo_placeholders: checked,
                                    has_no_subscription_or_payment: checked ? true : current.has_no_subscription_or_payment,
                                }))}
                            />
                        ) : null}
                    </div>
                ) : (
                    <div className="rounded-md border border-slate-200 bg-slate-50 px-3 py-2 text-sm text-slate-700">
                        {selectedCount.toLocaleString()} selected client{selectedCount === 1 ? '' : 's'} will be previewed for deletion.
                    </div>
                )}

                <div className="flex items-center justify-between gap-3 rounded-md border border-slate-200 bg-white px-3 py-2">
                    <div>
                        <p className="text-xs font-semibold uppercase tracking-[0.08em] text-slate-500">Mandatory preview</p>
                        <p className="text-sm text-slate-700">
                            {preview ? `${Number(preview.total_count || 0).toLocaleString()} matching clients` : 'Preview the deletion impact before confirmation.'}
                        </p>
                    </div>
                    <button type="button" onClick={onPreview} disabled={previewPending} className="crm-btn-secondary disabled:opacity-50">
                        {previewPending ? 'Loading...' : 'Preview matches'}
                    </button>
                </div>

                {preview ? (
                    <div className="space-y-3">
                        <div className="grid gap-2 sm:grid-cols-4">
                            <Impact label="Eligible" value={Number(preview.total_count || 0)} />
                            <Impact label="Blocked" value={Number(preview.blocked_count || 0)} />
                            <Impact label="Never seen in" value={Number(preview.never_seen_included || 0)} />
                            <Impact label="Never seen out" value={Number(preview.never_seen_excluded || 0)} />
                        </div>
                        {preview.capped ? (
                            <p className="rounded-md border border-amber-200 bg-amber-50 px-3 py-2 text-xs text-amber-800">More than 500 clients matched. Narrow the filters.</p>
                        ) : null}
                        <div className="max-h-52 space-y-2 overflow-auto rounded-md border border-slate-200 bg-slate-50 p-3">
                            {(preview.clients || []).map((row) => (
                                <div key={row.client_id} className="flex items-start justify-between gap-3 rounded-md border border-slate-200 bg-white px-3 py-2">
                                    <div>
                                        <p className="text-sm font-semibold text-slate-900">{row.name || `Client #${row.client_id}`}</p>
                                        <p className="text-xs text-slate-500">CRM #{row.client_id} · {row.platform_name || 'Unknown market'}</p>
                                    </div>
                                    {!row.can_delete ? <span className="text-[11px] font-semibold text-amber-700">{row.delete_blocked_reason_code}</span> : null}
                                </div>
                            ))}
                            {!(preview.clients || []).length ? <p className="text-sm text-slate-500">No clients matched.</p> : null}
                        </div>
                    </div>
                ) : null}

                <label className="block">
                    <span className="mb-1 block text-xs font-semibold uppercase tracking-[0.08em] text-slate-500">Reason</span>
                    <textarea value={reason} onChange={(event) => onReasonChange(event.target.value)} rows={2} className="crm-input w-full" />
                </label>
                <label className="block">
                    <span className="mb-1 block text-xs font-semibold uppercase tracking-[0.08em] text-slate-500">Type DELETE to confirm</span>
                    <input value={confirmText} onChange={(event) => onConfirmTextChange(event.target.value)} className="crm-input" placeholder="DELETE" />
                </label>
            </div>
        </ConfirmDialog>
    );
}

function SafetyCheck({ checked, disabled = false, label, onChange, tone = 'safe' }) {
    return (
        <label className={`flex items-start gap-2 rounded-md border px-3 py-2 text-sm ${tone === 'danger' ? 'border-rose-200 bg-rose-50 text-rose-800' : 'border-slate-200 bg-slate-50 text-slate-700'}`}>
            <input type="checkbox" checked={checked} disabled={disabled} onChange={(event) => onChange(event.target.checked)} className="mt-0.5 h-4 w-4 rounded border-slate-300 text-teal-700" />
            <span>{label}</span>
        </label>
    );
}

function Impact({ label, value }) {
    return (
        <div className="rounded-md border border-slate-200 bg-slate-50 px-3 py-2">
            <p className="text-[11px] font-semibold uppercase tracking-[0.08em] text-slate-500">{label}</p>
            <p className="mt-1 text-sm font-semibold text-slate-900">{value.toLocaleString()}</p>
        </div>
    );
}
