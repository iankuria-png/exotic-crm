import React, { useMemo, useState } from 'react';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { AdminNav } from './CourseEditor';
import PageHeader from '../../../components/PageHeader';
import universityApi from '../../../services/universityApi';
import { useToast } from '../../../components/ToastProvider';

const baseQuestion = {
    kind: 'mcq',
    prompt: '',
    scenario_context: '',
    explanation: '',
    topic_tag: 'Discovery',
    weight: 1,
    options: [
        { text: '', is_correct: true, order: 1 },
        { text: '', is_correct: false, order: 2 },
        { text: '', is_correct: false, order: 3 },
        { text: '', is_correct: false, order: 4 },
    ],
};

export default function QuestionBank() {
    const toast = useToast();
    const queryClient = useQueryClient();
    const [selectedCertificationId, setSelectedCertificationId] = useState(null);
    const [form, setForm] = useState(baseQuestion);
    const certificationsQuery = useQuery({ queryKey: ['university-certifications'], queryFn: () => universityApi.listCertifications() });
    const certifications = certificationsQuery.data?.certifications || [];
    const certification = useMemo(() => certifications.find((item) => Number(item.id) === Number(selectedCertificationId)) || certifications[0], [certifications, selectedCertificationId]);
    const questionsQuery = useQuery({
        queryKey: ['university-questions', certification?.id],
        queryFn: () => universityApi.listQuestions(certification.id),
        enabled: Boolean(certification?.id),
    });
    const createQuestion = useMutation({
        mutationFn: () => universityApi.createQuestion(certification.id, form),
        onSuccess: () => {
            queryClient.invalidateQueries({ queryKey: ['university-questions', certification.id] });
            setForm(baseQuestion);
            toast.success('Question saved.');
        },
    });

    function updateOption(index, patch) {
        setForm((current) => ({
            ...current,
            options: current.options.map((option, optionIndex) => optionIndex === index ? { ...option, ...patch } : option),
        }));
    }

    return (
        <div className="space-y-5">
            <PageHeader title="Question Bank" subtitle="Maintain MCQ and scenario questions by certification." actions={<AdminNav />} />
            <section className="grid gap-5 xl:grid-cols-[360px_minmax(0,1fr)]">
                <form onSubmit={(event) => { event.preventDefault(); createQuestion.mutate(); }} className="rounded-lg border border-slate-200 bg-white p-5">
                    <h2 className="text-lg font-semibold text-slate-950">New question</h2>
                    <select value={certification?.id || ''} onChange={(event) => setSelectedCertificationId(event.target.value)} className="crm-input mt-4 w-full">
                        {certifications.map((item) => <option key={item.id} value={item.id}>{item.title}</option>)}
                    </select>
                    <div className="mt-3 grid grid-cols-2 gap-3">
                        <select value={form.kind} onChange={(event) => setForm((current) => ({ ...current, kind: event.target.value }))} className="crm-input">
                            <option value="mcq">MCQ</option>
                            <option value="scenario">Scenario</option>
                        </select>
                        <input value={form.topic_tag} onChange={(event) => setForm((current) => ({ ...current, topic_tag: event.target.value }))} className="crm-input" placeholder="Topic" />
                    </div>
                    <textarea value={form.prompt} onChange={(event) => setForm((current) => ({ ...current, prompt: event.target.value }))} className="crm-input mt-3 min-h-24 w-full" placeholder="Question prompt" />
                    {form.kind === 'scenario' ? (
                        <textarea value={form.scenario_context} onChange={(event) => setForm((current) => ({ ...current, scenario_context: event.target.value }))} className="crm-input mt-3 min-h-20 w-full" placeholder="Customer says..." />
                    ) : null}
                    <textarea value={form.explanation} onChange={(event) => setForm((current) => ({ ...current, explanation: event.target.value }))} className="crm-input mt-3 min-h-20 w-full" placeholder="Explanation" />
                    <div className="mt-4 space-y-2">
                        {form.options.map((option, index) => (
                            <label key={index} className="flex items-center gap-2">
                                <input type="radio" checked={option.is_correct} onChange={() => setForm((current) => ({ ...current, options: current.options.map((item, itemIndex) => ({ ...item, is_correct: itemIndex === index })) }))} />
                                <input value={option.text} onChange={(event) => updateOption(index, { text: event.target.value })} className="crm-input flex-1" placeholder={`Option ${index + 1}`} />
                            </label>
                        ))}
                    </div>
                    <button type="submit" disabled={!certification || !form.prompt.trim()} className="crm-btn-primary mt-4 w-full px-3 py-2 disabled:opacity-50">Save question</button>
                </form>

                <div className="rounded-lg border border-slate-200 bg-white p-5">
                    <h2 className="text-lg font-semibold text-slate-950">{certification?.title || 'Questions'}</h2>
                    <div className="mt-4 space-y-3">
                        {(questionsQuery.data?.questions || []).map((question) => (
                            <div key={question.id} className="rounded-lg border border-slate-200 px-4 py-3">
                                <div className="flex flex-wrap items-center gap-2">
                                    <span className="rounded bg-slate-100 px-2 py-1 text-xs font-bold uppercase text-slate-600">{question.kind}</span>
                                    <span className="rounded bg-teal-50 px-2 py-1 text-xs font-bold text-teal-700">{question.topic_tag || 'General'}</span>
                                </div>
                                <p className="mt-2 font-medium text-slate-950">{question.prompt}</p>
                                <p className="mt-1 text-sm text-slate-500">{question.options?.length || 0} options</p>
                            </div>
                        ))}
                    </div>
                </div>
            </section>
        </div>
    );
}
