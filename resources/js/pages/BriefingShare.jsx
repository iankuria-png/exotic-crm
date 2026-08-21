import React, { useEffect, useMemo, useState } from 'react';
import { useParams } from 'react-router-dom';
import api from '../services/api';
import { useAuth } from '../hooks/useAuth';
import AiStateBlock from '../components/ai/AiStateBlock';
import WeeklyPrioritiesPanel from '../components/dashboard/WeeklyPrioritiesPanel';
import { useToast } from '../components/ToastProvider';
import { copyToClipboard } from '../utils/clipboard';

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
                <AiStateBlock variant="loading" message="Loading your weekly briefing..." />
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

    return (
        <Shell>
            <ExecutiveBriefing data={state.data} token={token} user={user} />
        </Shell>
    );
}

function ExecutiveBriefing({ data, token, user }) {
    const body = data.body || {};
    const isV2 = body.version === 'executive_scorecard_v2' || body.version === 'executive_scorecard_v3' || Array.isArray(body.scorecards);
    const period = body.period || data.period || {};
    const scopeLabel = data.scope?.org_wide === false && data.scope?.platform_ids?.length
        ? `${data.scope.platform_ids.length} market${data.scope.platform_ids.length > 1 ? 's' : ''}`
        : 'All markets';
    const toast = useToast();
    const canCreatePriorities = Boolean(user?.is_ceo || ['admin', 'sub_admin'].includes(user?.role));
    const decisionSeed = body.executive_summary?.decision_points?.[0] || '';
    const fallbackMarkets = useMemo(() => briefingMarketOptions(body), [body]);
    const [marketOptions, setMarketOptions] = useState(fallbackMarkets);
    const markdown = useMemo(() => buildBriefingMarkdown(data), [data]);

    useEffect(() => {
        let cancelled = false;

        api.get('/platforms')
            .then(({ data: platformData }) => {
                if (cancelled) return;
                const fetched = Array.isArray(platformData?.platforms) ? platformData.platforms : [];
                const scopedIds = Array.isArray(data.scope?.platform_ids)
                    ? data.scope.platform_ids.map((id) => Number(id)).filter(Boolean)
                    : [];
                const scoped = scopedIds.length
                    ? fetched.filter((market) => scopedIds.includes(Number(market.id || market.platform_id)))
                    : fetched;
                setMarketOptions(scoped.length ? scoped : fallbackMarkets);
            })
            .catch(() => {
                if (!cancelled) {
                    setMarketOptions(fallbackMarkets);
                }
            });

        return () => {
            cancelled = true;
        };
    }, [data.scope?.platform_ids, fallbackMarkets]);

    const handlePrintPdf = () => {
        window.print();
    };

    const handleCopyMarkdown = async () => {
        try {
            await copyToClipboard(markdown);
            toast.success('Markdown copied for AI export.');
        } catch (error) {
            toast.error('Clipboard blocked. Select and copy the report manually.');
        }
    };

    if (!isV2) {
        return <LegacyBriefing data={data} />;
    }

    return (
        <article className="briefing-print-root mx-auto w-full max-w-7xl px-4 py-6 sm:px-6 lg:px-8">
            <header className="mb-6 border-b border-slate-200 pb-5">
                <div className="flex flex-wrap items-start justify-between gap-3">
                    <div className="flex flex-wrap items-center gap-2 text-xs font-semibold text-slate-500">
                        <span className="rounded-md bg-teal-50 px-2.5 py-1 text-teal-700">
                            {data.audience === 'ceo' ? 'Executive scorecard' : 'Sales scorecard'}
                        </span>
                        <span>{scopeLabel}</span>
                        {period.label ? <span>{period.label}</span> : null}
                        {period.display ? <span>{period.display}</span> : null}
                    </div>
                    <div className="briefing-no-print flex flex-wrap items-center gap-2">
                        <button
                            type="button"
                            onClick={handlePrintPdf}
                            className="rounded-md border border-slate-300 bg-white px-3 py-2 text-xs font-semibold text-slate-700 shadow-sm transition hover:bg-slate-50 active:scale-[0.98] focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-slate-400"
                        >
                            Generate PDF
                        </button>
                        <button
                            type="button"
                            onClick={handleCopyMarkdown}
                            className="rounded-md bg-slate-950 px-3 py-2 text-xs font-semibold text-white shadow-sm transition hover:bg-slate-800 active:scale-[0.98] focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-slate-500"
                        >
                            Copy markdown
                        </button>
                    </div>
                </div>
                <div className="mt-4 grid gap-4 lg:grid-cols-[minmax(0,1fr)_18rem] lg:items-end">
                    <div>
                        <h1 className="max-w-4xl text-balance text-3xl font-semibold leading-tight text-slate-950 sm:text-4xl">
                            {body.headline || 'Weekly executive scorecard'}
                        </h1>
                        {period.prior_label || period.prior_display ? (
                            <p className="mt-2 text-sm text-slate-600">
                                Compared with {period.prior_label || 'prior week'}
                                {period.prior_display ? ` (${period.prior_display})` : ''}
                            </p>
                        ) : null}
                    </div>
                    {data.summary_sms ? (
                        <aside className="rounded-lg bg-slate-950 p-4 text-sm text-slate-100 shadow-sm">
                            <p className="text-[11px] font-semibold uppercase tracking-[0.18em] text-teal-300">SMS digest</p>
                            <p className="mt-2 leading-relaxed">{data.summary_sms}</p>
                        </aside>
                    ) : null}
                </div>
            </header>

            <nav className="briefing-no-print mb-6 flex flex-wrap gap-2 rounded-xl border border-slate-200 bg-white p-2 shadow-sm">
                {[
                    ['#readout', 'Readout'],
                    ['#movement', 'Customers'],
                    ['#markets', 'Markets'],
                    ['#recovery', 'Recovery'],
                    ['#priorities', 'Priorities'],
                    ['#quality', 'Data quality'],
                ].map(([href, label]) => (
                    <a
                        key={href}
                        href={href}
                        className="rounded-md px-3 py-2 text-sm font-semibold text-slate-600 transition hover:bg-slate-50 hover:text-slate-950"
                    >
                        {label}
                    </a>
                ))}
            </nav>

            <ScorecardGrid scorecards={body.scorecards || []} />

            <div id="readout" className="mt-6 grid scroll-mt-6 gap-6 xl:grid-cols-[minmax(0,1.1fr)_minmax(22rem,0.9fr)]">
                <ExecutiveSummary summary={body.executive_summary} narrative={body.narrative} />
                <div id="movement" className="scroll-mt-6">
                    <CustomerMovement movement={body.customer_movement} />
                </div>
            </div>

            <div className="mt-6 grid gap-6 xl:grid-cols-2">
                <div id="markets" className="scroll-mt-6">
                    <MarketMovement movement={body.market_movement} />
                </div>
                <div id="recovery" className="scroll-mt-6">
                    <PaymentRecovery recovery={body.payment_recovery} />
                </div>
            </div>

            <div id="priorities" className="mt-6 scroll-mt-6">
                <WeeklyPrioritiesPanel
                    title="CEO Weekly Priorities"
                    subtitle="Turn the briefing into visible operating priorities for this week."
                    audience={data.audience === 'sales' ? 'sales' : 'all'}
                    allowCreate={canCreatePriorities}
                    createEndpoint={`/crm/briefings/shared/${token}/priorities`}
                    sourceSuggestion={decisionSeed}
                    markets={marketOptions}
                />
            </div>

            <div id="quality" className="mt-6 grid scroll-mt-6 gap-6 xl:grid-cols-[minmax(20rem,0.8fr)_minmax(0,1.2fr)]">
                <TeamExecution execution={body.team_execution} />
                <DataQuality quality={body.data_quality} generatedAt={data.generated_at} />
            </div>
        </article>
    );
}

function briefingMarketOptions(body = {}) {
    const seen = new Map();
    const addMarket = (market) => {
        const id = Number(market?.id || market?.platform_id);
        const name = market?.name || market?.platform_name || market?.market;

        if (!id || !name || seen.has(id)) {
            return;
        }

        seen.set(id, {
            id,
            name,
            country: market.country || market.code || null,
        });
    };

    [
        ...(body.market_movement?.growing || []),
        ...(body.market_movement?.declining || []),
        body.market_movement?.concentration?.top_market,
    ].forEach(addMarket);

    return Array.from(seen.values()).sort((a, b) => String(a.name).localeCompare(String(b.name)));
}

function buildBriefingMarkdown(data = {}) {
    const body = data.body || {};
    const period = body.period || data.period || {};
    const scopeLabel = data.scope?.org_wide === false && data.scope?.platform_ids?.length
        ? `${data.scope.platform_ids.length} market${data.scope.platform_ids.length > 1 ? 's' : ''}`
        : 'All markets';
    const lines = [];

    lines.push(`# ${markdownText(body.headline || 'Weekly executive scorecard')}`);
    lines.push('');
    lines.push(`- Audience: ${data.audience === 'ceo' ? 'Executive scorecard' : 'Sales scorecard'}`);
    lines.push(`- Scope: ${scopeLabel}`);
    if (period.label || period.display) {
        lines.push(`- Period: ${markdownText([period.label, period.display].filter(Boolean).join(' - '))}`);
    }
    if (period.prior_label || period.prior_display) {
        lines.push(`- Comparison: ${markdownText([period.prior_label, period.prior_display].filter(Boolean).join(' - '))}`);
    }
    lines.push(`- Generated: ${formatDateTime(data.generated_at)}`);

    if (data.summary_sms) {
        lines.push('');
        lines.push('## SMS Digest');
        lines.push(markdownText(data.summary_sms));
    }

    if (Array.isArray(body.scorecards) && body.scorecards.length) {
        lines.push('');
        lines.push('## Scorecard');
        lines.push('| Metric | Current | Previous | Change |');
        lines.push('| --- | ---: | ---: | ---: |');
        body.scorecards.forEach((card) => {
            const previous = card.prior === null || card.prior === undefined ? 'No baseline' : formatMetric(card.prior, card);
            const delta = card.delta_percent === null || card.delta_percent === undefined ? 'New' : `${card.delta_percent > 0 ? '+' : ''}${formatCompact(card.delta_percent)}%`;
            lines.push(`| ${markdownTableCell(card.label)} | ${markdownTableCell(formatMetric(card.current, card))} | ${markdownTableCell(previous)} | ${markdownTableCell(delta)} |`);
        });
    }

    if (body.narrative) {
        lines.push('');
        lines.push('## CEO Readout');
        lines.push(markdownText(body.narrative));
    }

    appendMarkdownBullets(lines, 'What Changed', body.executive_summary?.what_changed);
    appendMarkdownBullets(lines, 'Why It Matters', body.executive_summary?.why_it_matters);
    appendMarkdownBullets(lines, 'Decision Points', body.executive_summary?.decision_points);

    const movement = body.customer_movement || {};
    const snapshot = movement.active_subscribers_snapshot || {};
    lines.push('');
    lines.push('## Customer Movement');
    [
        ['Created profiles', movement.created_profiles],
        ['New paid customers', movement.new_paid_customers],
        ['Renewed profiles', movement.renewed_profiles],
        ['Churned profiles', movement.churned_profiles],
        ['Lost to churn', movement.lost_value_to_churn_display || formatMoneyValue(movement.lost_value_to_churn, movement.lost_value_to_churn_currency)],
        ['User snapshot', `${formatNumber(snapshot.current)} current vs ${formatNumber(snapshot.prior)} prior`],
        ['Renewals due', movement.renewals_due],
        ['Renewal payments', movement.renewal_payments],
        ['Net active movement', signedNumber(movement.net_active_movement)],
    ].forEach(([label, value]) => lines.push(`- ${label}: ${markdownText(value)}`));

    const markets = body.market_movement || {};
    lines.push('');
    lines.push('## Markets');
    appendMarketRows(lines, 'Growing', markets.growing || []);
    appendMarketRows(lines, 'Declining', markets.declining || []);
    if (markets.concentration?.top_market) {
        lines.push(`- Largest market: ${markdownText(markets.concentration.top_market.name)} at ${formatPercent(markets.concentration.top_market_share_percent)} of revenue`);
    }

    const recovery = body.payment_recovery || {};
    lines.push('');
    lines.push('## Payment Recovery');
    lines.push(`- Recovery rate: ${formatPercent(recovery.payment_recovery_rate)}`);
    lines.push(`- Recovered payments: ${formatNumber(recovery.recovered_payments)}`);
    lines.push(`- Failed payments: ${formatNumber(recovery.failed_payments)}`);
    lines.push(`- Recovered value: ${markdownText(formatRecoveryMoney(recovery, 'recovered_value'))}`);
    lines.push(`- Unrecovered value: ${markdownText(formatRecoveryMoney(recovery, 'lost_value'))}`);
    lines.push(`- Customers affected: ${formatNumber(recovery.failed_customers)}`);
    lines.push(`- Week-over-week movement: ${signedNumber(recovery.payment_recovery_rate_delta)} percentage points`);

    const execution = body.team_execution || {};
    lines.push('');
    lines.push('## Team Execution');
    lines.push(`- Active time: ${formatCompact(execution.active_hours)}h (${deltaText(execution.active_hours_delta_percent)} vs prior week)`);
    lines.push(`- Total actions: ${formatNumber(execution.total_actions)} (${deltaText(execution.total_actions_delta_percent)} vs prior week)`);
    lines.push(`- Actions per hour: ${formatCompact(execution.actions_per_hour)}`);
    lines.push(`- Active members: ${formatNumber(execution.active_members)}`);

    if (Array.isArray(execution.members) && execution.members.length) {
        lines.push('');
        lines.push('### Team Detail');
        lines.push('| Person | Revenue | Hours | Actions |');
        lines.push('| --- | ---: | ---: | ---: |');
        execution.members.forEach((member) => {
            lines.push(`| ${markdownTableCell(member.name)} | ${markdownTableCell(`${member.currency} ${formatCompact(member.revenue)} (${signedMoney(member.revenue_delta, member.currency)})`)} | ${markdownTableCell(`${formatCompact(member.active_hours)}h (${deltaText(member.active_hours_delta_percent)})`)} | ${markdownTableCell(`${formatNumber(member.actions)} (${deltaText(member.actions_delta_percent)})`)} |`);
        });
    }

    const quality = body.data_quality || {};
    const caveats = Array.isArray(quality.caveats) ? quality.caveats : [];
    lines.push('');
    lines.push('## Data Quality');
    lines.push(`- Confidence: ${markdownText(quality.confidence || 'medium')}`);
    lines.push(`- Freshness: ${markdownText(quality.freshness_label || formatDateTime(data.generated_at))}`);
    caveats.forEach((item) => lines.push(`- Caveat: ${markdownText(item)}`));

    return `${lines.join('\n')}\n`;
}

function appendMarkdownBullets(lines, title, items) {
    if (!Array.isArray(items) || !items.length) {
        return;
    }

    lines.push('');
    lines.push(`## ${title}`);
    items.forEach((item) => lines.push(`- ${markdownText(item)}`));
}

function appendMarketRows(lines, title, rows) {
    if (!Array.isArray(rows) || !rows.length) {
        return;
    }

    lines.push('');
    lines.push(`### ${title}`);
    rows.slice(0, 8).forEach((row) => {
        const delta = `${row.delta >= 0 ? '+' : '-'}${row.currency || ''} ${formatCompact(Math.abs(row.delta || 0))}`.trim();
        lines.push(`- ${markdownText(row.name)}: ${row.currency || ''} ${formatCompact(row.current)} (${delta})`.trim());
    });
}

function markdownText(value) {
    if (value === null || value === undefined || value === '') {
        return 'Not available';
    }

    return String(value).replace(/\s+/g, ' ').trim();
}

function markdownTableCell(value) {
    return markdownText(value).replace(/\|/g, '\\|');
}

function ScorecardGrid({ scorecards }) {
    if (!scorecards.length) {
        return null;
    }

    return (
        <section className="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
            {scorecards.map((card) => (
                <div key={card.key || card.label} className="rounded-lg border border-slate-200 bg-white p-4 shadow-sm">
                    <div className="flex items-start justify-between gap-3">
                        <p className="text-[11px] font-semibold uppercase tracking-[0.16em] text-slate-500">{card.label}</p>
                        <DeltaBadge card={card} />
                    </div>
                    <p className="mt-3 font-mono text-2xl font-semibold tracking-normal text-slate-950">
                        {formatMetric(card.current, card)}
                    </p>
                    <p className="mt-2 text-xs text-slate-500">
                        {card.prior === null || card.prior === undefined
                            ? 'No prior baseline'
                            : `${formatMetric(card.prior, card)} previous`}
                    </p>
                </div>
            ))}
        </section>
    );
}

function ExecutiveSummary({ summary = {}, narrative }) {
    const groups = [
        ['What changed', summary.what_changed || []],
        ['Why it matters', summary.why_it_matters || []],
        ['Decision points', summary.decision_points || []],
    ].filter(([, items]) => Array.isArray(items) && items.length);

    if (!groups.length && !narrative) {
        return null;
    }

    return (
        <section className="rounded-lg border border-slate-200 bg-white p-5 shadow-sm">
            <SectionTitle kicker="CEO readout" title="The week in operating terms" />
            {narrative ? (
                <div className="mt-3 max-w-4xl space-y-2 text-sm leading-6 text-slate-700">
                    {splitSentences(narrative).slice(0, 3).map((sentence, index) => (
                        <p key={index}>{highlightFacts(sentence)}</p>
                    ))}
                </div>
            ) : null}
            <div className="mt-5 grid gap-4 md:grid-cols-3">
                {groups.map(([title, items]) => (
                    <div key={title} className="rounded-lg border border-slate-100 bg-slate-50/70 p-3">
                        <h3 className="text-sm font-semibold text-slate-900">{title}</h3>
                        <ul className="mt-3 space-y-2.5">
                            {items.slice(0, 4).map((item, index) => (
                                <li key={`${title}-${index}`} className="flex gap-2 text-sm leading-5 text-slate-600">
                                    <span className="mt-2 h-1.5 w-1.5 shrink-0 rounded-full bg-teal-500" />
                                    <span>{highlightFacts(item)}</span>
                                </li>
                            ))}
                        </ul>
                    </div>
                ))}
            </div>
        </section>
    );
}

function CustomerMovement({ movement = {} }) {
    const snapshot = movement.active_subscribers_snapshot || {};
    const rows = [
        ['Created profiles', movement.created_profiles, movement.created_profiles_comparison],
        ['New paid customers', movement.new_paid_customers, movement.new_paid_customers_comparison],
        ['Renewed profiles', movement.renewed_profiles, movement.renewed_profiles_comparison],
        ['Churned profiles', movement.churned_profiles, movement.churned_profiles_comparison],
        ['Lost to churn', movement.lost_value_to_churn_display || formatMoneyValue(movement.lost_value_to_churn, movement.lost_value_to_churn_currency), null],
        ['User snapshot', formatNumber(snapshot.current), { delta_percent: snapshot.delta_percent }],
        ['Renewals due', movement.renewals_due, null],
        ['Renewal payments', movement.renewal_payments, null],
    ];

    return (
        <section className="rounded-lg border border-slate-200 bg-white p-5 shadow-sm">
            <SectionTitle kicker="Customer movement" title="Weekly profile flow" />
            <div className="mt-4 grid grid-cols-2 gap-3">
                {rows.map(([label, value, comparison]) => (
                    <MetricCell key={label} label={label} value={formatNumber(value)} comparison={comparison} />
                ))}
            </div>
            <div className="mt-4 rounded-md bg-slate-50 px-3 py-2 text-sm text-slate-700">
                Net active movement: <span className="font-semibold text-slate-950">{signedNumber(movement.net_active_movement)}</span>
                {movement.renewal_save_rate !== null && movement.renewal_save_rate !== undefined
                    ? ` - Renewal save rate ${formatPercent(movement.renewal_save_rate)}`
                    : ''}
                {snapshot.prior !== null && snapshot.prior !== undefined
                    ? ` - Active paid users ${formatNumber(snapshot.current)} vs ${formatNumber(snapshot.prior)}`
                    : ''}
            </div>
        </section>
    );
}

function MarketMovement({ movement = {} }) {
    return (
        <section className="rounded-lg border border-slate-200 bg-white p-5 shadow-sm">
            <SectionTitle kicker="Market portfolio" title="Growth and decline" />
            <div className="mt-4 grid gap-5 lg:grid-cols-2">
                <MarketTable title="Growing" rows={movement.growing || []} empty="No growing markets in this window." />
                <MarketTable title="Declining" rows={movement.declining || []} empty="No declining markets in this window." />
            </div>
            {movement.concentration?.top_market ? (
                <p className="mt-4 text-sm text-slate-600">
                    Largest market: <span className="font-semibold text-slate-900">{movement.concentration.top_market.name}</span>
                    {' '}at {formatPercent(movement.concentration.top_market_share_percent)} of revenue.
                </p>
            ) : null}
        </section>
    );
}

function MarketTable({ title, rows, empty }) {
    return (
        <div>
            <h3 className="text-sm font-semibold text-slate-900">{title}</h3>
            {rows.length ? (
                <div className="mt-2 overflow-x-auto">
                    <table className="min-w-full text-left text-sm">
                        <thead className="text-xs text-slate-500">
                            <tr>
                                <th className="py-2 pr-3 font-medium">Market</th>
                                <th className="py-2 pr-3 font-medium">Current</th>
                                <th className="py-2 pr-3 font-medium">Delta</th>
                            </tr>
                        </thead>
                        <tbody className="divide-y divide-slate-100">
                            {rows.slice(0, 5).map((row) => (
                                <tr key={`${title}-${row.platform_id || row.name}`}>
                                    <td className="py-2 pr-3 font-medium text-slate-900">{row.name}</td>
                                    <td className="py-2 pr-3 font-mono text-slate-700">{row.currency} {formatCompact(row.current)}</td>
                                    <td className={`py-2 pr-3 font-mono ${row.delta >= 0 ? 'text-emerald-700' : 'text-rose-700'}`}>
                                        {row.delta >= 0 ? '+' : '-'}{row.currency} {formatCompact(Math.abs(row.delta || 0))}
                                    </td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                </div>
            ) : (
                <p className="mt-2 rounded-md bg-slate-50 px-3 py-2 text-sm text-slate-500">{empty}</p>
            )}
        </div>
    );
}

function PaymentRecovery({ recovery = {} }) {
    return (
        <section className="rounded-lg border border-slate-200 bg-white p-5 shadow-sm">
            <SectionTitle kicker="Payment recovery" title="Failed payment follow-through" />
            <div className="mt-4 grid grid-cols-2 gap-3 lg:grid-cols-3">
                <MetricCell label="Recovery rate" value={formatPercent(recovery.payment_recovery_rate)} />
                <MetricCell label="Recovered payments" value={formatNumber(recovery.recovered_payments)} />
                <MetricCell label="Failed payments" value={formatNumber(recovery.failed_payments)} />
                <MetricCell label="Recovered value" value={formatRecoveryMoney(recovery, 'recovered_value')} />
                <MetricCell label="Unrecovered value" value={formatRecoveryMoney(recovery, 'lost_value')} />
                <MetricCell label="Customers affected" value={formatNumber(recovery.failed_customers)} />
            </div>
            <p className="mt-4 text-sm text-slate-600">
                Recovery moved {signedNumber(recovery.payment_recovery_rate_delta)} percentage points versus the prior week.
                {recovery.normalization_partial ? ' Some values are shown from native currency breakdowns because FX was partial.' : ''}
            </p>
        </section>
    );
}

function TeamExecution({ execution = {} }) {
    const members = Array.isArray(execution.members) ? execution.members : [];

    return (
        <section className="rounded-lg border border-slate-200 bg-white p-5 shadow-sm">
            <SectionTitle kicker="Team execution" title="Work rhythm" />
            <div className="mt-4 space-y-3">
                <MetricCell label="Active time" value={`${formatCompact(execution.active_hours)}h`} />
                <MetricCell label="Total actions" value={formatNumber(execution.total_actions)} />
                <MetricCell label="Actions per hour" value={formatCompact(execution.actions_per_hour)} />
                <MetricCell label="Active members" value={formatNumber(execution.active_members)} />
            </div>
            <p className="mt-4 text-sm text-slate-600">
                Active time was {deltaText(execution.active_hours_delta_percent)} and actions were {deltaText(execution.total_actions_delta_percent)} vs prior week.
            </p>
            {members.length ? (
                <div className="mt-5 overflow-x-auto rounded-lg border border-slate-100">
                    <table className="min-w-full text-left text-sm">
                        <thead className="bg-slate-50 text-xs uppercase tracking-[0.12em] text-slate-500">
                            <tr>
                                <th className="px-3 py-2 font-semibold">Person</th>
                                <th className="px-3 py-2 font-semibold">Revenue</th>
                                <th className="px-3 py-2 font-semibold">Hours</th>
                                <th className="px-3 py-2 font-semibold">Actions</th>
                            </tr>
                        </thead>
                        <tbody className="divide-y divide-slate-100">
                            {members.map((member) => (
                                <tr key={member.user_id}>
                                    <td className="px-3 py-3">
                                        <p className="font-semibold text-slate-900">{member.name}</p>
                                        <p className="text-xs text-slate-500">{member.role}</p>
                                    </td>
                                    <td className="px-3 py-3 font-mono text-slate-800">
                                        {member.currency} {formatCompact(member.revenue)}
                                        <span className={`ml-2 text-xs ${Number(member.revenue_delta || 0) >= 0 ? 'text-emerald-700' : 'text-rose-700'}`}>
                                            {signedMoney(member.revenue_delta, member.currency)}
                                        </span>
                                    </td>
                                    <td className="px-3 py-3 text-slate-700">
                                        {formatCompact(member.active_hours)}h
                                        <span className="ml-2 text-xs text-slate-500">{deltaText(member.active_hours_delta_percent)}</span>
                                    </td>
                                    <td className="px-3 py-3 text-slate-700">
                                        {formatNumber(member.actions)}
                                        <span className="ml-2 text-xs text-slate-500">{deltaText(member.actions_delta_percent)}</span>
                                    </td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                </div>
            ) : null}
        </section>
    );
}

function DataQuality({ quality = {}, generatedAt }) {
    const caveats = Array.isArray(quality.caveats) ? quality.caveats : [];

    return (
        <section className="rounded-lg border border-slate-200 bg-slate-50 p-5">
            <SectionTitle kicker="Data quality" title="Source notes" />
            <div className="mt-4 grid gap-3 sm:grid-cols-3">
                <MetricCell label="Confidence" value={quality.confidence || 'medium'} muted />
                <MetricCell label="Generated" value={quality.freshness_label || formatDateTime(generatedAt)} muted />
                <MetricCell label="Caveats" value={formatNumber(caveats.length)} muted />
            </div>
            {caveats.length ? (
                <ul className="mt-4 space-y-2 text-sm text-slate-600">
                    {caveats.map((item, index) => (
                        <li key={index}>{item}</li>
                    ))}
                </ul>
            ) : null}
        </section>
    );
}

function LegacyBriefing({ data }) {
    const body = data.body || {};
    const periodFrom = data.period?.period_start ? data.period.period_start.slice(0, 10) : null;
    const periodTo = data.period?.period_end ? data.period.period_end.slice(0, 10) : null;

    return (
        <article className="mx-auto w-full max-w-3xl px-4 py-8 sm:px-6">
            <div className="rounded-lg border border-slate-200 bg-white p-6 shadow-sm">
                <header className="border-b border-slate-200 pb-4">
                    <p className="text-xs font-semibold uppercase tracking-[0.16em] text-teal-700">
                        {data.audience === 'ceo' ? 'Executive briefing' : 'Sales briefing'}
                    </p>
                    <h1 className="mt-1 text-2xl font-semibold text-slate-950">
                        {body.headline || 'Weekly performance briefing'}
                    </h1>
                    {periodFrom && periodTo ? (
                        <p className="mt-1 text-sm text-slate-500">{periodFrom} to {periodTo}</p>
                    ) : null}
                </header>
                <BulletSection title="Highlights" items={body.highlights} />
                <BulletSection title="Watch items" items={body.watch_items} tone="amber" />
                {body.narrative ? <p className="mt-5 text-sm leading-6 text-slate-700">{body.narrative}</p> : null}
                {data.summary_sms ? (
                    <footer className="mt-5 rounded-md bg-slate-50 px-4 py-3 text-xs text-slate-500">
                        SMS sent: {data.summary_sms}
                    </footer>
                ) : null}
            </div>
        </article>
    );
}

function BulletSection({ title, items, tone = 'teal' }) {
    if (!Array.isArray(items) || !items.length) {
        return null;
    }

    const dot = tone === 'amber' ? 'bg-amber-500' : 'bg-teal-500';

    return (
        <section className="mt-5">
            <h2 className="mb-2 text-sm font-semibold text-slate-800">{title}</h2>
            <ul className="space-y-1.5">
                {items.map((item, index) => (
                    <li key={index} className="flex gap-2 text-sm text-slate-700">
                        <span className={`mt-1.5 h-1.5 w-1.5 shrink-0 rounded-full ${dot}`} />
                        <span>{item}</span>
                    </li>
                ))}
            </ul>
        </section>
    );
}

function SectionTitle({ kicker, title }) {
    return (
        <div>
            <p className="text-[11px] font-semibold uppercase tracking-[0.18em] text-teal-700">{kicker}</p>
            <h2 className="mt-1 text-xl font-semibold text-slate-950">{title}</h2>
        </div>
    );
}

function MetricCell({ label, value, comparison, muted = false }) {
    return (
        <div className={`rounded-md px-3 py-2 ${muted ? 'bg-white' : 'bg-slate-50'}`}>
            <p className="text-[11px] font-semibold uppercase tracking-[0.14em] text-slate-500">{label}</p>
            <p className="mt-1 font-mono text-lg font-semibold text-slate-950">{value ?? '0'}</p>
            {comparison?.delta_percent !== null && comparison?.delta_percent !== undefined ? (
                <p className="mt-1 text-xs text-slate-500">{deltaText(comparison.delta_percent)} vs prior week</p>
            ) : null}
        </div>
    );
}

function DeltaBadge({ card }) {
    if (card.delta_percent === null || card.delta_percent === undefined) {
        return <span className="rounded-md bg-slate-100 px-2 py-1 text-xs font-semibold text-slate-500">New</span>;
    }

    const tone = card.status === 'good'
        ? 'bg-emerald-50 text-emerald-700'
        : card.status === 'watch'
            ? 'bg-rose-50 text-rose-700'
            : 'bg-slate-100 text-slate-600';

    return (
        <span className={`rounded-md px-2 py-1 text-xs font-semibold ${tone}`}>
            {card.delta_percent > 0 ? '+' : ''}{formatCompact(card.delta_percent)}%
        </span>
    );
}

function Shell({ children }) {
    return (
        <main className="min-h-screen bg-[#eef2f5] text-slate-900">
            {children}
        </main>
    );
}

function formatMetric(value, card) {
    if (card.unit === 'money') {
        return `${card.currency || ''} ${formatCompact(value)}`.trim();
    }
    if (card.unit === 'percent') {
        return formatPercent(value);
    }
    if (card.unit === 'hours') {
        return `${formatCompact(value)}h`;
    }
    return formatNumber(value);
}

function formatNumber(value) {
    const number = Number(value || 0);
    return new Intl.NumberFormat('en-US', { maximumFractionDigits: 0 }).format(number);
}

function formatCompact(value) {
    const number = Number(value || 0);
    return new Intl.NumberFormat('en-US', { maximumFractionDigits: number >= 100 ? 0 : 1 }).format(number);
}

function formatPercent(value) {
    return `${formatCompact(value)}%`;
}

function formatRecoveryMoney(recovery, key) {
    const display = recovery?.[`${key}_display`];
    if (display) {
        return display;
    }

    const value = recovery?.[key];
    if (value !== null && value !== undefined) {
        return `${recovery.currency || ''} ${formatCompact(value)}`.trim();
    }

    const breakdown = recovery?.[`${key}_breakdown`] || {};
    const parts = Object.entries(breakdown)
        .filter(([, amount]) => Number(amount || 0) !== 0)
        .slice(0, 2)
        .map(([currency, amount]) => `${currency} ${formatCompact(amount)}`);

    return parts.length ? parts.join(' + ') : 'Not available';
}

function formatMoneyValue(value, currency = 'USD') {
    if (value === null || value === undefined) {
        return 'Not available';
    }

    return `${currency || ''} ${formatCompact(value)}`.trim();
}

function signedMoney(value, currency = 'USD') {
    const number = Number(value || 0);
    const sign = number > 0 ? '+' : number < 0 ? '-' : '';

    return `${sign}${currency || ''} ${formatCompact(Math.abs(number))}`.trim();
}

function signedNumber(value) {
    const number = Number(value || 0);
    return `${number > 0 ? '+' : ''}${formatCompact(number)}`;
}

function deltaText(value) {
    if (value === null || value === undefined) {
        return 'without a prior baseline';
    }

    const number = Number(value || 0);
    if (number === 0) {
        return 'flat';
    }

    return `${number > 0 ? 'up' : 'down'} ${formatCompact(Math.abs(number))}%`;
}

function formatDateTime(value) {
    if (!value) {
        return 'Not recorded';
    }

    return new Date(value).toLocaleString('en-GB', {
        day: '2-digit',
        month: 'short',
        year: 'numeric',
        hour: '2-digit',
        minute: '2-digit',
    });
}

function splitSentences(text) {
    return String(text || '')
        .split(/(?<=[.!?])\s+/)
        .map((item) => item.trim())
        .filter(Boolean);
}

function highlightFacts(text) {
    const pattern = /((?:USD|KES|TZS|GHS|NGN|ZMW|UGX|CDF|XOF|XAF|CFA)\s?[-+]?[0-9][0-9,]*(?:\.[0-9]+)?|[-+]?[0-9][0-9,]*(?:\.[0-9]+)?%|Week\s[0-9]+|[0-9][0-9,]*\s?(?:payments|profiles|customers|actions|hours|active hours))/gi;
    const isFact = new RegExp(pattern.source, 'i');
    const parts = String(text || '').split(pattern);

    return parts.map((part, index) => (
        isFact.test(part)
            ? <strong key={index} className="font-semibold text-slate-950">{part}</strong>
            : <React.Fragment key={index}>{part}</React.Fragment>
    ));
}
