import React from 'react';
import { Link } from 'react-router-dom';
import StatusChip from '../../faq/StatusChip';

export default function Articles({ articles = [] }) {
    return (
        <section className="crm-surface space-y-4 px-5 py-5">
            <div>
                <p className="text-sm font-semibold text-slate-900">Articles</p>
                <p className="text-sm text-slate-500">Open any article in the FAQ route for inline authoring.</p>
            </div>
            <div className="space-y-3">
                {articles.map((article) => (
                    <Link key={article.id} to={`/faq/a/${article.slug}`} className="flex flex-wrap items-center justify-between gap-3 rounded-2xl border border-slate-200 px-4 py-3 transition hover:border-teal-200 hover:bg-teal-50/50">
                        <div>
                            <p className="text-sm font-semibold text-slate-900">{article.title}</p>
                            <p className="text-sm text-slate-500">{article.summary}</p>
                        </div>
                        <StatusChip status={article.status} />
                    </Link>
                ))}
            </div>
        </section>
    );
}
