/**
 * Client-side CSV export for tables that are already fully loaded in the
 * browser. The Operations tab's tables are bounded by design — four queue
 * lanes, at most a couple of hundred incidents — so a server round-trip would
 * add load to the very endpoint that exists to report the platform is under
 * load.
 */
function escapeCell(value) {
    if (value === null || value === undefined) return '';
    const text = String(value);
    return /[",\n]/.test(text) ? `"${text.replaceAll('"', '""')}"` : text;
}

export default function exportRowsToCsv(filename, columns, rows) {
    const header = columns.map((column) => escapeCell(column.label)).join(',');
    const body = rows.map((row) => columns.map((column) => escapeCell(column.value(row))).join(','));
    const csv = [header, ...body].join('\n');

    const blob = new Blob([`﻿${csv}`], { type: 'text/csv;charset=utf-8;' });
    const url = URL.createObjectURL(blob);
    const link = document.createElement('a');
    link.href = url;
    link.download = `${filename}-${new Date().toISOString().slice(0, 10)}.csv`;
    document.body.appendChild(link);
    link.click();
    link.remove();
    URL.revokeObjectURL(url);
}
