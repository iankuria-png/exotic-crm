import React, { useEffect, useMemo, useState } from 'react';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { useNavigate } from 'react-router-dom';
import api from '../services/api';
import { useToast } from './ToastProvider';

const STATUS_META = {
    0: {
        label: 'Live',
        dot: 'bg-emerald-500',
        badge: 'bg-emerald-50 text-emerald-700 ring-emerald-200',
    },
    1: {
        label: 'Waiting on client',
        dot: 'bg-amber-500',
        badge: 'bg-amber-50 text-amber-700 ring-amber-200',
    },
    2: {
        label: 'Needs reply',
        dot: 'bg-sky-500',
        badge: 'bg-sky-50 text-sky-700 ring-sky-200',
    },
    3: {
        label: 'Archived',
        dot: 'bg-slate-400',
        badge: 'bg-slate-100 text-slate-700 ring-slate-200',
    },
};

function formatDateTime(value) {
    if (!value) return '—';

    const date = new Date(value);
    if (Number.isNaN(date.getTime())) return '—';

    return date.toLocaleString();
}

function formatRelativeTime(value) {
    if (!value) return '—';

    const date = new Date(value);
    if (Number.isNaN(date.getTime())) return '—';

    const diffMs = date.getTime() - Date.now();
    const diffMinutes = Math.round(diffMs / 60000);
    const absoluteMinutes = Math.abs(diffMinutes);

    if (absoluteMinutes < 1) return 'just now';
    if (absoluteMinutes < 60) return diffMinutes < 0 ? `${absoluteMinutes}m ago` : `in ${absoluteMinutes}m`;

    const absoluteHours = Math.round(absoluteMinutes / 60);
    if (absoluteHours < 24) return diffMinutes < 0 ? `${absoluteHours}h ago` : `in ${absoluteHours}h`;

    const absoluteDays = Math.round(absoluteHours / 24);
    return diffMinutes < 0 ? `${absoluteDays}d ago` : `in ${absoluteDays}d`;
}

function statusMeta(statusCode) {
    return STATUS_META[Number(statusCode)] || {
        label: 'Unknown',
        dot: 'bg-slate-400',
        badge: 'bg-slate-100 text-slate-700 ring-slate-200',
    };
}

function isClientMessage(message) {
    return ['lead', 'user', 'visitor', 'client'].includes(String(message?.user_type || '').toLowerCase());
}

function isImageAttachment(attachment) {
    const source = String(attachment?.url || attachment?.name || '').toLowerCase();
    return ['.jpg', '.jpeg', '.png', '.gif', '.webp', '.bmp', '.svg'].some((extension) => source.includes(extension));
}

function EmptyStateCard({ tone = 'info', title, description, actionLabel, onAction }) {
    const toneClasses = tone === 'error'
        ? 'border-rose-200 bg-rose-50 text-rose-900'
        : 'border-teal-200 bg-teal-50 text-teal-900';

    return (
        <section className={`rounded-xl border px-4 py-5 ${toneClasses}`}>
            <div className="space-y-2">
                <h3 className="text-base font-semibold">{title}</h3>
                <p className="text-sm leading-6 text-current/80">{description}</p>
            </div>
            {actionLabel && onAction ? (
                <button
                    type="button"
                    onClick={onAction}
                    className="mt-4 inline-flex min-h-11 items-center rounded-md border border-current/20 bg-white px-3 py-2 text-sm font-semibold text-current transition hover:bg-white/80"
                >
                    {actionLabel}
                </button>
            ) : null}
        </section>
    );
}

function AttachmentPreview({ attachment }) {
    const image = isImageAttachment(attachment);

    return (
        <div className="mt-2 rounded-lg border border-slate-200 bg-white/70 p-2">
            {image ? (
                <a
                    href={attachment.url}
                    target="_blank"
                    rel="noreferrer"
                    className="block overflow-hidden rounded-md"
                >
                    <img
                        src={attachment.url}
                        alt={attachment.name || 'Conversation attachment'}
                        className="h-28 w-full rounded-md object-cover"
                    />
                </a>
            ) : null}
            <div className={`flex flex-wrap items-center justify-between gap-2 ${image ? 'mt-2' : ''}`}>
                <p className="text-xs font-medium text-slate-700">{attachment.name || 'Attachment'}</p>
                <a
                    href={attachment.url}
                    target="_blank"
                    rel="noreferrer"
                    className="text-xs font-semibold text-teal-700 underline-offset-2 hover:underline"
                >
                    {image ? 'Open full size' : 'Download file'}
                </a>
            </div>
        </div>
    );
}

export default function SupportBoardChat({ clientId, client }) {
    const navigate = useNavigate();
    const queryClient = useQueryClient();
    const toast = useToast();
    const resolvedClientId = String(clientId || client?.id || '');
    const numericClientId = Number(resolvedClientId || 0);
    const [selectedConversationId, setSelectedConversationId] = useState(null);
    const [replyMessage, setReplyMessage] = useState('');
    const [showEarlierMessages, setShowEarlierMessages] = useState(false);

    const statusQuery = useQuery({
        queryKey: ['client-support-board-status', resolvedClientId],
        queryFn: () => api.get(`/crm/clients/${resolvedClientId}/support-board/status`).then((response) => response.data),
        enabled: numericClientId > 0,
        staleTime: 60_000,
    });

    const canLoadConversations = Boolean(statusQuery.data?.configured) && Boolean(statusQuery.data?.matched);

    const conversationsQuery = useQuery({
        queryKey: ['client-support-board-conversations', resolvedClientId],
        queryFn: () => api.get(`/crm/clients/${resolvedClientId}/support-board/conversations`).then((response) => response.data),
        enabled: numericClientId > 0 && canLoadConversations,
        staleTime: 45_000,
    });

    const conversations = Array.isArray(conversationsQuery.data) ? conversationsQuery.data : [];

    useEffect(() => {
        if (!conversations.length) {
            setSelectedConversationId(null);
            return;
        }

        if (!conversations.some((conversation) => Number(conversation.id) === Number(selectedConversationId))) {
            setSelectedConversationId(conversations[0].id);
        }
    }, [conversations, selectedConversationId]);

    useEffect(() => {
        setShowEarlierMessages(false);
    }, [selectedConversationId]);

    const selectedConversationSummary = useMemo(
        () => conversations.find((conversation) => Number(conversation.id) === Number(selectedConversationId)) || null,
        [conversations, selectedConversationId],
    );

    const conversationQuery = useQuery({
        queryKey: ['client-support-board-conversation', resolvedClientId, selectedConversationId],
        queryFn: () => api.get(`/crm/clients/${resolvedClientId}/support-board/conversations/${selectedConversationId}`).then((response) => response.data),
        enabled: numericClientId > 0 && canLoadConversations && selectedConversationId !== null,
        staleTime: 15_000,
    });

    const refreshMutation = useMutation({
        mutationFn: async () => {
            const refreshedStatus = await api.get(`/crm/clients/${resolvedClientId}/support-board/status`, {
                params: { refresh: 1 },
            }).then((response) => response.data);

            queryClient.setQueryData(['client-support-board-status', resolvedClientId], refreshedStatus);

            if (!refreshedStatus?.configured || !refreshedStatus?.matched) {
                queryClient.setQueryData(['client-support-board-conversations', resolvedClientId], []);
                return refreshedStatus;
            }

            const refreshedConversations = await api.get(`/crm/clients/${resolvedClientId}/support-board/conversations`, {
                params: { refresh: 1 },
            }).then((response) => response.data);

            queryClient.setQueryData(['client-support-board-conversations', resolvedClientId], refreshedConversations);

            if (selectedConversationId !== null) {
                const refreshedConversation = await api.get(
                    `/crm/clients/${resolvedClientId}/support-board/conversations/${selectedConversationId}`,
                ).then((response) => response.data);

                queryClient.setQueryData(
                    ['client-support-board-conversation', resolvedClientId, selectedConversationId],
                    refreshedConversation,
                );
            }

            return refreshedStatus;
        },
        onSuccess: () => {
            toast.success('Support Board cache refreshed.');
        },
        onError: (error) => {
            toast.error(error?.response?.data?.message || 'Refreshing Support Board data failed.');
        },
    });

    const replyMutation = useMutation({
        mutationFn: (payload) =>
            api.post(
                `/crm/clients/${resolvedClientId}/support-board/conversations/${selectedConversationId}/reply`,
                payload,
            ).then((response) => response.data),
        onSuccess: () => {
            setReplyMessage('');
            queryClient.invalidateQueries({ queryKey: ['client-support-board-conversations', resolvedClientId] });
            queryClient.invalidateQueries({ queryKey: ['client-support-board-conversation', resolvedClientId, selectedConversationId] });
            queryClient.invalidateQueries({ queryKey: ['client', resolvedClientId] });
            queryClient.invalidateQueries({ queryKey: ['client-timeline', resolvedClientId] });
            toast.success('Reply sent to Support Board.');
        },
        onError: (error) => {
            toast.error(error?.response?.data?.message || 'Sending reply failed.');
        },
    });

    const blockingError = statusQuery.error || conversationsQuery.error;
    const blockingErrorMessage = blockingError?.response?.data?.message || 'Could not load Support Board conversations right now.';
    const conversationErrorMessage = conversationQuery.error?.response?.data?.message || 'Could not load this conversation.';

    const conversation = conversationQuery.data || null;
    const messages = Array.isArray(conversation?.messages) ? conversation.messages : [];
    const hasCollapsedMessages = messages.length > 50;
    const visibleMessages = hasCollapsedMessages && !showEarlierMessages
        ? messages.slice(-30)
        : messages;
    const hiddenMessageCount = Math.max(messages.length - visibleMessages.length, 0);
    const statusDetails = statusQuery.data || null;

    if (statusQuery.isLoading) {
        return (
            <section className="crm-surface p-5">
                <p className="text-sm text-slate-500">Loading Support Board chat...</p>
            </section>
        );
    }

    if (blockingError) {
        return (
            <section className="crm-surface p-5">
                <EmptyStateCard
                    tone="error"
                    title="Support Board is temporarily unavailable"
                    description={blockingErrorMessage}
                    actionLabel="Try again"
                    onAction={() => {
                        statusQuery.refetch();
                        conversationsQuery.refetch();
                    }}
                />
            </section>
        );
    }

    if (!statusDetails?.configured) {
        return (
            <section className="crm-surface p-5">
                <EmptyStateCard
                    title="Support Board is not configured for this market"
                    description="Add the Support Board API URL, token, and sender defaults in Settings before using the CRM chat view."
                    actionLabel="Go to Settings"
                    onAction={() => navigate('/settings?tab=integrations')}
                />
            </section>
        );
    }

    if (!statusDetails?.matched) {
        const triedPhones = Array.isArray(statusDetails?.tried?.phone)
            ? statusDetails.tried.phone.filter(Boolean).join(', ')
            : '';
        const triedEmail = statusDetails?.tried?.email || '';

        return (
            <section className="crm-surface p-5">
                <EmptyStateCard
                    title="No Support Board contact match yet"
                    description={`We could not match ${client?.name || 'this client'} in Support Board. Tried phone variants: ${triedPhones || 'none'}. Tried email: ${triedEmail || 'none'}.`}
                    actionLabel="Refresh match"
                    onAction={() => refreshMutation.mutate()}
                />
            </section>
        );
    }

    if (conversationsQuery.isLoading) {
        return (
            <section className="crm-surface p-5">
                <p className="text-sm text-slate-500">Loading conversations...</p>
            </section>
        );
    }

    if (!conversations.length) {
        return (
            <section className="crm-surface p-5">
                <EmptyStateCard
                    title="No conversations found"
                    description="This Support Board contact is linked, but there are no active or archived conversations to show yet."
                    actionLabel="Refresh conversations"
                    onAction={() => refreshMutation.mutate()}
                />
            </section>
        );
    }

    return (
        <section className="crm-surface p-4">
            <div className="grid gap-4 xl:grid-cols-[320px_minmax(0,1fr)]">
                <aside className="rounded-xl border border-slate-200 bg-slate-50">
                    <div className="flex items-center justify-between gap-3 border-b border-slate-200 px-4 py-3">
                        <div>
                            <h3 className="text-sm font-semibold text-slate-900">Conversations</h3>
                            <p className="text-xs text-slate-500">Support Board threads for this client.</p>
                        </div>
                        <button
                            type="button"
                            onClick={() => refreshMutation.mutate()}
                            disabled={refreshMutation.isPending}
                            className="crm-btn-secondary px-3 py-1.5 text-xs disabled:cursor-not-allowed disabled:opacity-50"
                        >
                            {refreshMutation.isPending ? 'Refreshing...' : 'Refresh'}
                        </button>
                    </div>

                    <div className="max-h-[720px] space-y-2 overflow-auto p-3">
                        {conversations.map((conversationItem) => {
                            const meta = statusMeta(conversationItem.status_code);
                            const selected = Number(conversationItem.id) === Number(selectedConversationId);

                            return (
                                <button
                                    key={conversationItem.id}
                                    type="button"
                                    onClick={() => setSelectedConversationId(conversationItem.id)}
                                    className={`w-full rounded-xl border p-3 text-left transition ${
                                        selected
                                            ? 'border-teal-300 bg-white shadow-sm'
                                            : 'border-slate-200 bg-white hover:border-slate-300'
                                    }`}
                                >
                                    <div className="flex items-start justify-between gap-3">
                                        <div className="min-w-0">
                                            <div className="flex items-center gap-2">
                                                <span className={`inline-block h-2.5 w-2.5 rounded-full ${meta.dot}`} />
                                                <p className="truncate text-sm font-semibold text-slate-900">
                                                    Conversation #{conversationItem.id}
                                                </p>
                                            </div>
                                            <p className="mt-2 line-clamp-2 text-sm text-slate-600">
                                                {conversationItem.last_message || 'No preview available yet.'}
                                            </p>
                                        </div>
                                        <div className="shrink-0 text-right">
                                            <span className={`inline-flex items-center rounded-md px-2 py-0.5 text-[11px] font-medium ring-1 ring-inset ${meta.badge}`}>
                                                {meta.label}
                                            </span>
                                            <p className="mt-2 text-[11px] text-slate-500">
                                                {formatRelativeTime(conversationItem.updated_at || conversationItem.created_at)}
                                            </p>
                                        </div>
                                    </div>
                                    <div className="mt-3 flex items-center justify-between text-[11px] text-slate-500">
                                        <span>{formatDateTime(conversationItem.updated_at || conversationItem.created_at)}</span>
                                        <span>{conversationItem.attachment_count || 0} attachments</span>
                                    </div>
                                </button>
                            );
                        })}
                    </div>
                </aside>

                <div className="min-w-0 rounded-xl border border-slate-200 bg-white">
                    <div className="border-b border-slate-200 px-4 py-3">
                        {selectedConversationSummary ? (
                            <div className="flex flex-wrap items-start justify-between gap-3">
                                <div>
                                    <h3 className="text-base font-semibold text-slate-900">
                                        Conversation #{selectedConversationSummary.id}
                                    </h3>
                                    <p className="mt-1 text-sm text-slate-500">
                                        Created {formatDateTime(conversation?.created_at || selectedConversationSummary.created_at)}
                                    </p>
                                </div>
                                <div className="flex flex-wrap items-center gap-2">
                                    <span className={`inline-flex items-center rounded-md px-2.5 py-1 text-xs font-medium ring-1 ring-inset ${statusMeta(selectedConversationSummary.status_code).badge}`}>
                                        {statusMeta(selectedConversationSummary.status_code).label}
                                    </span>
                                    <span className="text-xs text-slate-500">
                                        Updated {formatRelativeTime(selectedConversationSummary.updated_at || selectedConversationSummary.created_at)}
                                    </span>
                                </div>
                            </div>
                        ) : (
                            <div>
                                <h3 className="text-base font-semibold text-slate-900">Conversation</h3>
                                <p className="mt-1 text-sm text-slate-500">Select a conversation to view its full thread.</p>
                            </div>
                        )}
                    </div>

                    <div className="space-y-4 p-4">
                        {conversationQuery.isLoading ? (
                            <p className="text-sm text-slate-500">Loading thread...</p>
                        ) : conversationQuery.error ? (
                            <EmptyStateCard
                                tone="error"
                                title="Conversation unavailable"
                                description={conversationErrorMessage}
                                actionLabel="Retry conversation"
                                onAction={() => conversationQuery.refetch()}
                            />
                        ) : !conversation ? (
                            <EmptyStateCard
                                title="Select a conversation"
                                description="Choose a conversation from the left panel to load its full message history."
                            />
                        ) : (
                            <>
                                {hasCollapsedMessages ? (
                                    <div className="rounded-lg border border-slate-200 bg-slate-50 px-3 py-2 text-sm text-slate-600">
                                        <div className="flex flex-wrap items-center justify-between gap-2">
                                            <p>
                                                {showEarlierMessages
                                                    ? `Showing all ${messages.length} messages in this thread.`
                                                    : `Showing the latest 30 messages. ${hiddenMessageCount} earlier messages are hidden.`}
                                            </p>
                                            <button
                                                type="button"
                                                onClick={() => setShowEarlierMessages((current) => !current)}
                                                className="text-sm font-semibold text-teal-700 underline-offset-2 hover:underline"
                                            >
                                                {showEarlierMessages ? 'Show latest 30 only' : 'Show earlier messages'}
                                            </button>
                                        </div>
                                    </div>
                                ) : null}

                                <div className="max-h-[560px] space-y-3 overflow-auto pr-1">
                                    {visibleMessages.map((message) => {
                                        const clientMessage = isClientMessage(message);

                                        return (
                                            <div
                                                key={`${message.id || message.created_at || 'message'}-${message.user_id || 'user'}`}
                                                className={`flex ${clientMessage ? 'justify-start' : 'justify-end'}`}
                                            >
                                                <div
                                                    className={`max-w-3xl rounded-2xl px-4 py-3 shadow-sm ${
                                                        clientMessage
                                                            ? 'bg-slate-100 text-slate-800'
                                                            : 'bg-teal-50 text-slate-900'
                                                    }`}
                                                >
                                                    <div className="flex flex-wrap items-center gap-2 text-xs text-slate-500">
                                                        <span className="font-semibold text-slate-700">
                                                            {message.full_name || `${message.first_name || ''} ${message.last_name || ''}`.trim() || (clientMessage ? 'Client' : 'Agent')}
                                                        </span>
                                                        <span>{formatDateTime(message.created_at)}</span>
                                                    </div>

                                                    {message.message ? (
                                                        <p className="mt-2 whitespace-pre-wrap text-sm leading-6">{message.message}</p>
                                                    ) : null}

                                                    {Array.isArray(message.attachments) && message.attachments.length > 0 ? (
                                                        <div className="mt-2 space-y-2">
                                                            {message.attachments.map((attachment, index) => (
                                                                <AttachmentPreview
                                                                    key={`${attachment.url || attachment.name || 'attachment'}-${index}`}
                                                                    attachment={attachment}
                                                                />
                                                            ))}
                                                        </div>
                                                    ) : null}
                                                </div>
                                            </div>
                                        );
                                    })}
                                </div>

                                {statusDetails?.can_reply ? (
                                    <div className="rounded-xl border border-slate-200 bg-slate-50 p-3">
                                        <label htmlFor="support-board-reply" className="mb-2 block text-sm font-medium text-slate-700">
                                            Reply in Support Board
                                        </label>
                                        <textarea
                                            id="support-board-reply"
                                            rows={3}
                                            value={replyMessage}
                                            onChange={(event) => setReplyMessage(event.target.value)}
                                            className="crm-input"
                                            placeholder="Type a reply..."
                                        />
                                        <div className="mt-3 flex justify-end">
                                            <button
                                                type="button"
                                                onClick={() => replyMutation.mutate({ message: replyMessage.trim() })}
                                                disabled={!replyMessage.trim() || replyMutation.isPending}
                                                className="crm-btn-primary disabled:cursor-not-allowed disabled:opacity-50"
                                            >
                                                {replyMutation.isPending ? 'Sending...' : 'Send'}
                                            </button>
                                        </div>
                                    </div>
                                ) : (
                                    <div className="rounded-xl border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-800">
                                        Replies are disabled until this market or user has a Support Board sender ID configured in Settings.
                                    </div>
                                )}
                            </>
                        )}
                    </div>
                </div>
            </div>
        </section>
    );
}
