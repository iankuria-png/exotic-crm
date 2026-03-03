import React from 'react';
import SectionFrame from '../SectionFrame';

const EVENT_COLORS = {
    payment: 'bg-teal-500',
    deal: 'bg-amber-500',
    lead: 'bg-blue-500',
    client: 'bg-slate-400',
};

function formatRelativeTime(value) {
    if (!value) return '--';
    const timestamp = new Date(value).getTime();
    if (Number.isNaN(timestamp)) return '--';

    const deltaMinutes = Math.floor((Date.now() - timestamp) / 60_000);
    if (deltaMinutes < 1) return 'just now';
    if (deltaMinutes < 60) return `${deltaMinutes}m ago`;

    const deltaHours = Math.floor(deltaMinutes / 60);
    if (deltaHours < 24) return `${deltaHours}h ago`;

    const deltaDays = Math.floor(deltaHours / 24);
    return `${deltaDays}d ago`;
}

function formatEventDescription(event) {
    const type = event.event_type || '';
    const entity = event.entity_type || '';

    const label = type.replace(/_/g, ' ');
    return `${label} (${entity})`;
}

export default function RecentActivityWidget({ events = [], isLoading }) {
    return (
        <SectionFrame title="Recent Activity" subtitle="Latest system events">
            {isLoading ? (
                <div className="space-y-2">
                    {[1, 2, 3].map((item) => (
                        <div key={item} className="h-10 animate-pulse rounded-md bg-slate-100" />
                    ))}
                </div>
            ) : events.length > 0 ? (
                <div className="space-y-1">
                    {events.map((event) => (
                        <div
                            key={event.id}
                            className="flex items-start gap-3 rounded-lg px-2 py-2 transition hover:bg-slate-50"
                        >
                            <span
                                className={`mt-1.5 h-2 w-2 shrink-0 rounded-full ${EVENT_COLORS[event.entity_type] || EVENT_COLORS.client}`}
                                aria-hidden="true"
                            />
                            <div className="min-w-0 flex-1">
                                <p className="text-sm font-medium capitalize text-slate-700">
                                    {formatEventDescription(event)}
                                </p>
                                <p className="text-xs text-slate-400">{formatRelativeTime(event.created_at)}</p>
                            </div>
                        </div>
                    ))}
                </div>
            ) : (
                <div className="rounded-md border border-dashed border-slate-200 bg-slate-50 px-4 py-6 text-center text-sm text-slate-500">
                    No recent activity.
                </div>
            )}
        </SectionFrame>
    );
}
