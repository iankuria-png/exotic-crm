import React, { useEffect, useMemo, useState } from 'react';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import api from '../../services/api';
import { useToast } from '../ToastProvider';
import { copyToClipboard } from '../../utils/clipboard';

function situationLabel(value) {
    const labels = {
        expired: 'Expired',
        expiring: 'Expiring soon',
        never_paid: 'Never paid',
        active: 'Active',
    };

    return labels[value] || 'Check-in';
}

const LIFECYCLE_FLOW_OPTIONS = [
    { value: 'reactivation', label: 'Win-back' },
    { value: 'onboarding', label: 'Welcome & activate' },
];

const LIFECYCLE_SKIP_LABELS = {
    disabled_global: 'Lifecycle SMS is switched off globally',
    market_sms_disabled: 'Lifecycle SMS is off for this market',
    flow_disabled: 'This flow is off for this market',
    market_no_psp: 'This market has no payment provider',
    client_already_active: 'Client already has an active subscription',
    already_sent: 'Already sent recently',
    rate_capped: 'Client hit the reminder cap',
    no_template: 'No template configured for this flow',
    no_offer_configured: 'No offer plan set for this market',
    missing_phone: 'No phone number on file',
    reminders_paused: 'Reminders are paused for this client',
    signup_source_excluded: 'Not an eligible signup source',
    quiet_hours: 'Market is in quiet hours',
};

function lifecycleSkipLabel(reason) {
    return LIFECYCLE_SKIP_LABELS[reason] || reason || 'Not eligible right now';
}

function suggestedFlow(situation) {
    if (situation === 'never_paid') return 'onboarding';
    return 'reactivation';
}

// Dynamic, link-bearing lifecycle SMS — routed through the same gated service the
// automation uses (dedup / state / quiet hours / pause all apply). Preview before
// send shows exactly what the client will receive.
function LifecycleSmsBlock({ client, situation, toast }) {
    const queryClient = useQueryClient();
    const clientId = client?.id;
    const [flow, setFlow] = useState(() => suggestedFlow(situation));

    const previewQuery = useQuery({
        queryKey: ['client-lifecycle-preview', clientId, flow],
        queryFn: () => api.get(`/crm/clients/${clientId}/lifecycle-sms/preview`, { params: { flow } }).then((r) => r.data),
        enabled: Boolean(clientId),
    });

    const sendMutation = useMutation({
        mutationFn: () => api.post(`/crm/clients/${clientId}/lifecycle-sms`, { flow }).then((r) => r.data),
        onSuccess: (data) => {
            queryClient.invalidateQueries({ queryKey: ['client-lifecycle-preview', clientId] });
            toast?.success?.(data?.message || 'Lifecycle SMS sent.');
        },
        onError: (err) => toast?.error?.(err?.response?.data?.message || 'Send failed.'),
    });

    const preview = previewQuery.data;

    return (
        <section className="mb-3 rounded-lg border border-teal-200 bg-teal-50/40 px-3 py-2">
            <div className="flex items-center gap-2">
                <span className="text-[11px] font-semibold uppercase tracking-wide text-teal-800">SMS with pay link</span>
                <div className="ml-auto flex items-center gap-2">
                    <select value={flow} onChange={(e) => setFlow(e.target.value)} className="crm-select text-xs" aria-label="Lifecycle flow">
                        {LIFECYCLE_FLOW_OPTIONS.map((opt) => (
                            <option key={opt.value} value={opt.value}>{opt.label}</option>
                        ))}
                    </select>
                    <button
                        type="button"
                        disabled={sendMutation.isPending || !preview?.would_send}
                        title={!preview?.would_send ? lifecycleSkipLabel(preview?.skip_reason) : 'Send SMS with payment link'}
                        onClick={() => {
                            if (window.confirm(`Send the ${flow === 'onboarding' ? 'Welcome & activate' : 'Win-back'} SMS to ${client?.name || 'this client'}?`)) {
                                sendMutation.mutate();
                            }
                        }}
                        className="rounded-md bg-teal-700 px-3 py-1.5 text-xs font-semibold text-white hover:bg-teal-800 disabled:cursor-not-allowed disabled:opacity-60"
                    >
                        {sendMutation.isPending ? 'Sending…' : 'Send'}
                    </button>
                </div>
            </div>
            {previewQuery.isLoading ? null : preview?.body ? (
                <p className="mt-1.5 line-clamp-2 text-[11px] leading-5 text-slate-600" title={preview.body}>{preview.body}</p>
            ) : (
                <p className="mt-1.5 text-[11px] text-slate-400">No lifecycle template for this flow / market.</p>
            )}
            {preview && !preview.would_send ? (
                <p className="mt-1 text-[11px] text-amber-600">Can’t send: {lifecycleSkipLabel(preview.skip_reason)}.</p>
            ) : null}
        </section>
    );
}

export default function QuickReplyModal({ client, onClose }) {
    const toast = useToast();
    const [copiedId, setCopiedId] = useState(null);
    const clientId = client?.id;

    const repliesQuery = useQuery({
        queryKey: ['client-quick-replies', clientId],
        queryFn: () => api.get(`/crm/clients/${clientId}/quick-replies`).then((response) => response.data),
        enabled: !!clientId,
    });

    useEffect(() => {
        if (!copiedId) return undefined;
        const timeout = setTimeout(() => setCopiedId(null), 2000);
        return () => clearTimeout(timeout);
    }, [copiedId]);

    useEffect(() => {
        const handleEscape = (event) => {
            if (event.key === 'Escape') {
                onClose?.();
            }
        };

        window.addEventListener('keydown', handleEscape);
        return () => window.removeEventListener('keydown', handleEscape);
    }, [onClose]);

    const payload = repliesQuery.data || {};
    const messages = useMemo(() => payload.messages || [], [payload.messages]);
    const whatsappPhone = payload.whatsapp_phone || null;

    const handleCopy = async (message) => {
        try {
            await copyToClipboard(message.body);
            setCopiedId(message.id);
            toast.success('Message copied');
        } catch {
            toast.error('Clipboard blocked. Select the message and copy manually.');
        }
    };

    const handleWhatsApp = (message) => {
        if (!whatsappPhone) {
            return;
        }

        window.open(
            `https://wa.me/${whatsappPhone}?text=${encodeURIComponent(message.body)}`,
            '_blank',
            'noopener,noreferrer'
        );
    };

    if (!client) {
        return null;
    }

    return (
        <div className="fixed inset-0 z-[110] flex items-center justify-center bg-slate-900/45 p-4" onClick={onClose}>
            <div
                role="dialog"
                aria-modal="true"
                aria-labelledby="quick-reply-title"
                className="flex max-h-[86vh] w-full max-w-2xl flex-col overflow-hidden rounded-lg border border-slate-200 bg-white shadow-xl"
                onClick={(event) => event.stopPropagation()}
            >
                <header className="crm-panel-header">
                    <div className="min-w-0">
                        <h3 id="quick-reply-title" className="crm-panel-title truncate">
                            Quick outreach - {client.name || `Client #${client.id}`}
                        </h3>
                        <p className="crm-panel-subtitle">
                            {situationLabel(payload.situation)} - {client.phone_normalized || 'No phone'}
                        </p>
                    </div>
                    <button
                        type="button"
                        onClick={onClose}
                        className="flex h-8 w-8 shrink-0 items-center justify-center rounded-md border border-slate-200 text-slate-500 transition hover:bg-slate-50 hover:text-slate-700 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-slate-400"
                        aria-label="Close quick outreach"
                    >
                        <svg className="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
                            <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M6 18 18 6M6 6l12 12" />
                        </svg>
                    </button>
                </header>

                <div className="min-h-[220px] flex-1 overflow-y-auto p-4">
                    <LifecycleSmsBlock client={client} situation={payload.situation} toast={toast} />

                    {repliesQuery.isLoading ? (
                        <div className="flex items-center justify-center gap-2 py-12 text-sm text-slate-500">
                            <div className="h-5 w-5 animate-spin rounded-full border-2 border-teal-600 border-t-transparent" />
                            Loading quick replies...
                        </div>
                    ) : repliesQuery.isError ? (
                        <div className="rounded-md border border-rose-200 bg-rose-50 px-3 py-2 text-sm text-rose-700">
                            Quick replies could not be loaded.
                        </div>
                    ) : messages.length === 0 ? (
                        <div className="rounded-md border border-slate-200 bg-slate-50 px-3 py-2 text-sm text-slate-600">
                            No quick replies configured - add them in Settings &gt; Templates.
                        </div>
                    ) : (
                        <div className="space-y-3">
                            {messages.map((message) => (
                                <article key={message.id} className="rounded-lg border border-slate-200 bg-white p-3 shadow-sm">
                                    <div className="mb-2 flex flex-wrap items-center justify-between gap-2">
                                        <div className="flex min-w-0 items-center gap-2">
                                            <h4 className="truncate text-sm font-semibold text-slate-900">{message.title}</h4>
                                            {message.suggested ? (
                                                <span className="inline-flex shrink-0 items-center rounded-md bg-teal-50 px-2 py-0.5 text-[11px] font-semibold text-teal-700 ring-1 ring-inset ring-teal-200">
                                                    Suggested
                                                </span>
                                            ) : null}
                                        </div>
                                        <span className="text-[11px] font-medium uppercase tracking-[0.12em] text-slate-400">
                                            {String(message.category || '').replace('_', ' ')}
                                        </span>
                                    </div>
                                    <p className="whitespace-pre-wrap rounded-md bg-slate-50 p-3 text-sm leading-6 text-slate-700 ring-1 ring-inset ring-slate-100">
                                        {message.body}
                                    </p>
                                    <div className="mt-3 flex flex-wrap justify-end gap-2">
                                        <button
                                            type="button"
                                            onClick={() => handleCopy(message)}
                                            className="crm-btn-secondary px-3 py-1.5 text-xs"
                                        >
                                            {copiedId === message.id ? 'Copied' : 'Copy'}
                                        </button>
                                        <button
                                            type="button"
                                            onClick={() => handleWhatsApp(message)}
                                            disabled={!whatsappPhone}
                                            title={whatsappPhone ? 'Send via WhatsApp' : 'No WhatsApp-ready phone number'}
                                            className="rounded-md bg-teal-700 px-3 py-1.5 text-xs font-semibold text-white transition hover:bg-teal-800 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-teal-600 disabled:cursor-not-allowed disabled:opacity-50"
                                        >
                                            WhatsApp
                                        </button>
                                    </div>
                                </article>
                            ))}
                        </div>
                    )}
                </div>
            </div>
        </div>
    );
}
