import React, { useEffect, useState } from 'react';
import { useMutation } from '@tanstack/react-query';
import api from '../services/api';
import ExportModal from './ExportModal';
import { useToast } from './ToastProvider';

const COLUMN_OPTIONS = [
    ['id', 'ID'],
    ['phone', 'Phone'],
    ['amount', 'Amount'],
    ['currency', 'Currency'],
    ['status', 'Status'],
    ['completed_at', 'Completed At'],
    ['created_at', 'Created At'],
    ['transaction_reference', 'Transaction Reference'],
    ['client_name', 'Client Name'],
    ['deal_subscription_lifecycle', 'Subscription Lifecycle'],
    ['product_name', 'Product Name'],
    ['match_confidence', 'Match Confidence'],
];

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

export default function PaymentExportModal({
    open,
    onClose,
    activeFilters,
}) {
    const toast = useToast();
    const [selectedColumns, setSelectedColumns] = useState(COLUMN_OPTIONS.map(([key]) => key));
    const [dateFormat, setDateFormat] = useState('Y-m-d');

    useEffect(() => {
        if (!open) {
            return;
        }

        setSelectedColumns(COLUMN_OPTIONS.map(([key]) => key));
        setDateFormat('Y-m-d');
    }, [open]);

    const exportMutation = useMutation({
        mutationFn: () => api.post('/crm/payments/export', {
            ...activeFilters,
            columns: selectedColumns,
            date_format: dateFormat,
        }, {
            responseType: 'blob',
        }),
        onSuccess: (response) => {
            saveBlob(response.data, 'crm-payments.xlsx');
            toast.success('Payments export started.');
            onClose?.();
        },
        onError: async (error) => {
            const blob = error?.response?.data;
            toast.error(blob instanceof Blob ? await readBlobError(blob) : 'Payments export failed.');
        },
    });

    const toggleColumn = (column) => {
        setSelectedColumns((current) => (
            current.includes(column)
                ? current.filter((item) => item !== column)
                : [...current, column]
        ));
    };

    return (
        <ExportModal
            open={open}
            onClose={onClose}
            onExport={() => exportMutation.mutate()}
            exportDisabled={selectedColumns.length === 0 || exportMutation.isPending}
            isExporting={exportMutation.isPending}
            title="Export Payments"
            subtitle="Export the current payments workspace filters to Excel."
            footerContent={`${selectedColumns.length} column${selectedColumns.length === 1 ? '' : 's'} selected`}
        >
            <div className="space-y-5">
                <section className="grid gap-3 md:grid-cols-2">
                    {COLUMN_OPTIONS.map(([column, label]) => (
                        <label key={column} className="flex items-center gap-3 rounded-lg border border-slate-200 bg-white px-3 py-3 text-sm text-slate-700">
                            <input
                                type="checkbox"
                                checked={selectedColumns.includes(column)}
                                onChange={() => toggleColumn(column)}
                                className="h-4 w-4 rounded border-slate-300 text-teal-700 focus:ring-teal-200"
                            />
                            <span className="font-medium">{label}</span>
                        </label>
                    ))}
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
            </div>
        </ExportModal>
    );
}
