import React, { useMemo, useState } from 'react';
import api from '../../services/api';
import ExportModal from '../ExportModal';
import { useToast } from '../ToastProvider';

const SCOPES = [
    {
        id: 'errors',
        title: 'Application errors',
        blurb: 'The error table, exactly as filtered behind this dialog.',
    },
    {
        id: 'pulse',
        title: 'Pulse performance',
        blurb: 'Slow requests, queries, jobs, outgoing calls and exceptions.',
    },
];

const FORMATS = {
    errors: [
        {
            id: 'csv',
            title: 'CSV',
            blurb: 'One row per signature — class, message, file, counts, first and last seen. Opens in a spreadsheet.',
        },
        {
            id: 'json',
            title: 'JSON debug bundle',
            blurb: 'The same signatures plus recent occurrences with full stack traces, request URLs and context.',
        },
    ],
    pulse: [
        {
            id: 'csv',
            title: 'CSV',
            blurb: 'Every Pulse finding flattened into one table: section, item, count, slowest time.',
        },
        {
            id: 'json',
            title: 'JSON snapshot',
            blurb: 'The findings grouped by section, keeping full SQL and route actions intact.',
        },
    ],
};

const PULSE_PERIODS = [
    { id: '1_hour', label: 'Past hour' },
    { id: '6_hours', label: 'Past 6 hours' },
    { id: '24_hours', label: 'Past 24 hours' },
    { id: '7_days', label: 'Past 7 days' },
];

const OCCURRENCE_CHOICES = [3, 5, 10, 20];

/** A JSON bundle is capped server-side; keep the two numbers in step. */
const MAX_BUNDLE_GROUPS = 500;

function saveBlob(blob, filename) {
    const url = URL.createObjectURL(blob);
    const anchor = document.createElement('a');
    anchor.href = url;
    anchor.download = filename;
    document.body.appendChild(anchor);
    anchor.click();
    document.body.removeChild(anchor);
    URL.revokeObjectURL(url);
}

function filenameFromResponse(response, fallback) {
    const disposition = response?.headers?.['content-disposition'] || '';
    const match = /filename="?([^";]+)"?/i.exec(disposition);
    return match ? match[1] : fallback;
}

async function readBlobError(blob) {
    try {
        const parsed = JSON.parse(await blob.text());
        return parsed.message || 'Export failed.';
    } catch {
        return 'Export failed.';
    }
}

function ChoiceCard({ active, title, blurb, onSelect }) {
    return (
        <button
            type="button"
            onClick={onSelect}
            aria-pressed={active}
            className={`rounded-lg border p-3 text-left transition ${
                active
                    ? 'border-teal-500 bg-teal-50/70 ring-1 ring-teal-500'
                    : 'border-slate-200 bg-white hover:border-slate-300 hover:bg-slate-50'
            }`}
        >
            <p className={`text-sm font-semibold ${active ? 'text-teal-900' : 'text-slate-800'}`}>{title}</p>
            <p className="mt-1 text-xs leading-relaxed text-slate-500">{blurb}</p>
        </button>
    );
}

/**
 * Export dialog for the Errors tab.
 *
 * It covers both halves of the same debugging job: the CRM's own error table,
 * and the performance findings Pulse renders but cannot export. Both leave as
 * either a spreadsheet-shaped CSV or a JSON bundle carrying the detail — stack
 * traces, full SQL — you actually need to fix the thing.
 */
export default function DiagnosticsExportModal({ open, onClose, errorFilters, matchedCount = 0 }) {
    const toast = useToast();
    const [scope, setScope] = useState('errors');
    const [format, setFormat] = useState('csv');
    const [period, setPeriod] = useState('24_hours');
    const [occurrences, setOccurrences] = useState(5);
    const [exporting, setExporting] = useState(false);

    const activeFilterSummary = useMemo(() => {
        const parts = [];
        if (errorFilters?.status) parts.push(`status: ${errorFilters.status}`);
        if (errorFilters?.level) parts.push(`level: ${errorFilters.level}`);
        if (errorFilters?.source) parts.push(`source: ${errorFilters.source}`);
        if (errorFilters?.search) parts.push(`search: “${errorFilters.search}”`);
        return parts.length ? parts.join(' · ') : 'no filters applied';
    }, [errorFilters]);

    const bundleTruncated = scope === 'errors' && format === 'json' && matchedCount > MAX_BUNDLE_GROUPS;

    const handleExport = async () => {
        setExporting(true);

        const isErrors = scope === 'errors';
        const url = isErrors ? '/crm/settings/error-logs/export' : '/crm/settings/pulse/export';
        const params = isErrors
            ? {
                format,
                ...(errorFilters?.search ? { search: errorFilters.search } : {}),
                ...(errorFilters?.level ? { level: errorFilters.level } : {}),
                ...(errorFilters?.source ? { source: errorFilters.source } : {}),
                ...(errorFilters?.status ? { status: errorFilters.status } : {}),
                ...(format === 'json' ? { occurrences } : {}),
            }
            : { format, period };

        try {
            const response = await api.get(url, { params, responseType: 'blob' });
            const fallback = `crm-${isErrors ? 'errors' : 'pulse'}-${new Date().toISOString().slice(0, 10)}.${format}`;
            saveBlob(response.data, filenameFromResponse(response, fallback));
            toast.success(isErrors ? 'Error export downloaded.' : 'Pulse snapshot downloaded.');
            onClose?.();
        } catch (error) {
            const blob = error?.response?.data;
            toast.error(blob instanceof Blob ? await readBlobError(blob) : 'Export failed. Please try again.');
        } finally {
            setExporting(false);
        }
    };

    return (
        <ExportModal
            open={open}
            title="Export diagnostics"
            subtitle="Take the errors and the Pulse findings offline so they can be worked through and closed out."
            exportLabel={format === 'json' ? 'Download JSON' : 'Download CSV'}
            onClose={onClose}
            onExport={handleExport}
            isExporting={exporting}
            exportDisabled={scope === 'errors' && matchedCount === 0}
            footerContent={
                scope === 'errors'
                    ? `${Number(matchedCount).toLocaleString()} signature${matchedCount === 1 ? '' : 's'} match — ${activeFilterSummary}`
                    : `Read from Pulse aggregates for the ${PULSE_PERIODS.find((item) => item.id === period)?.label.toLowerCase()}`
            }
        >
            <div className="space-y-5">
                <section>
                    <h4 className="text-xs font-semibold uppercase tracking-[0.1em] text-slate-500">What to export</h4>
                    <div className="mt-2 grid gap-2 sm:grid-cols-2">
                        {SCOPES.map((item) => (
                            <ChoiceCard
                                key={item.id}
                                active={scope === item.id}
                                title={item.title}
                                blurb={item.blurb}
                                onSelect={() => setScope(item.id)}
                            />
                        ))}
                    </div>
                </section>

                <section>
                    <h4 className="text-xs font-semibold uppercase tracking-[0.1em] text-slate-500">Format</h4>
                    <div className="mt-2 grid gap-2 sm:grid-cols-2">
                        {FORMATS[scope].map((item) => (
                            <ChoiceCard
                                key={item.id}
                                active={format === item.id}
                                title={item.title}
                                blurb={item.blurb}
                                onSelect={() => setFormat(item.id)}
                            />
                        ))}
                    </div>
                </section>

                {scope === 'errors' && format === 'json' ? (
                    <section>
                        <h4 className="text-xs font-semibold uppercase tracking-[0.1em] text-slate-500">Occurrences per error</h4>
                        <div className="mt-2 flex flex-wrap items-center gap-2">
                            {OCCURRENCE_CHOICES.map((choice) => (
                                <button
                                    key={choice}
                                    type="button"
                                    onClick={() => setOccurrences(choice)}
                                    aria-pressed={occurrences === choice}
                                    className={`rounded-md px-3 py-1.5 text-xs font-semibold ring-1 ring-inset transition ${
                                        occurrences === choice
                                            ? 'bg-teal-600 text-white ring-teal-600'
                                            : 'bg-white text-slate-600 ring-slate-200 hover:bg-slate-50'
                                    }`}
                                >
                                    {choice}
                                </button>
                            ))}
                            <p className="text-xs text-slate-500">Each occurrence carries its own stack trace, so larger files.</p>
                        </div>
                    </section>
                ) : null}

                {scope === 'pulse' ? (
                    <section>
                        <h4 className="text-xs font-semibold uppercase tracking-[0.1em] text-slate-500">Period</h4>
                        <select
                            value={period}
                            onChange={(event) => setPeriod(event.target.value)}
                            className="crm-select mt-2 w-full sm:max-w-xs"
                            aria-label="Pulse period"
                        >
                            {PULSE_PERIODS.map((item) => (
                                <option key={item.id} value={item.id}>{item.label}</option>
                            ))}
                        </select>
                        <p className="mt-2 text-xs text-slate-500">
                            Pulse keeps seven days of history. Anything older has already been trimmed.
                        </p>
                    </section>
                ) : null}

                {bundleTruncated ? (
                    <p className="rounded-md border border-amber-200 bg-amber-50 p-3 text-xs text-amber-800">
                        {Number(matchedCount).toLocaleString()} signatures match, but a JSON bundle carries stack traces and is
                        capped at the {MAX_BUNDLE_GROUPS} most recently seen. Narrow the filters, or take the CSV for the full list.
                    </p>
                ) : null}
            </div>
        </ExportModal>
    );
}
