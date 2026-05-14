import React from 'react';

export default function QuestionCard({ question, selectedOptionId, onSelect, showCorrect = false }) {
    return (
        <section className="space-y-5">
            {question?.scenario_context ? (
                <div className="rounded-lg border border-amber-200 bg-amber-50 px-4 py-3 text-sm font-medium text-amber-900">
                    {question.scenario_context}
                </div>
            ) : null}
            <h2 className="text-xl font-semibold text-slate-950">{question?.prompt}</h2>
            <div className="grid gap-3">
                {(question?.options || []).map((option, index) => {
                    const selected = Number(selectedOptionId) === Number(option.id);
                    const correct = showCorrect && option.is_correct;
                    const wrong = showCorrect && selected && !option.is_correct;

                    return (
                        <button
                            key={option.id}
                            type="button"
                            onClick={() => onSelect?.(question.id, option.id)}
                            className={[
                                'flex items-start gap-3 rounded-lg border px-4 py-3 text-left transition',
                                correct ? 'border-emerald-300 bg-emerald-50 text-emerald-950' : '',
                                wrong ? 'border-rose-300 bg-rose-50 text-rose-950' : '',
                                !correct && !wrong && selected ? 'border-teal-400 bg-teal-50 text-teal-950' : '',
                                !correct && !wrong && !selected ? 'border-slate-200 bg-white hover:border-teal-200 hover:bg-teal-50/60' : '',
                            ].join(' ')}
                        >
                            <span className="flex h-7 w-7 shrink-0 items-center justify-center rounded-full bg-slate-100 text-xs font-bold text-slate-600">
                                {String.fromCharCode(65 + index)}
                            </span>
                            <span className="text-sm font-medium">{option.text}</span>
                        </button>
                    );
                })}
            </div>
            {showCorrect && question?.explanation ? (
                <p className="rounded-lg bg-slate-50 px-4 py-3 text-sm text-slate-600">{question.explanation}</p>
            ) : null}
        </section>
    );
}
