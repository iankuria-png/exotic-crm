import React, { useMemo, useState } from 'react';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { Link, useNavigate, useSearchParams } from 'react-router-dom';
import PageHeader from '../../components/PageHeader';
import faqApi from '../../services/faqApi';
import useFaqAdmin from '../../hooks/useFaqAdmin';
import InlineCategoryManager from '../../components/faq/InlineCategoryManager';
import NewArticleSlideOver from '../../components/faq/NewArticleSlideOver';
import StatusChip from '../../components/faq/StatusChip';
import { FaqIconBubble, FaqWorkflowPill, resolveFaqArticleVisual, resolveFaqCategoryVisual } from '../../components/faq/faqVisuals';
import { useToast } from '../../components/ToastProvider';

const FEATURED_ARTICLE_SLUGS = [
    'adding-a-client-crm-only-vs-wordpress-provision',
    'search-visibility-and-market-sync',
    'client-access-setup-links-passwords-and-login-as-client',
    'sending-a-payment-link-from-client-detail',
    'activating-a-subscription-after-payment-review',
    'payment-diagnostics-failed-transactions-provider-status-and-next-actions',
    'profile-health-and-what-it-surfaces',
    'client-analytics-discovery-engagement-and-contact-intent',
    'client-filters-online-chat-verified-risk-and-behavior',
];

export default function FaqHome() {
    const [searchParams, setSearchParams] = useSearchParams();
    const [searchDraft, setSearchDraft] = useState(searchParams.get('search') || '');
    const [manageCategories, setManageCategories] = useState(false);
    const [newArticleOpen, setNewArticleOpen] = useState(false);
    const admin = useFaqAdmin();
    const navigate = useNavigate();
    const queryClient = useQueryClient();
    const toast = useToast();
    const crmPage = searchParams.get('crm_page') || '';

    const categoriesQuery = useQuery({
        queryKey: ['faq-categories', { includeArticles: true }],
        queryFn: () => faqApi.listCategories({ include_articles: 1 }),
    });
    const articlesQuery = useQuery({
        queryKey: ['faq-articles', { search: searchParams.get('search') || '', crmPage }],
        queryFn: () => faqApi.listArticles({ search: searchParams.get('search') || '', crm_page: crmPage || undefined, per_page: 24 }),
    });

    const categories = categoriesQuery.data?.categories || [];
    const articles = articlesQuery.data?.articles || [];
    const highlightedArticles = useMemo(() => {
        const list = [...articles];
        const search = searchParams.get('search') || '';

        if (!search && !crmPage) {
            list.sort((left, right) => {
                const leftIndex = FEATURED_ARTICLE_SLUGS.indexOf(left.slug);
                const rightIndex = FEATURED_ARTICLE_SLUGS.indexOf(right.slug);
                const safeLeft = leftIndex === -1 ? FEATURED_ARTICLE_SLUGS.length + 1 : leftIndex;
                const safeRight = rightIndex === -1 ? FEATURED_ARTICLE_SLUGS.length + 1 : rightIndex;

                if (safeLeft !== safeRight) {
                    return safeLeft - safeRight;
                }

                return String(left.title || '').localeCompare(String(right.title || ''));
            });
        }

        return list.slice(0, 8);
    }, [articles, crmPage, searchParams]);

    const createCategoryMutation = useMutation({
        mutationFn: faqApi.createCategory,
        onSuccess: () => {
            queryClient.invalidateQueries({ queryKey: ['faq-categories'] });
            toast.success('Category created.');
        },
        onError: () => toast.error('Unable to create category.'),
    });
    const reorderCategoriesMutation = useMutation({
        mutationFn: faqApi.reorderCategories,
        onSuccess: () => queryClient.invalidateQueries({ queryKey: ['faq-categories'] }),
    });
    const deleteCategoryMutation = useMutation({
        mutationFn: faqApi.deleteCategory,
        onSuccess: () => {
            queryClient.invalidateQueries({ queryKey: ['faq-categories'] });
            toast.success('Category deleted.');
        },
        onError: () => toast.error('Unable to delete category.'),
    });
    const createArticleMutation = useMutation({
        mutationFn: faqApi.createArticle,
        onSuccess: ({ article }) => {
            queryClient.invalidateQueries({ queryKey: ['faq-articles'] });
            queryClient.invalidateQueries({ queryKey: ['faq-categories'] });
            setNewArticleOpen(false);
            toast.success('Draft article created.');
            navigate(`/faq/a/${article.slug}`);
        },
        onError: () => toast.error('Unable to create article.'),
    });

    return (
        <div className="space-y-4">
            <PageHeader
                title="Knowledge Center"
                subtitle="Operational guides for profile onboarding, access, subscriptions, payments, and market discipline."
                actions={(
                    <>
                        <form
                            onSubmit={(event) => {
                                event.preventDefault();
                                const next = new URLSearchParams(searchParams);
                                if (searchDraft) {
                                    next.set('search', searchDraft);
                                } else {
                                    next.delete('search');
                                }
                                setSearchParams(next);
                            }}
                            className="flex items-center gap-2"
                        >
                            <input value={searchDraft} onChange={(event) => setSearchDraft(event.target.value)} placeholder="Search FAQ" className="w-64 rounded-xl border border-slate-200 px-3 py-2 text-sm" />
                            <button type="submit" className="crm-btn-secondary px-3 py-2 text-sm">Search</button>
                        </form>
                        {admin.canEdit ? <button type="button" onClick={() => setNewArticleOpen(true)} className="crm-btn-primary px-3 py-2 text-sm">New article</button> : null}
                        {admin.canEdit ? <button type="button" onClick={() => setManageCategories((current) => !current)} className="crm-btn-secondary px-3 py-2 text-sm">{manageCategories ? 'Hide categories' : 'Edit categories'}</button> : null}
                    </>
                )}
            />

            <section className="grid gap-4 xl:grid-cols-[minmax(0,0.9fr)_minmax(0,1.1fr)]">
                <div className="space-y-4">
                    <section className="crm-surface px-5 py-5">
                        <div className="mb-4 flex items-center justify-between gap-3">
                            <div>
                                <p className="text-sm font-semibold text-slate-900">Browse by workflow area</p>
                                <p className="text-sm text-slate-500">Start with the screen or queue you are already working in.</p>
                            </div>
                        </div>
                        <div className="space-y-3">
                            {categories.map((category) => (
                                <Link key={category.id} to={`/faq/c/${category.slug}`} className="block rounded-2xl border border-slate-200 px-4 py-4 transition hover:border-teal-200 hover:bg-teal-50/50">
                                    <div className="flex items-start justify-between gap-3">
                                        <div className="flex items-start gap-3">
                                            <FaqIconBubble visual={resolveFaqCategoryVisual(category)} className="mt-0.5 h-12 w-12" />
                                            <div>
                                                <p className="text-sm font-semibold text-slate-900">{category.name}</p>
                                                <FaqWorkflowPill visual={resolveFaqCategoryVisual(category)} />
                                                <p className="mt-2 text-sm text-slate-500">{category.description}</p>
                                            </div>
                                        </div>
                                        <div className="text-right text-xs text-slate-500">
                                            <p>{category.published_articles_count} published</p>
                                            {admin.isAdmin ? <p>{category.draft_articles_count} drafts</p> : null}
                                        </div>
                                    </div>
                                </Link>
                            ))}
                        </div>
                    </section>
                    <InlineCategoryManager
                        open={manageCategories}
                        categories={categories}
                        onCreate={(payload) => createCategoryMutation.mutate(payload)}
                        onDelete={(slug) => deleteCategoryMutation.mutate(slug)}
                        onReorder={(ids) => reorderCategoriesMutation.mutate(ids)}
                    />
                </div>

                <section className="space-y-4">
                    <section className="crm-surface px-5 py-5">
                        <div className="mb-4 flex items-center justify-between gap-3">
                            <div>
                                <p className="text-sm font-semibold text-slate-900">{searchParams.get('search') ? 'Search results' : 'Priority workflows'}</p>
                                <p className="text-sm text-slate-500">{searchParams.get('search') ? `Articles matching "${searchParams.get('search')}"` : 'Start with the workflows agents touch most often in production.'}</p>
                            </div>
                            {crmPage ? <StatusChip status={crmPage} /> : null}
                        </div>
                        <div className="space-y-3">
                            {highlightedArticles.map((article) => (
                                <Link key={article.id} to={`/faq/a/${article.slug}${articlesQuery.data?.search_log_id ? `?search_log_id=${articlesQuery.data.search_log_id}` : ''}`} className="block rounded-2xl border border-slate-200 px-4 py-4 transition hover:border-teal-200 hover:bg-teal-50/50">
                                    <div className="flex items-start justify-between gap-3">
                                        <div className="flex items-start gap-3">
                                            <FaqIconBubble visual={resolveFaqArticleVisual(article) || resolveFaqCategoryVisual(article.category)} className="mt-0.5 h-10 w-10 rounded-xl" />
                                            <div>
                                                <FaqWorkflowPill visual={resolveFaqArticleVisual(article) || resolveFaqCategoryVisual(article.category)} />
                                                <p className="mt-2 text-sm font-semibold text-slate-900">{article.title}</p>
                                                <p className="mt-1 text-sm leading-6 text-slate-500">{article.summary}</p>
                                            </div>
                                        </div>
                                        {admin.isAdmin ? <StatusChip status={article.status} /> : null}
                                    </div>
                                </Link>
                            ))}
                            {!articlesQuery.isLoading && !highlightedArticles.length ? <p className="text-sm text-slate-500">No articles match the current search.</p> : null}
                        </div>
                    </section>
                </section>
            </section>

            <NewArticleSlideOver
                open={newArticleOpen}
                categories={categories}
                onClose={() => setNewArticleOpen(false)}
                onSubmit={(payload) => createArticleMutation.mutate(payload)}
            />
        </div>
    );
}
