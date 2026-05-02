import React, { useMemo, useState } from 'react';
import { useQuery } from '@tanstack/react-query';
import { Link, useLocation } from 'react-router-dom';
import faqApi from '../../services/faqApi';

function resolveCrmPage(pathname) {
    if (pathname.startsWith('/clients/') && pathname !== '/clients') return 'client_detail';
    if (pathname.startsWith('/clients')) return 'clients';
    if (pathname.startsWith('/payments')) return 'payments';
    if (pathname.startsWith('/deals')) return 'deals';
    if (pathname.startsWith('/campaigns')) return 'campaigns';
    if (pathname.startsWith('/conversations')) return 'conversations';
    if (pathname.startsWith('/leads')) return 'leads';
    return 'dashboard';
}

export default function HelpButton() {
    const [open, setOpen] = useState(false);
    const location = useLocation();
    const crmPage = resolveCrmPage(location.pathname);
    const query = useQuery({
        queryKey: ['faq-help-drawer', crmPage],
        queryFn: () => faqApi.listArticles({ crm_page: crmPage, per_page: 8 }),
        enabled: open,
    });
    const results = useMemo(() => query.data?.articles || [], [query.data]);

    return (
        <>
            <button
                type="button"
                onClick={() => setOpen(true)}
                className="rounded-xl border border-slate-200 bg-white px-3 py-2 text-sm font-medium text-slate-700 shadow-sm transition hover:border-teal-200 hover:text-teal-700"
            >
                ?
            </button>
            {open ? (
                <div className="fixed inset-0 z-[110] bg-slate-950/35" onClick={() => setOpen(false)}>
                    <aside className="ml-auto flex h-full w-full max-w-md flex-col border-l border-slate-200 bg-white shadow-xl" onClick={(event) => event.stopPropagation()}>
                        <div className="border-b border-slate-100 px-5 py-4">
                            <h3 className="text-lg font-semibold text-slate-900">Contextual help</h3>
                            <p className="text-sm text-slate-500">Articles mapped to this CRM screen.</p>
                        </div>
                        <div className="space-y-3 px-5 py-5">
                            <Link to={`/faq?crm_page=${crmPage}`} onClick={() => setOpen(false)} className="crm-btn-secondary inline-flex px-3 py-2 text-sm">
                                Open Knowledge Center
                            </Link>
                            {query.isLoading ? <p className="text-sm text-slate-500">Loading help articles...</p> : null}
                            {!query.isLoading && !results.length ? <p className="text-sm text-slate-500">No mapped articles yet for this screen.</p> : null}
                            {results.map((article) => (
                                <Link
                                    key={article.id}
                                    to={`/faq/a/${article.slug}`}
                                    onClick={() => setOpen(false)}
                                    className="block rounded-2xl border border-slate-200 px-4 py-4 transition hover:border-teal-200 hover:bg-teal-50/50"
                                >
                                    <p className="text-sm font-semibold text-slate-900">{article.title}</p>
                                    <p className="mt-1 text-sm text-slate-500">{article.summary}</p>
                                </Link>
                            ))}
                        </div>
                    </aside>
                </div>
            ) : null}
        </>
    );
}
