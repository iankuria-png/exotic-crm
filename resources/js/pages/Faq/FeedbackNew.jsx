import React, { useState } from 'react';
import { useMutation } from '@tanstack/react-query';
import html2canvas from 'html2canvas';
import { useNavigate, useSearchParams } from 'react-router-dom';
import PageHeader from '../../components/PageHeader';
import faqApi from '../../services/faqApi';
import { useToast } from '../../components/ToastProvider';

export default function FeedbackNew() {
    const [searchParams] = useSearchParams();
    const navigate = useNavigate();
    const toast = useToast();
    const [kind, setKind] = useState(searchParams.get('kind') || 'general');
    const [title, setTitle] = useState('');
    const [comment, setComment] = useState('');
    const [severity, setSeverity] = useState('medium');
    const [contextPath, setContextPath] = useState(searchParams.get('context_path') || window.location.pathname + window.location.search);
    const [screenshotFile, setScreenshotFile] = useState(null);

    const mutation = useMutation({
        mutationFn: (formData) => faqApi.createFeedback(formData),
        onSuccess: ({ feedback }) => {
            toast.success('Feedback submitted.');
            navigate(`/faq/feedback/${feedback.id}`);
        },
        onError: () => toast.error('Unable to submit feedback.'),
    });

    const captureScreenshot = async () => {
        const canvas = await html2canvas(document.body);
        const blob = await new Promise((resolve) => canvas.toBlob(resolve, 'image/png'));
        if (!blob) {
            toast.error('Screenshot capture failed.');
            return;
        }

        setScreenshotFile(new File([blob], 'faq-feedback-context.png', { type: 'image/png' }));
        toast.success('Screenshot captured.');
    };

    const submit = () => {
        const formData = new FormData();
        formData.append('kind', kind);
        formData.append('title', title);
        formData.append('comment', comment);
        formData.append('context_path', contextPath);
        formData.append('context_meta[route]', window.location.pathname);
        if (kind === 'bug') {
            formData.append('severity', severity);
        }
        if (screenshotFile) {
            formData.append('screenshot', screenshotFile);
        }
        mutation.mutate(formData);
    };

    return (
        <div className="space-y-4">
            <PageHeader title="Submit feedback" subtitle="Capture product feedback with enough context for fast triage." />
            <section className="crm-surface grid gap-4 px-5 py-5 xl:grid-cols-[minmax(0,1fr)_340px]">
                <div className="space-y-4">
                    <div className="grid gap-4 md:grid-cols-2">
                        <label className="space-y-1.5 text-sm text-slate-600">
                            <span className="font-medium text-slate-800">Kind</span>
                            <select value={kind} onChange={(event) => setKind(event.target.value)} className="w-full rounded-xl border border-slate-200 px-3 py-2">
                                <option value="bug">Bug</option>
                                <option value="feature_request">Feature request</option>
                                <option value="general">General suggestion</option>
                                <option value="article_suggestion">Article suggestion</option>
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
                        <input value={title} onChange={(event) => setTitle(event.target.value)} className="w-full rounded-xl border border-slate-200 px-3 py-2" placeholder="Short summary for the triage queue" />
                    </label>
                    <label className="space-y-1.5 text-sm text-slate-600">
                        <span className="font-medium text-slate-800">Context path</span>
                        <input value={contextPath} onChange={(event) => setContextPath(event.target.value)} className="w-full rounded-xl border border-slate-200 px-3 py-2" />
                    </label>
                    <label className="space-y-1.5 text-sm text-slate-600">
                        <span className="font-medium text-slate-800">Details</span>
                        <textarea value={comment} onChange={(event) => setComment(event.target.value)} rows={10} className="w-full rounded-2xl border border-slate-200 px-3 py-3" placeholder="Steps to reproduce, expected vs actual, or the workflow gap you hit." />
                    </label>
                </div>

                <aside className="space-y-4 rounded-2xl border border-slate-200 bg-slate-50 p-4">
                    <div>
                        <p className="text-sm font-semibold text-slate-900">Context capture</p>
                        <p className="mt-1 text-sm text-slate-500">Attach a screenshot of the current CRM state when it helps triage.</p>
                    </div>
                    <button type="button" onClick={captureScreenshot} className="crm-btn-secondary w-full px-3 py-2 text-sm">Capture screenshot</button>
                    <input type="file" accept="image/png,image/jpeg,image/webp" onChange={(event) => setScreenshotFile(event.target.files?.[0] || null)} className="w-full rounded-xl border border-slate-200 px-3 py-2 text-sm" />
                    {screenshotFile ? <p className="text-sm text-slate-600">Attached: {screenshotFile.name}</p> : null}
                    <button type="button" onClick={submit} disabled={mutation.isPending} className="crm-btn-primary w-full px-3 py-2 text-sm">
                        {mutation.isPending ? 'Submitting...' : 'Submit feedback'}
                    </button>
                </aside>
            </section>
        </div>
    );
}
