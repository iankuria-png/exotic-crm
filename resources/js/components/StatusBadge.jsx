import React from 'react';

const statusStyles = {
    // Profile statuses
    publish: 'bg-emerald-50 text-emerald-700 ring-emerald-200',
    private: 'bg-slate-100 text-slate-600 ring-slate-200',
    draft: 'bg-amber-50 text-amber-700 ring-amber-200',
    pending: 'bg-amber-50 text-amber-700 ring-amber-200',
    // Deal statuses
    active: 'bg-emerald-50 text-emerald-700 ring-emerald-200',
    awaiting_payment: 'bg-amber-50 text-amber-700 ring-amber-200',
    paid: 'bg-sky-50 text-sky-700 ring-sky-200',
    expired: 'bg-rose-50 text-rose-700 ring-rose-200',
    renewed: 'bg-teal-50 text-teal-700 ring-teal-200',
    cancelled: 'bg-slate-100 text-slate-500 ring-slate-200',
    untracked: 'bg-amber-50 text-amber-700 ring-amber-200',
    // Payment statuses
    completed: 'bg-emerald-50 text-emerald-700 ring-emerald-200',
    initiated: 'bg-amber-50 text-amber-700 ring-amber-200',
    failed: 'bg-rose-50 text-rose-700 ring-rose-200',
    // Lead statuses
    new: 'bg-sky-50 text-sky-700 ring-sky-200',
    contacted: 'bg-teal-50 text-teal-700 ring-teal-200',
    qualified: 'bg-indigo-50 text-indigo-700 ring-indigo-200',
    converted: 'bg-emerald-50 text-emerald-700 ring-emerald-200',
    lost: 'bg-rose-50 text-rose-700 ring-rose-200',
    // Match confidence
    auto_high: 'bg-emerald-50 text-emerald-700 ring-emerald-200',
    auto_low: 'bg-amber-50 text-amber-700 ring-amber-200',
    manual: 'bg-sky-50 text-sky-700 ring-sky-200',
    unmatched: 'bg-rose-50 text-rose-700 ring-rose-200',
    high: 'bg-emerald-50 text-emerald-700 ring-emerald-200',
    medium: 'bg-amber-50 text-amber-700 ring-amber-200',
    low: 'bg-rose-50 text-rose-700 ring-rose-200',
    open: 'bg-slate-100 text-slate-600 ring-slate-200',
    manual_review: 'bg-amber-50 text-amber-700 ring-amber-200',
    resolved: 'bg-emerald-50 text-emerald-700 ring-emerald-200',
};

const statusLabels = {
    publish: 'Active',
    private: 'Inactive',
    awaiting_payment: 'Awaiting Payment',
    renewed: 'Renewed',
    untracked: 'Untracked',
    auto_high: 'Auto (High)',
    auto_low: 'Auto (Low)',
    manual_review: 'Manual Review',
};

export default function StatusBadge({ status }) {
    if (!status) return null;
    const style = statusStyles[status] || 'bg-slate-100 text-slate-600 ring-slate-200';
    const label = statusLabels[status] || status.charAt(0).toUpperCase() + status.slice(1).replace(/_/g, ' ');

    return (
        <span className={`inline-flex items-center rounded-md px-2.5 py-0.5 text-xs font-medium ring-1 ring-inset ${style}`}>
            {label}
        </span>
    );
}
