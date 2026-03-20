import React, { useEffect, useMemo, useState } from 'react';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { useNavigate } from 'react-router-dom';
import api from '../services/api';
import ConfirmDialog from './ConfirmDialog';
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

const SYNC_FIELD_OPTIONS = [
    {
        key: 'name',
        label: 'Name',
        description: 'Keep the client display name aligned with the Support Board profile.',
    },
    {
        key: 'email',
        label: 'Email',
        description: 'Sync the primary email address when one side is fresher.',
    },
    {
        key: 'phone',
        label: 'Phone',
        description: 'Sync the WhatsApp/contact number used for chat matching.',
    },
    {
        key: 'city',
        label: 'City',
        description: 'Optional: use CRM city or a location-derived Support Board city suggestion.',
    },
];

const PRIMARY_DETAIL_SLUGS = ['phone', 'country_code', 'location', 'currency', 'current_url', 'time_zone', 'browser_language'];

function defaultSyncForm() {
    return {
        direction: 'support_board_to_crm',
        mode: 'fill_blanks',
        fields: ['name', 'email', 'phone'],
        reason: 'Support Board profile sync from client chat',
    };
}

function formatDateTime(value) {
    if (!value) return '—';

    const date = new Date(value);
    if (Number.isNaN(date.getTime())) return String(value);

    return date.toLocaleString();
}

function formatRelativeTime(value) {
    if (!value) return '—';

    const date = new Date(value);
    if (Number.isNaN(date.getTime())) return String(value);

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

function syncOutcomeMeta(outcome) {
    switch (outcome) {
        case 'fill':
            return {
                label: 'Fill',
                badge: 'bg-emerald-50 text-emerald-700 ring-emerald-200',
            };
        case 'update':
            return {
                label: 'Update',
                badge: 'bg-sky-50 text-sky-700 ring-sky-200',
            };
        case 'conflict':
            return {
                label: 'Review',
                badge: 'bg-amber-50 text-amber-700 ring-amber-200',
            };
        case 'unavailable':
            return {
                label: 'Unavailable',
                badge: 'bg-slate-100 text-slate-600 ring-slate-200',
            };
        default:
            return {
                label: 'No change',
                badge: 'bg-slate-100 text-slate-700 ring-slate-200',
            };
    }
}

function directionLabel(direction) {
    return direction === 'crm_to_support_board'
        ? 'CRM -> Support Board'
        : 'Support Board -> CRM';
}

function modeLabel(mode) {
    return mode === 'overwrite' ? 'Overwrite selected fields' : 'Fill blanks only';
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

function DetailValue({ detail }) {
    const value = detail?.value;
    if (!value) {
        return <span className="text-sm text-slate-400">—</span>;
    }

    const valueString = String(value);
    const isLink = /^https?:\/\//i.test(valueString);

    if (isLink) {
        return (
            <div className="space-y-1">
                <a
                    href={valueString}
                    target="_blank"
                    rel="noreferrer"
                    className="text-sm font-semibold text-teal-700 underline decoration-teal-200 underline-offset-2"
                >
                    Open link
                </a>
                <p className="break-all text-[11px] text-slate-500">{valueString}</p>
            </div>
        );
    }

    return (
        <p className="break-words text-sm font-medium text-slate-800">
            {valueString}
        </p>
    );
}

function SupportBoardDetailCard({ detail }) {
    return (
        <div className="rounded-lg border border-slate-200 bg-slate-50 px-3 py-2.5">
            <p className="text-[11px] font-semibold uppercase tracking-[0.09em] text-slate-500">{detail?.name || detail?.slug}</p>
            <div className="mt-1">
                <DetailValue detail={detail} />
            </div>
        </div>
    );
}

function SyncPreviewRow({ row }) {
    const meta = syncOutcomeMeta(row?.outcome);

    return (
        <article className="rounded-lg border border-slate-200 bg-white p-3 shadow-sm">
            <div className="flex flex-wrap items-start justify-between gap-2">
                <div>
                    <h4 className="text-sm font-semibold text-slate-900">{row?.label}</h4>
                    <p className="mt-1 text-xs text-slate-500">{row?.reason}</p>
                </div>
                <span className={`inline-flex items-center rounded-md px-2 py-1 text-[11px] font-semibold ring-1 ring-inset ${meta.badge}`}>
                    {meta.label}
                </span>
            </div>

            <div className="mt-3 grid gap-3 md:grid-cols-2">
                <div className="rounded-lg border border-slate-200 bg-slate-50 px-3 py-2">
                    <p className="text-[11px] font-semibold uppercase tracking-[0.09em] text-slate-500">{row?.source_label}</p>
                    <p className="mt-1 break-words text-sm text-slate-900">{row?.source_value || '—'}</p>
                    {row?.source_note ? (
                        <p className="mt-1 text-[11px] text-slate-500">{row.source_note}</p>
                    ) : null}
                </div>

                <div className="rounded-lg border border-slate-200 bg-slate-50 px-3 py-2">
                    <p className="text-[11px] font-semibold uppercase tracking-[0.09em] text-slate-500">{row?.target_label}</p>
                    <p className="mt-1 break-words text-sm text-slate-900">{row?.target_value || '—'}</p>
                </div>
            </div>
        </article>
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
    const [showAllDetails, setShowAllDetails] = useState(false);
    const [syncForm, setSyncForm] = useState(defaultSyncForm());
    const [syncPreview, setSyncPreview] = useState(null);
    const [syncConfirmOpen, setSyncConfirmOpen] = useState(false);

    const resetPreviewState = () => {
        setSyncPreview(null);
        setSyncConfirmOpen(false);
    };

    const updateSyncForm = (updater) => {
        setSyncForm((current) => {
            const next = typeof updater === 'function'
                ? updater(current)
                : { ...current, ...updater };

            return next;
        });
        resetPreviewState();
    };

    const statusQuery = useQuery({
        queryKey: ['client-support-board-status', resolvedClientId],
        queryFn: () => api.get(`/crm/clients/${resolvedClientId}/support-board/status`).then((response) => response.data),
        enabled: numericClientId > 0,
        staleTime: 60_000,
    });

    const canLoadSupportBoardProfile = Boolean(statusQuery.data?.configured) && Boolean(statusQuery.data?.matched);

    const profileQuery = useQuery({
        queryKey: ['client-support-board-profile', resolvedClientId],
        queryFn: () => api.get(`/crm/clients/${resolvedClientId}/support-board/profile`).then((response) => response.data),
        enabled: numericClientId > 0 && canLoadSupportBoardProfile,
        staleTime: 60_000,
    });

    const canLoadConversations = canLoadSupportBoardProfile;

    const conversationsQuery = useQuery({
        queryKey: ['client-support-board-conversations', resolvedClientId],
        queryFn: () => api.get(`/crm/clients/${resolvedClientId}/support-board/conversations`).then((response) => response.data),
        enabled: numericClientId > 0 && canLoadConversations,
        staleTime: 45_000,
    });

    const conversations = Array.isArray(conversationsQuery.data) ? conversationsQuery.data : [];

    useEffect(() => {
        setSyncForm(defaultSyncForm());
        setShowAllDetails(false);
        resetPreviewState();
    }, [resolvedClientId]);

    useEffect(() => {
        if (!canLoadSupportBoardProfile) {
            setShowAllDetails(false);
            resetPreviewState();
        }
    }, [canLoadSupportBoardProfile]);

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
                queryClient.removeQueries({ queryKey: ['client-support-board-profile', resolvedClientId] });
                return refreshedStatus;
            }

            const [refreshedProfile, refreshedConversations] = await Promise.all([
                api.get(`/crm/clients/${resolvedClientId}/support-board/profile`, {
                    params: { refresh: 1 },
                }).then((response) => response.data),
                api.get(`/crm/clients/${resolvedClientId}/support-board/conversations`, {
                    params: { refresh: 1 },
                }).then((response) => response.data),
            ]);

            queryClient.setQueryData(['client-support-board-profile', resolvedClientId], refreshedProfile);
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
            resetPreviewState();
            toast.success('Support Board cache refreshed.');
        },
        onError: (error) => {
            toast.error(error?.response?.data?.message || 'Refreshing Support Board data failed.');
        },
    });

    const previewMutation = useMutation({
        mutationFn: (payload) =>
            api.post(`/crm/clients/${resolvedClientId}/support-board/profile-sync/preview`, payload)
                .then((response) => response.data),
        onSuccess: (payload) => {
            setSyncPreview(payload);
            toast.success('Sync preview is ready. Review the changes before applying them.');
        },
        onError: (error) => {
            toast.error(error?.response?.data?.message || 'Could not generate a sync preview.');
        },
    });

    const applyMutation = useMutation({
        mutationFn: (payload) =>
            api.post(`/crm/clients/${resolvedClientId}/support-board/profile-sync/apply`, payload)
                .then((response) => response.data),
        onSuccess: (payload) => {
            resetPreviewState();
            queryClient.invalidateQueries({ queryKey: ['client', resolvedClientId] });
            queryClient.invalidateQueries({ queryKey: ['client-timeline', resolvedClientId] });
            queryClient.invalidateQueries({ queryKey: ['client-support-board-status', resolvedClientId] });
            queryClient.invalidateQueries({ queryKey: ['client-support-board-profile', resolvedClientId] });
            queryClient.invalidateQueries({ queryKey: ['client-support-board-conversations', resolvedClientId] });
            if (selectedConversationId !== null) {
                queryClient.invalidateQueries({ queryKey: ['client-support-board-conversation', resolvedClientId, selectedConversationId] });
            }
            toast.success(payload?.message || 'Support Board profile sync applied.');
        },
        onError: (error) => {
            toast.error(error?.response?.data?.message || 'Support Board profile sync failed.');
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

    const statusDetails = statusQuery.data || null;
    const profileDetails = profileQuery.data || null;
    const messages = Array.isArray(conversationQuery.data?.messages) ? conversationQuery.data.messages : [];
    const hasCollapsedMessages = messages.length > 50;
    const visibleMessages = hasCollapsedMessages && !showEarlierMessages
        ? messages.slice(-30)
        : messages;
    const hiddenMessageCount = Math.max(messages.length - visibleMessages.length, 0);
    const statusErrorMessage = statusQuery.error?.response?.data?.message || 'Could not load Support Board chat right now.';
    const conversationsErrorMessage = conversationsQuery.error?.response?.data?.message || 'Could not load conversation threads right now.';
    const conversationErrorMessage = conversationQuery.error?.response?.data?.message || 'Could not load this conversation.';
    const profileErrorMessage = profileQuery.error?.response?.data?.message || 'Could not load Support Board profile details right now.';

    const selectedFields = Array.isArray(syncForm.fields) ? syncForm.fields : [];
    const syncPreviewRows = Array.isArray(syncPreview?.rows) ? syncPreview.rows : [];
    const applicablePreviewRows = syncPreviewRows.filter((row) => ['fill', 'update'].includes(row?.outcome));
    const syncWarnings = Array.isArray(syncPreview?.warnings) ? syncPreview.warnings : [];
    const primaryDetails = Array.isArray(profileDetails?.primary_details) && profileDetails.primary_details.length > 0
        ? profileDetails.primary_details
        : Array.isArray(profileDetails?.details)
            ? profileDetails.details.filter((detail) => PRIMARY_DETAIL_SLUGS.includes(detail?.slug))
            : [];
    const secondaryDetails = Array.isArray(profileDetails?.secondary_details)
        ? profileDetails.secondary_details
        : [];
    const marketSuggestion = profileDetails?.suggestions?.market || null;
    const citySuggestion = profileDetails?.suggestions?.city || null;

    const renderStateSection = (content, paddingClass = 'p-5') => (
        <section className={`crm-surface ${paddingClass}`}>
            {content}
        </section>
    );

    if (statusQuery.isLoading) {
        return renderStateSection(
            <p className="text-sm text-slate-500">Loading Support Board chat...</p>,
        );
    }

    if (statusQuery.error) {
        return renderStateSection(
            <EmptyStateCard
                tone="error"
                title="Support Board is temporarily unavailable"
                description={statusErrorMessage}
                actionLabel="Try again"
                onAction={() => statusQuery.refetch()}
            />,
        );
    }

    if (!statusDetails?.configured) {
        return renderStateSection(
            <EmptyStateCard
                title="Support Board is not configured for this market"
                description="Add the Support Board API URL, token, and sender defaults in Settings before using the CRM chat view."
                actionLabel="Go to Settings"
                onAction={() => navigate('/settings?tab=integrations')}
            />,
        );
    }

    if (!statusDetails?.matched) {
        const triedPhones = Array.isArray(statusDetails?.tried?.phone)
            ? statusDetails.tried.phone.filter(Boolean).join(', ')
            : '';
        const triedEmail = statusDetails?.tried?.email || '';

        return renderStateSection(
            <EmptyStateCard
                title="No Support Board contact match yet"
                description={`We could not match ${client?.name || 'this client'} in Support Board. Tried phone variants: ${triedPhones || 'none'}. Tried email: ${triedEmail || 'none'}.`}
                actionLabel="Refresh match"
                onAction={() => refreshMutation.mutate()}
            />,
        );
    }

    return (
        <>
            <section className="crm-surface p-4">
                <div className="grid gap-4 xl:grid-cols-[320px_minmax(0,1fr)] 2xl:grid-cols-[320px_minmax(0,1fr)_360px]">
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

                        <div className="max-h-[720px] overflow-auto p-3">
                            {conversationsQuery.isLoading ? (
                                <p className="px-1 py-2 text-sm text-slate-500">Loading conversations...</p>
                            ) : conversationsQuery.error ? (
                                <EmptyStateCard
                                    tone="error"
                                    title="Conversation list unavailable"
                                    description={conversationsErrorMessage}
                                    actionLabel="Retry"
                                    onAction={() => conversationsQuery.refetch()}
                                />
                            ) : !conversations.length ? (
                                <div className="rounded-xl border border-dashed border-slate-300 bg-white px-4 py-5 text-sm text-slate-500">
                                    No Support Board conversations are attached to this contact yet.
                                </div>
                            ) : (
                                <div className="space-y-2">
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
                            )}
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
                                            Created {formatDateTime(conversationQuery.data?.created_at || selectedConversationSummary.created_at)}
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
                                    <h3 className="text-base font-semibold text-slate-900">Conversation workspace</h3>
                                    <p className="mt-1 text-sm text-slate-500">Select a conversation to load its full message history, or use the profile panel to review metadata and sync details.</p>
                                </div>
                            )}
                        </div>

                        <div className="space-y-4 p-4">
                            {conversationsQuery.isLoading ? (
                                <p className="text-sm text-slate-500">Loading thread...</p>
                            ) : conversationsQuery.error ? (
                                <EmptyStateCard
                                    tone="error"
                                    title="Conversation workspace unavailable"
                                    description={conversationsErrorMessage}
                                    actionLabel="Retry"
                                    onAction={() => conversationsQuery.refetch()}
                                />
                            ) : !selectedConversationId ? (
                                <EmptyStateCard
                                    title="No conversation selected"
                                    description={conversations.length
                                        ? 'Choose a conversation from the list to load the message history.'
                                        : 'This client is linked in Support Board, but there are no message threads yet.'}
                                />
                            ) : conversationQuery.isLoading ? (
                                <p className="text-sm text-slate-500">Loading thread...</p>
                            ) : conversationQuery.error ? (
                                <EmptyStateCard
                                    tone="error"
                                    title="Conversation unavailable"
                                    description={conversationErrorMessage}
                                    actionLabel="Retry conversation"
                                    onAction={() => conversationQuery.refetch()}
                                />
                            ) : !conversationQuery.data ? (
                                <EmptyStateCard
                                    title="Conversation unavailable"
                                    description="Support Board did not return conversation details for the selected thread."
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

                    <aside className="space-y-4 xl:col-span-2 2xl:col-span-1 2xl:sticky 2xl:top-4">
                        <section className="rounded-xl border border-slate-200 bg-white shadow-sm">
                            <div className="border-b border-slate-200 px-4 py-3">
                                <h3 className="text-sm font-semibold text-slate-900">Support Board profile</h3>
                                <p className="mt-1 text-xs text-slate-500">Review the linked contact details before syncing anything back to CRM.</p>
                            </div>

                            <div className="space-y-4 p-4">
                                {profileQuery.isLoading ? (
                                    <p className="text-sm text-slate-500">Loading Support Board profile details...</p>
                                ) : profileQuery.error ? (
                                    <EmptyStateCard
                                        tone="error"
                                        title="Profile details unavailable"
                                        description={profileErrorMessage}
                                        actionLabel="Retry profile"
                                        onAction={() => profileQuery.refetch()}
                                    />
                                ) : !profileDetails?.sb_user ? (
                                    <EmptyStateCard
                                        title="Profile details are not available yet"
                                        description="Refresh the Support Board cache to retry loading the matched profile."
                                        actionLabel="Refresh"
                                        onAction={() => refreshMutation.mutate()}
                                    />
                                ) : (
                                    <>
                                        <div className="rounded-xl border border-slate-200 bg-slate-50 px-4 py-3">
                                            <div className="flex items-start justify-between gap-3">
                                                <div className="min-w-0">
                                                    <h4 className="truncate text-base font-semibold text-slate-900">
                                                        {profileDetails.sb_user.full_name || 'Unnamed Support Board user'}
                                                    </h4>
                                                    <p className="mt-1 text-xs text-slate-500">
                                                        Support Board user #{profileDetails.sb_user.id || '—'}
                                                    </p>
                                                </div>
                                                <span className="inline-flex items-center rounded-md bg-slate-900 px-2.5 py-1 text-[11px] font-semibold text-white">
                                                    {String(profileDetails.sb_user.user_type || 'user').replace(/_/g, ' ')}
                                                </span>
                                            </div>

                                            <div className="mt-3 grid gap-2 sm:grid-cols-2">
                                                <div className="rounded-lg border border-slate-200 bg-white px-3 py-2">
                                                    <p className="text-[11px] font-semibold uppercase tracking-[0.09em] text-slate-500">Matched by</p>
                                                    <p className="mt-1 text-sm font-medium text-slate-800">{profileDetails.matched_by || '—'}</p>
                                                </div>
                                                <div className="rounded-lg border border-slate-200 bg-white px-3 py-2">
                                                    <p className="text-[11px] font-semibold uppercase tracking-[0.09em] text-slate-500">Last activity</p>
                                                    <p className="mt-1 text-sm font-medium text-slate-800">{formatRelativeTime(profileDetails.sb_user.last_activity)}</p>
                                                    <p className="mt-0.5 text-[11px] text-slate-500">{formatDateTime(profileDetails.sb_user.last_activity)}</p>
                                                </div>
                                            </div>

                                            <div className="mt-2 rounded-lg border border-slate-200 bg-white px-3 py-2">
                                                <p className="text-[11px] font-semibold uppercase tracking-[0.09em] text-slate-500">Created</p>
                                                <p className="mt-1 text-sm font-medium text-slate-800">{formatDateTime(profileDetails.sb_user.creation_time)}</p>
                                            </div>
                                        </div>

                                        {marketSuggestion && !marketSuggestion.matches_current_market ? (
                                            <div className="rounded-lg border border-amber-200 bg-amber-50 px-3 py-2 text-sm text-amber-800">
                                                <p className="font-semibold text-amber-900">Market review suggested</p>
                                                <p className="mt-1">
                                                    Support Board hints suggest <span className="font-semibold">{marketSuggestion.country_name}</span>, but this CRM client is still in <span className="font-semibold">{marketSuggestion.current_market || 'the current market'}</span>.
                                                </p>
                                                <p className="mt-1 text-xs text-amber-700">{marketSuggestion.note}</p>
                                            </div>
                                        ) : null}

                                        {citySuggestion?.value ? (
                                            <div className="rounded-lg border border-sky-200 bg-sky-50 px-3 py-2 text-sm text-sky-800">
                                                <p className="font-semibold text-sky-900">City suggestion available</p>
                                                <p className="mt-1">
                                                    Location data suggests <span className="font-semibold">{citySuggestion.value}</span>.
                                                </p>
                                                <p className="mt-1 text-xs text-sky-700">{citySuggestion.note}</p>
                                            </div>
                                        ) : null}

                                        {primaryDetails.length > 0 ? (
                                            <div className="grid gap-3">
                                                {primaryDetails.map((detail) => (
                                                    <SupportBoardDetailCard key={detail.slug} detail={detail} />
                                                ))}
                                            </div>
                                        ) : (
                                            <div className="rounded-lg border border-dashed border-slate-300 bg-slate-50 px-4 py-4 text-sm text-slate-500">
                                                No additional Support Board details are available for this user yet.
                                            </div>
                                        )}

                                        {secondaryDetails.length > 0 ? (
                                            <div className="space-y-3">
                                                <button
                                                    type="button"
                                                    onClick={() => setShowAllDetails((current) => !current)}
                                                    className="inline-flex min-h-11 items-center rounded-md border border-slate-200 px-3 py-2 text-sm font-semibold text-slate-700 transition hover:bg-slate-50"
                                                >
                                                    {showAllDetails ? 'Hide extra metadata' : `Show ${secondaryDetails.length} more detail${secondaryDetails.length === 1 ? '' : 's'}`}
                                                </button>

                                                {showAllDetails ? (
                                                    <div className="grid gap-3">
                                                        {secondaryDetails.map((detail) => (
                                                            <SupportBoardDetailCard key={detail.slug} detail={detail} />
                                                        ))}
                                                    </div>
                                                ) : null}
                                            </div>
                                        ) : null}
                                    </>
                                )}
                            </div>
                        </section>

                        <section className="rounded-xl border border-slate-200 bg-white shadow-sm">
                            <div className="border-b border-slate-200 px-4 py-3">
                                <h3 className="text-sm font-semibold text-slate-900">Sync profile details</h3>
                                <p className="mt-1 text-xs text-slate-500">Preview every change before it is applied. Market stays untouched even if Support Board hints suggest another country.</p>
                            </div>

                            <div className="space-y-4 p-4">
                                <div>
                                    <p className="mb-2 text-[11px] font-semibold uppercase tracking-[0.09em] text-slate-500">Direction</p>
                                    <div className="grid gap-2 sm:grid-cols-2">
                                        {[
                                            { key: 'support_board_to_crm', label: 'Support Board -> CRM' },
                                            { key: 'crm_to_support_board', label: 'CRM -> Support Board' },
                                        ].map((option) => (
                                            <button
                                                key={option.key}
                                                type="button"
                                                onClick={() => updateSyncForm((current) => ({ ...current, direction: option.key }))}
                                                className={`min-h-11 rounded-lg border px-3 py-2 text-sm font-semibold transition ${
                                                    syncForm.direction === option.key
                                                        ? 'border-teal-300 bg-teal-50 text-teal-800'
                                                        : 'border-slate-200 bg-white text-slate-700 hover:bg-slate-50'
                                                }`}
                                            >
                                                {option.label}
                                            </button>
                                        ))}
                                    </div>
                                </div>

                                <div>
                                    <label htmlFor="support-board-sync-mode" className="mb-2 block text-[11px] font-semibold uppercase tracking-[0.09em] text-slate-500">
                                        Safety mode
                                    </label>
                                    <select
                                        id="support-board-sync-mode"
                                        value={syncForm.mode}
                                        onChange={(event) => updateSyncForm((current) => ({ ...current, mode: event.target.value }))}
                                        className="crm-select w-full"
                                    >
                                        <option value="fill_blanks">Fill blanks only</option>
                                        <option value="overwrite">Overwrite selected fields</option>
                                    </select>
                                    <p className="mt-2 text-xs leading-5 text-slate-500">
                                        Start with <span className="font-semibold text-slate-700">Fill blanks only</span>. Use overwrite only after confirming the target side is stale.
                                    </p>
                                </div>

                                {syncForm.mode === 'overwrite' ? (
                                    <div className="rounded-lg border border-amber-200 bg-amber-50 px-3 py-3 text-sm text-amber-800">
                                        <p className="font-semibold text-amber-900">Overwrite mode is active</p>
                                        <p className="mt-1">
                                            Any field marked ready in the preview can replace an existing value. Review each row before applying.
                                        </p>
                                    </div>
                                ) : null}

                                <div>
                                    <p className="mb-2 text-[11px] font-semibold uppercase tracking-[0.09em] text-slate-500">Fields</p>
                                    <div className="grid gap-2">
                                        {SYNC_FIELD_OPTIONS.map((field) => {
                                            const checked = selectedFields.includes(field.key);

                                            return (
                                                <label
                                                    key={field.key}
                                                    className={`flex min-h-11 cursor-pointer items-start gap-3 rounded-lg border px-3 py-2 transition ${
                                                        checked
                                                            ? 'border-teal-300 bg-teal-50'
                                                            : 'border-slate-200 bg-white hover:bg-slate-50'
                                                    }`}
                                                >
                                                    <input
                                                        type="checkbox"
                                                        checked={checked}
                                                        onChange={() => {
                                                            updateSyncForm((current) => ({
                                                                ...current,
                                                                fields: current.fields.includes(field.key)
                                                                    ? current.fields.filter((value) => value !== field.key)
                                                                    : [...current.fields, field.key],
                                                            }));
                                                        }}
                                                        className="mt-1 h-4 w-4 rounded border-slate-300 text-teal-700 focus:ring-teal-600"
                                                    />
                                                    <span className="min-w-0">
                                                        <span className="block text-sm font-semibold text-slate-900">{field.label}</span>
                                                        <span className="mt-1 block text-xs text-slate-600">{field.description}</span>
                                                    </span>
                                                </label>
                                            );
                                        })}
                                    </div>
                                </div>

                                <div className="flex flex-wrap items-center justify-between gap-3 rounded-lg border border-slate-200 bg-slate-50 px-3 py-3">
                                    <div>
                                        <p className="text-sm font-semibold text-slate-900">Preview before applying</p>
                                        <p className="mt-1 text-xs text-slate-500">This compares the chosen source against the current target and highlights safe fills, updates, and conflicts.</p>
                                    </div>
                                    <button
                                        type="button"
                                        onClick={() => previewMutation.mutate({
                                            direction: syncForm.direction,
                                            mode: syncForm.mode,
                                            fields: syncForm.fields,
                                        })}
                                        disabled={!profileDetails?.sb_user || selectedFields.length === 0 || previewMutation.isPending || applyMutation.isPending}
                                        className="crm-btn-primary disabled:cursor-not-allowed disabled:opacity-50"
                                    >
                                        {previewMutation.isPending ? 'Preparing preview...' : 'Preview sync'}
                                    </button>
                                </div>

                                {syncPreview ? (
                                    <div className="space-y-4 rounded-xl border border-slate-200 bg-slate-50 p-4">
                                        <div className="flex flex-wrap items-start justify-between gap-3">
                                            <div>
                                                <p className="text-sm font-semibold text-slate-900">Preview ready</p>
                                                <p className="mt-1 text-xs text-slate-500">
                                                    {directionLabel(syncPreview.direction)} • {modeLabel(syncPreview.mode)}
                                                </p>
                                            </div>
                                            <div className="flex flex-wrap gap-2">
                                                <span className="inline-flex items-center rounded-md bg-emerald-50 px-2.5 py-1 text-[11px] font-semibold text-emerald-700 ring-1 ring-inset ring-emerald-200">
                                                    {syncPreview.counts?.applyable || 0} ready
                                                </span>
                                                <span className="inline-flex items-center rounded-md bg-amber-50 px-2.5 py-1 text-[11px] font-semibold text-amber-700 ring-1 ring-inset ring-amber-200">
                                                    {syncPreview.counts?.conflicts || 0} review
                                                </span>
                                                <span className="inline-flex items-center rounded-md bg-slate-100 px-2.5 py-1 text-[11px] font-semibold text-slate-700 ring-1 ring-inset ring-slate-200">
                                                    {syncPreview.counts?.same || 0} unchanged
                                                </span>
                                            </div>
                                        </div>

                                        {syncWarnings.length > 0 ? (
                                            <div className="space-y-2 rounded-lg border border-amber-200 bg-amber-50 px-3 py-3 text-sm text-amber-800">
                                                <p className="font-semibold text-amber-900">Review notes</p>
                                                {syncWarnings.map((warning, index) => (
                                                    <p key={`${warning.type || 'warning'}-${index}`}>{warning.message}</p>
                                                ))}
                                            </div>
                                        ) : null}

                                        <div className="space-y-3">
                                            {syncPreviewRows.map((row) => (
                                                <SyncPreviewRow key={row.field} row={row} />
                                            ))}
                                        </div>

                                        <div>
                                            <label htmlFor="support-board-sync-reason" className="mb-2 block text-[11px] font-semibold uppercase tracking-[0.09em] text-slate-500">
                                                Reason for audit log
                                            </label>
                                            <textarea
                                                id="support-board-sync-reason"
                                                rows={3}
                                                value={syncForm.reason}
                                                onChange={(event) => updateSyncForm((current) => ({ ...current, reason: event.target.value }))}
                                                className="crm-input"
                                                placeholder="Why is this sync being applied?"
                                            />
                                        </div>

                                        <div className="flex flex-wrap justify-between gap-2">
                                            <button
                                                type="button"
                                                onClick={() => previewMutation.mutate({
                                                    direction: syncForm.direction,
                                                    mode: syncForm.mode,
                                                    fields: syncForm.fields,
                                                })}
                                                disabled={previewMutation.isPending || applyMutation.isPending}
                                                className="crm-btn-secondary disabled:cursor-not-allowed disabled:opacity-50"
                                            >
                                                {previewMutation.isPending ? 'Refreshing preview...' : 'Refresh preview'}
                                            </button>
                                            <button
                                                type="button"
                                                onClick={() => setSyncConfirmOpen(true)}
                                                disabled={applicablePreviewRows.length === 0 || !syncForm.reason.trim() || applyMutation.isPending}
                                                className="crm-btn-primary disabled:cursor-not-allowed disabled:opacity-50"
                                            >
                                                Apply sync
                                            </button>
                                        </div>
                                    </div>
                                ) : (
                                    <div className="rounded-lg border border-dashed border-slate-300 bg-slate-50 px-4 py-4 text-sm text-slate-500">
                                        Choose a direction, select the fields, and run a preview. Nothing is written until you confirm the apply step.
                                    </div>
                                )}
                            </div>
                        </section>
                    </aside>
                </div>
            </section>

            <ConfirmDialog
                open={syncConfirmOpen}
                title="Apply Support Board profile sync"
                message="This will apply only the changes marked ready in the preview."
                confirmLabel={applyMutation.isPending ? 'Applying...' : 'Apply sync'}
                onCancel={() => setSyncConfirmOpen(false)}
                onConfirm={() => applyMutation.mutate({
                    direction: syncForm.direction,
                    mode: syncForm.mode,
                    fields: syncForm.fields,
                    reason: syncForm.reason.trim(),
                })}
                confirmDisabled={applyMutation.isPending || applicablePreviewRows.length === 0 || !syncForm.reason.trim()}
                isPending={applyMutation.isPending}
                tone={syncForm.mode === 'overwrite' ? 'warning' : 'default'}
            >
                <div className="space-y-3">
                    <div className="rounded-md border border-slate-200 bg-slate-50 px-3 py-2 text-xs text-slate-700">
                        <p><span className="font-semibold text-slate-900">Direction:</span> {directionLabel(syncForm.direction)}</p>
                        <p className="mt-1"><span className="font-semibold text-slate-900">Mode:</span> {modeLabel(syncForm.mode)}</p>
                        <p className="mt-1"><span className="font-semibold text-slate-900">Ready changes:</span> {applicablePreviewRows.length}</p>
                    </div>

                    <div className="space-y-2 rounded-md border border-slate-200 bg-white px-3 py-3">
                        {applicablePreviewRows.map((row) => (
                            <div key={`confirm-${row.field}`} className="flex items-start justify-between gap-3 text-xs">
                                <div>
                                    <p className="font-semibold text-slate-900">{row.label}</p>
                                    <p className="mt-0.5 text-slate-500">
                                        {row.source_label}: {row.source_value || '—'}
                                    </p>
                                    <p className="mt-0.5 text-slate-500">
                                        {row.target_label}: {row.target_value || '—'}
                                    </p>
                                </div>
                                <span className={`inline-flex items-center rounded-md px-2 py-0.5 text-[11px] font-semibold ring-1 ring-inset ${syncOutcomeMeta(row.outcome).badge}`}>
                                    {syncOutcomeMeta(row.outcome).label}
                                </span>
                            </div>
                        ))}
                    </div>
                </div>
            </ConfirmDialog>
        </>
    );
}
