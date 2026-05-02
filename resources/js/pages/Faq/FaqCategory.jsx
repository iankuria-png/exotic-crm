import React, { useState } from 'react';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { Link, useNavigate, useParams } from 'react-router-dom';
import PageHeader from '../../components/PageHeader';
import faqApi from '../../services/faqApi';
import useFaqAdmin from '../../hooks/useFaqAdmin';
import NewArticleSlideOver from '../../components/faq/NewArticleSlideOver';
import StatusChip from '../../components/faq/StatusChip';
import { FaqIconBubble, FaqWorkflowPill, resolveFaqArticleVisual, resolveFaqCategoryVisual } from '../../components/faq/faqVisuals';
import { useToast } from '../../components/ToastProvider';

export default function FaqCategory() {
    const { slug } = useParams();
    const admin = useFaqAdmin();
    const queryClient = useQueryClient();
    const navigate = useNavigate();
    const toast = useToast();
    const [newArticleOpen, setNewArticleOpen] = useState(false);

    const categoriesQuery = useQuery({
        queryKey: ['faq-categories', 'include-articles'],
        queryFn: () => faqApi.listCategories({ include_articles: 1 }),
    });
    const articlesQuery = useQuery({
        queryKey: ['faq-articles', { categorySlug: slug }],
        queryFn: () => faqApi.listArticles({ category_slug: slug, per_page: 50 }),
    });

    const categories = categoriesQuery.data?.categories || [];
    const category = categories.find((item) => item.slug === slug);
    const articles = articlesQuery.data?.articles || [];

    const createArticleMutation = useMutation({
        mutationFn: faqApi.createArticle,
        onSuccess: ({ article }) => {
            queryClient.invalidateQueries({ queryKey: ['faq-articles'] });
            queryClient.invalidateQueries({ queryKey: ['faq-categories'] });
            toast.success('Draft article created.');
            navigate(`/faq/a/${article.slug}`);
        },
        onError: () => toast.error('Unable to create article.'),
    });

    return (
        <div className="space-y-4">
            <div>
                <Link to="/faq" className="inline-flex items-center gap-2 text-sm font-medium text-teal-700 transition hover:text-teal-800">
                    <svg className="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
                        <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M15 19l-7-7 7-7" />
                    </svg>
                    Back to Knowledge Center
                </Link>
            </div>
            <PageHeader
                title={category?.name || 'Category'}
                subtitle={category?.description || 'Browse published knowledge base articles for this workflow area.'}
                actions={admin.canEdit ? (
                    <button type="button" onClick={() => setNewArticleOpen(true)} className="crm-btn-primary px-3 py-2 text-sm">New article in category</button>
                ) : null}
            />

            <section className="crm-surface px-5 py-5">
                {category ? (
                    <div className="mb-5 flex items-start gap-3 rounded-2xl border border-slate-200 bg-slate-50/70 px-4 py-4">
                        <FaqIconBubble visual={resolveFaqCategoryVisual(category)} className="h-12 w-12" />
                        <div>
                            <FaqWorkflowPill visual={resolveFaqCategoryVisual(category)} />
                            <p className="mt-2 text-sm leading-6 text-slate-600">{category.description}</p>
                        </div>
                    </div>
                ) : null}
                <div className="space-y-3">
                    {articles.map((article) => (
                        <Link key={article.id} to={`/faq/a/${article.slug}`} className="block rounded-2xl border border-slate-200 px-4 py-4 transition hover:border-teal-200 hover:bg-teal-50/50">
                            <div className="flex items-start justify-between gap-3">
                                <div className="flex items-start gap-3">
                                    <FaqIconBubble visual={resolveFaqArticleVisual(article) || resolveFaqCategoryVisual(category)} className="mt-0.5 h-10 w-10 rounded-xl" />
                                    <div>
                                        <FaqWorkflowPill visual={resolveFaqArticleVisual(article) || resolveFaqCategoryVisual(category)} />
                                        <p className="mt-2 text-sm font-semibold text-slate-900">{article.title}</p>
                                        <p className="mt-1 max-w-3xl text-sm leading-6 text-slate-500">{article.summary}</p>
                                    </div>
                                </div>
                                {admin.isAdmin ? <StatusChip status={article.status} /> : null}
                            </div>
                        </Link>
                    ))}
                    {!articlesQuery.isLoading && !articles.length ? <p className="text-sm text-slate-500">No articles are published in this category yet.</p> : null}
                </div>
            </section>

            <NewArticleSlideOver
                open={newArticleOpen}
                initialCategoryId={category?.id}
                categories={categories}
                onClose={() => setNewArticleOpen(false)}
                onSubmit={(payload) => createArticleMutation.mutate(payload)}
            />
        </div>
    );
}
