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

const SOURCE_DESCRIPTIONS = {
    auto: 'Route by question',
    business_data: 'Revenue, markets, payments',
    sales_data: 'Agents, targets, pipeline',
    project_status: 'Commits and deploys',
    hybrid: 'Business plus project evidence',
};

const SUGGESTIONS = {
    business_data: [
        'Which market has the most revenue in this dashboard window?',
        'Show revenue by market for the selected window.',
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

export default function AiInsightsPanel({ user, context = null }) {
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
    const reportingCurrency = answer?.reporting_currency || health.reporting_currency || 'USD';
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
            ...(context ? { context } : {}),
        });
    };

    const sourceAvailable = (key) => {
        if (key === 'auto') {
            return health.enabled && Object.values(sources).some(Boolean);
        }
        return health.enabled && sources[key] !== false;
    };

    return (
        <section className="overflow-hidden rounded-lg border border-slate-200 bg-white shadow-sm" aria-label="Talk to Your Data">
            <div className="border-b border-slate-200 bg-slate-50/80 px-4 py-3">
                <div className="flex flex-col gap-3 xl:flex-row xl:items-center xl:justify-between">
                    <div className="min-w-0">
                        <p className="text-xs font-semibold uppercase text-slate-500">AI analyst</p>
                        <div className="mt-1 flex flex-wrap items-end gap-x-3 gap-y-1">
                            <h2 className="text-xl font-semibold text-slate-950">Talk to Your Data</h2>
                            <span className="text-sm font-medium text-slate-500">Reporting in {reportingCurrency}</span>
                        </div>
                    </div>
                    <div className="grid grid-cols-2 gap-2 text-xs sm:flex sm:flex-wrap sm:items-center">
                        <StatusBadge ok={!!health.enabled}>{health.enabled ? 'Enabled' : 'Disabled'}</StatusBadge>
                        <StatusBadge ok={health.scope !== 'empty'}>{health.scope === 'market_scoped' ? 'Market scoped' : 'Org wide'}</StatusBadge>
                        <StatusBadge ok>{reportingCurrency}</StatusBadge>
                        {health.daily_cost_cap_usd !== undefined ? (
                            <span className="rounded-md border border-slate-200 bg-white px-2.5 py-1 font-semibold text-slate-600">
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
                <div className="grid gap-4 p-4 xl:grid-cols-[260px_minmax(0,1fr)]">
                    <div className="rounded-md border border-slate-200 bg-slate-50 p-3" aria-label="Insight source">
                        <p className="mb-2 text-xs font-semibold uppercase text-slate-500">Source</p>
                        <div className="grid gap-2">
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
                                    className={`min-h-11 rounded-md border px-3 py-2 text-left transition focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-teal-500 ${
                                        source === key
                                            ? 'border-teal-300 bg-white text-teal-900 shadow-sm'
                                            : 'border-transparent bg-transparent text-slate-600 hover:border-slate-200 hover:bg-white'
                                    } disabled:cursor-not-allowed disabled:border-slate-200 disabled:bg-slate-100 disabled:text-slate-400`}
                                >
                                    <span className="flex items-center justify-between gap-3">
                                        <span>
                                            <span className="block text-sm font-semibold">{SOURCE_LABELS[key]}</span>
                                            <span className="mt-0.5 block text-xs font-medium text-slate-500">{SOURCE_DESCRIPTIONS[key]}</span>
                                        </span>
                                        <span className={`h-2.5 w-2.5 shrink-0 rounded-full ${available ? 'bg-emerald-400' : 'bg-slate-300'}`} aria-hidden="true" />
                                    </span>
                                </button>
                            );
                        })}
                        </div>
                    </div>

                    <div className="min-w-0 space-y-4">
                        <form
                            className="rounded-md border border-slate-200 bg-white p-3"
                            onSubmit={(event) => {
                                event.preventDefault();
                                submit();
                            }}
                        >
                            <div className="grid gap-3 lg:grid-cols-[1fr_auto]">
                                <label className="sr-only" htmlFor="ai-insights-question">Question</label>
                                <textarea
                                    id="ai-insights-question"
                                    value={question}
                                    onChange={(event) => setQuestion(event.target.value)}
                                    rows={3}
                                    placeholder="Ask about revenue, agent performance, deploy status, or recent commits."
                                    className="min-h-[104px] w-full resize-y rounded-md border border-slate-300 bg-slate-50 px-3 py-2 text-sm text-slate-800 focus:border-teal-500 focus:bg-white focus:outline-none focus:ring-2 focus:ring-teal-100"
                                />
                                <button
                                    type="submit"
                                    disabled={askMutation.isPending || question.trim().length < 3}
                                    className="min-h-11 rounded-md bg-teal-600 px-6 py-2 text-sm font-semibold text-white transition hover:bg-teal-700 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-teal-500 disabled:cursor-not-allowed disabled:opacity-60 lg:min-h-[104px]"
                                >
                                    {askMutation.isPending ? 'Asking...' : 'Ask'}
                                </button>
                            </div>
                            <div className="mt-3 grid gap-2 md:grid-cols-3">
                                {visibleSuggestions.map((prompt) => (
                                    <button
                                        key={prompt}
                                        type="button"
                                        onClick={() => submit(prompt)}
                                        disabled={askMutation.isPending}
                                        className="min-h-11 rounded-md border border-slate-200 bg-slate-50 px-3 py-2 text-left text-xs font-semibold text-slate-600 transition hover:border-teal-200 hover:bg-white hover:text-slate-900 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-slate-400 disabled:opacity-60"
                                    >
                                        {prompt}
                                    </button>
                                ))}
                            </div>
                        </form>

                        {askMutation.isPending ? (
                            <AiStateBlock variant="loading" message="Validating sources and gathering evidence..." />
                        ) : answer ? (
                            <AnswerPanel
                                answer={answer}
                                activeSource={activeSource}
                                showGeneratedSql={!!health.show_generated_sql}
                                currency={reportingCurrency}
                                onAskFollowUp={submit}
                                isAsking={askMutation.isPending}
                            />
                        ) : (
                            <AiStateBlock
                                variant="empty"
                                title="No question asked yet"
                                message="Choose a source or leave Auto selected, then ask a bounded read-only question."
                            />
                        )}
                    </div>
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

function AnswerPanel({ answer, activeSource, showGeneratedSql, currency, onAskFollowUp, isAsking }) {
    const isOk = answer.status === 'ok';
    const rows = Array.isArray(answer.rows) ? answer.rows : [];
    const columns = Array.isArray(answer.columns) ? answer.columns : [];
    const columnMeta = answer.column_meta || {};
    const statusCopy = statusMessage(answer);
    const cleanedAnswer = cleanText(answer.answer || '');

    return (
        <div className="overflow-hidden rounded-lg border border-slate-200 bg-white">
            <div className="border-b border-slate-200 bg-slate-50 px-4 py-3">
                <div className="flex flex-wrap items-center justify-between gap-2">
                    <div>
                        <p className="text-xs font-semibold uppercase text-slate-500">
                            {SOURCE_LABELS[answer.source || activeSource] || 'Answer'}
                        </p>
                        <div className="mt-1 flex flex-wrap items-center gap-2">
                            <h3 className="text-base font-semibold text-slate-950">{isOk ? 'Answer' : statusCopy.title}</h3>
                            {isOk ? (
                                <span className="rounded-md bg-white px-2 py-1 text-xs font-semibold text-slate-600 ring-1 ring-slate-200">
                                    {rows.length} row{rows.length === 1 ? '' : 's'} / {currency}
                                </span>
                            ) : null}
                        </div>
                    </div>
                    <div className="flex flex-wrap gap-2">
                        {answer.answer ? <CopyButton text={answer.answer} /> : null}
                        {rows.length > 0 ? <ExportButton rows={rows} columns={columns} columnMeta={columnMeta} currency={currency} /> : null}
                    </div>
                </div>
            </div>

            <div className="space-y-4 p-4">
                {isOk ? (
                    <AnswerSummary answer={answer} fallbackText={cleanedAnswer || 'No narrative answer was returned.'} />
                ) : (
                    <div className="rounded-md border border-amber-200 bg-amber-50 px-3 py-2 text-sm text-amber-800">
                        <p className="font-semibold">{statusCopy.title}</p>
                        <p className="mt-1">{answer.message || statusCopy.message}</p>
                        {answer.reason ? <p className="mt-1 text-xs">Reason: {answer.reason}</p> : null}
                    </div>
                )}

                {rows.length > 0 ? (
                    <ResultTable rows={rows} columns={columns} columnMeta={columnMeta} currency={currency} />
                ) : isOk ? (
                    <AiStateBlock
                        variant="empty"
                        title="No rows returned"
                        message="The query was safe, but the selected scope did not contain matching data."
                    />
                ) : null}

                <details className="rounded-md border border-slate-200 bg-slate-50">
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
                        {answer.evidence_source ? <EvidenceSource source={answer.evidence_source} /> : null}
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

                {isOk ? (
                    <FollowUpComposer
                        suggestions={answer.follow_up_suggestions}
                        onAsk={onAskFollowUp}
                        disabled={isAsking}
                    />
                ) : null}
            </div>
        </div>
    );
}

function AnswerSummary({ answer, fallbackText }) {
    const structured = answer.structured_answer || null;

    if (!structured) {
        return <NarrativeBlock text={fallbackText} />;
    }

    const metrics = Array.isArray(structured.metrics) ? structured.metrics : [];
    const bullets = Array.isArray(structured.bullets) ? structured.bullets : [];

    return (
        <div className="space-y-3">
            <div className="border-l-4 border-teal-500 bg-teal-50/70 px-4 py-3">
                <p className="text-base font-semibold text-slate-950">{cleanText(structured.headline || 'Answer')}</p>
                <p className="mt-1 text-sm leading-6 text-slate-700">{cleanText(structured.summary || fallbackText)}</p>
            </div>

            {metrics.length > 0 ? (
                <div className="grid gap-2 sm:grid-cols-2 xl:grid-cols-4">
                    {metrics.map((metric, index) => (
                        <div key={`${metric.label || index}`} className="rounded-md border border-slate-200 bg-slate-50 px-3 py-2">
                            <p className="text-xs font-semibold uppercase text-slate-500">{metric.label || 'Metric'}</p>
                            <p className="mt-1 text-sm font-semibold text-slate-950">{cleanText(metric.value || '-')}</p>
                            {metric.detail ? <p className="mt-0.5 text-xs text-slate-500">{cleanText(metric.detail)}</p> : null}
                        </div>
                    ))}
                </div>
            ) : null}

            {bullets.length > 0 ? (
                <div className="divide-y divide-slate-100 rounded-md border border-slate-200 bg-white">
                    {bullets.map((item, index) => (
                        <div key={`${item.label || index}`} className="grid gap-2 px-3 py-2 sm:grid-cols-[1fr_auto] sm:items-center">
                            <div>
                                <p className="text-sm font-semibold text-slate-800">{cleanText(item.label || `#${index + 1}`)}</p>
                                {item.detail ? <p className="mt-0.5 text-xs text-slate-500">{cleanText(item.detail)}</p> : null}
                            </div>
                            <p className="crm-mono text-sm font-semibold text-slate-950">{cleanText(item.value || '')}</p>
                        </div>
                    ))}
                </div>
            ) : null}
        </div>
    );
}

function NarrativeBlock({ text }) {
    const cleaned = cleanText(text);
    const parts = cleaned
        .split(/(?<=[.!?])\s+/)
        .map((part) => part.trim())
        .filter(Boolean);
    const lead = parts[0] || cleaned;
    const rest = parts.slice(1, 4);

    return (
        <div className="border-l-4 border-teal-500 bg-teal-50/70 px-4 py-3">
            <p className="text-sm leading-6 text-slate-900">{lead}</p>
            {rest.length > 0 ? (
                <ul className="mt-2 space-y-1 text-sm leading-6 text-slate-700">
                    {rest.map((part) => <li key={part}>{part}</li>)}
                </ul>
            ) : null}
        </div>
    );
}

function FollowUpComposer({ suggestions, onAsk, disabled }) {
    const [draft, setDraft] = useState('');
    const prompts = Array.isArray(suggestions) && suggestions.length > 0
        ? suggestions.slice(0, 3)
        : ['Compare this with the previous 30 days.', 'Show the top 10 rows.', 'Break this down by market.'];

    const submit = (prompt = draft) => {
        const text = String(prompt || '').trim();
        if (!text || disabled) return;
        setDraft('');
        onAsk?.(text);
    };

    return (
        <div className="rounded-md border border-slate-200 bg-slate-50 p-3">
            <div className="flex flex-col gap-2 lg:flex-row lg:items-end">
                <div className="min-w-0 flex-1">
                    <label htmlFor="ai-follow-up-question" className="text-xs font-semibold uppercase text-slate-500">Follow up</label>
                    <input
                        id="ai-follow-up-question"
                        type="text"
                        value={draft}
                        onChange={(event) => setDraft(event.target.value)}
                        onKeyDown={(event) => {
                            if (event.key === 'Enter') {
                                event.preventDefault();
                                submit();
                            }
                        }}
                        placeholder="Ask a follow-up using the same dashboard context."
                        className="mt-1 min-h-11 w-full rounded-md border border-slate-300 bg-white px-3 py-2 text-sm text-slate-800 focus:border-teal-500 focus:outline-none focus:ring-2 focus:ring-teal-100"
                    />
                </div>
                <button
                    type="button"
                    onClick={() => submit()}
                    disabled={disabled || draft.trim().length < 3}
                    className="min-h-11 rounded-md bg-slate-900 px-4 py-2 text-sm font-semibold text-white transition hover:bg-slate-800 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-slate-500 disabled:cursor-not-allowed disabled:opacity-60"
                >
                    Ask follow-up
                </button>
            </div>
            <div className="mt-2 flex flex-wrap gap-2">
                {prompts.map((prompt) => (
                    <button
                        key={prompt}
                        type="button"
                        onClick={() => submit(prompt)}
                        disabled={disabled}
                        className="min-h-11 rounded-md border border-slate-200 bg-white px-3 py-2 text-left text-xs font-semibold text-slate-600 transition hover:border-teal-200 hover:text-slate-900 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-slate-400 disabled:opacity-60"
                    >
                        {prompt}
                    </button>
                ))}
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

function ResultTable({ rows, columns, columnMeta, currency }) {
    return (
        <div className="overflow-hidden rounded-md border border-slate-200 bg-white">
            <div className="overflow-x-auto">
                <table className="min-w-full text-sm">
                    <thead>
                        <tr className="border-b border-slate-200 bg-slate-50 text-left text-xs uppercase text-slate-500">
                            {columns.map((column) => (
                                <th
                                    key={column}
                                    className={`whitespace-nowrap px-3 py-2 font-semibold ${isNumericColumn(rows, column) ? 'text-right' : 'text-left'}`}
                                >
                                    {formatColumnLabel(column)}
                                </th>
                            ))}
                        </tr>
                    </thead>
                    <tbody>
                        {rows.slice(0, 25).map((row, index) => (
                            <tr key={index} className="border-b border-slate-100 last:border-0 hover:bg-slate-50">
                                {columns.map((column) => (
                                    <td
                                        key={column}
                                        className={`whitespace-nowrap px-3 py-2 text-slate-700 ${isNumericColumn(rows, column) ? 'text-right crm-mono tabular-nums' : ''} ${isMoneyColumn(column, columnMeta) ? 'font-semibold text-slate-900' : ''}`}
                                    >
                                        {formatCellValue(row[column], column, columnMeta, currency)}
                                    </td>
                                ))}
                            </tr>
                        ))}
                    </tbody>
                </table>
            </div>
            {rows.length > 25 ? <p className="px-3 py-2 text-xs text-slate-500">Showing first 25 rows.</p> : null}
        </div>
    );
}

function formatColumnLabel(column) {
    const label = String(column || '')
        .replaceAll('_', ' ')
        .replace(/\b\w/g, (char) => char.toUpperCase());

    return label
        .replace(/\bUsd\b/g, 'USD')
        .replace(/\bId\b/g, 'ID')
        .replace(/\bMrr\b/g, 'MRR')
        .replace(/\bArr\b/g, 'ARR');
}

function formatCellValue(value, column, columnMeta = {}, currency = 'USD') {
    if (value === null || value === undefined || value === '') {
        return '-';
    }

    if (columnMeta[column]?.type === 'percent' && isNumericValue(value)) {
        return `${Number(value).toFixed(1)}%`;
    }

    if (isMoneyColumn(column, columnMeta) && isNumericValue(value)) {
        return new Intl.NumberFormat('en-US', {
            style: 'currency',
            currency,
            minimumFractionDigits: 2,
            maximumFractionDigits: 2,
        }).format(Number(value));
    }

    if (isNumericValue(value)) {
        const number = Number(value);

        if (Number.isInteger(number)) {
            return new Intl.NumberFormat('en-US').format(number);
        }

        return new Intl.NumberFormat('en-US', {
            minimumFractionDigits: 0,
            maximumFractionDigits: 2,
        }).format(number);
    }

    return String(value);
}

function isMoneyColumn(column, columnMeta = {}) {
    if (columnMeta[column]?.type === 'money') {
        return true;
    }

    const name = String(column || '').toLowerCase();

    if (name.endsWith('_id') || name.endsWith('_count') || ['id', 'count', 'payments_count'].includes(name)) {
        return false;
    }

    return ['revenue', 'amount', 'total', 'cost', 'usd', 'mrr', 'arr', 'ltv', 'price'].some((token) => name.includes(token));
}

function isNumericValue(value) {
    return value !== null && value !== '' && !Array.isArray(value) && Number.isFinite(Number(value));
}

function isNumericColumn(rows, column) {
    return rows.some((row) => isNumericValue(row?.[column]));
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
                    <p className="text-xs font-semibold uppercase text-slate-500">Commits</p>
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
                    <p className="text-xs font-semibold uppercase text-slate-500">Deployments</p>
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

function EvidenceSource({ source }) {
    const window = source?.window;
    const windowLabel = window?.from && window?.to
        ? `${window.from} to ${window.to}`
        : null;

    return (
        <div className="rounded-md border border-slate-200 bg-white px-3 py-2 text-sm text-slate-600">
            <p className="font-semibold text-slate-800">{source.label || 'Evidence source'}</p>
            <p className="mt-1 text-xs text-slate-500">
                {[source.method, windowLabel].filter(Boolean).join(' / ')}
            </p>
        </div>
    );
}

function cleanText(value) {
    return String(value || '')
        .replace(/\*\*/g, '')
        .replace(/__/g, '')
        .replace(/\s+/g, ' ')
        .trim();
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

function ExportButton({ rows, columns, columnMeta, currency }) {
    const exportCsv = () => {
        const escape = (value) => `"${String(value ?? '').replaceAll('"', '""')}"`;
        const csv = [columns.map((column) => escape(formatColumnLabel(column))).join(',')]
            .concat(rows.map((row) => columns.map((column) => escape(formatCellValue(row[column], column, columnMeta, currency))).join(',')))
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
