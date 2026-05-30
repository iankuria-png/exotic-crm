import React, { useEffect, useState } from 'react';
import { useParams } from 'react-router-dom';
import api from '../services/api';
import { useAuth } from '../hooks/useAuth';
import AiStateBlock from '../components/ai/AiStateBlock';

/**
 * Recipient-facing weekly briefing at /b/:token.
 *
 * The link is not self-authenticating: if the visitor is not logged in we send
 * them to /login?next=/b/:token so they return here after password OR Google
 * sign-in. The backend enforces that the viewer is the actual recipient (or an
 * admin/CEO when admin_override is on).
 */
export default function BriefingShare() {
    const { token } = useParams();
    const { user, isLoading: authLoading } = useAuth();
    const [state, setState] = useState({ status: 'loading', data: null, error: null });

    useEffect(() => {
        if (authLoading) {
            return;
        }

        if (!user) {
            const next = encodeURIComponent(`/b/${token}`);
            window.location.href = `/login?next=${next}`;
            return;
        }

        let cancelled = false;
        setState({ status: 'loading', data: null, error: null });

        api.get(`/crm/briefings/shared/${token}`)
            .then(({ data }) => {
                if (!cancelled) {
                    setState({ status: 'ready', data, error: null });
                }
            })
            .catch((err) => {
                if (cancelled) {
                    return;
                }
                const status = err.response?.status;
                const message = status === 410
                    ? 'This briefing link has expired.'
                    : status === 403
                        ? 'This briefing was sent to a different person. Sign in with the account it was addressed to.'
                        : status === 404
                            ? 'We could not find that briefing.'
                            : (err.response?.data?.message || 'Unable to load this briefing.');
                setState({ status: 'error', data: null, error: message });
            });

        return () => {
            cancelled = true;
        };
    }, [token, user, authLoading]);

    if (authLoading || state.status === 'loading') {
        return (
            <Shell>
                <AiStateBlock variant="loading" message="Loading your weekly briefing…" />
            </Shell>
        );
    }

    if (state.status === 'error') {
        return (
            <Shell>
                <AiStateBlock variant="error" message={state.error} />
            </Shell>
        );
    }

    const { data } = state;
    const body = data.body || {};
    const periodFrom = data.period?.period_start ? data.period.period_start.slice(0, 10) : null;
    const periodTo = data.period?.period_end ? data.period.period_end.slice(0, 10) : null;

    return (
        <Shell>
            <div className="space-y-6">
                <header className="border-b border-slate-200 pb-4">
                    <p className="text-xs font-semibold uppercase tracking-wide text-teal-600">
                        {data.audience === 'ceo' ? 'Executive briefing' : 'Sales briefing'}
                        {data.scope?.org_wide === false && data.scope?.platform_ids?.length
                            ? ` · ${data.scope.platform_ids.length} market${data.scope.platform_ids.length > 1 ? 's' : ''}`
                            : ' · All markets'}
                    </p>
                    <h1 className="mt-1 text-xl font-bold text-slate-900">
                        {body.headline || 'Weekly performance briefing'}
                    </h1>
                    {periodFrom && periodTo ? (
                        <p className="mt-1 text-sm text-slate-500">{periodFrom} → {periodTo}</p>
                    ) : null}
                </header>

                {Array.isArray(body.highlights) && body.highlights.length ? (
                    <section>
                        <h2 className="mb-2 text-sm font-semibold text-slate-700">Highlights</h2>
                        <ul className="space-y-1.5">
                            {body.highlights.map((item, i) => (
                                <li key={i} className="flex gap-2 text-sm text-slate-700">
                                    <span className="mt-1.5 h-1.5 w-1.5 shrink-0 rounded-full bg-teal-500" />
                                    <span>{item}</span>
                                </li>
                            ))}
                        </ul>
                    </section>
                ) : null}

                {Array.isArray(body.watch_items) && body.watch_items.length ? (
                    <section>
                        <h2 className="mb-2 text-sm font-semibold text-amber-700">Watch items</h2>
                        <ul className="space-y-1.5">
                            {body.watch_items.map((item, i) => (
                                <li key={i} className="flex gap-2 text-sm text-amber-800">
                                    <span className="mt-1.5 h-1.5 w-1.5 shrink-0 rounded-full bg-amber-500" />
                                    <span>{item}</span>
                                </li>
                            ))}
                        </ul>
                    </section>
                ) : null}

                {body.narrative ? (
                    <section>
                        <h2 className="mb-2 text-sm font-semibold text-slate-700">Summary</h2>
                        <p className="text-sm leading-relaxed text-slate-700">{body.narrative}</p>
                    </section>
                ) : null}

                {data.summary_sms ? (
                    <footer className="rounded-lg bg-slate-50 px-4 py-3 text-xs text-slate-500">
                        SMS sent: “{data.summary_sms}”
                    </footer>
                ) : null}
            </div>
        </Shell>
    );
}

function Shell({ children }) {
    return (
        <div className="min-h-screen bg-slate-100 py-8">
            <div className="mx-auto max-w-2xl rounded-xl bg-white p-6 shadow-sm">
                {children}
            </div>
        </div>
    );
}
