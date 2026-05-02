import React, { useState } from 'react';
import { useMutation, useQueryClient } from '@tanstack/react-query';
import faqApi from '../../services/faqApi';
import { useToast } from '../ToastProvider';

export default function HelpfulWidget({ article }) {
    const [comment, setComment] = useState('');
    const [activeKind, setActiveKind] = useState('');
    const queryClient = useQueryClient();
    const toast = useToast();

    const mutation = useMutation({
        mutationFn: (payload) => faqApi.createFeedback(payload),
        onSuccess: () => {
            setComment('');
            setActiveKind('');
            queryClient.invalidateQueries({ queryKey: ['faq-article', article?.slug] });
            queryClient.invalidateQueries({ queryKey: ['faq-articles'] });
            toast.success('Thanks. Your article feedback was captured.');
        },
        onError: () => {
            toast.error('Unable to save article feedback right now.');
        },
    });

    const submit = (kind) => {
        setActiveKind(kind);
        mutation.mutate({
            article_id: article?.id,
            kind,
            helpful: kind === 'helpful',
            comment: comment || null,
        });
    };

    return (
        <section className="crm-surface space-y-3 px-5 py-4">
            <div className="flex flex-wrap items-center justify-between gap-3">
                <div>
                    <p className="text-sm font-semibold text-slate-900">Did this help on the case you are working?</p>
                    <p className="text-sm text-slate-500">Flag gaps while the client, payment, or queue context is still fresh.</p>
                </div>
                <div className="flex flex-wrap gap-2">
                    <button type="button" onClick={() => submit('helpful')} disabled={mutation.isPending} className="crm-btn-secondary px-3 py-2 text-sm">
                        Helpful
                    </button>
                    <button type="button" onClick={() => submit('unhelpful')} disabled={mutation.isPending} className="crm-btn-secondary px-3 py-2 text-sm">
                        Needs work
                    </button>
                </div>
            </div>
            <textarea
                value={comment}
                onChange={(event) => setComment(event.target.value)}
                rows={3}
                className="w-full rounded-xl border border-slate-200 px-3 py-2 text-sm text-slate-700 shadow-sm outline-none ring-0 transition focus:border-teal-400"
                placeholder={activeKind === 'unhelpful' ? 'What was missing, misleading, or too vague?' : 'Optional note from the case you just worked'}
            />
        </section>
    );
}
