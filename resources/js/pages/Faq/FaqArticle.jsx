import React, { useState } from 'react';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { Link, useNavigate, useParams, useSearchParams } from 'react-router-dom';
import PageHeader from '../../components/PageHeader';
import faqApi from '../../services/faqApi';
import MarkdownRenderer from '../../components/faq/MarkdownRenderer';
import HelpfulWidget from '../../components/faq/HelpfulWidget';
import ArticleCtaButton from '../../components/faq/ArticleCtaButton';
import FeedbackDialog from '../../components/faq/FeedbackDialog';
import AdminEditBar from '../../components/faq/AdminEditBar';
import InlineTiptapEditor from '../../components/faq/InlineTiptapEditor';
import CtaManagerDialog from '../../components/faq/CtaManagerDialog';
import MediaManagerDialog from '../../components/faq/MediaManagerDialog';
import WalkthroughRecorder from '../../components/faq/WalkthroughRecorder';
import StatusChip from '../../components/faq/StatusChip';
import FaqMediaLightbox from '../../components/faq/FaqMediaLightbox';
import { FaqWorkflowPill, resolveFaqArticleVisual, resolveFaqCategoryVisual } from '../../components/faq/faqVisuals';
import useFaqAdmin from '../../hooks/useFaqAdmin';
import { useToast } from '../../components/ToastProvider';

export default function FaqArticle() {
    const { slug } = useParams();
    const [searchParams] = useSearchParams();
    const [feedbackOpen, setFeedbackOpen] = useState(false);
    const [editorOpen, setEditorOpen] = useState(false);
    const [ctaOpen, setCtaOpen] = useState(false);
    const [mediaOpen, setMediaOpen] = useState(false);
    const [walkthroughOpen, setWalkthroughOpen] = useState(false);
    const [lightboxIndex, setLightboxIndex] = useState(0);
    const [lightboxItems, setLightboxItems] = useState([]);
    const [lightboxOpen, setLightboxOpen] = useState(false);
    const admin = useFaqAdmin();
    const queryClient = useQueryClient();
    const toast = useToast();
    const navigate = useNavigate();

    const articleQuery = useQuery({
        queryKey: ['faq-article', slug, searchParams.get('search_log_id') || ''],
        queryFn: () => faqApi.getArticle(slug, { search_log_id: searchParams.get('search_log_id') || undefined }),
    });
    const walkthroughsQuery = useQuery({
        queryKey: ['faq-walkthroughs'],
        queryFn: () => faqApi.listWalkthroughs(),
        enabled: admin.isAdmin,
    });

    const article = articleQuery.data?.article;
    const articleSlug = article?.slug || slug;
    const articleMediaItems = (article?.media || []).map((media) => ({
        kind: media.kind,
        url: media.url,
        caption: media.caption,
        mime: media.mime,
    }));

    const openLightbox = (items, startIndex = 0) => {
        const usableItems = (items || []).filter((item) => item?.url);
        if (!usableItems.length) {
            return;
        }

        setLightboxItems(usableItems);
        setLightboxIndex(startIndex);
        setLightboxOpen(true);
    };

    const updateMutation = useMutation({
        mutationFn: (payload) => faqApi.updateArticle(articleSlug, payload),
        onSuccess: () => {
            queryClient.invalidateQueries({ queryKey: ['faq-article', slug] });
            queryClient.invalidateQueries({ queryKey: ['faq-articles'] });
            toast.success('Article updated.');
        },
        onError: () => toast.error('Unable to update article.'),
    });
    const draftMutation = useMutation({
        mutationFn: (payload) => faqApi.saveDraft(articleSlug, payload),
        onSuccess: () => {
            queryClient.invalidateQueries({ queryKey: ['faq-article', slug] });
            queryClient.invalidateQueries({ queryKey: ['faq-articles'] });
            toast.success('Draft saved.');
        },
        onError: () => toast.error('Unable to save draft.'),
    });
    const publishMutation = useMutation({
        mutationFn: async (payload) => {
            if (payload) {
                await faqApi.saveDraft(articleSlug, payload);
            }
            return faqApi.publishArticle(articleSlug);
        },
        onSuccess: () => {
            queryClient.invalidateQueries({ queryKey: ['faq-article', slug] });
            queryClient.invalidateQueries({ queryKey: ['faq-articles'] });
            setEditorOpen(false);
            toast.success('Article published.');
        },
        onError: () => toast.error('Unable to publish article.'),
    });
    const deleteMutation = useMutation({
        mutationFn: () => faqApi.deleteArticle(articleSlug),
        onSuccess: () => {
            queryClient.invalidateQueries({ queryKey: ['faq-articles'] });
            toast.success('Article deleted.');
            navigate('/faq');
        },
        onError: () => toast.error('Unable to delete article.'),
    });
    const duplicateMutation = useMutation({
        mutationFn: () => faqApi.duplicateArticle(articleSlug),
        onSuccess: ({ article: copy }) => {
            toast.success('Article duplicated.');
            navigate(`/faq/a/${copy.slug}`);
        },
        onError: () => toast.error('Unable to duplicate article.'),
    });
    const uploadMediaMutation = useMutation({
        mutationFn: ({ file, caption }) => {
            const formData = new FormData();
            formData.append('file', file);
            if (caption) formData.append('caption', caption);
            return faqApi.uploadMedia(articleSlug, formData);
        },
        onSuccess: () => {
            queryClient.invalidateQueries({ queryKey: ['faq-article', slug] });
            toast.success('Media uploaded.');
        },
        onError: () => toast.error('Unable to upload media.'),
    });
    const deleteMediaMutation = useMutation({
        mutationFn: (mediaId) => faqApi.deleteMedia(articleSlug, mediaId),
        onSuccess: () => {
            queryClient.invalidateQueries({ queryKey: ['faq-article', slug] });
            toast.success('Media deleted.');
        },
        onError: () => toast.error('Unable to delete media.'),
    });
    const createWalkthroughMutation = useMutation({
        mutationFn: faqApi.createWalkthrough,
        onSuccess: async ({ walkthrough }) => {
            const nextCtas = [
                ...(article?.ctas || []),
                {
                    kind: 'walkthrough',
                    label: 'Start walkthrough',
                    target_path: article?.ctas?.[0]?.target_path || '/faq',
                    walkthrough_id: walkthrough.slug,
                },
            ];
            await faqApi.updateArticle(articleSlug, { ctas: nextCtas });
            queryClient.invalidateQueries({ queryKey: ['faq-article', slug] });
            queryClient.invalidateQueries({ queryKey: ['faq-walkthroughs'] });
            setWalkthroughOpen(false);
            toast.success('Walkthrough created and attached.');
        },
        onError: () => toast.error('Unable to create walkthrough.'),
    });

    return (
        <div className="space-y-4">
            <div className="flex flex-wrap items-center gap-3 text-sm">
                <Link to="/faq" className="inline-flex items-center gap-2 font-medium text-teal-700 transition hover:text-teal-800">
                    <svg className="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
                        <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M15 19l-7-7 7-7" />
                    </svg>
                    Back to Knowledge Center
                </Link>
                {article?.category?.slug ? (
                    <Link to={`/faq/c/${article.category.slug}`} className="inline-flex items-center gap-2 font-medium text-slate-500 transition hover:text-slate-700">
                        <span className="text-slate-300">/</span>
                        Back to {article.category.name}
                    </Link>
                ) : null}
            </div>
            <PageHeader
                title={article?.title || 'Article'}
                subtitle={article?.summary || 'Knowledge base article'}
                actions={article?.category ? <StatusChip status={article.category.crm_page || article.status} /> : null}
            />

            {article?.category ? (
                <div className="flex flex-wrap items-center gap-2">
                    <FaqWorkflowPill visual={resolveFaqCategoryVisual(article.category)} />
                    <FaqWorkflowPill visual={resolveFaqArticleVisual(article)} />
                </div>
            ) : null}

            {admin.canEdit && article ? (
                <AdminEditBar
                    article={article}
                    onEdit={() => setEditorOpen(true)}
                    onOpenCtas={() => setCtaOpen(true)}
                    onOpenMedia={() => setMediaOpen(true)}
                    onOpenWalkthroughs={() => setWalkthroughOpen(true)}
                    onPublish={() => publishMutation.mutate()}
                    onDuplicate={() => duplicateMutation.mutate()}
                    onDelete={() => {
                        if (window.confirm('Delete this FAQ article?')) {
                            deleteMutation.mutate();
                        }
                    }}
                />
            ) : null}

            <InlineTiptapEditor
                article={article}
                open={editorOpen}
                onCancel={() => setEditorOpen(false)}
                onSaveDraft={(payload) => draftMutation.mutate(payload)}
                onPublish={(payload) => publishMutation.mutate(payload)}
            />

            <section className="grid gap-4 xl:grid-cols-[minmax(0,1.05fr)_minmax(0,0.95fr)]">
                <article className="crm-surface space-y-6 px-6 py-6 lg:px-8">
                    {article?.media?.length ? (
                        <div className="grid gap-3 md:grid-cols-2">
                            {article.media.map((media, mediaIndex) => (
                                media.kind === 'video'
                                    ? (
                                        <div key={media.id} className="space-y-2">
                                            <video src={media.url} controls className="w-full rounded-2xl border border-slate-200 bg-black" />
                                            <button
                                                type="button"
                                                onClick={() => openLightbox(articleMediaItems, mediaIndex)}
                                                className="inline-flex items-center gap-2 text-sm font-medium text-teal-700 transition hover:text-teal-800"
                                            >
                                                Open larger preview
                                            </button>
                                        </div>
                                    )
                                    : (
                                        <button
                                            key={media.id}
                                            type="button"
                                            onClick={() => openLightbox(articleMediaItems, mediaIndex)}
                                            className="block cursor-zoom-in text-left"
                                        >
                                            <img src={media.url} alt={media.caption || ''} className="w-full rounded-2xl border border-slate-200 object-cover transition hover:shadow-md" />
                                        </button>
                                    )
                            ))}
                        </div>
                    ) : null}
                    <div className="border-b border-slate-100 pb-4">
                        <p className="text-xs font-semibold uppercase tracking-[0.16em] text-slate-400">
                            Operator Guide
                        </p>
                    </div>
                    <MarkdownRenderer onMediaOpen={openLightbox}>{article?.body}</MarkdownRenderer>
                </article>

                <aside className="space-y-4">
                    <section className="crm-surface space-y-4 px-5 py-5">
                        <div>
                            <p className="text-sm font-semibold text-slate-900">Next steps</p>
                            <p className="text-sm text-slate-500">Jump to the exact CRM screen or guided flow tied to this article.</p>
                        </div>
                        <div className="flex flex-wrap gap-2">
                            {(article?.ctas || []).map((cta) => <ArticleCtaButton key={cta.id} cta={cta} />)}
                        </div>
                    </section>
                    <HelpfulWidget article={article} />
                    <section className="crm-surface space-y-3 px-5 py-5">
                        <div>
                            <p className="text-sm font-semibold text-slate-900">Report a gap</p>
                            <p className="text-sm text-slate-500">If this missed a real production scenario, flag it before the context disappears.</p>
                        </div>
                        <button type="button" onClick={() => setFeedbackOpen(true)} className="crm-btn-secondary px-3 py-2 text-sm">Suggest edit or report bug</button>
                    </section>
                </aside>
            </section>

            <FeedbackDialog open={feedbackOpen} onClose={() => setFeedbackOpen(false)} article={article} />
            <CtaManagerDialog
                open={ctaOpen}
                article={article}
                walkthroughs={walkthroughsQuery.data?.walkthroughs || []}
                onClose={() => setCtaOpen(false)}
                onSave={(ctas) => {
                    updateMutation.mutate({ ctas });
                    setCtaOpen(false);
                }}
            />
            <MediaManagerDialog
                open={mediaOpen}
                article={article}
                onClose={() => setMediaOpen(false)}
                onUpload={(file, caption) => uploadMediaMutation.mutate({ file, caption })}
                onDelete={(mediaId) => deleteMediaMutation.mutate(mediaId)}
            />
            <WalkthroughRecorder
                open={walkthroughOpen}
                articleTitle={article?.title}
                onClose={() => setWalkthroughOpen(false)}
                onSave={(payload) => createWalkthroughMutation.mutate(payload)}
            />
            <FaqMediaLightbox
                open={lightboxOpen}
                items={lightboxItems}
                index={lightboxIndex}
                onChangeIndex={setLightboxIndex}
                onClose={() => setLightboxOpen(false)}
            />
        </div>
    );
}
