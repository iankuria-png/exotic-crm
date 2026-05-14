import React from 'react';
import { Link, useParams } from 'react-router-dom';
import { Bar, BarChart, CartesianGrid, PolarAngleAxis, PolarGrid, Radar, RadarChart, ResponsiveContainer, Tooltip, XAxis, YAxis } from 'recharts';
import { useQuery } from '@tanstack/react-query';
import CertificatePreview from '../../components/University/CertificatePreview';
import QuestionCard from '../../components/University/QuestionCard';
import universityApi from '../../services/universityApi';

export default function AttemptResult() {
    const { attemptId } = useParams();
    const resultQuery = useQuery({
        queryKey: ['university-attempt-result', attemptId],
        queryFn: () => universityApi.getAttemptResult(attemptId),
    });
    const attempt = resultQuery.data?.attempt;
    const topics = Object.entries(attempt?.per_topic_breakdown || {}).map(([topic, row]) => ({ topic, score: Number(row.score_pct || 0) }));

    if (resultQuery.isLoading) {
        return <div className="rounded-lg border border-slate-200 bg-white p-6 text-sm text-slate-500">Loading result...</div>;
    }

    return (
        <div className="space-y-5">
            <section className={`rounded-lg border p-6 ${attempt?.passed ? 'border-emerald-200 bg-emerald-50' : 'border-rose-200 bg-rose-50'}`}>
                <p className="text-xs font-bold uppercase tracking-[0.16em] text-slate-600">{attempt?.passed ? 'Passed' : 'Not passed'}</p>
                <h1 className="mt-2 text-4xl font-bold text-slate-950">{Math.round(Number(attempt?.score_pct || 0))}%</h1>
                <p className="mt-2 text-sm text-slate-600">{attempt?.certification?.title}</p>
            </section>

            <section className="grid gap-5 lg:grid-cols-[minmax(0,1fr)_320px]">
                <div className="rounded-lg border border-slate-200 bg-white p-5">
                    <h2 className="text-lg font-semibold text-slate-950">Topic breakdown</h2>
                    <div className="mt-4 grid gap-5 xl:grid-cols-2">
                        <div className="h-72">
                            <ResponsiveContainer width="100%" height="100%">
                                <BarChart data={topics}>
                                    <CartesianGrid strokeDasharray="3 3" />
                                    <XAxis dataKey="topic" />
                                    <YAxis domain={[0, 100]} />
                                    <Tooltip />
                                    <Bar dataKey="score" fill="#0f766e" radius={[6, 6, 0, 0]} />
                                </BarChart>
                            </ResponsiveContainer>
                        </div>
                        <div className="h-72">
                            <ResponsiveContainer width="100%" height="100%">
                                <RadarChart data={topics}>
                                    <PolarGrid />
                                    <PolarAngleAxis dataKey="topic" />
                                    <Radar dataKey="score" fill="#14b8a6" fillOpacity={0.35} stroke="#0f766e" />
                                    <Tooltip />
                                </RadarChart>
                            </ResponsiveContainer>
                        </div>
                    </div>
                </div>
                <CertificatePreview certificate={attempt?.certificate} />
            </section>

            <section className="rounded-lg border border-slate-200 bg-white p-5">
                <h2 className="text-lg font-semibold text-slate-950">Question review</h2>
                <div className="mt-5 space-y-6">
                    {(attempt?.answers || []).map((answer) => (
                        <div key={answer.question_id} className="border-b border-slate-100 pb-6 last:border-b-0 last:pb-0">
                            <QuestionCard question={answer.question} selectedOptionId={answer.selected_option_id} showCorrect />
                        </div>
                    ))}
                </div>
            </section>

            <Link to="/university" className="crm-btn-secondary inline-flex px-3 py-2 text-sm">Back to University</Link>
        </div>
    );
}
