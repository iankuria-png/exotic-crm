import React, { useCallback, useEffect, useMemo, useState } from 'react';
import { buildDiagnostics, copyDiagnostics, getAppBuild } from '../utils/diagnostics';

// Operator-facing connectivity diagnostics — lets an office user collect a real
// health picture (and a copyable report) without opening a terminal. Uses fetch
// rather than the axios client so it can read response headers (X-Request-Id,
// Cloudflare) and time each hop precisely, and never triggers the 401 logout flow.

const SLOW_MS = 2500;

function authHeaders() {
    const token = localStorage.getItem('crm_token');
    return token ? { Authorization: `Bearer ${token}` } : {};
}

async function timedFetch(url, options = {}) {
    const start = performance.now();
    try {
        const response = await fetch(url, { cache: 'no-store', ...options });
        const ms = Math.round(performance.now() - start);
        return { ok: true, response, ms };
    } catch (error) {
        const ms = Math.round(performance.now() - start);
        return { ok: false, error, ms };
    }
}

function pill(status) {
    switch (status) {
        case 'ok':
            return 'bg-emerald-50 text-emerald-700 ring-emerald-200';
        case 'warn':
            return 'bg-amber-50 text-amber-700 ring-amber-200';
        case 'fail':
            return 'bg-rose-50 text-rose-700 ring-rose-200';
        default:
            return 'bg-slate-50 text-slate-600 ring-slate-200';
    }
}

const VERDICTS = {
    healthy: { label: 'Healthy', cls: 'border-emerald-200 bg-emerald-50 text-emerald-800', hint: 'Everything looks good from this browser.' },
    slow: { label: 'Slow', cls: 'border-amber-200 bg-amber-50 text-amber-800', hint: 'The server is reachable but responding slowly.' },
    auth: { label: 'Auth issue', cls: 'border-amber-200 bg-amber-50 text-amber-800', hint: 'The connection works but your session isn’t authenticated. Try signing in again.' },
    unreachable: { label: 'Unreachable', cls: 'border-rose-200 bg-rose-50 text-rose-800', hint: 'This browser can’t reach the CRM server right now.' },
    running: { label: 'Checking…', cls: 'border-slate-200 bg-slate-50 text-slate-700', hint: 'Running diagnostics.' },
};

export default function NetworkCheck() {
    const [running, setRunning] = useState(false);
    const [testedAt, setTestedAt] = useState(null);
    const [results, setResults] = useState(null);
    const [copied, setCopied] = useState(false);

    const runChecks = useCallback(async () => {
        setRunning(true);
        setCopied(false);

        const online = typeof navigator !== 'undefined' ? navigator.onLine : true;

        const [shell, health, ip, asset] = await Promise.all([
            timedFetch('/'),
            timedFetch('/api/crm/me', { headers: { Accept: 'application/json', ...authHeaders() } }),
            timedFetch('/api/crm/whoami-ip', { headers: { Accept: 'application/json', ...authHeaders() } }),
            timedFetch('/build/manifest.json'),
        ]);

        // API health → the primary signal for the verdict.
        let healthStatus = 'fail';
        let healthDetail = 'No response (server unreachable)';
        let requestId = null;
        let cfRay = null;
        let serverHeader = null;

        if (health.ok && health.response) {
            requestId = health.response.headers.get('x-request-id');
            cfRay = health.response.headers.get('cf-ray');
            serverHeader = health.response.headers.get('server');
            const code = health.response.status;
            if (code === 200) {
                healthStatus = health.ms > SLOW_MS ? 'warn' : 'ok';
                healthDetail = `HTTP 200 · ${health.ms} ms${health.ms > SLOW_MS ? ' (slow)' : ''}`;
            } else if (code === 401) {
                healthStatus = 'warn';
                healthDetail = 'HTTP 401 · not authenticated';
            } else {
                healthStatus = 'fail';
                healthDetail = `HTTP ${code}`;
            }
        }

        let publicIp = null;
        if (ip.ok && ip.response?.status === 200) {
            try {
                publicIp = (await ip.response.json())?.ip || null;
            } catch {
                publicIp = null;
            }
        }

        const checks = [
            {
                key: 'online',
                label: 'Browser connection',
                status: online ? 'ok' : 'fail',
                detail: online ? 'Reported online' : 'Reported offline',
            },
            {
                key: 'shell',
                label: 'App loads (server shell)',
                status: shell.ok && shell.response?.ok ? (shell.ms > SLOW_MS ? 'warn' : 'ok') : 'fail',
                detail: shell.ok && shell.response
                    ? `HTTP ${shell.response.status} · ${shell.ms} ms`
                    : 'Unreachable',
            },
            {
                key: 'health',
                label: 'API health',
                status: healthStatus,
                detail: healthDetail,
            },
            {
                key: 'ip',
                label: 'Your public IP',
                status: publicIp ? 'ok' : 'warn',
                detail: publicIp || 'Unavailable',
            },
            {
                key: 'asset',
                label: 'Static assets',
                status: asset.ok && asset.response?.ok ? 'ok' : 'warn',
                detail: asset.ok && asset.response?.ok
                    ? `Reachable · ${asset.ms} ms`
                    : 'Not found (normal in dev mode)',
            },
            {
                key: 'edge',
                label: 'Cloudflare edge',
                status: cfRay ? 'ok' : 'idle',
                detail: cfRay ? `cf-ray ${cfRay}` : 'Not detected (direct connection)',
            },
        ];

        let verdict = 'healthy';
        if (!online || (!health.ok)) {
            verdict = 'unreachable';
        } else if (health.response?.status === 401) {
            verdict = 'auth';
        } else if (health.response && !health.response.ok) {
            verdict = 'unreachable';
        } else if (health.ms > SLOW_MS) {
            verdict = 'slow';
        }

        setResults({ checks, verdict, requestId, cfRay, serverHeader, publicIp });
        setTestedAt(new Date());
        setRunning(false);
    }, []);

    useEffect(() => {
        runChecks();
    }, [runChecks]);

    const diagnosticsExtra = useMemo(() => {
        if (!results) {
            return {};
        }
        const extra = { verdict: VERDICTS[results.verdict]?.label || results.verdict };
        results.checks.forEach((check) => {
            extra[`check_${check.key}`] = `${check.status} · ${check.detail}`;
        });
        if (results.requestId) {
            extra.lastRequestId = results.requestId;
        }
        return extra;
    }, [results]);

    const handleCopy = async () => {
        const ok = await copyDiagnostics(buildDiagnostics({ extra: { ...diagnosticsExtra, source: 'network-check' } }));
        setCopied(ok);
    };

    const verdict = running && !results ? VERDICTS.running : VERDICTS[results?.verdict] || VERDICTS.running;

    return (
        <div className="mx-auto max-w-3xl space-y-6">
            <div className="flex flex-wrap items-start justify-between gap-3">
                <div>
                    <h1 className="text-xl font-semibold text-slate-900">Network check</h1>
                    <p className="mt-1 text-sm text-slate-500">
                        Diagnose connection problems between this browser and the CRM, and copy a report for support.
                    </p>
                </div>
                <button
                    type="button"
                    onClick={runChecks}
                    disabled={running}
                    className="inline-flex items-center gap-2 rounded-lg bg-teal-600 px-4 py-2 text-sm font-semibold text-white transition hover:bg-teal-700 disabled:cursor-not-allowed disabled:opacity-60"
                >
                    {running ? (
                        <>
                            <span className="h-4 w-4 animate-spin rounded-full border-2 border-white/50 border-t-white" />
                            Checking…
                        </>
                    ) : 'Re-run check'}
                </button>
            </div>

            {/* Verdict banner */}
            <div className={`rounded-2xl border p-5 ${verdict.cls}`}>
                <div className="flex items-center gap-3">
                    <span className="text-lg font-semibold">{verdict.label}</span>
                </div>
                <p className="mt-1 text-sm opacity-90">{verdict.hint}</p>
            </div>

            {/* Check rows */}
            <div className="overflow-hidden rounded-2xl border border-slate-200 bg-white">
                {(results?.checks || []).map((check, index) => (
                    <div
                        key={check.key}
                        className={`flex items-center justify-between gap-4 px-5 py-3.5 ${index > 0 ? 'border-t border-slate-100' : ''}`}
                    >
                        <div className="min-w-0">
                            <p className="text-sm font-medium text-slate-800">{check.label}</p>
                            <p className="truncate text-xs text-slate-500">{check.detail}</p>
                        </div>
                        <span className={`shrink-0 rounded-full px-2.5 py-1 text-xs font-semibold uppercase tracking-wide ring-1 ring-inset ${pill(check.status)}`}>
                            {check.status === 'ok' ? 'OK' : check.status === 'warn' ? 'Check' : check.status === 'fail' ? 'Fail' : '—'}
                        </span>
                    </div>
                ))}
                {!results && running ? (
                    <div className="space-y-3 p-5">
                        {[0, 1, 2, 3].map((n) => (
                            <div key={n} className="h-10 animate-pulse rounded-lg bg-slate-100" />
                        ))}
                    </div>
                ) : null}
            </div>

            {/* Meta + actions */}
            <div className="rounded-2xl border border-slate-200 bg-slate-50 p-5">
                <dl className="grid grid-cols-1 gap-x-6 gap-y-2 text-sm sm:grid-cols-2">
                    <div className="flex justify-between gap-3">
                        <dt className="text-slate-500">Tested at</dt>
                        <dd className="font-medium text-slate-800">{testedAt ? testedAt.toLocaleString() : '—'}</dd>
                    </div>
                    <div className="flex justify-between gap-3">
                        <dt className="text-slate-500">App build</dt>
                        <dd className="font-mono text-xs text-slate-800">{getAppBuild()}</dd>
                    </div>
                    <div className="flex justify-between gap-3">
                        <dt className="text-slate-500">Last request ID</dt>
                        <dd className="font-mono text-xs text-slate-800">{results?.requestId || '—'}</dd>
                    </div>
                    <div className="flex min-w-0 justify-between gap-3">
                        <dt className="shrink-0 text-slate-500">Page</dt>
                        <dd className="truncate font-mono text-xs text-slate-800">{typeof window !== 'undefined' ? window.location.href : ''}</dd>
                    </div>
                </dl>
                <div className="mt-4 flex flex-wrap gap-2">
                    <button
                        type="button"
                        onClick={handleCopy}
                        disabled={!results}
                        className="inline-flex items-center justify-center rounded-lg border border-slate-300 bg-white px-4 py-2 text-sm font-semibold text-slate-700 transition hover:bg-slate-100 disabled:opacity-50"
                    >
                        {copied ? 'Copied ✓' : 'Copy diagnostics'}
                    </button>
                </div>
            </div>
        </div>
    );
}
