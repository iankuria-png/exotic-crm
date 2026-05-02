import React, { useMemo, useState } from 'react';
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
    const location = useLocation();
    const crmPage = resolveCrmPage(location.pathname);
    const query = useQuery({
        queryKey: ['faq-help-drawer', crmPage],
        queryFn: () => faqApi.listArticles({ crm_page: crmPage, per_page: 6 }),
        enabled: open,
    });
    const results = useMemo(() => query.data?.articles || [], [query.data]);
    const total = query.data?.pagination?.total || results.length;
    const visibleResults = results.slice(0, 4);

    return (
        <>
            <button
                type="button"
                onClick={() => setOpen(true)}
                className="rounded-xl border border-slate-200 bg-white px-3 py-2 text-sm font-medium text-slate-700 shadow-sm transition hover:border-teal-200 hover:text-teal-700"
            >
                ?
            </button>
            <FaqFlyoutPanel
                open={open}
                onClose={() => setOpen(false)}
                title="Contextual help"
                subtitle={`Focused guidance for the ${crmPageLabel(crmPage)} screen without leaving the workflow.`}
                widthClassName="max-w-xl"
                footer={(
                    <div className="flex flex-wrap items-center justify-between gap-3">
                        <p className="text-sm text-slate-500">
                            {total ? `${total} article${total === 1 ? '' : 's'} mapped to this screen.` : 'No mapped articles yet.'}
                        </p>
                        <Link to={`/faq?crm_page=${crmPage}`} onClick={() => setOpen(false)} className="crm-btn-secondary inline-flex px-3 py-2 text-sm">
                            Open Knowledge Center
                        </Link>
                    </div>
                )}
            >
                <div className="space-y-4">
                    <div className="rounded-2xl border border-slate-200 bg-slate-50/80 px-4 py-4">
                        <p className="text-xs font-semibold uppercase tracking-[0.16em] text-slate-500">Current screen</p>
                        <p className="mt-2 text-sm font-semibold text-slate-900">{crmPageLabel(crmPage)}</p>
                        <p className="mt-1 text-sm leading-6 text-slate-500">Use these articles when you need the next safe action, not a general product tour.</p>
                    </div>
                    {query.isLoading ? <p className="text-sm text-slate-500">Loading help articles...</p> : null}
                    {!query.isLoading && !results.length ? <p className="text-sm text-slate-500">No mapped articles yet for this screen.</p> : null}
                    {visibleResults.map((article) => (
                        <Link
                            key={article.id}
                            to={`/faq/a/${article.slug}`}
                            onClick={() => setOpen(false)}
                            className="block rounded-2xl border border-slate-200 px-4 py-4 transition hover:border-teal-200 hover:bg-teal-50/50"
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
