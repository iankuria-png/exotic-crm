import React from 'react';

const TONE_CLASSES = {
    slate: {
        bubble: 'bg-slate-100 text-slate-700 ring-slate-200',
        pill: 'bg-slate-100 text-slate-700 ring-slate-200',
    },
    sky: {
        bubble: 'bg-sky-50 text-sky-700 ring-sky-200',
        pill: 'bg-sky-50 text-sky-700 ring-sky-200',
    },
    teal: {
        bubble: 'bg-teal-50 text-teal-700 ring-teal-200',
        pill: 'bg-teal-50 text-teal-700 ring-teal-200',
    },
    emerald: {
        bubble: 'bg-emerald-50 text-emerald-700 ring-emerald-200',
        pill: 'bg-emerald-50 text-emerald-700 ring-emerald-200',
    },
    amber: {
        bubble: 'bg-amber-50 text-amber-700 ring-amber-200',
        pill: 'bg-amber-50 text-amber-700 ring-amber-200',
    },
    indigo: {
        bubble: 'bg-indigo-50 text-indigo-700 ring-indigo-200',
        pill: 'bg-indigo-50 text-indigo-700 ring-indigo-200',
    },
    rose: {
        bubble: 'bg-rose-50 text-rose-700 ring-rose-200',
        pill: 'bg-rose-50 text-rose-700 ring-rose-200',
    },
};

function WorkflowGlyph({ name, className = 'h-5 w-5' }) {
    switch (name) {
        case 'compass':
            return (
                <svg className={className} fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
                    <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={1.8} d="M12 3.75a8.25 8.25 0 1 0 8.25 8.25A8.25 8.25 0 0 0 12 3.75Z" />
                    <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={1.8} d="m14.6 9.4-2.18 4.36-4.37 2.18 2.18-4.36 4.37-2.18Z" />
                </svg>
            );
        case 'layout':
            return (
                <svg className={className} fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
                    <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={1.8} d="M4.5 5.25h15v13.5h-15z" />
                    <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={1.8} d="M4.5 9.75h15M9.75 9.75v9" />
                </svg>
            );
        case 'users':
            return (
                <svg className={className} fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
                    <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={1.8} d="M15.75 19.5v-.75A3.75 3.75 0 0 0 12 15H6.75A3.75 3.75 0 0 0 3 18.75v.75" />
                    <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={1.8} d="M9.375 11.25A3.375 3.375 0 1 0 9.375 4.5a3.375 3.375 0 0 0 0 6.75Z" />
                    <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={1.8} d="M17.25 8.25a2.625 2.625 0 0 1 0 5.25m3 6v-.75a3 3 0 0 0-2.25-2.903" />
                </svg>
            );
        case 'user-plus':
            return (
                <svg className={className} fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
                    <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={1.8} d="M15 19.5v-.75A3.75 3.75 0 0 0 11.25 15H6.75A3.75 3.75 0 0 0 3 18.75v.75" />
                    <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={1.8} d="M9 11.25A3.75 3.75 0 1 0 9 3.75a3.75 3.75 0 0 0 0 7.5Z" />
                    <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={1.8} d="M18 8.25v4.5M15.75 10.5h4.5" />
                </svg>
            );
        case 'key':
            return (
                <svg className={className} fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
                    <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={1.8} d="M15 7.5a3 3 0 1 1-2.91 3.75H10.5l-1.5 1.5v1.5h-1.5v1.5H6v1.5H3.75v-2.379a1.5 1.5 0 0 1 .44-1.06l5.154-5.153A3 3 0 0 1 15 7.5Z" />
                </svg>
            );
        case 'link':
            return (
                <svg className={className} fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
                    <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={1.8} d="M13.19 8.688a4.5 4.5 0 0 1 6.364 6.364l-2.636 2.636a4.5 4.5 0 0 1-6.364-6.364m.256-3.012a4.5 4.5 0 0 0-6.364 0L1.81 10.948a4.5 4.5 0 1 0 6.364 6.364l2.636-2.636" />
                </svg>
            );
        case 'clipboard':
            return (
                <svg className={className} fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
                    <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={1.8} d="M9 4.5h6A2.25 2.25 0 0 1 17.25 6.75v11.25A2.25 2.25 0 0 1 15 20.25H9A2.25 2.25 0 0 1 6.75 18V6.75A2.25 2.25 0 0 1 9 4.5Z" />
                    <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={1.8} d="M9.75 4.5V3.75A1.5 1.5 0 0 1 11.25 2.25h1.5a1.5 1.5 0 0 1 1.5 1.5v.75" />
                    <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={1.8} d="M9.75 9h4.5M9.75 12.75h4.5M9.75 16.5h3" />
                </svg>
            );
        case 'shield-check':
            return (
                <svg className={className} fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
                    <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={1.8} d="M12 3.75c2.005 1.515 4.292 2.49 6.75 2.875v4.72c0 4.287-2.513 8.189-6.423 9.975L12 21.75l-.327-.43C7.763 19.534 5.25 15.632 5.25 11.345V6.625A12.5 12.5 0 0 0 12 3.75Z" />
                    <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={1.8} d="m9.75 12.375 1.5 1.5 3-3.375" />
                </svg>
            );
        case 'sparkles':
            return (
                <svg className={className} fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
                    <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={1.8} d="m9.813 15.904.977-2.475 2.475-.977-2.475-.977-.977-2.475-.977 2.475-2.475.977 2.475.977.977 2.475ZM17.25 8.25l.53-1.342 1.342-.53-1.342-.53-.53-1.342-.53 1.342-1.342.53 1.342.53.53 1.342ZM16.894 20.567l.708-1.798 1.798-.708-1.798-.708-.708-1.798-.708 1.798-1.798.708 1.798.708.708 1.798Z" />
                </svg>
            );
        case 'credit-card':
            return (
                <svg className={className} fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
                    <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={1.8} d="M3.75 6.75h16.5A1.5 1.5 0 0 1 21.75 8.25v7.5a1.5 1.5 0 0 1-1.5 1.5H3.75a1.5 1.5 0 0 1-1.5-1.5v-7.5a1.5 1.5 0 0 1 1.5-1.5Z" />
                    <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={1.8} d="M2.25 10.5h19.5M6 15.75h3" />
                </svg>
            );
        case 'receipt':
            return (
                <svg className={className} fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
                    <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={1.8} d="M6 3.75h12v16.5l-2.25-1.5-2.25 1.5-2.25-1.5-2.25 1.5L6 18.75V3.75Z" />
                    <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={1.8} d="M9 8.25h6M9 12h6M9 15.75h3.75" />
                </svg>
            );
        case 'chat':
            return (
                <svg className={className} fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
                    <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={1.8} d="M7.5 8.25h9M7.5 12h5.25M6 19.5l-2.25 1.5v-4.215A7.5 7.5 0 0 1 3 12a7.5 7.5 0 0 1 7.5-7.5h3A7.5 7.5 0 0 1 21 12a7.5 7.5 0 0 1-7.5 7.5H6Z" />
                </svg>
            );
        case 'funnel':
            return (
                <svg className={className} fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
                    <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={1.8} d="M3.75 5.25h16.5l-6.75 7.5v5.25l-3 1.5v-6.75l-6.75-7.5Z" />
                </svg>
            );
        default:
            return (
                <svg className={className} fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
                    <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={1.8} d="M12 6v6l4 2.25M21 12a9 9 0 1 1-18 0 9 9 0 0 1 18 0Z" />
                </svg>
            );
    }
}

export function resolveFaqCategoryVisual(category) {
    const key = category?.slug || category?.crm_page || '';

    switch (key) {
        case 'cross-cutting':
        case 'cross_cutting':
            return { tone: 'slate', icon: 'compass', label: 'Operating rules' };
        case 'dashboard':
            return { tone: 'sky', icon: 'layout', label: 'Read the queues' };
        case 'clients':
            return { tone: 'teal', icon: 'users', label: 'Manage profiles' };
        case 'leads':
            return { tone: 'indigo', icon: 'funnel', label: 'Qualify pipeline' };
        case 'client-detail':
        case 'client_detail':
            return { tone: 'amber', icon: 'clipboard', label: 'Work one record' };
        case 'payments-subscriptions':
        case 'payments':
            return { tone: 'emerald', icon: 'credit-card', label: 'Payments and activation' };
        case 'team':
            return { tone: 'indigo', icon: 'users', label: 'Coaching and goals' };
        default:
            return { tone: 'slate', icon: 'compass', label: 'Workflow guide' };
    }
}

export function resolveFaqArticleVisual(article) {
    const title = String(article?.title || article || '').toLowerCase();

    if (title.includes('adding a client')) return { tone: 'teal', icon: 'user-plus', label: 'Create client' };
    if (title.includes('editing a client')) return { tone: 'sky', icon: 'clipboard', label: 'Edit safely' };
    if (title.includes('market sync') || title.includes('sync')) return { tone: 'sky', icon: 'layout', label: 'Sync and visibility' };
    if (title.includes('client access')) return { tone: 'amber', icon: 'key', label: 'Access tools' };
    if (title.includes('payment link')) return { tone: 'indigo', icon: 'link', label: 'Send payment link' };
    if (title.includes('payment diagnostics')) return { tone: 'indigo', icon: 'receipt', label: 'Diagnostics' };
    if (title.includes('activating a subscription')) return { tone: 'emerald', icon: 'credit-card', label: 'Activate subscription' };
    if (title.includes('analytics')) return { tone: 'sky', icon: 'layout', label: 'Analytics' };
    if (title.includes('profile health')) return { tone: 'amber', icon: 'shield-check', label: 'Duplicate review' };
    if (title.includes('leaderboard') || title.includes('goal')) return { tone: 'indigo', icon: 'users', label: 'Team coaching' };
    if (title.includes('subscription')) return { tone: 'emerald', icon: 'credit-card', label: 'Subscription workflow' };
    if (title.includes('verified')) return { tone: 'emerald', icon: 'shield-check', label: 'Verification' };
    if (title.includes('new badge')) return { tone: 'rose', icon: 'sparkles', label: 'Badge behavior' };
    if (title.includes('search') || title.includes('filter')) return { tone: 'slate', icon: 'funnel', label: 'Find the right rows' };
    if (title.includes('lead')) return { tone: 'indigo', icon: 'funnel', label: 'Lead workflow' };
    if (title.includes('payment')) return { tone: 'indigo', icon: 'receipt', label: 'Payment handling' };
    if (title.includes('tour')) return { tone: 'amber', icon: 'chat', label: 'Profile movement' };
    if (title.includes('dashboard')) return { tone: 'sky', icon: 'layout', label: 'Queue reading' };

    return null;
}

export function FaqIconBubble({ visual, className = '' }) {
    const tone = TONE_CLASSES[visual?.tone] || TONE_CLASSES.slate;

    return (
        <span className={`inline-flex h-11 w-11 shrink-0 items-center justify-center rounded-2xl ring-1 ring-inset ${tone.bubble} ${className}`}>
            <WorkflowGlyph name={visual?.icon} />
        </span>
    );
}

export function FaqWorkflowPill({ visual }) {
    if (!visual?.label) {
        return null;
    }

    const tone = TONE_CLASSES[visual?.tone] || TONE_CLASSES.slate;

    return (
        <span className={`inline-flex items-center gap-1.5 rounded-full px-2.5 py-1 text-[11px] font-semibold uppercase tracking-[0.12em] ring-1 ring-inset ${tone.pill}`}>
            <WorkflowGlyph name={visual?.icon} className="h-3.5 w-3.5" />
            {visual.label}
        </span>
    );
}
