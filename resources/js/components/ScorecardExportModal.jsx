import React, { useEffect, useMemo, useState } from 'react';
import { useMutation, useQuery } from '@tanstack/react-query';
import api from '../services/api';
import ExportModal from './ExportModal';
import { useToast } from './ToastProvider';
import { formatCurrency, asNumber } from '../utils/currency';

const SECTION_LABELS = {
    revenue: 'Revenue',
    client_snapshot: 'Active Clients',
    daily_peak: 'Daily Peak',
    best_package: 'Best Package',
    conversion: 'Conversion',
    contact_mix: 'Contact Mix',
};

const DEFAULT_SECTIONS = ['revenue', 'client_snapshot', 'daily_peak', 'best_package', 'conversion'];

function formatPrimaryAmount(node) {
    if (!node) {
        return '—';
    }

    if (node.normalized_total !== null && node.normalized_total !== undefined) {
        return formatCurrency(node.normalized_total, node.normalized_currency || 'USD');
    }

    if (node.scalar_amount !== null && node.scalar_amount !== undefined) {
        const currency = Object.keys(node.breakdown || {})[0] || 'KES';
        return formatCurrency(node.scalar_amount, currency);
    }

    return 'Mixed currencies';
}

function saveBlob(blob, fallbackName) {
    const url = URL.createObjectURL(blob);
    const anchor = document.createElement('a');
    anchor.href = url;
    anchor.download = fallbackName;
    document.body.appendChild(anchor);
    anchor.click();
    document.body.removeChild(anchor);
    URL.revokeObjectURL(url);
}

async function readBlobError(blob) {
    try {
        const text = await blob.text();
        const parsed = JSON.parse(text);
        return parsed.message || 'Export failed.';
    } catch {
        return 'Export failed.';
    }
}

function PreviewCard({ label, value, meta }) {
    return (
        <article className="rounded-lg border border-slate-200 bg-slate-50/70 p-4">
            <p className="text-sm font-medium text-slate-600">{label}</p>
            <p className="mt-2 text-xl font-semibold text-slate-900">{value}</p>
            {meta ? <p className="mt-1 text-sm text-slate-500">{meta}</p> : null}
        </article>
    );
}

export default function ScorecardExportModal({
    open,
    onClose,
    platformId,
    fromDate,
    toDate,
    reportingCurrency,
}) {
    const toast = useToast();
    const [selectedSections, setSelectedSections] = useState(DEFAULT_SECTIONS);
    const [dateFormat, setDateFormat] = useState('Y-m-d');
    const isSinglePlatform = Boolean(platformId);
    const isRangeInvalid = Boolean(fromDate && toDate && fromDate > toDate);

    useEffect(() => {
        if (!open) {
            return;
        }

        setDateFormat('Y-m-d');
        setSelectedSections(isSinglePlatform
            ? [...DEFAULT_SECTIONS, 'contact_mix']
            : DEFAULT_SECTIONS
        );
    }, [open, isSinglePlatform]);

    useEffect(() => {
        if (isSinglePlatform) {
            setSelectedSections((current) => (
                current.includes('contact_mix') ? current : [...current, 'contact_mix']
            ));
            return;
        }

        setSelectedSections((current) => current.filter((section) => section !== 'contact_mix'));
    }, [isSinglePlatform]);

    const previewQuery = useQuery({
        queryKey: ['scorecard-preview', open, fromDate, toDate, platformId, selectedSections, reportingCurrency.displayMode, reportingCurrency.targetCurrency],
        queryFn: () => api.get('/crm/reports/scorecard/preview', {
            params: {
                ...(platformId ? { platform_id: platformId } : {}),
                ...(fromDate ? { from: fromDate } : {}),
                ...(toDate ? { to: toDate } : {}),
                sections: selectedSections,
                ...reportingCurrency.queryParams,
            },
        }).then((response) => response.data),
        enabled: open && !isRangeInvalid && selectedSections.length > 0,
        staleTime: 30_000,
    });

    const exportMutation = useMutation({
        mutationFn: () => api.post('/crm/reports/scorecard/export', {
            ...(platformId ? { platform_id: platformId } : {}),
            ...(fromDate ? { from: fromDate } : {}),
            ...(toDate ? { to: toDate } : {}),
            sections: selectedSections,
            date_format: dateFormat,
            ...reportingCurrency.queryParams,
        }, {
            responseType: 'blob',
        }),
        onSuccess: (response) => {
            saveBlob(response.data, 'crm-scorecard.xlsx');
            toast.success('Scorecard export started.');
            onClose?.();
        },
        onError: async (error) => {
            const blob = error?.response?.data;
            toast.error(blob instanceof Blob ? await readBlobError(blob) : 'Scorecard export failed.');
        },
    });

    const cards = useMemo(() => {
        const sections = previewQuery.data?.sections || {};
        const items = [];

        if (sections.revenue) {
            items.push({
                key: 'revenue',
                label: SECTION_LABELS.revenue,
                value: formatPrimaryAmount(sections.revenue.total),
                meta: `${Object.keys(sections.revenue.total?.breakdown || {}).length || 0} currency bucket(s)`,
            });
        }

        if (sections.client_snapshot) {
            items.push({
                key: 'client_snapshot',
                label: SECTION_LABELS.client_snapshot,
                value: `${asNumber(sections.client_snapshot.end_count).toLocaleString()} end-of-range`,
                meta: `${asNumber(sections.client_snapshot.change)} net change`,
            });
        }

        if (sections.daily_peak) {
            items.push({
                key: 'daily_peak',
                label: SECTION_LABELS.daily_peak,
                value: sections.daily_peak.top_day
                    ? formatPrimaryAmount(sections.daily_peak.top_day)
                    : '—',
                meta: sections.daily_peak.top_day?.date || 'No successful payments',
            });
        }

        if (sections.best_package) {
            const topPackage = sections.best_package.rows?.[0];
            items.push({
                key: 'best_package',
                label: SECTION_LABELS.best_package,
                value: topPackage?.label || '—',
                meta: topPackage ? formatCurrency(topPackage.normalized_total ?? topPackage.value ?? 0, topPackage.normalized_currency || Object.keys(topPackage.revenue_breakdown || {})[0] || 'KES') : 'No package revenue',
            });
        }

        if (sections.conversion) {
            items.push({
                key: 'conversion',
                label: SECTION_LABELS.conversion,
                value: `${asNumber(sections.conversion.conversion_rate)}%`,
                meta: `${asNumber(sections.conversion.totals?.converted).toLocaleString()} converted`,
            });
        }

        if (sections.contact_mix) {
            const platformCount = sections.contact_mix.platforms?.length || 0;
            const totalContacts = (sections.contact_mix.platforms || []).reduce((sum, platform) => (
                sum + Object.values(platform.platform_contact_mix || {}).reduce((innerSum, row) => innerSum + asNumber(row?.total), 0)
            ), 0);
            items.push({
                key: 'contact_mix',
                label: SECTION_LABELS.contact_mix,
                value: `${totalContacts.toLocaleString()} tracked actions`,
                meta: `${platformCount} market${platformCount === 1 ? '' : 's'}`,
            });
        }

        return items;
    }, [previewQuery.data]);

    const toggleSection = (section) => {
        if (section === 'contact_mix' && !isSinglePlatform) {
            return;
        }

        setSelectedSections((current) => (
            current.includes(section)
                ? current.filter((item) => item !== section)
                : [...current, section]
        ));
    };

    return (
        <ExportModal
            open={open}
            onClose={onClose}
            onExport={() => exportMutation.mutate()}
            exportDisabled={isRangeInvalid || selectedSections.length === 0 || exportMutation.isPending}
            isExporting={exportMutation.isPending}
            title="Export Scorecard"
            subtitle="Build a multi-sheet Excel scorecard for the current report range."
            footerContent={previewQuery.isLoading ? 'Refreshing preview...' : `${selectedSections.length} section${selectedSections.length === 1 ? '' : 's'} selected`}
        >
            <div className="space-y-5">
                {isRangeInvalid ? (
                    <p className="rounded-md border border-rose-200 bg-rose-50 px-3 py-2 text-sm text-rose-700">
                        Choose a valid date range before exporting.
                    </p>
                ) : null}

                <section className="grid gap-3 md:grid-cols-2">
                    {Object.entries(SECTION_LABELS).map(([section, label]) => {
                        const disabled = section === 'contact_mix' && !isSinglePlatform;

                        return (
                            <label key={section} className={`flex items-center gap-3 rounded-lg border px-3 py-3 text-sm ${disabled ? 'border-slate-200 bg-slate-50 text-slate-400' : 'border-slate-200 bg-white text-slate-700'}`}>
                                <input
                                    type="checkbox"
                                    checked={selectedSections.includes(section)}
                                    onChange={() => toggleSection(section)}
                                    disabled={disabled}
                                    className="h-4 w-4 rounded border-slate-300 text-teal-700 focus:ring-teal-200"
                                />
                                <span className="font-medium">{label}</span>
                            </label>
                        );
                    })}
                </section>

                <section className="grid gap-3 md:grid-cols-2">
                    <label className="space-y-1">
                        <span className="text-sm font-medium text-slate-700">Date format</span>
                        <select
                            value={dateFormat}
                            onChange={(event) => setDateFormat(event.target.value)}
                            className="crm-select"
                        >
                            <option value="Y-m-d">YYYY-MM-DD</option>
                            <option value="d/m/Y">DD/MM/YYYY</option>
                            <option value="m/d/Y">MM/DD/YYYY</option>
                        </select>
                    </label>
                </section>

                <section className="space-y-3">
                    <div className="flex items-center justify-between gap-3">
                        <h4 className="text-sm font-semibold text-slate-800">Preview</h4>
                        {previewQuery.isFetching ? <span className="text-xs text-slate-500">Refreshing…</span> : null}
                    </div>
                    {previewQuery.isLoading ? (
                        <div className="grid gap-3 md:grid-cols-2">
                            {selectedSections.map((section) => (
                                <div key={section} className="h-24 animate-pulse rounded-lg bg-slate-100" />
                            ))}
                        </div>
                    ) : previewQuery.isError ? (
                        <p className="rounded-md border border-rose-200 bg-rose-50 px-3 py-2 text-sm text-rose-700">
                            Preview could not be loaded.
                        </p>
                    ) : (
                        <div className="grid gap-3 md:grid-cols-2">
                            {cards.map((card) => (
                                <PreviewCard key={card.key} label={card.label} value={card.value} meta={card.meta} />
                            ))}
                        </div>
                    )}
                </section>
            </div>
        </ExportModal>
    );
}
