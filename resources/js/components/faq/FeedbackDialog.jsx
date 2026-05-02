import React, { useState } from 'react';
import { useMutation } from '@tanstack/react-query';
import faqApi from '../../services/faqApi';
import { useToast } from '../ToastProvider';

export default function FeedbackDialog({ open, onClose, article, initialKind = 'article_suggestion' }) {
    const [kind, setKind] = useState(initialKind);
    const [title, setTitle] = useState('');
    const [comment, setComment] = useState('');
    const [severity, setSeverity] = useState('medium');
    const toast = useToast();

    const mutation = useMutation({
        mutationFn: (payload) => faqApi.createFeedback(payload),
        onSuccess: () => {
            setTitle('');
            setComment('');
            setSeverity('medium');
            toast.success('Feedback submitted.');
            onClose?.();
        },
        onError: () => toast.error('Unable to submit feedback right now.'),
    });

    if (!open) {
        return null;
    }

    return (
        <div className="fixed inset-0 z-[120] flex items-center justify-center bg-slate-950/45 p-4" onClick={onClose}>
            <div className="w-full max-w-2xl rounded-2xl border border-slate-200 bg-white shadow-xl" onClick={(event) => event.stopPropagation()}>
                <div className="border-b border-slate-100 px-5 py-4">
                    <h3 className="text-lg font-semibold text-slate-900">Send article feedback</h3>
                    <p className="text-sm text-slate-500">Attach context to this article so admins can refine it quickly.</p>
                </div>
                <div className="space-y-4 px-5 py-5">
                    <div className="grid gap-4 md:grid-cols-2">
                        <label className="space-y-1.5 text-sm text-slate-600">
                            <span className="font-medium text-slate-800">Type</span>
                            <select value={kind} onChange={(event) => setKind(event.target.value)} className="w-full rounded-xl border border-slate-200 px-3 py-2">
                                <option value="article_suggestion">Suggest edit</option>
                                <option value="bug">Report bug</option>
                                <option value="general">General feedback</option>
                            </select>
                        </label>
                        {kind === 'bug' ? (
                            <label className="space-y-1.5 text-sm text-slate-600">
                                <span className="font-medium text-slate-800">Severity</span>
                                <select value={severity} onChange={(event) => setSeverity(event.target.value)} className="w-full rounded-xl border border-slate-200 px-3 py-2">
                                    <option value="low">Low</option>
                                    <option value="medium">Medium</option>
                                    <option value="high">High</option>
                                    <option value="critical">Critical</option>
                                </select>
                            </label>
                        ) : null}
                    </div>
                    <label className="space-y-1.5 text-sm text-slate-600">
                        <span className="font-medium text-slate-800">Title</span>
                        <input value={title} onChange={(event) => setTitle(event.target.value)} className="w-full rounded-xl border border-slate-200 px-3 py-2" placeholder="Short summary" />
                    </label>
                    <label className="space-y-1.5 text-sm text-slate-600">
                        <span className="font-medium text-slate-800">Comment</span>
                        <textarea value={comment} onChange={(event) => setComment(event.target.value)} rows={6} className="w-full rounded-xl border border-slate-200 px-3 py-2" placeholder="Explain what helped, what did not, or what should change." />
                    </label>
                </div>
                <div className="flex justify-end gap-2 border-t border-slate-100 px-5 py-4">
                    <button type="button" onClick={onClose} className="crm-btn-secondary px-3 py-2 text-sm">Cancel</button>
                    <button
                        type="button"
                        onClick={() => mutation.mutate({
                            article_id: article?.id,
                            kind,
                            title: title || null,
                            comment: comment || null,
                            severity: kind === 'bug' ? severity : null,
                            context_path: window.location.pathname + window.location.search,
                        })}
                        disabled={mutation.isPending}
                        className="crm-btn-primary px-3 py-2 text-sm"
                    >
                        {mutation.isPending ? 'Sending...' : 'Send feedback'}
                    </button>
                </div>
            </div>
        </div>
    );
}
