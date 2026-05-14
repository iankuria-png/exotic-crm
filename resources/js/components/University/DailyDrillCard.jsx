import React, { useState } from 'react';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import universityApi from '../../services/universityApi';
import { useToast } from '../ToastProvider';

export default function DailyDrillCard() {
    const toast = useToast();
    const queryClient = useQueryClient();
    const [picked, setPicked] = useState(null);

    const drillQuery = useQuery({
        queryKey: ['university-daily-drill'],
        queryFn: () => universityApi.todayDrill(),
        staleTime: 60_000,
    });

    const answerMutation = useMutation({
        mutationFn: (selectedIndex) => universityApi.answerDrill(drillQuery.data.drill.id, selectedIndex),
        onSuccess: (data) => {
            queryClient.invalidateQueries({ queryKey: ['university-daily-drill'] });
            queryClient.invalidateQueries({ queryKey: ['university-engagement-me'] });
            queryClient.invalidateQueries({ queryKey: ['university-leaderboard'] });
            if (data?.newly_earned_badges?.length) {
                toast.success(`Badge earned: ${data.newly_earned_badges.join(', ')}`);
            }
        },
    });

    if (drillQuery.isLoading) {
        return <div className="rounded-2xl border border-slate-200 bg-white p-5 text-sm text-slate-500">Loading today's drill…</div>;
    }
    const data = drillQuery.data;
    if (!data?.drill) {
        return <div className="rounded-2xl border border-slate-200 bg-white p-5 text-sm text-slate-500">No daily drill available.</div>;
    }
    const { drill, completed, completion } = data;

    return (
        <div className="rounded-2xl border border-amber-200 bg-gradient-to-br from-amber-50 via-white to-orange-50 p-5 shadow-sm">
            <div className="flex items-center justify-between">
                <div>
                    <p className="text-[11px] font-bold uppercase tracking-[0.16em] text-amber-700">Daily drill · {drill.topic_tag}</p>
                    <p className="mt-1 text-sm text-slate-500">One scenario question. Builds your streak.</p>
                </div>
                <span className="inline-flex h-10 w-10 items-center justify-center rounded-full bg-amber-100 text-amber-700">
                    <svg className="h-5 w-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M15.59 14.37a6 6 0 0 1-5.84 7.38v-4.8m5.84-2.58a14.98 14.98 0 0 0 6.16-12.12A14.98 14.98 0 0 0 9.631 8.41m5.96 5.96a14.926 14.926 0 0 1-5.841 2.58m-.119-8.54a6 6 0 0 0-7.381 5.84h4.8m2.581-5.84a14.927 14.927 0 0 0-2.58 5.84m2.699 2.7c-.103.021-.207.041-.311.06a15.09 15.09 0 0 1-2.448-2.448 14.9 14.9 0 0 1 .06-.312m-2.24 2.39a4.493 4.493 0 0 0-1.757 4.306 4.493 4.493 0 0 0 4.306-1.758M16.5 9a1.5 1.5 0 1 1-3 0 1.5 1.5 0 0 1 3 0Z" /></svg>
                </span>
            </div>

            {drill.scenario_context ? (
                <div className="mt-3 rounded-lg bg-white px-3 py-2 text-sm italic text-slate-600 ring-1 ring-slate-200">
                    {drill.scenario_context}
                </div>
            ) : null}

            <p className="mt-3 text-[15px] font-semibold leading-snug text-slate-950">{drill.prompt}</p>

            <div className="mt-3 grid gap-2">
                {drill.options.map((opt, idx) => {
                    const isPicked = (completed ? completion?.selected_index : picked) === idx;
                    const isCorrect = drill.correct_index === idx;
                    const showResult = completed;
                    let cls = 'border-slate-200 bg-white hover:border-amber-300';
                    if (showResult && isCorrect) cls = 'border-emerald-400 bg-emerald-50 text-emerald-900';
                    else if (showResult && isPicked && !isCorrect) cls = 'border-rose-400 bg-rose-50 text-rose-900';
                    else if (isPicked) cls = 'border-amber-400 bg-amber-50';
                    return (
                        <button
                            key={idx}
                            type="button"
                            disabled={completed || answerMutation.isPending}
                            onClick={() => { setPicked(idx); answerMutation.mutate(idx); }}
                            className={`flex items-start gap-3 rounded-lg border px-3 py-2.5 text-left text-sm transition disabled:cursor-not-allowed ${cls}`}
                        >
                            <span className="mt-0.5 flex h-5 w-5 shrink-0 items-center justify-center rounded-full border border-current text-[11px] font-bold">{String.fromCharCode(65 + idx)}</span>
                            <span>{opt}</span>
                        </button>
                    );
                })}
            </div>

            {completed && drill.explanation ? (
                <div className="mt-3 rounded-lg bg-slate-900 px-3 py-2 text-sm text-slate-100">
                    <span className="font-semibold">Why:</span> {drill.explanation}
                </div>
            ) : null}
        </div>
    );
}
