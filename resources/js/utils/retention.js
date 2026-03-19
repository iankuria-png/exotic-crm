export const RETENTION_BANDS = ['Stable', 'Watchlist', 'Needs Attention', 'Critical'];

export const RETENTION_BEHAVIOR_TAGS = [
    'Champion',
    'Stable',
    'Trial Converting',
    'Payment Friction',
    'Renewal Risk',
    'Dormant',
    'Win-back Candidate',
];

export function isRetentionWatchBand(band) {
    return ['Watchlist', 'Needs Attention', 'Critical'].includes(String(band || ''));
}

export function retentionBandClasses(band) {
    switch (String(band || '')) {
    case 'Stable':
        return 'bg-emerald-50 text-emerald-700 ring-emerald-200';
    case 'Watchlist':
        return 'bg-sky-50 text-sky-700 ring-sky-200';
    case 'Needs Attention':
        return 'bg-amber-50 text-amber-700 ring-amber-200';
    case 'Critical':
        return 'bg-rose-50 text-rose-700 ring-rose-200';
    default:
        return 'bg-slate-100 text-slate-600 ring-slate-200';
    }
}

export function retentionBandAccent(band) {
    switch (String(band || '')) {
    case 'Stable':
        return 'bg-emerald-500';
    case 'Watchlist':
        return 'bg-sky-500';
    case 'Needs Attention':
        return 'bg-amber-500';
    case 'Critical':
        return 'bg-rose-500';
    default:
        return 'bg-slate-400';
    }
}

export function retentionBandTone(band) {
    switch (String(band || '')) {
    case 'Stable':
        return 'success';
    case 'Watchlist':
        return 'accent';
    case 'Needs Attention':
        return 'warning';
    case 'Critical':
        return 'danger';
    default:
        return 'default';
    }
}

export const SALES_BAND_LABELS = {
    Stable: 'On Track',
    Watchlist: 'Monitor',
    'Needs Attention': 'Follow Up',
    Critical: 'Urgent',
};

export function salesBandLabel(band) {
    return SALES_BAND_LABELS[String(band || '')] || band || 'Unknown';
}

export function salesBandBorderColor(band) {
    switch (String(band || '')) {
    case 'Stable':
        return 'border-emerald-400';
    case 'Watchlist':
        return 'border-sky-400';
    case 'Needs Attention':
        return 'border-amber-400';
    case 'Critical':
        return 'border-rose-400';
    default:
        return 'border-slate-300';
    }
}

export function mapDriverToAction(driver) {
    const label = String(driver?.label || '').toLowerCase();
    const severity = Number(driver?.severity || 0);

    const borderColor = severity >= 70 ? 'border-rose-400' : severity >= 40 ? 'border-amber-400' : 'border-slate-200';
    const iconBg = severity >= 70 ? 'bg-rose-100 text-rose-600' : severity >= 40 ? 'bg-amber-100 text-amber-600' : 'bg-slate-100 text-slate-600';

    // Skip positive drivers — don't clutter the action list
    if (label.includes('healthy payment') && severity < 20) {
        return null;
    }

    // --- Payments ---
    if (label.includes('failed payment') || label.includes('payment failure')) {
        return { headline: 'Payment failed', suggestion: 'Send payment link', actionType: 'campaign', borderColor, iconBg };
    }
    if (label.includes('awaiting completion') || label.includes('pending payment')) {
        return { headline: 'Payment not finished', suggestion: 'Follow up', actionType: 'conversation', borderColor, iconBg };
    }

    // --- Subscription lifecycle ---
    if (label.includes('expires within') || label.includes('expiring soon') || label.includes('subscription expire')) {
        return { headline: 'Subscription ending soon', suggestion: 'Send reminder', actionType: 'campaign', borderColor, iconBg };
    }
    if (label.includes('activation payment') || label.includes('awaiting activation') || label.includes('payment pending')) {
        return { headline: 'Waiting for payment', suggestion: 'Send payment link', actionType: 'campaign', borderColor, iconBg };
    }
    if (label.includes('cancellation') || label.includes('cancelled')) {
        return { headline: 'Recently cancelled', suggestion: 'Reach out', actionType: 'conversation', borderColor, iconBg };
    }

    // --- Engagement ---
    if (label.includes('no recent') || label.includes('client activity')) {
        return { headline: 'Gone quiet', suggestion: 'Send message', actionType: 'conversation', borderColor, iconBg };
    }

    // --- Reminders ---
    if (label.includes('reminders paused') || label.includes('renewal reminders paused')) {
        return { headline: 'Reminders are off', suggestion: 'Resume reminders', actionType: 'campaign', borderColor, iconBg };
    }
    if (label.includes('not converting')) {
        return { headline: 'Reminders not working', suggestion: 'Try a different approach', actionType: 'conversation', borderColor, iconBg };
    }
    if (label.includes('reminder failed') || label.includes('reminders failed')) {
        return { headline: 'Reminder delivery failed', suggestion: 'Check reminders', actionType: 'campaign', borderColor, iconBg };
    }

    // --- Notifications ---
    if (label.includes('notification delivery') || label.includes('notification friction')) {
        return { headline: 'Notifications failing', suggestion: 'Check notifications', actionType: 'conversation', borderColor, iconBg };
    }

    // --- Market baseline ---
    if (label.includes('less active than similar') || label.includes('less active')) {
        return { headline: 'Less active than peers', suggestion: 'Send message', actionType: 'conversation', borderColor, iconBg };
    }
    if (label.includes('payments are lower') || label.includes('paying less')) {
        return { headline: 'Below average payments', suggestion: 'Review account', actionType: 'deals', borderColor, iconBg };
    }

    // --- Misc ---
    if (label.includes('limited relationship')) {
        return { headline: 'New client', suggestion: 'Review account', actionType: 'deals', borderColor, iconBg };
    }

    // Fallback — pass through original text
    return { headline: driver?.label || 'Needs review', suggestion: 'Review account', actionType: 'conversation', borderColor, iconBg };
}
