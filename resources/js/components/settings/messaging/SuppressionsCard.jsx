import React from 'react';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import api from '../../../services/api';

function formatDate(value) {
    if (!value) return 'Never';
    const date = new Date(value);
    return Number.isNaN(date.getTime()) ? 'Never' : date.toLocaleString();
}

export default function SuppressionsCard({ statusChip, toast }) {
    const queryClient = useQueryClient();
    const suppressionsQuery = useQuery({
        queryKey: ['messaging-suppressions'],
        queryFn: () => api.get('/crm/messaging/suppressions?active_only=1&channel=whatsapp').then((response) => response.data),
    });
    const rows = suppressionsQuery.data?.data || [];

    const revokeMutation = useMutation({
        mutationFn: (suppression) => api.post(`/crm/messaging/suppressions/${suppression.id}/revoke`).then((response) => response.data),
        onSuccess: () => {
            toast?.success?.('Suppression revoked.');
            queryClient.invalidateQueries({ queryKey: ['messaging-suppressions'] });
        },
        onError: (error) => toast?.error?.(error.response?.data?.message || 'Could not revoke suppression.'),
    });

    return (
        <section className="rounded-lg border border-slate-200 bg-white">
            <div className="flex flex-wrap items-center justify-between gap-2 border-b border-slate-200 px-4 py-3">
                <div>
                    <h4 className="text-sm font-semibold text-slate-900">WhatsApp Suppressions</h4>
                    <p className="mt-1 text-xs text-slate-500">Active opt-outs recorded from inbound STOP keywords or admin action.</p>
                </div>
                <span className={`inline-flex rounded-md px-2 py-0.5 text-xs font-medium ring-1 ring-inset ${statusChip(rows.length ? 'configured_disabled' : 'connected')}`}>
                    {rows.length} active
                </span>
            </div>
            <div className="overflow-x-auto">
                <table className="min-w-full divide-y divide-slate-200 text-sm">
                    <thead className="bg-slate-50 text-left text-xs font-semibold uppercase tracking-wide text-slate-500">
                        <tr>
                            <th className="px-4 py-2">Phone</th>
                            <th className="px-4 py-2">Market</th>
                            <th className="px-4 py-2">Reason</th>
                            <th className="px-4 py-2">Recorded</th>
                            <th className="px-4 py-2 text-right">Action</th>
                        </tr>
                    </thead>
                    <tbody className="divide-y divide-slate-100">
                        {rows.map((suppression) => (
                            <tr key={suppression.id}>
                                <td className="px-4 py-3 font-medium text-slate-900">{suppression.phone_e164}</td>
                                <td className="px-4 py-3 text-slate-700">{suppression.platform?.name || 'Global'}</td>
                                <td className="px-4 py-3 text-slate-700">{String(suppression.reason || 'manual').replaceAll('_', ' ')}</td>
                                <td className="px-4 py-3 text-xs text-slate-500">{formatDate(suppression.opted_out_at)}</td>
                                <td className="px-4 py-3 text-right">
                                    <button
                                        type="button"
                                        disabled={revokeMutation.isPending}
                                        onClick={() => revokeMutation.mutate(suppression)}
                                        className="crm-btn-secondary px-2.5 py-1.5 text-xs disabled:cursor-not-allowed disabled:opacity-60"
                                    >
                                        Revoke
                                    </button>
                                </td>
                            </tr>
                        ))}
                        {!rows.length ? (
                            <tr>
                                <td colSpan={5} className="px-4 py-6 text-center text-sm text-slate-500">
                                    {suppressionsQuery.isLoading ? 'Loading suppressions...' : 'No active WhatsApp suppressions.'}
                                </td>
                            </tr>
                        ) : null}
                    </tbody>
                </table>
            </div>
        </section>
    );
}
