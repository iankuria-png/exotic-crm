import React, { useMemo, useState } from 'react';
import { useMutation, useQuery } from '@tanstack/react-query';
import api from '../../services/api';
import AiStateBlock from './AiStateBlock';

const SOURCE_LABELS = {
    auto: 'Auto',
    business_data: 'Business Data',
    sales_data: 'Sales Data',
    project_status: 'Project Status',
    hybrid: 'Hybrid',
};

const SUGGESTIONS = {
    business_data: [
        'Which markets had the most revenue this month?',
        'Show revenue by market for the last 30 days.',
        'Which countries are growing fastest?',
    ],
    sales_data: [
        'Which agent performed best recently?',
        'Show sales performance by agent.',
        'Which markets need more sales attention?',
    ],
    project_status: [
        'What shipped recently?',
        'What is pending deployment?',
        'What commits mention billing?',
    ],
    hybrid: [
        'Did recent billing changes affect revenue?',
        'Summarize revenue and recent project changes.',
        'What looks risky across deploys and business data?',
    ],
};

const CHIP_ORDER = ['auto', 'business_data', 'sales_data', 'project_status', 'hybrid'];

function canUseAiInsights(user) {
    return !!user && (user.is_ceo || ['admin', 'sub_admin'].includes(user.role));
}

export default function AiInsightsPanel({ user }) {
    const [question, setQuestion] = useState('');
    const [source, setSource] = useState('auto');
    const [answer, setAnswer] = useState(null);

    const healthQuery = useQuery({
        queryKey: ['ai-insights-health'],
        queryFn: () => api.get('/crm/ai/insights/health').then((r) => r.data),
        enabled: canUseAiInsights(user),
        staleTime: 30_000,
        retry: false,
    });

    const askMutation = useMutation({
        mutationFn: (payload) => api.post('/crm/ai/ask', payload).then((r) => r.data),
        onSuccess: (data) => setAnswer(data),
        onError: (error) => {
            setAnswer({
                status: error?.response?.data?.status || 'error',
                source: source === 'auto' ? null : source,
                answer: null,
                message: error?.response?.data?.message || 'AI insights could not answer that question.',
                reason: error?.response?.data?.reason || null,
                rows: [],
                columns: [],
            });
        },
    });

    const health = healthQuery.data || {};
    const sources = health.sources || {};
    const activeSource = answer?.source || (source === 'auto' ? 'auto' : source);
    const visibleSuggestions = useMemo(() => {
        const key = source === 'auto' ? 'business_data' : source;
        return SUGGESTIONS[key] || SUGGESTIONS.business_data;
    }, [source]);

    if (!canUseAiInsights(user)) {
        return null;
    }

    const submit = (nextQuestion = question) => {
        const trimmed = String(nextQuestion || '').trim();
        if (trimmed.length < 3 || askMutation.isPending) {
            return;
        }

        setQuestion(trimmed);
        askMutation.mutate({
            question: trimmed,
            ...(source !== 'auto' ? { source } : {}),
        });
    };

    const sourceAvailable = (key) => {
        if (key === 'auto') {
            return health.enabled && Object.values(sources).some(Boolean);
        }
        return health.enabled && sources[key] !== false;
    };

    return (
        <section className="rounded-lg border border-slate-200 bg-white shadow-sm" aria-label="Talk to Your Data">
            <div className="border-b border-slate-200 px-4 py-3">
                <div className="flex flex-col gap-3 lg:flex-row lg:items-start lg:justify-between">
                    <div>
                        <p className="text-[11px] font-semibold uppercase tracking-[0.12em] text-slate-500">Talk to Your Data</p>
                        <h2 className="mt-1 text-lg font-semibold text-slate-950">Ask read-only questions</h2>
                        <p className="mt-1 max-w-3xl text-sm text-slate-500">
                            Answers use validated reporting views and read-only project evidence. No actions are executed from chat.
                        </p>
                    </div>
                    <div className="flex flex-wrap items-center gap-2 text-xs">
                        <StatusBadge ok={!!health.enabled}>{health.enabled ? 'Enabled' : 'Disabled'}</StatusBadge>
                        <StatusBadge ok={health.scope !== 'empty'}>{health.scope === 'market_scoped' ? 'Market scoped' : 'Org wide'}</StatusBadge>
                        {health.daily_cost_cap_usd !== undefined ? (
                            <span className="rounded-md border border-slate-200 bg-slate-50 px-2.5 py-1 text-slate-600">
                                ${Number(health.daily_cost_used_usd || 0).toFixed(4)} / ${Number(health.daily_cost_cap_usd || 0).toFixed(2)}
                            </span>
                        ) : null}
                    </div>
                </div>
            </div>

            {healthQuery.isLoading ? (
                <AiStateBlock className="m-4" variant="loading" message="Checking AI insight sources..." />
            ) : healthQuery.isError ? (
                <AiStateBlock
                    className="m-4"
                    variant="error"
                    title="AI insights are unavailable"
                    message={healthQuery.error?.response?.data?.message || 'The insight health check did not complete.'}
                    onRetry={() => healthQuery.refetch()}
                />
            ) : !health.enabled ? (
                <AiStateBlock
                    className="m-4"
                    variant="empty"
                    title="AI insights are disabled"
                    message="Enable Talk to Data in Settings before asking dashboard questions."
                />
            ) : (
                <div className="space-y-4 p-4">
                    <div className="flex flex-wrap gap-2" aria-label="Insight source">
                        {CHIP_ORDER.map((key) => {
                            const available = sourceAvailable(key);
                            return (
                                <button
                                    key={key}
                                    type="button"
                                    onClick={() => available && setSource(key)}
                                    disabled={!available}
                                    aria-pressed={source === key}
                                    title={!available ? `${SOURCE_LABELS[key]} is disabled or unavailable` : SOURCE_LABELS[key]}
                                    className={`min-h-11 rounded-md border px-3 py-2 text-sm font-semibold transition focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-teal-500 ${
                                        source === key
                                            ? 'border-teal-300 bg-teal-50 text-teal-800'
                                            : 'border-slate-200 bg-white text-slate-600 hover:bg-slate-50'
                                    } disabled:cursor-not-allowed disabled:border-slate-200 disabled:bg-slate-100 disabled:text-slate-400`}
                                >
                                    {SOURCE_LABELS[key]}
                                </button>
                            );
                        })}
                    </div>

                    <form
                        className="grid gap-2 lg:grid-cols-[1fr_auto]"
                        onSubmit={(event) => {
                            event.preventDefault();
                            submit();
                        }}
                    >
                        <label className="sr-only" htmlFor="ai-insights-question">Question</label>
                        <textarea
                            id="ai-insights-question"
                            value={question}
                            onChange={(event) => setQuestion(event.target.value)}
                            rows={3}
                            placeholder="Ask about revenue, agent performance, deploy status, or recent commits."
                            className="min-h-[88px] w-full resize-y rounded-md border border-slate-300 px-3 py-2 text-sm text-slate-800 focus:border-teal-500 focus:outline-none focus:ring-2 focus:ring-teal-100"
                        />
                        <button
                            type="submit"
                            disabled={askMutation.isPending || question.trim().length < 3}
                            className="min-h-11 rounded-md bg-teal-600 px-5 py-2 text-sm font-semibold text-white transition hover:bg-teal-700 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-teal-500 disabled:cursor-not-allowed disabled:opacity-60"
                        >
                            {askMutation.isPending ? 'Asking...' : 'Ask'}
                        </button>
                    </form>

                    <div className="flex flex-wrap gap-2">
                        {visibleSuggestions.map((prompt) => (
                            <button
                                key={prompt}
                                type="button"
                                onClick={() => submit(prompt)}
                                disabled={askMutation.isPending}
                                className="min-h-11 rounded-md border border-slate-200 bg-slate-50 px-3 py-2 text-left text-xs font-medium text-slate-600 transition hover:bg-white focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-slate-400 disabled:opacity-60"
                            >
                                {prompt}
                            </button>
                        ))}
                    </div>

                    {askMutation.isPending ? (
                        <AiStateBlock variant="loading" message="Validating sources and gathering evidence..." />
                    ) : answer ? (
                        <AnswerPanel answer={answer} activeSource={activeSource} showGeneratedSql={!!health.show_generated_sql} />
                    ) : (
                        <AiStateBlock
                            variant="empty"
                            title="No question asked yet"
                            message="Choose a source or leave Auto selected, then ask a bounded read-only question."
                        />
                    )}
                </div>
            )}
        </section>
    );
}

function StatusBadge({ ok, children }) {
    return (
        <span className={`rounded-md px-2.5 py-1 font-semibold ${ok ? 'bg-emerald-50 text-emerald-700' : 'bg-slate-100 text-slate-500'}`}>
            {children}
        </span>
    );
}

function AnswerPanel({ answer, activeSource, showGeneratedSql }) {
    const isOk = answer.status === 'ok';
    const rows = Array.isArray(answer.rows) ? answer.rows : [];
    const columns = Array.isArray(answer.columns) ? answer.columns : [];
    const statusCopy = statusMessage(answer);

    return (
        <div className="rounded-lg border border-slate-200 bg-slate-50/70">
            <div className="border-b border-slate-200 bg-white px-4 py-3">
                <div className="flex flex-wrap items-center justify-between gap-2">
                    <div>
                        <p className="text-xs font-semibold uppercase tracking-[0.12em] text-slate-500">
                            {SOURCE_LABELS[answer.source || activeSource] || 'Answer'}
                        </p>
                        <h3 className="mt-1 text-sm font-semibold text-slate-950">{isOk ? 'Answer' : statusCopy.title}</h3>
                    </div>
                    <div className="flex flex-wrap gap-2">
                        {answer.answer ? <CopyButton text={answer.answer} /> : null}
                        {rows.length > 0 ? <ExportButton rows={rows} columns={columns} /> : null}
                    </div>
                </div>
            </div>

            <div className="space-y-4 p-4">
                {isOk ? (
                    <p className="whitespace-pre-wrap text-sm leading-6 text-slate-800">{answer.answer || 'No narrative answer was returned.'}</p>
                ) : (
                    <div className="rounded-md border border-amber-200 bg-amber-50 px-3 py-2 text-sm text-amber-800">
                        <p className="font-semibold">{statusCopy.title}</p>
                        <p className="mt-1">{answer.message || statusCopy.message}</p>
                        {answer.reason ? <p className="mt-1 text-xs">Reason: {answer.reason}</p> : null}
                    </div>
                )}

                {rows.length > 0 ? (
                    <ResultTable rows={rows} columns={columns} />
                ) : isOk ? (
                    <AiStateBlock
                        variant="empty"
                        title="No rows returned"
                        message="The query was safe, but the selected scope did not contain matching data."
                    />
                ) : null}

                <details className="rounded-md border border-slate-200 bg-white">
                    <summary className="cursor-pointer px-3 py-2 text-sm font-semibold text-slate-700 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-teal-500">
                        Evidence
                    </summary>
                    <div className="space-y-3 border-t border-slate-200 p-3">
                        {showGeneratedSql && answer.generated_sql ? (
                            <pre className="max-h-48 overflow-auto rounded-md bg-slate-950 p-3 text-xs text-slate-100">
                                <code>{answer.generated_sql}</code>
                            </pre>
                        ) : null}
                        {answer.project ? <ProjectEvidence project={answer.project} /> : null}
                        {answer.chart ? (
                            <p className="text-xs text-slate-500">
                                Chart suggestion: {answer.chart.type} using {answer.chart.x} and {answer.chart.y}.
                            </p>
                        ) : null}
                        {(!answer.generated_sql || !showGeneratedSql) && !answer.project && !answer.chart ? (
                            <p className="text-sm text-slate-500">No additional evidence was returned for this answer.</p>
                        ) : null}
                    </div>
                </details>
            </div>
        </div>
    );
}

function statusMessage(answer) {
    const map = {
        invalid_sql: ['Query blocked', 'The generated query failed safety validation. Try narrowing or rephrasing the question.'],
        source_disabled: ['Source disabled', 'This source is disabled in Settings.'],
        source_unavailable: ['Source unavailable', 'The selected source could not be reached. Other sources may still work.'],
        provider_unavailable: ['Provider unavailable', 'The AI provider did not return an answer. No data was changed.'],
        rate_limited: ['Rate limit reached', 'Please wait before asking another question.'],
        cost_capped: ['Cost cap reached', 'The daily AI insights budget has been reached.'],
        refused: ['Read-only boundary', 'AI insights can retrieve, validate, and summarize only.'],
    };
    const [title, message] = map[answer.status] || ['Question failed', 'The question could not be answered.'];

    return { title, message };
}

function ResultTable({ rows, columns }) {
    return (
        <div className="overflow-x-auto rounded-md border border-slate-200 bg-white">
            <table className="min-w-full text-sm">
                <thead>
                    <tr className="border-b border-slate-200 bg-slate-50 text-left text-xs uppercase tracking-[0.08em] text-slate-500">
                        {columns.map((column) => (
                            <th key={column} className="px-3 py-2 font-semibold">{column}</th>
                        ))}
                    </tr>
                </thead>
                <tbody>
                    {rows.slice(0, 25).map((row, index) => (
                        <tr key={index} className="border-b border-slate-100 last:border-0">
                            {columns.map((column) => (
                                <td key={column} className="px-3 py-2 text-slate-700">
                                    {String(row[column] ?? '')}
                                </td>
                            ))}
                        </tr>
                    ))}
                </tbody>
            </table>
            {rows.length > 25 ? <p className="px-3 py-2 text-xs text-slate-500">Showing first 25 rows.</p> : null}
        </div>
    );
}

function ProjectEvidence({ project }) {
    const commits = Array.isArray(project.commits) ? project.commits : [];
    const deployments = Array.isArray(project.deployments) ? project.deployments : [];

    if (project.available === false) {
        return (
            <div className="rounded-md border border-amber-200 bg-amber-50 px-3 py-2 text-sm text-amber-800">
                Project intelligence is unavailable. {(project.notes || []).join(' ')}
            </div>
        );
    }

    return (
        <div className="space-y-3">
            {commits.length > 0 ? (
                <div>
                    <p className="text-xs font-semibold uppercase tracking-[0.08em] text-slate-500">Commits</p>
                    <ul className="mt-2 divide-y divide-slate-100 rounded-md border border-slate-200 bg-white">
                        {commits.slice(0, 8).map((commit) => (
                            <li key={commit.sha || commit.short_sha} className="px-3 py-2 text-sm">
                                <div className="flex flex-wrap items-center gap-2">
                                    <span className="crm-mono rounded bg-slate-100 px-1.5 py-0.5 text-xs font-semibold text-slate-700">
                                        {commit.short_sha || 'unknown'}
                                    </span>
                                    <span className="font-medium text-slate-800">{commit.message_subject || commit.message || 'No subject'}</span>
                                </div>
                                <p className="mt-1 text-xs text-slate-500">
                                    {commit.author || 'Unknown author'} · {commit.authored_at || 'Unknown date'}
                                    {commit.url ? (
                                        <>
                                            {' '}· <a className="font-semibold text-teal-700 hover:text-teal-800" href={commit.url} target="_blank" rel="noreferrer">Open</a>
                                        </>
                                    ) : null}
                                </p>
                            </li>
                        ))}
                    </ul>
                </div>
            ) : null}

            {deployments.length > 0 ? (
                <div>
                    <p className="text-xs font-semibold uppercase tracking-[0.08em] text-slate-500">Deployments</p>
                    <ul className="mt-2 divide-y divide-slate-100 rounded-md border border-slate-200 bg-white">
                        {deployments.slice(0, 5).map((deployment, index) => (
                            <li key={`${deployment.sha || deployment.short_sha || index}`} className="px-3 py-2 text-sm text-slate-700">
                                <span className="crm-mono text-xs font-semibold">{deployment.short_sha || deployment.sha || 'unknown'}</span>
                                {' '}· {deployment.status || deployment.state || 'unknown'} · {deployment.deployed_at || deployment.finished_at || 'unknown date'}
                            </li>
                        ))}
                    </ul>
                </div>
            ) : null}
        </div>
    );
}

function CopyButton({ text }) {
    const [copied, setCopied] = useState(false);

    return (
        <button
            type="button"
            onClick={async () => {
                await navigator.clipboard?.writeText(text);
                setCopied(true);
                window.setTimeout(() => setCopied(false), 1500);
            }}
            className="min-h-11 rounded-md border border-slate-300 bg-white px-3 py-2 text-xs font-semibold text-slate-700 transition hover:bg-slate-50 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-slate-400"
        >
            {copied ? 'Copied' : 'Copy'}
        </button>
    );
}

function ExportButton({ rows, columns }) {
    const exportCsv = () => {
        const escape = (value) => `"${String(value ?? '').replaceAll('"', '""')}"`;
        const csv = [columns.map(escape).join(',')]
            .concat(rows.map((row) => columns.map((column) => escape(row[column])).join(',')))
            .join('\n');
        const blob = new Blob([csv], { type: 'text/csv;charset=utf-8' });
        const url = URL.createObjectURL(blob);
        const anchor = document.createElement('a');
        anchor.href = url;
        anchor.download = 'ai-insights-results.csv';
        anchor.click();
        URL.revokeObjectURL(url);
    };

    return (
        <button
            type="button"
            onClick={exportCsv}
            className="min-h-11 rounded-md border border-slate-300 bg-white px-3 py-2 text-xs font-semibold text-slate-700 transition hover:bg-slate-50 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-slate-400"
        >
            Export CSV
        </button>
    );
}
