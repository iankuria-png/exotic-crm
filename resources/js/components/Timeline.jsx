import React from 'react';

const eventTones = {
    deal_created: 'bg-sky-100 text-sky-700',
    deal_activated: 'bg-emerald-100 text-emerald-700',
    deal_extended: 'bg-indigo-100 text-indigo-700',
    profile_activated: 'bg-emerald-100 text-emerald-700',
    profile_deactivated: 'bg-rose-100 text-rose-700',
    note_added: 'bg-amber-100 text-amber-700',
    payment_received: 'bg-emerald-100 text-emerald-700',
    status_changed: 'bg-teal-100 text-teal-700',
    support_chat_reply: 'bg-sky-100 text-sky-700',
    support_board_profile_sync: 'bg-violet-100 text-violet-700',
};

function formatEventDescription(event) {
    const content = event.content || {};
    const currency = content.currency || 'KES';

    switch (event.event_type) {
        case 'deal_created':
            return `Subscription created: ${content.plan_type || ''} (${content.duration || ''}) ${currency} ${content.amount || 0}`;
        case 'deal_activated':
            return `Subscription activated, expires ${content.expires_at ? new Date(content.expires_at).toLocaleDateString() : ''}`;
        case 'deal_extended':
            return `Subscription extended by ${content.additional_days || 0} days`;
        case 'profile_activated':
            return `Profile activated for ${content.duration_days || 0} days`;
        case 'profile_deactivated':
            return `Profile deactivated${content.reason ? `: ${content.reason}` : ''}`;
        case 'note_added':
            return `${content.note_type || 'Note'} note added${content.has_follow_up ? ' with follow-up' : ''}`;
        case 'payment_received':
            return `Payment received: ${currency} ${content.amount || 0}`;
        case 'status_changed':
            return `Status changed from ${content.from || '?'} to ${content.to || '?'}`;
        case 'support_chat_reply':
            return 'Chat reply sent via Support Board';
        case 'support_board_profile_sync':
            return `Support Board profile sync applied${content.direction ? ` (${String(content.direction).replace(/_/g, ' ')})` : ''}`;
        default:
            return event.event_type?.replace(/_/g, ' ') || 'Event';
    }
}

export default function Timeline({ events, isLoading }) {
    if (isLoading) {
        return (
            <div className="flex items-center justify-center py-8 text-slate-500">
                <div className="mr-2 h-5 w-5 animate-spin rounded-full border-2 border-teal-600 border-t-transparent" />
                Loading timeline...
            </div>
        );
    }

    if (!events?.length) {
        return <p className="py-6 text-center text-sm text-slate-500">No activity yet.</p>;
    }

    return (
        <div className="space-y-2">
            {events.map((event, idx) => {
                const tone = eventTones[event.event_type] || 'bg-slate-100 text-slate-600';

                return (
                    <article key={event.id || idx} className="rounded-md border border-slate-200 p-3">
                        <div className="flex items-start gap-3">
                            <span className={`mt-0.5 inline-flex h-6 w-6 shrink-0 items-center justify-center rounded-full text-[11px] font-semibold ${tone}`}>
                                {event.event_type?.charAt(0)?.toUpperCase() || 'E'}
                            </span>
                            <div className="min-w-0 flex-1">
                                <p className="text-sm text-slate-800">{formatEventDescription(event)}</p>
                                <div className="mt-1 flex flex-wrap items-center gap-2 text-xs text-slate-500">
                                    {event.actor ? <span>by {event.actor.name}</span> : null}
                                    <span>{event.created_at ? new Date(event.created_at).toLocaleString() : ''}</span>
                                </div>
                            </div>
                        </div>
                    </article>
                );
            })}
        </div>
    );
}
