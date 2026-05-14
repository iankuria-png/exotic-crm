import React, { useEffect, useMemo, useState } from 'react';
import { useLocation, useNavigate, useParams } from 'react-router-dom';
import { useMutation } from '@tanstack/react-query';
import QuestionCard from '../../components/University/QuestionCard';
import universityApi from '../../services/universityApi';
import { useToast } from '../../components/ToastProvider';

export default function QuizRunner() {
    const { attemptId } = useParams();
    const location = useLocation();
    const navigate = useNavigate();
    const toast = useToast();
    const attempt = location.state?.attempt;
    const questions = location.state?.questions || [];
    const [index, setIndex] = useState(0);
    const [answers, setAnswers] = useState({});
    const [remaining, setRemaining] = useState(() => Number(attempt?.certification?.time_limit_minutes || 30) * 60);
    const activeQuestion = questions[index];

    const submitMutation = useMutation({
        mutationFn: () => universityApi.submitAttempt(attemptId, Object.entries(answers).map(([questionId, selectedOptionId]) => ({ question_id: Number(questionId), selected_option_id: Number(selectedOptionId) }))),
        onSuccess: () => navigate(`/university/results/${attemptId}`),
        onError: (error) => toast.warning(error?.response?.data?.message || 'Attempt could not be submitted.'),
    });

    useEffect(() => {
        if (!questions.length) return undefined;
        const timer = window.setInterval(() => setRemaining((current) => Math.max(0, current - 1)), 1000);
        return () => window.clearInterval(timer);
    }, [questions.length]);

    useEffect(() => {
        if (remaining === 0 && questions.length && !submitMutation.isPending) {
            submitMutation.mutate();
        }
    }, [remaining, questions.length, submitMutation]);

    const answered = useMemo(() => Object.keys(answers).length, [answers]);
    const minutes = Math.floor(remaining / 60);
    const seconds = String(remaining % 60).padStart(2, '0');

    if (!questions.length) {
        return (
            <div className="rounded-lg border border-slate-200 bg-white p-6">
                <h1 className="text-xl font-semibold text-slate-950">Attempt unavailable</h1>
                <p className="mt-2 text-sm text-slate-500">Start a new attempt from the certification page.</p>
            </div>
        );
    }

    return (
        <div className="mx-auto max-w-4xl space-y-5">
            <header className="rounded-lg border border-slate-200 bg-white p-5">
                <div className="flex flex-wrap items-center justify-between gap-3">
                    <div>
                        <p className="text-xs font-bold uppercase tracking-[0.16em] text-teal-700">Question {index + 1} of {questions.length}</p>
                        <div className="mt-3 flex gap-1">
                            {questions.map((question, dotIndex) => (
                                <button key={question.id} type="button" onClick={() => setIndex(dotIndex)} className={`h-2.5 w-8 rounded-full ${dotIndex === index ? 'bg-teal-600' : answers[question.id] ? 'bg-emerald-400' : 'bg-slate-200'}`} aria-label={`Go to question ${dotIndex + 1}`} />
                            ))}
                        </div>
                    </div>
                    <div className={`rounded-lg px-4 py-2 text-lg font-bold ${remaining < 300 ? 'bg-rose-50 text-rose-700' : remaining < 600 ? 'bg-amber-50 text-amber-700' : 'bg-slate-50 text-slate-800'}`}>
                        {minutes}:{seconds}
                    </div>
                </div>
            </header>

            <main className="rounded-lg border border-slate-200 bg-white p-6">
                <QuestionCard
                    question={activeQuestion}
                    selectedOptionId={answers[activeQuestion.id]}
                    onSelect={(questionId, optionId) => setAnswers((current) => ({ ...current, [questionId]: optionId }))}
                />
            </main>

            <footer className="flex flex-wrap items-center justify-between gap-3 rounded-lg border border-slate-200 bg-white p-4">
                <button type="button" disabled={index === 0} onClick={() => setIndex((current) => current - 1)} className="crm-btn-secondary px-3 py-2 text-sm disabled:opacity-50">Previous</button>
                <p className="text-sm font-medium text-slate-500">{answered}/{questions.length} answered</p>
                {index < questions.length - 1 ? (
                    <button type="button" onClick={() => setIndex((current) => current + 1)} className="crm-btn-primary px-3 py-2 text-sm">Next</button>
                ) : (
                    <button type="button" onClick={() => window.confirm('Submit this attempt?') && submitMutation.mutate()} disabled={submitMutation.isPending} className="crm-btn-primary px-3 py-2 text-sm disabled:opacity-50">Submit</button>
                )}
            </footer>
        </div>
    );
}
