import React, { useState } from 'react';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { useParams } from 'react-router-dom';
import PageHeader from '../../components/PageHeader';
import faqApi from '../../services/faqApi';
import KindChip from '../../components/faq/KindChip';
import StatusChip from '../../components/faq/StatusChip';
import SeverityChip from '../../components/faq/SeverityChip';
import useFaqAdmin from '../../hooks/useFaqAdmin';
import { useToast } from '../../components/ToastProvider';

export default function FeedbackDetail() {
    const { id } = useParams();
    const admin = useFaqAdmin();
    const toast = useToast();
    const queryClient = useQueryClient();
    const [commentBody, setCommentBody] = useState('');
    const [internal, setInternal] = useState(false);
    const [status, setStatus] = useState('');
    const [adminNotes, setAdminNotes] = useState('');

    const query = useQuery({
        queryKey: ['faq-feedback-detail', id],
        queryFn: () => faqApi.getFeedback(id),
    });
    const feedback = query.data?.feedback;

    const voteMutation = useMutation({
        mutationFn: () => faqApi.toggleFeedbackVote(id),
        onSuccess: () => queryClient.invalidateQueries({ queryKey: ['faq-feedback-detail', id] }),
    });
    const commentMutation = useMutation({
        mutationFn: () => faqApi.addFeedbackComment(id, { body: commentBody, is_internal: internal }),
        onSuccess: () => {
            setCommentBody('');
            setInternal(false);
            queryClient.invalidateQueries({ queryKey: ['faq-feedback-detail', id] });
            toast.success('Comment added.');
        },
        onError: () => toast.error('Unable to add comment.'),
    });
    const updateMutation = useMutation({
        mutationFn: () => faqApi.updateFeedback(id, { status: status || undefined, admin_notes: adminNotes || undefined }),
        onSuccess: () => {
            queryClient.invalidateQueries({ queryKey: ['faq-feedback-detail', id] });
            queryClient.invalidateQueries({ queryKey: ['faq-feedback'] });
            toast.success('Feedback updated.');
        },
        onError: () => toast.error('Unable to update feedback.'),
    });

    return (
        <div className="space-y-4">
            <PageHeader title={feedback?.title || 'Feedback'} subtitle={feedback?.context_path || 'Feedback detail'} />

            <section className="grid gap-4 xl:grid-cols-[minmax(0,1fr)_360px]">
                <div className="space-y-4">
                    <section className="crm-surface space-y-4 px-5 py-5">
                        <div className="flex flex-wrap items-center gap-2">
                            <KindChip kind={feedback?.kind} />
                            <StatusChip status={feedback?.status} />
                            <SeverityChip severity={feedback?.severity} />
                        </div>
                        <p className="text-sm leading-6 text-slate-700">{feedback?.comment}</p>
                        {feedback?.article ? (
                            <p className="text-sm text-slate-500">Related article: {feedback.article.title}</p>
                        ) : null}
                        {feedback?.screenshot_url ? <img src={feedback.screenshot_url} alt="" className="w-full rounded-2xl border border-slate-200" /> : null}
                        <div className="flex flex-wrap gap-2">
                            <button type="button" onClick={() => voteMutation.mutate()} className="crm-btn-secondary px-3 py-2 text-sm">Upvote ({feedback?.votes_count || 0})</button>
                        </div>
                    </section>

                    <section className="crm-surface space-y-4 px-5 py-5">
                        <div>
                            <p className="text-sm font-semibold text-slate-900">Status timeline</p>
                            <p className="text-sm text-slate-500">The detail page is the canonical view for changes in v1.</p>
                        </div>
                        <div className="space-y-3">
                            {(feedback?.status_history || []).map((entry, index) => (
                                <div key={`${entry.status}-${index}`} className="rounded-2xl border border-slate-200 px-4 py-3">
                                    <div className="flex flex-wrap items-center justify-between gap-3">
                                        <StatusChip status={entry.status} />
                                        <span className="text-xs text-slate-500">{entry.changed_at ? new Date(entry.changed_at).toLocaleString() : 'Unknown time'}</span>
                                    </div>
                                    {entry.note ? <p className="mt-2 text-sm text-slate-600">{entry.note}</p> : null}
                                </div>
                            ))}
                        </div>
                    </section>

                    <section className="crm-surface space-y-4 px-5 py-5">
                        <div>
                            <p className="text-sm font-semibold text-slate-900">Threaded comments</p>
                            <p className="text-sm text-slate-500">Internal comments stay hidden from non-admin users.</p>
                        </div>
                        <div className="space-y-3">
                            {(feedback?.comments || []).map((comment) => (
                                <div key={comment.id} className="rounded-2xl border border-slate-200 px-4 py-3">
                                    <div className="flex items-center justify-between gap-3">
                                        <p className="text-sm font-semibold text-slate-900">{comment.user?.name || 'User'}</p>
                                        <span className="text-xs text-slate-500">{comment.created_at ? new Date(comment.created_at).toLocaleString() : ''}</span>
                                    </div>
                                    <p className="mt-2 text-sm text-slate-600">{comment.body}</p>
                                    {admin.isAdmin && comment.is_internal ? <p className="mt-2 text-xs font-semibold uppercase tracking-[0.12em] text-amber-700">Internal</p> : null}
                                </div>
                            ))}
                        </div>
                        <div className="space-y-3 rounded-2xl border border-slate-200 bg-slate-50 px-4 py-4">
                            <textarea value={commentBody} onChange={(event) => setCommentBody(event.target.value)} rows={4} className="w-full rounded-xl border border-slate-200 px-3 py-2 text-sm" placeholder="Add a public comment or status note." />
                            {admin.isAdmin ? (
                                <label className="flex items-center gap-2 text-sm text-slate-600">
                                    <input type="checkbox" checked={internal} onChange={(event) => setInternal(event.target.checked)} className="h-4 w-4 rounded border-slate-300 text-teal-700" />
                                    Internal admin comment
                                </label>
                            ) : null}
                            <button type="button" onClick={() => commentMutation.mutate()} className="crm-btn-secondary px-3 py-2 text-sm">Add comment</button>
                        </div>
                    </section>
                </div>

                {admin.isAdmin ? (
                    <aside className="crm-surface space-y-4 px-5 py-5">
                        <div>
                            <p className="text-sm font-semibold text-slate-900">Admin triage</p>
                            <p className="text-sm text-slate-500">Status changes drive the submitter unread dot and timeline.</p>
                        </div>
                        <select value={status} onChange={(event) => setStatus(event.target.value)} className="w-full rounded-xl border border-slate-200 px-3 py-2 text-sm">
                            <option value="">Keep current status</option>
                            {['new', 'triaged', 'planned', 'in_progress', 'shipped', 'resolved', 'wontfix', 'duplicate'].map((value) => (
                                <option key={value} value={value}>{value.replaceAll('_', ' ')}</option>
                            ))}
                        </select>
                        <textarea value={adminNotes} onChange={(event) => setAdminNotes(event.target.value)} rows={6} className="w-full rounded-2xl border border-slate-200 px-3 py-3 text-sm" placeholder="Internal context or the message you want reflected in the status timeline." />
                        <button type="button" onClick={() => updateMutation.mutate()} className="crm-btn-primary px-3 py-2 text-sm">Save triage update</button>
                    </aside>
                ) : null}
            </section>
        </div>
    );
}
