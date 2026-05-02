import React, { useDeferredValue, useMemo, useState } from 'react';
import { useQuery } from '@tanstack/react-query';
import { Link, useLocation } from 'react-router-dom';
import faqApi from '../../services/faqApi';
import { FaqIconBubble, FaqWorkflowPill, resolveFaqArticleVisual, resolveFaqCategoryVisual } from './faqVisuals';
import FaqFlyoutPanel from './FaqFlyoutPanel';

function resolveCrmPage(pathname) {
    if (pathname.startsWith('/team')) return 'team';
    if (pathname.startsWith('/clients/') && pathname !== '/clients') return 'client_detail';
    if (pathname.startsWith('/clients')) return 'clients';
    if (pathname.startsWith('/payments')) return 'payments';
    if (pathname.startsWith('/deals')) return 'deals';
    if (pathname.startsWith('/campaigns')) return 'campaigns';
    if (pathname.startsWith('/conversations')) return 'conversations';
    if (pathname.startsWith('/leads')) return 'leads';
    return 'dashboard';
}

function crmPageLabel(crmPage) {
    switch (crmPage) {
        case 'team':
            return 'Team';
        case 'client_detail':
            return 'Client detail';
        case 'clients':
            return 'Clients';
        case 'payments':
            return 'Payments';
        case 'leads':
            return 'Leads';
        case 'dashboard':
        default:
            return 'Dashboard';
    }
}

export default function HelpButton() {
    const [open, setOpen] = useState(false);
    const [search, setSearch] = useState('');
    const location = useLocation();
    const crmPage = resolveCrmPage(location.pathname);
    const deferredSearch = useDeferredValue(search.trim());
    const query = useQuery({
        queryKey: ['faq-help-drawer', crmPage, deferredSearch],
        queryFn: () => faqApi.listArticles({ crm_page: crmPage, per_page: 8, search: deferredSearch || undefined }),
        enabled: open,
    });
    const results = useMemo(() => query.data?.articles || [], [query.data]);
    const total = query.data?.pagination?.total || results.length;
    const visibleResults = results.slice(0, 5);

    return (
        <>
            <button
                type="button"
                onClick={() => setOpen(true)}
                className="inline-flex items-center gap-2 rounded-lg border border-slate-200 bg-white px-3 py-2 text-sm font-medium text-slate-700 shadow-sm transition hover:border-slate-300 hover:bg-slate-50 hover:text-slate-900"
            >
                <svg className="h-4 w-4 text-slate-500" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
                    <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={1.9} d="M12 17.25v-.01M9.75 9a2.25 2.25 0 1 1 3.89 1.55c-.6.62-1.39 1.17-1.39 2.2v.25" />
                    <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={1.9} d="M21 12a9 9 0 1 1-9-9 9 9 0 0 1 9 9Z" />
                </svg>
                Help
            </button>
            <FaqFlyoutPanel
                open={open}
                onClose={() => setOpen(false)}
                title="Contextual help"
                subtitle={`Search the runbooks mapped to ${crmPageLabel(crmPage)} without leaving the workflow.`}
                widthClassName="max-w-xl"
                footer={(
                    <div className="flex flex-wrap items-center justify-between gap-3">
                        <p className="text-sm text-slate-500">
                            {total ? `${total} article${total === 1 ? '' : 's'} available for this screen.` : 'No mapped articles yet.'}
                        </p>
                        <Link to={`/faq?crm_page=${crmPage}`} onClick={() => setOpen(false)} className="crm-btn-secondary inline-flex rounded-lg px-3 py-2 text-sm">
                            Open Knowledge Center
                        </Link>
                    </div>
                )}
            >
                <div className="space-y-4">
                    <div className="grid gap-3">
                        <label className="block">
                            <span className="mb-2 block text-xs font-semibold uppercase tracking-[0.16em] text-slate-500">Search contextual FAQ</span>
                            <span className="flex items-center gap-2 rounded-xl border border-slate-200 bg-white px-3 py-2.5 shadow-sm transition focus-within:border-teal-400 focus-within:ring-2 focus-within:ring-teal-100">
                                <svg className="h-4 w-4 shrink-0 text-slate-400" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
                                    <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={1.8} d="m21 21-4.35-4.35M10.5 18a7.5 7.5 0 1 1 0-15 7.5 7.5 0 0 1 0 15Z" />
                                </svg>
                                <input
                                    type="search"
                                    value={search}
                                    onChange={(event) => setSearch(event.target.value)}
                                    placeholder={`Search ${crmPageLabel(crmPage).toLowerCase()} workflows`}
                                    className="w-full border-0 bg-transparent p-0 text-sm text-slate-700 placeholder:text-slate-400 focus:outline-none focus:ring-0"
                                />
                                {search ? (
                                    <button
                                        type="button"
                                        onClick={() => setSearch('')}
                                        className="inline-flex h-7 w-7 items-center justify-center rounded-md text-slate-400 transition hover:bg-slate-100 hover:text-slate-600"
                                        aria-label="Clear search"
                                    >
                                        <svg className="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
                                            <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="m6 6 12 12M18 6 6 18" />
                                        </svg>
                                    </button>
                                ) : null}
                            </span>
                        </label>

                        <div className="rounded-xl border border-slate-200 bg-slate-50/80 px-4 py-4">
                            <p className="text-xs font-semibold uppercase tracking-[0.16em] text-slate-500">Current screen</p>
                            <div className="mt-2 flex items-start justify-between gap-3">
                                <div>
                                    <p className="text-sm font-semibold text-slate-900">{crmPageLabel(crmPage)}</p>
                                    <p className="mt-1 text-sm leading-6 text-slate-500">Use these notes for the next safe action, not a general product tour.</p>
                                </div>
                                <span className="rounded-lg border border-slate-200 bg-white px-2.5 py-1 text-[11px] font-semibold uppercase tracking-[0.12em] text-slate-500">
                                    {total} mapped
                                </span>
                            </div>
                        </div>
                    </div>
                    {query.isLoading ? <p className="text-sm text-slate-500">Loading help articles...</p> : null}
                    {!query.isLoading && !results.length ? (
                        <div className="rounded-xl border border-dashed border-slate-200 bg-slate-50/70 px-4 py-4 text-sm text-slate-500">
                            {deferredSearch
                                ? `No contextual matches for “${deferredSearch}”. Try a broader term or open the full knowledge center.`
                                : 'No mapped articles yet for this screen.'}
                        </div>
                    ) : null}
                    {visibleResults.map((article) => (
                        <Link
                            key={article.id}
                            to={`/faq/a/${article.slug}`}
                            onClick={() => setOpen(false)}
                            className="block rounded-xl border border-slate-200 px-4 py-4 transition hover:border-teal-200 hover:bg-teal-50/50"
                        >
                            <div className="flex items-start gap-3">
                                <FaqIconBubble visual={resolveFaqArticleVisual(article) || resolveFaqCategoryVisual(article.category)} className="mt-0.5 h-10 w-10 rounded-xl" />
                                <div>
                                    <FaqWorkflowPill visual={resolveFaqArticleVisual(article) || resolveFaqCategoryVisual(article.category)} />
                                    <p className="mt-2 text-sm font-semibold text-slate-900">{article.title}</p>
                                    <p className="mt-1 text-sm leading-6 text-slate-500">{article.summary}</p>
                                </div>
                            </div>
                        </Link>
                    ))}
                    {results.length > visibleResults.length ? (
                        <p className="text-sm text-slate-500">Showing the top {visibleResults.length}. Open the knowledge center for the full list.</p>
                    ) : null}
                </div>
            </FaqFlyoutPanel>
        </>
    );
}
