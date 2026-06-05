import React, { useEffect, useMemo, useState } from 'react';
import { useQuery } from '@tanstack/react-query';
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
