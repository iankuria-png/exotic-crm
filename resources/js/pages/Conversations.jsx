import React, { useEffect, useMemo, useState } from 'react';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { useNavigate } from 'react-router-dom';
import api from '../services/api';
import { useAuth } from '../hooks/useAuth';
import PageHeader from '../components/PageHeader';
import MetricCard from '../components/MetricCard';
import StatusBadge from '../components/StatusBadge';

function formatTimestamp(value) {
    if (!value) return '--';
    const date = new Date(value);
    if (Number.isNaN(date.getTime())) return '--';
    return date.toLocaleString();
}

function formatRelative(value) {
    if (!value) return '--';
    const date = new Date(value);
    if (Number.isNaN(date.getTime())) return '--';

    const deltaMs = Date.now() - date.getTime();
    const minutes = Math.floor(deltaMs / 60_000);
    if (minutes < 1) return 'Just now';
    if (minutes < 60) return `${minutes}m ago`;

    const hours = Math.floor(minutes / 60);
    if (hours < 24) return `${hours}h ago`;

    const days = Math.floor(hours / 24);
    return `${days}d ago`;
}

function normalizeTemplate(template, clientName) {
    if (!template?.body) return '';

    return template.body
        .replace(/\{\{\s*client_name\s*\}\}/gi, clientName || 'client')
        .replace(/\{\{\s*name\s*\}\}/gi, clientName || 'client')
        .replace(/\{\{\s*expiry_date\s*\}\}/gi, 'your expiry date')
        .replace(/\{\{\s*package\s*\}\}/gi, 'your package');
}

export default function Conversations() {
    const navigate = useNavigate();
    const queryClient = useQueryClient();
    const { user } = useAuth();

    const [searchInput, setSearchInput] = useState('');
    const [search, setSearch] = useState('');
    const [selectedClientId, setSelectedClientId] = useState(null);
    const [showAllMessages, setShowAllMessages] = useState(false);
    const [selectedTemplateId, setSelectedTemplateId] = useState('');
    const [draftMessage, setDraftMessage] = useState('');
    const [followUpAt, setFollowUpAt] = useState('');
    const [composerFeedback, setComposerFeedback] = useState(null);

    const { data: clientListData, isLoading: clientsLoading } = useQuery({
        queryKey: ['conversation-clients', search],
        queryFn: () => api.get('/crm/clients', { params: { per_page: 40, ...(search ? { search } : {}) } }).then((response) => response.data),
    });

    const { data: dashboardData } = useQuery({
        queryKey: ['conversation-dashboard'],
        queryFn: () => api.get('/crm/dashboard').then((response) => response.data),
        staleTime: 30_000,
    });

    const { data: templatesData } = useQuery({
        queryKey: ['conversation-templates'],
        queryFn: () =>
            api.get('/crm/settings/templates', {
                params: { per_page: 100, status: 'active', channel: 'sms' },
            }).then((response) => response.data),
    });

    const clients = clientListData?.data || [];
    const templates = templatesData?.data || [];

    useEffect(() => {
        if (clients.length === 0) {
            setSelectedClientId(null);
            return;
        }

        if (!selectedClientId || !clients.some((client) => client.id === selectedClientId)) {
            setSelectedClientId(clients[0].id);
        }
    }, [clients, selectedClientId]);

    const { data: selectedClient, isLoading: threadLoading } = useQuery({
        queryKey: ['conversation-client', selectedClientId],
        queryFn: () => api.get(`/crm/clients/${selectedClientId}`).then((response) => response.data),
        enabled: !!selectedClientId,
    });

    const followUpCountByClient = useMemo(() => {
        const map = new Map();
        (dashboardData?.upcoming_follow_ups || []).forEach((note) => {
            if (!note.client_id) return;
            map.set(note.client_id, (map.get(note.client_id) || 0) + 1);
        });
        return map;
    }, [dashboardData]);

    const conversationNotes = useMemo(() => {
        const notes = selectedClient?.notes || [];
        return [...notes].sort((left, right) => new Date(left.created_at) - new Date(right.created_at));
    }, [selectedClient]);

    const visibleNotes = useMemo(() => {
        if (showAllMessages) return conversationNotes;
        return conversationNotes.slice(-18);
    }, [conversationNotes, showAllMessages]);

    const sendMessageMutation = useMutation({
        mutationFn: () =>
            api.post(`/crm/conversations/clients/${selectedClientId}/send`, {
                template_id: selectedTemplateId || null,
                message: draftMessage.trim(),
                follow_up_at: followUpAt || null,
            }).then((response) => response.data),
        onSuccess: (result) => {
            queryClient.invalidateQueries({ queryKey: ['conversation-client', selectedClientId] });
            queryClient.invalidateQueries({ queryKey: ['conversation-dashboard'] });
            setDraftMessage('');
            setFollowUpAt('');
            setSelectedTemplateId('');
            const deliveryStatus = result?.delivery?.status || 'unknown';
            const tone = result?.delivery?.success ? 'success' : 'danger';
            setComposerFeedback({
                tone,
                text: result?.delivery?.success
                    ? `SMS sent (${deliveryStatus}) and logged to timeline.`
                    : `SMS failed (${deliveryStatus}). Message was still logged.`,
            });
        },
        onError: () => {
            setComposerFeedback({ tone: 'danger', text: 'Failed to send message. Please retry.' });
        },
    });

    const handleSearch = (event) => {
        event.preventDefault();
        setSearch(searchInput.trim());
    };

    const openFollowUps = dashboardData?.upcoming_follow_ups?.length || 0;
    const unmatchedPayments = dashboardData?.kpis?.unmatched_payments || 0;
    const totalThreads = clients.length;

    return (
        <div className="space-y-4">
            <PageHeader
                title="Conversations"
                subtitle="Manage payment confirmations, follow-ups, and customer replies from one thread view."
                actions={selectedClient ? (
                    <button type="button" onClick={() => navigate(`/clients/${selectedClient.id}`)} className="crm-btn-secondary">
                        Open client profile
                    </button>
                ) : null}
            />

            <section className="grid gap-4 md:grid-cols-3">
                <MetricCard label="Active Threads" value={totalThreads.toLocaleString()} meta="clients in current view" tone="accent" />
                <MetricCard label="Follow-ups Due" value={openFollowUps.toLocaleString()} meta="scheduled reminders" tone="warning" />
                <MetricCard label="Unmatched Payments" value={unmatchedPayments.toLocaleString()} meta="high-priority queue" tone="danger" />
            </section>

            <section className="crm-surface overflow-hidden">
                <div className="grid min-h-[620px] lg:grid-cols-[320px_minmax(0,1fr)]">
                    <aside className="border-b border-slate-100 lg:border-b-0 lg:border-r">
                        <div className="border-b border-slate-100 px-4 py-3">
                            <h3 className="crm-panel-title">Client Inbox</h3>
                            <p className="crm-panel-subtitle">Focus first on clients with pending follow-ups.</p>
                            <form onSubmit={handleSearch} className="mt-3">
                                <div className="relative">
                                    <input
                                        value={searchInput}
                                        onChange={(event) => setSearchInput(event.target.value)}
                                        placeholder="Search client..."
                                        className="crm-input pr-10"
                                    />
                                    <button type="submit" className="absolute right-2 top-1/2 -translate-y-1/2 rounded p-1 text-slate-400 transition hover:text-slate-600">
                                        <svg className="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
                                            <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z" />
                                        </svg>
                                    </button>
                                </div>
                            </form>
                        </div>

                        <div className="max-h-[540px] overflow-y-auto">
                            {clientsLoading ? (
                                <p className="px-4 py-6 text-sm text-slate-500">Loading threads...</p>
                            ) : clients.length === 0 ? (
                                <p className="px-4 py-6 text-sm text-slate-500">No clients found.</p>
                            ) : (
                                clients.map((client) => {
                                    const active = client.id === selectedClientId;
                                    const followUps = followUpCountByClient.get(client.id) || 0;

                                    return (
                                        <button
                                            key={client.id}
                                            type="button"
                                            onClick={() => {
                                                setSelectedClientId(client.id);
                                                setShowAllMessages(false);
                                                setComposerFeedback(null);
                                            }}
                                            className={`flex w-full items-start justify-between gap-3 border-b border-slate-100 px-4 py-3 text-left transition ${
                                                active ? 'bg-teal-50/40' : 'hover:bg-slate-50'
                                            }`}
                                        >
                                            <span className="min-w-0">
                                                <span className="block truncate text-sm font-semibold text-slate-900">{client.name || 'Unnamed client'}</span>
                                                <span className="crm-mono mt-0.5 block truncate text-xs text-slate-500">{client.phone_normalized || 'No phone'}</span>
                                            </span>
                                            {followUps > 0 ? (
                                                <span className="inline-flex h-5 min-w-[1.25rem] items-center justify-center rounded-full bg-amber-100 px-1.5 text-xs font-semibold text-amber-700">
                                                    {followUps}
                                                </span>
                                            ) : null}
                                        </button>
                                    );
                                })
                            )}
                        </div>
                    </aside>

                    <div className="flex min-h-[620px] flex-col">
                        {threadLoading || !selectedClient ? (
                            <div className="flex flex-1 items-center justify-center text-sm text-slate-500">
                                Loading conversation...
                            </div>
                        ) : (
                            <>
                                <header className="crm-panel-header">
                                    <div>
                                        <h3 className="crm-panel-title">{selectedClient.name || 'Unnamed client'}</h3>
                                        <p className="crm-panel-subtitle">{selectedClient.phone_normalized || 'No phone'} • {selectedClient.platform?.name || 'Unknown market'}</p>
                                    </div>
                                    <StatusBadge status={selectedClient.profile_status} />
                                </header>

                                <div className="flex-1 space-y-2 overflow-y-auto bg-slate-50/50 px-4 py-4">
                                    {conversationNotes.length > 18 && !showAllMessages ? (
                                        <button
                                            type="button"
                                            onClick={() => setShowAllMessages(true)}
                                            className="mx-auto block rounded-full border border-slate-300 bg-white px-3 py-1 text-xs font-semibold text-slate-600 transition hover:bg-slate-50"
                                        >
                                            Show earlier messages ({conversationNotes.length - 18})
                                        </button>
                                    ) : null}

                                    {visibleNotes.length > 0 ? visibleNotes.map((note) => {
                                        const isMine = !!user?.id && note.author_id === user.id;
                                        const isSystem = note.note_type === 'system';

                                        return (
                                            <div key={note.id} className={`flex ${isMine ? 'justify-end' : 'justify-start'}`}>
                                                <article
                                                    className={`max-w-[84%] rounded-md border px-3 py-2.5 shadow-sm ${
                                                        isSystem
                                                            ? 'border-amber-200 bg-amber-50'
                                                            : isMine
                                                                ? 'border-teal-200 bg-teal-50'
                                                                : 'border-slate-200 bg-white'
                                                    }`}
                                                >
                                                    <p className="whitespace-pre-wrap text-sm text-slate-800">{note.content}</p>
                                                    <div className="mt-2 flex items-center gap-2 text-[11px] text-slate-500">
                                                        <span className="rounded bg-slate-100 px-1.5 py-0.5 uppercase tracking-wide">
                                                            {note.note_type}
                                                        </span>
                                                        <span>{formatRelative(note.created_at)}</span>
                                                        {note.follow_up_at ? <span>Follow-up: {formatTimestamp(note.follow_up_at)}</span> : null}
                                                    </div>
                                                </article>
                                            </div>
                                        );
                                    }) : (
                                        <div className="rounded-md border border-dashed border-slate-200 bg-white px-4 py-8 text-center text-sm text-slate-500">
                                            No conversation history yet. Start with a payment confirmation message.
                                        </div>
                                    )}
                                </div>

                                <footer className="border-t border-slate-100 bg-white p-4">
                                    <div className="grid gap-2 md:grid-cols-[220px_220px_minmax(0,1fr)]">
                                        <select
                                            value={selectedTemplateId}
                                            onChange={(event) => {
                                                const templateId = event.target.value;
                                                setSelectedTemplateId(templateId);

                                                const template = templates.find((item) => String(item.id) === templateId);
                                                if (template) {
                                                    setDraftMessage(normalizeTemplate(template, selectedClient.name));
                                                }
                                            }}
                                            className="crm-select"
                                        >
                                            <option value="">Template library</option>
                                            {templates.map((template) => (
                                                <option key={template.id} value={template.id}>{template.title}</option>
                                            ))}
                                        </select>
                                        <input
                                            type="datetime-local"
                                            value={followUpAt}
                                            onChange={(event) => setFollowUpAt(event.target.value)}
                                            className="crm-input"
                                        />
                                        <textarea
                                            value={draftMessage}
                                            onChange={(event) => setDraftMessage(event.target.value)}
                                            placeholder="Write message..."
                                            rows={2}
                                            className="crm-input min-h-[40px] resize-y"
                                        />
                                    </div>

                                    <div className="mt-2 flex flex-wrap items-center justify-between gap-2">
                                        <p className={`text-xs font-medium ${
                                            composerFeedback?.tone === 'success' ? 'text-emerald-700' : composerFeedback?.tone === 'danger' ? 'text-rose-700' : 'text-slate-500'
                                        }`}>
                                            {composerFeedback?.text || 'Messages are sent through the SMS gateway and logged on the client timeline.'}
                                        </p>
                                        <button
                                            type="button"
                                            onClick={() => sendMessageMutation.mutate()}
                                            disabled={!draftMessage.trim() || sendMessageMutation.isPending}
                                            className="crm-btn-primary disabled:cursor-not-allowed disabled:opacity-50"
                                        >
                                            {sendMessageMutation.isPending ? 'Sending...' : 'Send message'}
                                        </button>
                                    </div>
                                </footer>
                            </>
                        )}
                    </div>
                </div>
            </section>
        </div>
    );
}
