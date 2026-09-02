import React, { useEffect, useMemo, useState } from 'react';
import { useMutation } from '@tanstack/react-query';
import api from '../services/api';
import { useToast } from './ToastProvider';

const TARGET_OPTIONS = [
    { key: 'profile', label: 'Profile' },
    { key: 'edit_profile', label: 'Edit profile' },
    { key: 'change_password', label: 'Password' },
    { key: 'home', label: 'Home' },
];

// Mirrors ClientSessionDiagnosticsService stage order so the running state can
// show the real pipeline instead of a spinner.
const STAGE_BLUEPRINT = [
    { key: 'client_record', label: 'CRM client record' },
    { key: 'platform_credentials', label: 'Market API credentials' },
    { key: 'rest_reachable', label: 'WordPress REST endpoint reachable' },
    { key: 'rest_authenticated', label: 'CRM authenticates to WordPress' },
    { key: 'session_link_mint', label: 'WordPress mints a session link' },
    { key: 'consumer_reachable', label: 'Session handler executes' },
    { key: 'token_consumed', label: 'One-time token is accepted' },
    { key: 'auth_cookie', label: 'Login cookie issued' },
    { key: 'host_alignment', label: 'Cookie and destination hosts match' },
    { key: 'landing_session', label: 'Client lands logged in' },
];

const STATUS_STYLE = {
    pass: {
        dot: 'bg-emerald-500',
        ring: 'ring-emerald-200',
        chip: 'bg-emerald-50 text-emerald-700 ring-emerald-200',
        glyph: '✓',
        label: 'Pass',
    },
    warn: {
        dot: 'bg-amber-500',
        ring: 'ring-amber-200',
        chip: 'bg-amber-50 text-amber-700 ring-amber-200',
        glyph: '!',
        label: 'Warning',
    },
    fail: {
        dot: 'bg-rose-500',
        ring: 'ring-rose-200',
        chip: 'bg-rose-50 text-rose-700 ring-rose-200',
        glyph: '✕',
        label: 'Failed',
    },
    skipped: {
        dot: 'bg-slate-300',
        ring: 'ring-slate-200',
        chip: 'bg-slate-100 text-slate-500 ring-slate-200',
        glyph: '–',
        label: 'Not reached',
    },
};

const VERDICT_STYLE = {
    pass: 'border-emerald-200 bg-emerald-50 text-emerald-900',
    warn: 'border-amber-200 bg-amber-50 text-amber-900',
    fail: 'border-rose-200 bg-rose-50 text-rose-900',
};

function statusStyle(status) {
    return STATUS_STYLE[status] || STATUS_STYLE.skipped;
}

function StageRow({ stage, index, expanded, onToggle }) {
    const style = statusStyle(stage.status);
    const isSkipped = stage.status === 'skipped';

    return (
        <li className={`rounded-md border bg-white transition ${expanded ? 'border-slate-300 shadow-sm' : 'border-slate-200'}`}>
            <button
                type="button"
                onClick={onToggle}
                className="flex w-full items-start gap-3 px-3 py-2.5 text-left"
            >
                <span
                    className={`mt-0.5 flex h-5 w-5 shrink-0 items-center justify-center rounded-full text-[11px] font-bold text-white ring-4 ${style.dot} ${style.ring}`}
                    aria-hidden="true"
                >
                    {style.glyph}
                </span>
                <span className="min-w-0 flex-1">
                    <span className="flex flex-wrap items-center gap-2">
                        <span className={`text-xs font-semibold ${isSkipped ? 'text-slate-400' : 'text-slate-900'}`}>
                            {index + 1}. {stage.label}
                        </span>
                        <span className={`rounded-full px-1.5 py-0.5 text-[10px] font-semibold uppercase tracking-[0.06em] ring-1 ring-inset ${style.chip}`}>
                            {style.label}
                        </span>
                        {typeof stage.duration_ms === 'number' ? (
                            <span className="text-[10px] tabular-nums text-slate-400">{stage.duration_ms} ms</span>
                        ) : null}
                    </span>
                    <span className={`mt-0.5 block text-[11px] ${isSkipped ? 'text-slate-400' : 'text-slate-600'}`}>
                        {stage.summary}
                    </span>
                </span>
                <span className="mt-1 shrink-0 text-[10px] text-slate-400">{expanded ? 'Hide' : 'Details'}</span>
            </button>

            {expanded ? (
                <div className="border-t border-slate-100 px-3 py-2.5">
                    {stage.hint ? (
                        <p className="mb-2 rounded-md border border-slate-200 bg-slate-50 px-2.5 py-2 text-[11px] leading-relaxed text-slate-700">
                            <span className="font-semibold text-slate-900">What to do: </span>
                            {stage.hint}
                        </p>
                    ) : null}
                    {stage.facts?.length ? (
                        <dl className="space-y-1">
                            {stage.facts.map((fact, factIndex) => (
                                <div key={`${fact.label}-${factIndex}`} className="flex flex-wrap gap-x-2 gap-y-0.5 text-[11px]">
                                    <dt className="w-40 shrink-0 text-slate-500">{fact.label}</dt>
                                    <dd className="min-w-0 flex-1 break-all font-mono text-[10.5px] text-slate-800">{fact.value}</dd>
                                </div>
                            ))}
                        </dl>
                    ) : (
                        <p className="text-[11px] text-slate-400">No details captured for this stage.</p>
                    )}
                </div>
            ) : null}
        </li>
    );
}

export default function ClientSessionDiagnosticsPanel({ open, onClose, client, initialTarget = 'profile' }) {
    const toast = useToast();
    const [target, setTarget] = useState(initialTarget);
    const [report, setReport] = useState(null);
    const [errorMessage, setErrorMessage] = useState(null);
    const [expandedKeys, setExpandedKeys] = useState([]);

    const runMutation = useMutation({
        mutationFn: (payload) => api
            // Markets can take 3-10s per hop, so the whole trace legitimately
            // outlives the shared 60s ceiling. The server caps its own run.
            .post(`/crm/clients/${client.id}/login-as-client/debug`, payload, { timeout: 180_000 })
            .then((response) => response.data),
        onSuccess: (data) => {
            const diagnostics = data?.diagnostics || null;
            setReport(diagnostics);
            setErrorMessage(null);

            const failing = diagnostics?.overall?.failing_stage;
            setExpandedKeys(failing ? [failing] : []);

            const status = diagnostics?.overall?.status;
            if (status === 'pass') {
                toast.success('Session pipeline completed end to end.');
            } else if (status === 'warn') {
                toast.warning('Session pipeline completed with warnings.');
            } else {
                toast.error('Session pipeline failed. See the flagged stage.');
            }
        },
        onError: (error) => {
            setReport(null);
            setErrorMessage(
                error?.response?.data?.message
                || error?.message
                || 'The diagnostic could not be run.',
            );
        },
    });

    useEffect(() => {
        if (!open) {
            return;
        }

        setTarget(initialTarget);
        setReport(null);
        setErrorMessage(null);
        setExpandedKeys([]);
    }, [open, client?.id, initialTarget]);

    useEffect(() => {
        if (!open) {
            return undefined;
        }

        const onKeyDown = (event) => {
            if (event.key === 'Escape') {
                onClose();
            }
        };

        window.addEventListener('keydown', onKeyDown);
        return () => window.removeEventListener('keydown', onKeyDown);
    }, [open, onClose]);

    const stages = report?.stages || [];
    const counts = useMemo(() => stages.reduce((accumulator, stage) => {
        accumulator[stage.status] = (accumulator[stage.status] || 0) + 1;
        return accumulator;
    }, {}), [stages]);

    if (!open || !client) {
        return null;
    }

    const verdict = report?.overall || null;
    const isRunning = runMutation.isPending;

    const toggleStage = (key) => {
        setExpandedKeys((current) => (
            current.includes(key) ? current.filter((item) => item !== key) : [...current, key]
        ));
    };

    const handleRun = () => {
        setErrorMessage(null);
        runMutation.mutate({
            target,
            reason: `Login-as-client diagnostics for CRM #${client.id}`,
            source: 'crm.session_diagnostics',
        });
    };

    const handleCopyReport = async () => {
        if (!report) {
            return;
        }

        try {
            await navigator.clipboard.writeText(JSON.stringify(report, null, 2));
            toast.success('Diagnostic report copied.');
        } catch {
            toast.error('Unable to copy the report.');
        }
    };

    return (
        <div
            className="fixed inset-0 z-[80] flex items-start justify-center overflow-y-auto bg-slate-900/55 p-4 sm:p-8"
            onClick={(event) => {
                // The panel renders inside the client-access drawer, whose own
                // overlay closes on click. Stop here so dismissing the trace
                // does not also close the drawer behind it.
                event.stopPropagation();
                onClose();
            }}
        >
            <div
                role="dialog"
                aria-modal="true"
                aria-label="Client session diagnostics"
                className="w-full max-w-3xl rounded-lg border border-slate-200 bg-slate-50 shadow-2xl"
                onClick={(event) => event.stopPropagation()}
            >
                <header className="rounded-t-lg border-b border-slate-200 bg-white px-5 py-4">
                    <div className="flex flex-wrap items-start justify-between gap-3">
                        <div>
                            <p className="text-xs font-semibold uppercase tracking-[0.12em] text-slate-500">Admin diagnostics</p>
                            <h3 className="mt-1 text-lg font-semibold text-slate-900">Login as client — pipeline trace</h3>
                            <p className="mt-1 text-xs text-slate-500">
                                {client.name || `Client #${client.id}`} • CRM #{client.id}
                                {client.platform?.name ? ` • ${client.platform.name}` : ''}
                            </p>
                        </div>
                        <button
                            type="button"
                            onClick={onClose}
                            className="rounded-md border border-slate-200 px-2.5 py-1.5 text-xs font-semibold text-slate-600 transition hover:bg-slate-50"
                        >
                            Close
                        </button>
                    </div>

                    <div className="mt-3 flex flex-wrap items-center gap-2">
                        <span className="text-[11px] font-semibold uppercase tracking-[0.08em] text-slate-500">Target</span>
                        <div className="flex flex-wrap gap-1 rounded-md border border-slate-200 bg-white p-1">
                            {TARGET_OPTIONS.map((option) => (
                                <button
                                    key={option.key}
                                    type="button"
                                    disabled={isRunning}
                                    onClick={() => setTarget(option.key)}
                                    className={`rounded px-2 py-1 text-[11px] font-semibold transition disabled:cursor-not-allowed disabled:opacity-50 ${
                                        target === option.key
                                            ? 'bg-teal-50 text-teal-800 ring-1 ring-inset ring-teal-200'
                                            : 'text-slate-600 hover:bg-slate-50 hover:text-slate-900'
                                    }`}
                                >
                                    {option.label}
                                </button>
                            ))}
                        </div>
                        <button
                            type="button"
                            onClick={handleRun}
                            disabled={isRunning}
                            className="rounded-md bg-teal-700 px-3 py-1.5 text-xs font-semibold text-white shadow-sm transition hover:bg-teal-800 disabled:cursor-not-allowed disabled:opacity-60"
                        >
                            {isRunning ? 'Tracing pipeline…' : (report ? 'Run again' : 'Run diagnostic')}
                        </button>
                        {report ? (
                            <button
                                type="button"
                                onClick={handleCopyReport}
                                className="rounded-md border border-slate-300 bg-white px-2.5 py-1.5 text-xs font-semibold text-slate-700 transition hover:bg-slate-100"
                            >
                                Copy report
                            </button>
                        ) : null}
                    </div>

                    <p className="mt-2 text-[11px] text-slate-500">
                        Runs the real flow server-side against this market: mints a one-time link, consumes it, and follows the
                        redirect with a cookie jar. It burns its own token, so it never invalidates a link staff are using.
                        Secrets are redacted from the report. A slow market can take a minute or two to trace.
                    </p>
                </header>

                <div className="px-5 py-4">
                    {errorMessage ? (
                        <div className="rounded-md border border-rose-200 bg-rose-50 px-3 py-3 text-xs text-rose-800">
                            <p className="font-semibold">The diagnostic could not run.</p>
                            <p className="mt-1">{errorMessage}</p>
                            <button
                                type="button"
                                onClick={handleRun}
                                className="mt-2 rounded-md border border-rose-300 bg-white px-2.5 py-1 text-[11px] font-semibold text-rose-700 transition hover:bg-rose-100"
                            >
                                Retry
                            </button>
                        </div>
                    ) : null}

                    {isRunning ? (
                        <ol className="space-y-1.5">
                            {STAGE_BLUEPRINT.map((stage, index) => (
                                <li
                                    key={stage.key}
                                    className="flex items-center gap-3 rounded-md border border-slate-200 bg-white px-3 py-2.5"
                                >
                                    <span className="h-5 w-5 shrink-0 animate-pulse rounded-full bg-slate-200" aria-hidden="true" />
                                    <span className="text-xs font-semibold text-slate-400">
                                        {index + 1}. {stage.label}
                                    </span>
                                </li>
                            ))}
                        </ol>
                    ) : null}

                    {!isRunning && !report && !errorMessage ? (
                        <div className="rounded-md border border-dashed border-slate-300 bg-white px-5 py-10 text-center">
                            <p className="text-sm font-semibold text-slate-800">No trace yet</p>
                            <p className="mx-auto mt-1 max-w-md text-xs text-slate-500">
                                Run the diagnostic to walk all ten hops between the CRM and this market&apos;s WordPress site and
                                see exactly which one drops the session.
                            </p>
                            <button
                                type="button"
                                onClick={handleRun}
                                className="mt-4 rounded-md bg-teal-700 px-3 py-1.5 text-xs font-semibold text-white shadow-sm transition hover:bg-teal-800"
                            >
                                Run diagnostic
                            </button>
                        </div>
                    ) : null}

                    {!isRunning && report ? (
                        <div className="space-y-3">
                            {verdict ? (
                                <div className={`rounded-md border px-3 py-3 ${VERDICT_STYLE[verdict.status] || VERDICT_STYLE.fail}`}>
                                    <p className="text-sm font-semibold">{verdict.headline}</p>
                                    {verdict.root_cause ? (
                                        <p className="mt-1 text-xs">
                                            <span className="font-semibold">Root cause: </span>
                                            {verdict.root_cause}
                                        </p>
                                    ) : null}
                                    {verdict.recommended_fix ? (
                                        <p className="mt-1 text-xs">
                                            <span className="font-semibold">Fix: </span>
                                            {verdict.recommended_fix}
                                        </p>
                                    ) : null}
                                </div>
                            ) : null}

                            <div className="flex flex-wrap items-center gap-2 text-[11px] text-slate-500">
                                <span className="rounded-full bg-emerald-50 px-2 py-0.5 font-semibold text-emerald-700 ring-1 ring-inset ring-emerald-200">
                                    {counts.pass || 0} passed
                                </span>
                                <span className="rounded-full bg-amber-50 px-2 py-0.5 font-semibold text-amber-700 ring-1 ring-inset ring-amber-200">
                                    {counts.warn || 0} warnings
                                </span>
                                <span className="rounded-full bg-rose-50 px-2 py-0.5 font-semibold text-rose-700 ring-1 ring-inset ring-rose-200">
                                    {counts.fail || 0} failed
                                </span>
                                <span className="rounded-full bg-slate-100 px-2 py-0.5 font-semibold text-slate-500 ring-1 ring-inset ring-slate-200">
                                    {counts.skipped || 0} not reached
                                </span>
                                <span className="ml-auto">
                                    Target <span className="font-semibold text-slate-700">{report.target}</span> •{' '}
                                    {report.generated_at ? new Date(report.generated_at).toLocaleString() : ''}
                                </span>
                            </div>

                            <ol className="space-y-1.5">
                                {stages.map((stage, index) => (
                                    <StageRow
                                        key={stage.key}
                                        stage={stage}
                                        index={index}
                                        expanded={expandedKeys.includes(stage.key)}
                                        onToggle={() => toggleStage(stage.key)}
                                    />
                                ))}
                            </ol>
                        </div>
                    ) : null}
                </div>
            </div>
        </div>
    );
}
