import React, { useEffect, useMemo, useState } from 'react';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import api from '../services/api';
import { useToast } from './ToastProvider';

const METHOD_OPTIONS = [
    {
        key: 'setup_link',
        label: 'Setup link',
        description: 'Recommended: client sets password using secure reset link.',
    },
    {
        key: 'temporary_password',
        label: 'Temporary password',
        description: 'Set a temporary password and ask client to reset after first login.',
    },
];

const CHANNEL_OPTIONS = [
    { key: 'both', label: 'Email + SMS' },
    { key: 'email', label: 'Email only' },
    { key: 'sms', label: 'SMS only' },
];

const TIMING_OPTIONS = [
    { key: 'send_now', label: 'Send now', hint: 'Deliver immediately and log provider results.' },
    { key: 'manual_send_later', label: 'Manual send later', hint: 'Store in queue and send when ready.' },
];

const statusTone = {
    deferred: 'bg-amber-50 text-amber-700 ring-amber-200',
    sent: 'bg-emerald-50 text-emerald-700 ring-emerald-200',
    partial: 'bg-orange-50 text-orange-700 ring-orange-200',
    failed: 'bg-rose-50 text-rose-700 ring-rose-200',
};

function normalizePhone(phone) {
    if (!phone) return '';
    const cleaned = String(phone).replace(/[^\d+]/g, '').replace(/^\+/, '');
    if (cleaned.startsWith('0')) return `254${cleaned.slice(1)}`;
    return cleaned;
}

export default function CredentialDispatchDrawer({
    open,
    onClose,
    client,
    defaultReason = 'Client onboarding credentials dispatch',
    defaultSource = 'crm',
    onSuccess,
}) {
    const queryClient = useQueryClient();
    const toast = useToast();
    const [form, setForm] = useState({
        method: 'setup_link',
        channel: 'both',
        timing: 'send_now',
        recipient_email: '',
        recipient_phone: '',
        temporary_password: '',
        reason: defaultReason,
    });

    useEffect(() => {
        if (!open || !client) {
            return;
        }

        setForm({
            method: 'setup_link',
            channel: 'both',
            timing: 'send_now',
            recipient_email: client.email || '',
            recipient_phone: client.phone_normalized || '',
            temporary_password: '',
            reason: defaultReason,
        });
    }, [open, client, defaultReason]);

    const dispatchHistoryQuery = useQuery({
        queryKey: ['client-credential-dispatches', client?.id],
        queryFn: () => api.get(`/crm/clients/${client.id}/credentials/dispatches`, {
            params: { per_page: 10 },
        }).then((response) => response.data),
        enabled: Boolean(open && client?.id),
    });

    const sendMutation = useMutation({
        mutationFn: (payload) => api.post(`/crm/clients/${client.id}/credentials/dispatch`, payload).then((response) => response.data),
        onSuccess: (result) => {
            queryClient.invalidateQueries({ queryKey: ['client-credential-dispatches', client?.id] });
            queryClient.invalidateQueries({ queryKey: ['client-timeline', client?.id] });
            queryClient.invalidateQueries({ queryKey: ['client', client?.id] });

            const status = result?.dispatch?.status;
            if (status === 'sent') {
                toast.success('Credentials delivered successfully.');
            } else if (status === 'partial') {
                toast.warning('Credentials partially delivered. Review failed channel and retry.');
            } else if (status === 'deferred') {
                toast.info('Credential dispatch queued for manual send later.');
            } else {
                toast.error('Credential delivery failed. Review provider response and retry.');
            }

            if (typeof onSuccess === 'function') {
                onSuccess(result);
            }
        },
        onError: (error) => {
            toast.error(error?.response?.data?.message || 'Credential dispatch failed.');
        },
    });

    const retryMutation = useMutation({
        mutationFn: ({ dispatchId, payload }) => api.post(
            `/crm/clients/${client.id}/credentials/dispatches/${dispatchId}/retry`,
            payload,
        ).then((response) => response.data),
        onSuccess: () => {
            queryClient.invalidateQueries({ queryKey: ['client-credential-dispatches', client?.id] });
            queryClient.invalidateQueries({ queryKey: ['client-timeline', client?.id] });
            toast.success('Credential dispatch retried.');
        },
        onError: (error) => {
            toast.error(error?.response?.data?.message || 'Retry failed.');
        },
    });

    const requiresEmailNow = useMemo(
        () => form.timing === 'send_now' && (form.channel === 'email' || form.channel === 'both'),
        [form.channel, form.timing],
    );

    const requiresPhoneNow = useMemo(
        () => form.timing === 'send_now' && (form.channel === 'sms' || form.channel === 'both'),
        [form.channel, form.timing],
    );

    const canSubmit =
        Boolean(client?.id)
        && form.reason.trim().length > 0
        && (!requiresEmailNow || form.recipient_email.trim().length > 0)
        && (!requiresPhoneNow || normalizePhone(form.recipient_phone).length > 0)
        && !sendMutation.isPending;

    if (!open || !client) {
        return null;
    }

    const historyRows = dispatchHistoryQuery.data?.data || [];

    return (
        <div className="fixed inset-0 z-[70] bg-slate-900/45" onClick={onClose}>
            <aside
                className="absolute right-0 top-0 h-full w-full max-w-xl overflow-y-auto border-l border-slate-200 bg-white shadow-2xl"
                onClick={(event) => event.stopPropagation()}
            >
                <header className="sticky top-0 z-10 border-b border-slate-200 bg-white/95 px-5 py-4 backdrop-blur">
                    <div className="flex items-start justify-between gap-3">
                        <div>
                            <p className="text-xs font-semibold uppercase tracking-[0.12em] text-slate-500">Client credentials</p>
                            <h3 className="mt-1 text-lg font-semibold text-slate-900">Dispatch access details</h3>
                            <p className="mt-1 text-xs text-slate-500">
                                {client.name || `Client #${client.id}`} • CRM #{client.id}
                            </p>
                        </div>
                        <button
                            type="button"
                            onClick={onClose}
                            className="rounded-md border border-slate-200 px-2.5 py-1.5 text-xs font-semibold text-slate-600 transition hover:bg-slate-50"
                        >
                            Close
                        </button>
                    </div>
                </header>

                <div className="space-y-4 px-5 py-4">
                    <div className="rounded-md border border-teal-200 bg-teal-50/70 px-3 py-2 text-xs text-teal-800">
                        Recommended default: <span className="font-semibold">Setup link + Email/SMS + Send now</span> for secure onboarding.
                    </div>

                    <section>
                        <p className="mb-2 text-xs font-semibold uppercase tracking-[0.09em] text-slate-500">Method</p>
                        <div className="space-y-2">
                            {METHOD_OPTIONS.map((option) => (
                                <button
                                    key={option.key}
                                    type="button"
                                    onClick={() => setForm((current) => ({ ...current, method: option.key }))}
                                    className={`w-full rounded-md border px-3 py-2 text-left transition ${
                                        form.method === option.key
                                            ? 'border-teal-300 bg-teal-50'
                                            : 'border-slate-200 bg-white hover:bg-slate-50'
                                    }`}
                                >
                                    <p className="text-sm font-semibold text-slate-900">{option.label}</p>
                                    <p className="mt-0.5 text-xs text-slate-600">{option.description}</p>
                                </button>
                            ))}
                        </div>
                    </section>

                    <section className="grid gap-3 md:grid-cols-2">
                        <div>
                            <p className="mb-2 text-xs font-semibold uppercase tracking-[0.09em] text-slate-500">Channel</p>
                            <div className="inline-flex rounded-lg border border-slate-200 bg-slate-50 p-1">
                                {CHANNEL_OPTIONS.map((option) => (
                                    <button
                                        key={option.key}
                                        type="button"
                                        onClick={() => setForm((current) => ({ ...current, channel: option.key }))}
                                        className={`rounded-md px-2.5 py-1.5 text-xs font-semibold transition ${
                                            form.channel === option.key
                                                ? 'bg-white text-slate-900 shadow-sm'
                                                : 'text-slate-600 hover:text-slate-900'
                                        }`}
                                    >
                                        {option.label}
                                    </button>
                                ))}
                            </div>
                        </div>

                        <div>
                            <p className="mb-2 text-xs font-semibold uppercase tracking-[0.09em] text-slate-500">Timing</p>
                            <div className="space-y-1.5">
                                {TIMING_OPTIONS.map((option) => (
                                    <button
                                        key={option.key}
                                        type="button"
                                        onClick={() => setForm((current) => ({ ...current, timing: option.key }))}
                                        className={`w-full rounded-md border px-3 py-2 text-left transition ${
                                            form.timing === option.key
                                                ? 'border-teal-300 bg-teal-50'
                                                : 'border-slate-200 hover:bg-slate-50'
                                        }`}
                                    >
                                        <p className="text-xs font-semibold text-slate-900">{option.label}</p>
                                        <p className="text-[11px] text-slate-500">{option.hint}</p>
                                    </button>
                                ))}
                            </div>
                        </div>
                    </section>

                    <section className="grid gap-3 md:grid-cols-2">
                        <div>
                            <label className="mb-1 block text-sm font-medium text-slate-700">Recipient email</label>
                            <input
                                type="email"
                                value={form.recipient_email}
                                onChange={(event) => setForm((current) => ({ ...current, recipient_email: event.target.value }))}
                                className="crm-input"
                                placeholder="client@example.com"
                            />
                            {requiresEmailNow && !form.recipient_email.trim() ? (
                                <p className="mt-1 text-[11px] text-rose-600">Required for selected channel when sending now.</p>
                            ) : null}
                        </div>

                        <div>
                            <label className="mb-1 block text-sm font-medium text-slate-700">Recipient phone</label>
                            <input
                                type="text"
                                value={form.recipient_phone}
                                onChange={(event) => setForm((current) => ({ ...current, recipient_phone: event.target.value }))}
                                className="crm-input"
                                placeholder="2547XXXXXXXX"
                            />
                            {requiresPhoneNow && !normalizePhone(form.recipient_phone) ? (
                                <p className="mt-1 text-[11px] text-rose-600">Required for selected channel when sending now.</p>
                            ) : null}
                        </div>
                    </section>

                    {form.method === 'temporary_password' ? (
                        <section>
                            <label className="mb-1 block text-sm font-medium text-slate-700">Temporary password (optional)</label>
                            <input
                                type="text"
                                value={form.temporary_password}
                                onChange={(event) => setForm((current) => ({ ...current, temporary_password: event.target.value }))}
                                className="crm-input"
                                placeholder="Auto-generated if blank"
                            />
                            <p className="mt-1 text-[11px] text-slate-500">Password value is never stored in audit logs or dispatch records.</p>
                        </section>
                    ) : null}

                    <section>
                        <label className="mb-1 block text-sm font-medium text-slate-700">Reason</label>
                        <textarea
                            rows={3}
                            value={form.reason}
                            onChange={(event) => setForm((current) => ({ ...current, reason: event.target.value }))}
                            className="crm-input"
                        />
                    </section>

                    <footer className="flex flex-wrap items-center justify-end gap-2 border-t border-slate-200 pt-3">
                        <button type="button" onClick={onClose} className="crm-btn-secondary">Cancel</button>
                        <button
                            type="button"
                            disabled={!canSubmit}
                            onClick={() => {
                                sendMutation.mutate({
                                    method: form.method,
                                    channel: form.channel,
                                    timing: form.timing,
                                    recipient_email: form.recipient_email.trim() || null,
                                    recipient_phone: normalizePhone(form.recipient_phone.trim()) || null,
                                    temporary_password: form.method === 'temporary_password'
                                        ? (form.temporary_password.trim() || null)
                                        : null,
                                    reason: form.reason.trim(),
                                    source: defaultSource,
                                });
                            }}
                            className="crm-btn-primary disabled:cursor-not-allowed disabled:opacity-50"
                        >
                            {sendMutation.isPending
                                ? 'Processing...'
                                : form.timing === 'manual_send_later'
                                    ? 'Queue for manual send'
                                    : 'Send credentials'}
                        </button>
                    </footer>

                    <section className="border-t border-slate-200 pt-4">
                        <div className="flex items-center justify-between gap-2">
                            <p className="text-xs font-semibold uppercase tracking-[0.09em] text-slate-500">Recent dispatches</p>
                            {dispatchHistoryQuery.isFetching ? (
                                <span className="text-[11px] text-slate-400">Refreshing...</span>
                            ) : null}
                        </div>

                        <div className="mt-2 space-y-2">
                            {historyRows.length > 0 ? historyRows.map((row) => (
                                <article key={row.id} className="rounded-md border border-slate-200 bg-white p-3">
                                    <div className="flex flex-wrap items-start justify-between gap-2">
                                        <div>
                                            <p className="text-xs font-semibold text-slate-900">
                                                {row.method === 'setup_link' ? 'Setup link' : 'Temporary password'} • {row.channel}
                                            </p>
                                            <p className="mt-0.5 text-[11px] text-slate-500">
                                                {row.timing === 'manual_send_later' ? 'Queued' : 'Immediate'} • {new Date(row.created_at).toLocaleString()}
                                            </p>
                                        </div>
                                        <span className={`inline-flex items-center rounded-md px-2 py-0.5 text-[11px] font-semibold ring-1 ring-inset ${statusTone[row.status] || 'bg-slate-100 text-slate-700 ring-slate-200'}`}>
                                            {row.status}
                                        </span>
                                    </div>

                                    <p className="mt-1 text-[11px] text-slate-600">
                                        {row.recipient_email ? `Email: ${row.recipient_email}` : 'No email'} • {row.recipient_phone ? `Phone: ${row.recipient_phone}` : 'No phone'}
                                    </p>

                                    {row.error_message ? (
                                        <p className="mt-1 text-[11px] text-rose-700">{row.error_message}</p>
                                    ) : null}

                                    {['deferred', 'failed', 'partial'].includes(row.status) ? (
                                        <div className="mt-2 flex justify-end">
                                            <button
                                                type="button"
                                                disabled={retryMutation.isPending}
                                                onClick={() => {
                                                    retryMutation.mutate({
                                                        dispatchId: row.id,
                                                        payload: {
                                                            recipient_email: form.recipient_email.trim() || null,
                                                            recipient_phone: normalizePhone(form.recipient_phone.trim()) || null,
                                                            temporary_password: form.method === 'temporary_password'
                                                                ? (form.temporary_password.trim() || null)
                                                                : null,
                                                            reason: `Retry credential dispatch #${row.id} from CRM drawer`,
                                                        },
                                                    });
                                                }}
                                                className="rounded-md border border-slate-300 bg-slate-50 px-2.5 py-1 text-[11px] font-semibold text-slate-700 transition hover:bg-slate-100 disabled:cursor-not-allowed disabled:opacity-50"
                                            >
                                                {retryMutation.isPending ? 'Retrying...' : 'Retry send'}
                                            </button>
                                        </div>
                                    ) : null}
                                </article>
                            )) : (
                                <p className="rounded-md border border-dashed border-slate-300 bg-slate-50 px-3 py-6 text-center text-xs text-slate-500">
                                    No credential dispatch records yet.
                                </p>
                            )}
                        </div>
                    </section>
                </div>
            </aside>
        </div>
    );
}
