import React from 'react';
import { NavLink } from 'react-router-dom';
import { useQuery } from '@tanstack/react-query';
import { useAuth } from '../hooks/useAuth';
import faqApi from '../services/faqApi';

const brandLogo = '/Exotic%20Online%20Adv%20Logo-01-ChOpI09X.png';

const navGroups = [
    {
        title: 'Workspace',
        items: [
            { to: '/', label: 'Dashboard', icon: 'M3.75 4.5h7.5v6.75h-7.5V4.5Zm0 8.25h7.5V19.5h-7.5v-6.75Zm9 0h7.5V19.5h-7.5v-6.75Zm0-8.25h7.5v6.75h-7.5V4.5Z' },
            { to: '/team', label: 'Team', icon: 'M16.5 18.75a3.75 3.75 0 0 0-7.5 0m7.5 0H21m-4.5 0H7.5m0 0H3m4.5 0a3.75 3.75 0 0 1 7.5 0m-6-9a3 3 0 1 1-6 0 3 3 0 0 1 6 0Zm12 0a3 3 0 1 1-6 0 3 3 0 0 1 6 0Zm-6-1.5a3.75 3.75 0 1 1-7.5 0 3.75 3.75 0 0 1 7.5 0Z' },
            { to: '/clients', label: 'Clients', icon: 'M15.75 7.5a3.75 3.75 0 1 1-7.5 0 3.75 3.75 0 0 1 7.5 0ZM4.5 19.5a6.75 6.75 0 0 1 13.5 0' },
            { to: '/leads', label: 'Leads', icon: 'M3.75 6.75h16.5v10.5H3.75V6.75Zm0 0L12 13.125 20.25 6.75' },
            { to: '/conversations', label: 'Conversations', icon: 'M8.25 9.75h7.5m-7.5 3.75h4.5m-8.25-7.5h15A1.5 1.5 0 0 1 21 7.5v7.5a1.5 1.5 0 0 1-1.5 1.5H12l-4.5 3v-3H4.5A1.5 1.5 0 0 1 3 15V7.5A1.5 1.5 0 0 1 4.5 6Z' },
        ],
    },
    {
        title: 'Revenue',
        items: [
            { to: '/deals', label: 'Subscriptions', icon: 'M12 3.75v16.5m3.375-13.125h-4.5a2.625 2.625 0 0 0 0 5.25h2.25a2.625 2.625 0 1 1 0 5.25H8.25M21 12a9 9 0 1 1-18 0 9 9 0 0 1 18 0Z' },
            { to: '/payments', label: 'Payments', icon: 'M2.25 8.25h19.5M4.5 5.25h15a2.25 2.25 0 0 1 2.25 2.25v9a2.25 2.25 0 0 1-2.25 2.25h-15a2.25 2.25 0 0 1-2.25-2.25v-9A2.25 2.25 0 0 1 4.5 5.25Zm12 8.25h1.5' },
            { to: '/campaigns', label: 'Campaigns', icon: 'M3.75 11.25v1.5a1.5 1.5 0 0 0 1.5 1.5H6l1.5 4.5h2.25l-1.125-4.5H12a9 9 0 0 0 9-9v-.75a.75.75 0 0 0-1.28-.53L16.5 6.75h-8.25a4.5 4.5 0 0 0-4.5 4.5Z' },
            { to: '/reports', label: 'Reports', icon: 'M3.75 19.5h16.5M6.75 16.5v-5.25m5.25 5.25V8.25m5.25 8.25v-3.75' },
            { to: '/university', label: 'University', icon: 'M4.5 5.25A2.25 2.25 0 0 1 6.75 3h10.5a2.25 2.25 0 0 1 2.25 2.25v13.5A2.25 2.25 0 0 1 17.25 21H6.75A2.25 2.25 0 0 1 4.5 18.75V5.25Zm3 1.5h9m-9 3h9m-9 3h5.25' },
        ],
    },
    {
        title: 'Admin',
        items: [
            { to: '/settings', label: 'Settings', icon: 'M10.5 6h3m-6.75 6h10.5m-12.75 6h15M4.5 6h.008v.008H4.5V6Zm0 6h.008v.008H4.5V12Zm0 6h.008v.008H4.5V18Z' },
        ],
    },
];

const pushCampaignNavItem = {
    to: '/push-campaigns',
    label: 'Push Campaigns',
    icon: 'M4.5 6.75h15a1.5 1.5 0 0 1 1.5 1.5v7.5a1.5 1.5 0 0 1-1.5 1.5h-3l-3 2.25v-2.25h-9A1.5 1.5 0 0 1 3 15.75v-7.5a1.5 1.5 0 0 1 1.5-1.5Z',
};

const resourcesGroup = {
    title: 'Resources',
    items: [
        { to: '/faq', label: 'FAQ', icon: 'M11.25 4.5c-3.071 0-5.625 2.112-5.625 4.875 0 1.62.866 2.86 2.138 3.744.437.304.862.76.862 1.31v.321a.75.75 0 0 0 1.5 0v-.321c0-1.276-.673-2.269-1.506-2.847-1.036-.72-1.494-1.476-1.494-2.207 0-1.73 1.642-3.375 4.125-3.375 2.263 0 3.75 1.272 3.75 3 0 1.363-.787 2.29-2.25 3.218-.85.54-1.5 1.513-1.5 2.532v.75a.75.75 0 0 0 1.5 0v-.75c0-.424.283-.968.804-1.3 1.73-1.097 2.946-2.497 2.946-4.45 0-2.682-2.26-4.5-5.25-4.5ZM12 18.75a1.125 1.125 0 1 0 0 2.25 1.125 1.125 0 0 0 0-2.25Z' },
        { to: '/faq/feedback', label: 'Feedback', icon: 'M3.75 6.75h16.5v8.25H9.75l-3.75 3v-3h-2.25V6.75Z' },
    ],
};

export default function Sidebar({ onClose }) {
    const { user, logout, impersonation } = useAuth();
    const role = user?.role || '';
    const feedbackMetaQuery = useQuery({
        queryKey: ['sidebar-faq-feedback-meta', role],
        queryFn: () => faqApi.listFeedback({ per_page: 1, tab: role === 'admin' || role === 'sub_admin' ? undefined : 'mine' }),
        enabled: Boolean(user),
        staleTime: 30_000,
    });
    const feedbackMeta = feedbackMetaQuery.data?.meta || {};
    const showFeedbackDot = role === 'admin' || role === 'sub_admin'
        ? Number(feedbackMeta.admin_new_count || 0) > 0
        : Number(feedbackMeta.submitter_update_count || 0) > 0;

    const filteredNavGroups = role === 'marketing'
        ? [
            {
                title: 'Workspace',
                items: [
                    { to: '/', label: 'Dashboard', icon: 'M3.75 4.5h7.5v6.75h-7.5V4.5Zm0 8.25h7.5V19.5h-7.5v-6.75Zm9 0h7.5V19.5h-7.5v-6.75Zm0-8.25h7.5v6.75h-7.5V4.5Z' },
                    { to: '/team', label: 'Team', icon: 'M16.5 18.75a3.75 3.75 0 0 0-7.5 0m7.5 0H21m-4.5 0H7.5m0 0H3m4.5 0a3.75 3.75 0 0 1 7.5 0m-6-9a3 3 0 1 1-6 0 3 3 0 0 1 6 0Zm12 0a3 3 0 1 1-6 0 3 3 0 0 1 6 0Zm-6-1.5a3.75 3.75 0 1 1-7.5 0 3.75 3.75 0 0 1 7.5 0Z' },
                    { to: '/clients', label: 'Clients', icon: 'M15.75 7.5a3.75 3.75 0 1 1-7.5 0 3.75 3.75 0 0 1 7.5 0ZM4.5 19.5a6.75 6.75 0 0 1 13.5 0' },
                ],
            },
            {
                title: 'Campaigns',
                items: [pushCampaignNavItem],
            },
            resourcesGroup,
        ]
        : role === 'admin' || role === 'sub_admin'
            ? navGroups.map((group) => {
                if (group.title !== 'Revenue') {
                    return group;
                }

                if (group.items.some((item) => item.to === pushCampaignNavItem.to)) {
                    return group;
                }

                return {
                    ...group,
                    items: [...group.items, pushCampaignNavItem],
                };
            }).concat([resourcesGroup])
            : role === 'sales'
                ? navGroups.filter((group) => group.title !== 'Admin').concat([resourcesGroup])
                : navGroups.concat([resourcesGroup]);

    return (
        <div className="flex h-full flex-col border-r border-white/10 bg-gradient-to-b from-slate-950 via-slate-900 to-slate-900 text-slate-100">
            <div className="relative border-b border-white/10 px-4 py-4">
                <div className="flex items-center justify-center">
                    <div className="rounded-xl bg-white p-1.5 shadow-sm ring-1 ring-white/10">
                        <img src={brandLogo} alt="Exotic Online Advertising" className="h-8 w-auto object-contain" />
                    </div>
                </div>
                <button
                    onClick={onClose}
                    className="absolute right-3 top-3 rounded-lg p-1 text-slate-400 transition-colors hover:bg-white/10 hover:text-slate-100 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-teal-500 lg:hidden"
                    aria-label="Close navigation"
                >
                    <svg className="h-5 w-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M6 18L18 6M6 6l12 12" />
                    </svg>
                </button>
            </div>

            <nav className="flex-1 overflow-y-auto px-3 py-4">
                {filteredNavGroups.map((group) => (
                    <div key={group.title} className="mb-5">
                        <p className="px-3 text-[11px] font-semibold uppercase tracking-[0.18em] text-slate-500">{group.title}</p>
                        <div className="mt-2 space-y-1.5">
                            {group.items.map((item) => (
                                <NavLink
                                    key={item.to}
                                    to={item.to}
                                    end={item.to === '/'}
                                    className={({ isActive }) =>
                                        `group flex items-center gap-2.5 rounded-xl px-3 py-2.5 text-sm font-medium transition ${
                                            isActive
                                                ? 'bg-white/10 text-white shadow-[inset_0_0_0_1px_rgba(45,212,191,0.45)]'
                                                : 'text-slate-300 hover:bg-white/5 hover:text-white'
                                        }`
                                    }
                                    onClick={onClose}
                                >
                                    {({ isActive }) => (
                                        <>
                                            <span
                                                className={`flex h-8 w-8 shrink-0 items-center justify-center rounded-lg border transition ${
                                                    isActive
                                                        ? 'border-teal-300/50 bg-teal-400/15 text-teal-100'
                                                        : 'border-white/10 bg-white/5 text-slate-400 group-hover:border-white/20 group-hover:text-slate-200'
                                                }`}
                                                aria-hidden="true"
                                            >
                                                <svg className="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                    <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={1.9} d={item.icon} />
                                                </svg>
                                            </span>
                                            <span className="truncate">{item.label}</span>
                                            {item.to === '/faq/feedback' && showFeedbackDot ? (
                                                <span className="ml-auto inline-flex h-2.5 w-2.5 rounded-full bg-rose-500" aria-label="Unread feedback updates" />
                                            ) : null}
                                        </>
                                    )}
                                </NavLink>
                            ))}
                        </div>
                    </div>
                ))}
            </nav>

            <div className="border-t border-white/10 p-4">
                <div className="rounded-2xl border border-white/10 bg-white/[0.04] p-3">
                    <div className="flex items-center gap-3">
                        <div className="flex h-10 w-10 items-center justify-center rounded-xl bg-gradient-to-br from-teal-500 to-cyan-500 text-sm font-semibold text-white">
                            {user?.name?.charAt(0) || 'U'}
                        </div>
                        <div className="min-w-0 flex-1">
                            <p className="truncate text-sm font-medium text-slate-100">{user?.name || 'User'}</p>
                            <p className="truncate text-xs text-slate-400">{user?.email || 'No email'}</p>
                            {impersonation ? (
                                <p className="mt-1 truncate text-[11px] font-medium uppercase tracking-[0.12em] text-amber-300">
                                    Acting from {impersonation.impersonator?.email || impersonation.impersonator?.name}
                                </p>
                            ) : null}
                        </div>
                    </div>

                    <button
                        onClick={logout}
                        className="mt-3 inline-flex w-full items-center justify-center gap-2 rounded-xl border border-white/15 bg-slate-900/70 px-3 py-2 text-sm font-medium text-slate-100 transition hover:border-teal-300/50 hover:bg-teal-500/10 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-teal-500"
                    >
                        <svg className="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
                            <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M17 16l4-4m0 0l-4-4m4 4H7m6 4v1a3 3 0 01-3 3H6a3 3 0 01-3-3V7a3 3 0 013-3h4a3 3 0 013 3v1" />
                        </svg>
                        {impersonation ? 'Return to admin' : 'Sign out'}
                    </button>
                </div>
            </div>
        </div>
    );
}
