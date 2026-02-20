import React, { useEffect, useMemo, useState } from 'react';

const EMPTY_SELECTION = [];

function defaultRowId(row, index, rowIdKey) {
    if (row && Object.prototype.hasOwnProperty.call(row, rowIdKey)) {
        return row[rowIdKey];
    }
    if (row && Object.prototype.hasOwnProperty.call(row, 'id')) {
        return row.id;
    }
    return index;
}

export default function DataTable({
    columns,
    data,
    pagination,
    onPageChange,
    onRowClick,
    isLoading,
    emptyMessage = 'No records found.',
    compact = false,
    rowIdKey = 'id',
    selectable = false,
    bulkActions = [],
    onSelectionChange,
    clearSelectionKey,
}) {
    const rows = data || [];
    const { current_page, last_page, total, per_page } = pagination || {};
    const cellPadding = compact ? 'px-4 py-2.5' : 'px-4 py-3';

    const rowIds = useMemo(() => rows.map((row, index) => defaultRowId(row, index, rowIdKey)), [rows, rowIdKey]);

    const [selectedIds, setSelectedIds] = useState([]);
    const [activeBulkAction, setActiveBulkAction] = useState(null);

    useEffect(() => {
        setSelectedIds((prev) => {
            if (prev.length === 0) {
                return prev;
            }

            const filtered = prev.filter((id) => rowIds.includes(id));
            if (filtered.length === prev.length && filtered.every((id, index) => id === prev[index])) {
                return prev;
            }

            return filtered;
        });
    }, [rowIds]);

    useEffect(() => {
        if (clearSelectionKey !== undefined) {
            setSelectedIds((prev) => (prev.length ? [] : prev));
        }
    }, [clearSelectionKey]);

    const selectedRows = useMemo(() => {
        if (!selectedIds.length) return EMPTY_SELECTION;
        const idSet = new Set(selectedIds);
        return rows.filter((row, index) => idSet.has(defaultRowId(row, index, rowIdKey)));
    }, [rows, rowIdKey, selectedIds]);

    useEffect(() => {
        onSelectionChange?.(selectedRows);
    }, [onSelectionChange, selectedRows]);

    const allVisibleSelected = rowIds.length > 0 && rowIds.every((id) => selectedIds.includes(id));

    const toggleAll = () => {
        if (allVisibleSelected) {
            setSelectedIds([]);
            return;
        }
        setSelectedIds(rowIds);
    };

    const toggleRow = (id) => {
        setSelectedIds((prev) => {
            if (prev.includes(id)) {
                return prev.filter((value) => value !== id);
            }
            return [...prev, id];
        });
    };

    const runBulkAction = async (action) => {
        if (!action?.onClick || !selectedRows.length) {
            return;
        }

        setActiveBulkAction(action.key || action.label);

        try {
            await Promise.resolve(action.onClick(selectedRows, { clearSelection: () => setSelectedIds([]) }));
        } finally {
            setActiveBulkAction(null);
        }
    };

    return (
        <div className="crm-surface overflow-hidden">
            {selectable && selectedRows.length > 0 ? (
                <div className="flex flex-wrap items-center justify-between gap-2 border-b border-slate-200 bg-slate-50 px-4 py-2.5">
                    <p className="text-sm text-slate-600">{selectedRows.length} selected</p>
                    <div className="flex flex-wrap items-center gap-1.5">
                        {bulkActions.map((action) => {
                            const isPrimary = action.variant === 'primary';
                            const isDanger = action.variant === 'danger';
                            const loading = activeBulkAction === (action.key || action.label);

                            const className = isPrimary
                                ? 'crm-btn-primary px-3 py-1.5 text-xs disabled:cursor-not-allowed disabled:opacity-50'
                                : isDanger
                                    ? 'crm-btn-danger px-3 py-1.5 text-xs disabled:cursor-not-allowed disabled:opacity-50'
                                    : 'crm-btn-secondary px-3 py-1.5 text-xs disabled:cursor-not-allowed disabled:opacity-50';

                            return (
                                <button
                                    key={action.key || action.label}
                                    type="button"
                                    onClick={() => runBulkAction(action)}
                                    disabled={loading || !!action.disabled}
                                    className={className}
                                >
                                    {loading ? (action.loadingLabel || 'Working...') : action.label}
                                </button>
                            );
                        })}

                        <button
                            type="button"
                            onClick={() => setSelectedIds([])}
                            className="crm-btn-secondary px-3 py-1.5 text-xs"
                        >
                            Clear
                        </button>
                    </div>
                </div>
            ) : null}

            <div className="max-h-[68vh] overflow-auto">
                <table className="min-w-full divide-y divide-slate-200">
                    <thead className="bg-slate-50/95 backdrop-blur">
                        <tr>
                            {selectable ? (
                                <th className="sticky top-0 z-10 w-11 px-3 py-3 text-left bg-slate-50/95">
                                    <input
                                        type="checkbox"
                                        checked={allVisibleSelected}
                                        onChange={toggleAll}
                                        className="h-4 w-4 rounded border-slate-300 text-teal-700 focus:ring-2 focus:ring-teal-200"
                                        aria-label="Select all rows"
                                    />
                                </th>
                            ) : null}
                            {columns.map((col) => (
                                <th
                                    key={col.key}
                                    className="sticky top-0 z-10 bg-slate-50/95 px-4 py-3 text-left text-[11px] font-semibold uppercase tracking-[0.12em] text-slate-500"
                                    style={col.width ? { width: col.width } : {}}
                                >
                                    {col.label}
                                </th>
                            ))}
                        </tr>
                    </thead>
                    <tbody className="divide-y divide-slate-100">
                        {isLoading ? (
                            <tr>
                                <td colSpan={columns.length + (selectable ? 1 : 0)} className="px-4 py-12 text-center">
                                    <div className="flex items-center justify-center gap-2 text-slate-400">
                                        <div className="h-5 w-5 animate-spin rounded-full border-2 border-teal-600 border-t-transparent" />
                                        Loading...
                                    </div>
                                </td>
                            </tr>
                        ) : rows.length === 0 ? (
                            <tr>
                                <td colSpan={columns.length + (selectable ? 1 : 0)} className="px-4 py-12 text-center text-sm text-slate-500">
                                    {emptyMessage}
                                </td>
                            </tr>
                        ) : (
                            rows.map((row, index) => {
                                const rowId = defaultRowId(row, index, rowIdKey);
                                const isSelected = selectedIds.includes(rowId);

                                return (
                                    <tr
                                        key={rowId}
                                        onClick={() => onRowClick?.(row)}
                                        className={`${onRowClick ? 'cursor-pointer hover:bg-slate-50' : ''} ${isSelected ? 'bg-teal-50/40' : ''} transition-colors`}
                                    >
                                        {selectable ? (
                                            <td className={`${cellPadding} pl-3`}> 
                                                <input
                                                    type="checkbox"
                                                    checked={isSelected}
                                                    onChange={() => toggleRow(rowId)}
                                                    onClick={(event) => event.stopPropagation()}
                                                    className="h-4 w-4 rounded border-slate-300 text-teal-700 focus:ring-2 focus:ring-teal-200"
                                                    aria-label="Select row"
                                                />
                                            </td>
                                        ) : null}
                                        {columns.map((col) => (
                                            <td key={col.key} className={`${cellPadding} whitespace-nowrap text-sm text-slate-700`}>
                                                {col.render ? col.render(row) : row[col.key]}
                                            </td>
                                        ))}
                                    </tr>
                                );
                            })
                        )}
                    </tbody>
                </table>
            </div>

            {pagination && last_page > 1 ? (
                <div className="flex flex-col gap-2 border-t border-slate-200 bg-slate-50 px-4 py-3 sm:flex-row sm:items-center sm:justify-between">
                    <p className="text-sm text-slate-500">
                        Showing {((current_page - 1) * per_page) + 1}–{Math.min(current_page * per_page, total)} of {total}
                    </p>
                    <div className="flex gap-1">
                        <button
                            onClick={() => onPageChange(current_page - 1)}
                            disabled={current_page <= 1}
                            className="rounded-md border border-slate-300 bg-white px-3 py-1 text-sm text-slate-700 hover:bg-slate-50 disabled:cursor-not-allowed disabled:opacity-40"
                        >
                            Prev
                        </button>
                        {generatePageNumbers(current_page, last_page).map((page, index) =>
                            page === '...' ? (
                                <span key={`dots-${index}`} className="px-2 py-1 text-sm text-slate-400">...</span>
                            ) : (
                                <button
                                    key={page}
                                    onClick={() => onPageChange(page)}
                                    className={`rounded-md border px-3 py-1 text-sm ${
                                        page === current_page
                                            ? 'border-teal-700 bg-teal-700 text-white'
                                            : 'border-slate-300 bg-white text-slate-700 hover:bg-slate-50'
                                    }`}
                                >
                                    {page}
                                </button>
                            ),
                        )}
                        <button
                            onClick={() => onPageChange(current_page + 1)}
                            disabled={current_page >= last_page}
                            className="rounded-md border border-slate-300 bg-white px-3 py-1 text-sm text-slate-700 hover:bg-slate-50 disabled:cursor-not-allowed disabled:opacity-40"
                        >
                            Next
                        </button>
                    </div>
                </div>
            ) : null}
        </div>
    );
}

function generatePageNumbers(current, last) {
    if (last <= 7) return Array.from({ length: last }, (_, index) => index + 1);
    const pages = [1];
    if (current > 3) pages.push('...');
    for (let i = Math.max(2, current - 1); i <= Math.min(last - 1, current + 1); i += 1) {
        pages.push(i);
    }
    if (current < last - 2) pages.push('...');
    pages.push(last);
    return pages;
}
