import React, { useState } from 'react';
import { CashChange, CashState, cashMoney, cashWindow } from './ProductRevenueView';
import { getCountryFlag } from '../../utils/flags';

export default function MarketCashFocus({ data, loading, error, onRetry, currencyMode, renderDetail }) {
    const [expanded, setExpanded] = useState(null);
    const [website, setWebsite] = useState(null);
    const [search, setSearch] = useState('');
    const [showAll, setShowAll] = useState(false);
    if (loading || error || !data) return <CashState loading={loading} error={error} onRetry={onRetry} />;
    const markets = (data.markets || []).filter((m) => `${m.country} ${m.platforms.map((p) => p.name).join(' ')}`.toLowerCase().includes(search.toLowerCase()));
    const shown = showAll ? markets : markets.slice(0, 6);
    const leader = Math.max(...markets.map((m) => m.normalized_total ?? 0), 0);
    return <div>
        <div className="cash-focus-toolbar"><p className="cash-muted">All-product cash · {data.window?.days === 30 ? 'Last 30 days' : 'Selected period'}{data.window?.partial ? ' · in progress' : ''}</p><input aria-label="Find a market or website" placeholder="Find a market or website" value={search} onChange={(e) => setSearch(e.target.value)} /></div>
        <p className="cash-muted mb-4">{cashWindow(data.window)} vs {cashWindow(data.prior_window)} · equal elapsed time.</p>
        {shown.length === 0 && <p className="cash-muted py-8">No markets match this scope.</p>}
        {shown.map((market, index) => <article key={market.key} className="cash-focus">
            <button type="button" className="cash-focus-button" aria-expanded={expanded === market.key} aria-controls={`cash-market-${market.key.replaceAll(/[^a-z0-9]/g, '-')}`} onClick={() => setExpanded(expanded === market.key ? null : market.key)}>
                <div className="cash-focus-main"><div><div className="cash-focus-country"><span className="cash-rank">{String(index + 1).padStart(2, '0')}</span><span aria-hidden="true">{getCountryFlag(market.country)}</span>{market.country}<span className="cash-chevron" aria-hidden="true" style={{ display: 'inline-block', transform: expanded === market.key ? 'rotate(180deg)' : '', transition: 'transform 140ms' }}>⌄</span></div><p className="cash-muted ml-[27px] mt-1">{market.platforms.length} {market.platforms.length === 1 ? 'website' : 'websites'} · {market.payments_count.toLocaleString()} cash payments</p></div><div className="cash-focus-amount">{cashMoney(market, currencyMode)}<div className="mt-1"><CashChange value={market.change_percent} /></div></div></div>
                <div className="cash-focus-periods"><span>Today <strong>{cashMoney(market.today, currencyMode)}</strong></span><span>This week <strong>{cashMoney(market.week, currencyMode)}</strong></span></div>
                {market.normalized_total != null && leader > 0 && <div className="cash-rank-bar"><span style={{ width: `${100 * market.normalized_total / leader}%` }} /></div>}
            </button>
            <div id={`cash-market-${market.key.replaceAll(/[^a-z0-9]/g, '-')}`} hidden={expanded !== market.key} className="cash-focus-sites">
                {market.platforms.map((site) => <div className="cash-site" key={site.platform_id}>
                    <div className="cash-site-heading"><span>{site.name}</span><span>{cashMoney(site, currencyMode)}</span></div>
                    <p className="cash-muted">Original money · {cashMoney(site, 'native')}</p>
                    <p className="cash-muted mt-1">Today {cashMoney(site.today, currencyMode)} · This week {cashMoney(site.week, currencyMode)}</p>
                    <div className="mt-2"><CashChange value={site.change_percent} /> <span className="cash-muted">vs matched prior</span></div>
                    {site.targets?.length ? site.targets.map((goal) => <p key={goal.period} className="cash-site-target">{goal.period === 'weekly' ? 'This calendar week' : 'This calendar month'} target: {cashMoney(goal.actual)} / {goal.target_currency} {Number(goal.target).toLocaleString()} · {goal.percentage == null ? 'FX unavailable' : `${goal.percentage}% attained`}{goal.source === 'agent_allocations' ? ' · assigned agent goals' : ''}</p>) : <p className="cash-site-target">No target configured in {data.selected.normalized_currency}.</p>}
                    <button type="button" className="cash-site-button" aria-expanded={website === site.platform_id} onClick={() => setWebsite(website === site.platform_id ? null : site.platform_id)}>{website === site.platform_id ? 'Close' : 'Open'} website performance →</button>
                    {website === site.platform_id && <div className="mt-4">{renderDetail(site)}</div>}
                </div>)}
            </div>
        </article>)}
        {markets.length > 6 && <button type="button" className="cash-export mt-4" onClick={() => setShowAll(!showAll)}>{showAll ? 'Show top 6 markets' : `Show all ${markets.length} markets`}</button>}
        <p className="cash-muted mt-4">Bars show cash relative to the leading country. Targets use their own calendar cadence; attainment can exceed 100%.</p>
    </div>;
}
