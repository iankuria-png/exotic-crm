import React, { useEffect, useState } from 'react';
import { compactNumber, copyText } from './visitorFormat';
import './visitorDemand.css';

const PAGE_SIZE = 8;
const number = (value) => Number(value || 0);
const hasHistory = (row) => Boolean(row) && row.coverage !== 'unavailable';
const hasCoverageGap = (country) => (country.sites || []).some((site) => !hasHistory(site));
const observedValue = (row, key) => hasHistory(row) ? compactNumber(row?.[key]) : 'Unavailable';
const matches = (value, query) => String(value || '').toLocaleLowerCase().includes(query.trim().toLocaleLowerCase());

function Empty({ title, children }) {
    return <div className="vd-empty"><strong>{title}</strong><p>{children}</p></div>;
}

function Pager({ page, count, onChange, label }) {
    const pages = Math.ceil(count / PAGE_SIZE);
    if (pages < 2) return null;
    return <nav className="vd-pager" aria-label={`${label} pages`}>
        <span>{(page - 1) * PAGE_SIZE + 1}–{Math.min(page * PAGE_SIZE, count)} of {count}</span>
        <div><button type="button" disabled={page === 1} onClick={() => onChange(page - 1)} aria-label={`Previous ${label}`}>←</button><span>{page} / {pages}</span><button type="button" disabled={page === pages} onClick={() => onChange(page + 1)} aria-label={`Next ${label}`}>→</button></div>
    </nav>;
}

function Search({ label, value, onChange }) {
    return <label className="vd-search"><svg aria-hidden="true" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="1.7"><circle cx="10.5" cy="10.5" r="6.5" /><path d="m16 16 4 4" /></svg><input type="search" aria-label={label} placeholder={label} value={value} onChange={(event) => onChange(event.target.value)} /></label>;
}

export default function VisitorAttemptDemand({ demand, isLoading, toast }) {
    const [marketSearch, setMarketSearch] = useState('');
    const [mobileView, setMobileView] = useState('markets');
    const [citySearch, setCitySearch] = useState('');
    const [coverageFilter, setCoverageFilter] = useState('history');
    const [marketPage, setMarketPage] = useState(1);
    const [cityPage, setCityPage] = useState(1);
    const totals = demand?.totals || {};
    const countries = demand?.countries || [];
    const locations = demand?.profile_locations || [];
    const meta = demand?.profile_location_meta || {};
    const historyCount = countries.filter(hasHistory).length;
    const gapCount = countries.filter(hasCoverageGap).length;
    const sorted = [...countries].sort((a, b) => Number(hasHistory(b)) - Number(hasHistory(a)) || number(b.recorded_attempts) - number(a.recorded_attempts) || a.country.localeCompare(b.country));
    const filteredMarkets = sorted.filter((country) => matches([country.country, ...(country.sites || []).map((site) => site.platform_name)].join(' '), marketSearch)
        && (coverageFilter === 'all' || (coverageFilter === 'history' ? hasHistory(country) : hasCoverageGap(country))));
    const sortedCities = [...locations].sort((a, b) => number(b.recorded_attempts) - number(a.recorded_attempts) || a.city.localeCompare(b.city));
    const filteredCities = sortedCities.filter((row) => matches(`${row.city} ${row.country} ${row.platform_name}`, citySearch));
    const maxMarket = Math.max(1, ...countries.filter(hasHistory).map((row) => number(row.recorded_attempts)));
    const maxCity = Math.max(1, ...locations.map((row) => number(row.recorded_attempts)));
    const safeMarketPage = Math.min(marketPage, Math.max(1, Math.ceil(filteredMarkets.length / PAGE_SIZE)));
    const safeCityPage = Math.min(cityPage, Math.max(1, Math.ceil(filteredCities.length / PAGE_SIZE)));
    const scopeKey = countries.map((country) => `${country.country}:${(country.sites || []).map((site) => site.platform_id).join(',')}`).join('|');
    useEffect(() => { setMarketSearch(''); setCitySearch(''); setMarketPage(1); setCityPage(1); }, [scopeKey]);

    const download = () => {
        const rows = [
            ['Country', 'Website', 'Checkout setting', 'Recorded attempts', 'Site sessions', 'Coverage', 'Attempt definition', 'Session definition', 'Coverage note'],
            ...countries.flatMap((country) => (country.sites || []).map((site) => [country.country, site.platform_name, site.checkout_configured ? 'Configured' : 'Disabled',
                site.coverage === 'unavailable' ? 'Unavailable' : site.recorded_attempts,
                site.coverage === 'unavailable' ? 'Unavailable' : site.site_sessions, site.coverage,
                demand?.definition, demand?.session_definition, demand?.coverage_note])),
        ];
        const csv = rows.map((row) => row.map((value) => `"${String(value ?? '').replace(/^[=+\-@\t\r]/, (prefix) => `'${prefix}`).replaceAll('"', '""')}"`).join(',')).join('\n');
        const url = URL.createObjectURL(new Blob([csv], { type: 'text/csv;charset=utf-8;' }));
        const link = document.createElement('a');
        link.href = url;
        link.download = 'contact-unlock-demand.csv';
        link.click();
        setTimeout(() => URL.revokeObjectURL(url), 1000);
    };

    if (isLoading) return <div className="vd-workspace" role="status" aria-live="polite"><div className="vd-loading"><span className="vd-eyebrow">Contact unlock demand</span><h3>Loading recorded demand…</h3><p>Gathering market, website and profile-city signals.</p><div className="vd-skeleton" /><div className="vd-skeleton" /></div></div>;
    if (!demand) return <div className="vd-workspace"><Empty title="Demand data is unavailable">Refresh this report to retrieve recorded demand.</Empty></div>;

    return <div className="vd-workspace">
        <section className="vd-summary" aria-label="Recorded demand summary">
            <div className="vd-summary-intro"><span className="vd-eyebrow">Contact unlock demand</span><h3>Where visitors want to connect</h3><p>Recorded interest across your selected markets, including sites with checkout off.</p></div>
            <div className="vd-stats">
                <div className="vd-stat vd-stat-primary"><span>Unlock attempts</span><strong>{observedValue(totals, 'recorded_attempts')}</strong><small>Repeat clicks included</small></div>
                <div className="vd-stat"><span>Browser sessions</span><strong>{observedValue(totals, 'site_sessions')}</strong><small>Counted separately per website</small></div>
                <div className="vd-stat"><span>Sites with history</span><strong>{compactNumber(totals.sites_with_event_history)}<em> / {compactNumber(totals.sites)}</em></strong><small>Historical events; continuity unverified</small></div>
            </div>
            {number(totals.sites) > number(totals.sites_with_event_history) && <div className="vd-coverage-note"><span aria-hidden="true">◌</span><p><strong>{compactNumber(number(totals.sites) - number(totals.sites_with_event_history))} sites have no event history.</strong> Missing tracking does not mean zero interest.</p><button type="button" onClick={() => { setCoverageFilter('missing'); setMarketSearch(''); setMarketPage(1); setMobileView('markets'); }}>View coverage gaps <span aria-hidden="true">↗</span></button></div>}
        </section>

        <div className="vd-mobile-switch" role="group" aria-label="Demand view"><button type="button" aria-pressed={mobileView === 'markets'} onClick={() => setMobileView('markets')}>Markets <span>{countries.length}</span></button><button type="button" aria-pressed={mobileView === 'cities'} onClick={() => setMobileView('cities')}>Profile cities <span>{locations.length}</span></button></div>
        <div className="vd-explore" data-mobile-view={mobileView}>
            <section className="vd-panel" aria-labelledby="vd-markets-title">
                <header className="vd-panel-heading"><div><span className="vd-eyebrow">Market signals</span><h3 id="vd-markets-title">Markets by demand</h3><p>Ranked by recorded unlock attempts.</p></div><button type="button" className="vd-action" onClick={download} disabled={!countries.length} title="Exports all websites in the current country, website and date selection">Export all CSV <span aria-hidden="true">↓</span></button></header>
                <div className="vd-tools"><Search label="Search markets or websites" value={marketSearch} onChange={(value) => { setMarketSearch(value); setMarketPage(1); }} /><div className="vd-segments" role="group" aria-label="Market coverage filter">{[['history', 'With history', historyCount], ['all', 'All', countries.length], ['missing', 'Coverage gaps', gapCount]].map(([key, label, count]) => <button key={key} type="button" aria-pressed={coverageFilter === key} onClick={() => { setCoverageFilter(key); setMarketPage(1); }}>{label}<span>{count}</span></button>)}</div></div>
                <div className="vd-list-caption"><span>{coverageFilter === 'history' ? 'Countries with any event history' : coverageFilter === 'missing' ? 'Countries with missing website history' : 'All selected countries'}</span><span>Attempts / sessions</span></div>
                <div className="vd-market-list" key={`${scopeKey}-${coverageFilter}-${safeMarketPage}-${marketSearch}`}>
                    {filteredMarkets.slice((safeMarketPage - 1) * PAGE_SIZE, safeMarketPage * PAGE_SIZE).map((country) => <details className="vd-market" key={country.country}>
                        <summary><span className="vd-market-label"><strong>{country.country}</strong><small>{country.sites_with_event_history} / {(country.sites || []).length} sites with history{hasHistory(country) && number(country.sites_with_event_history) < (country.sites || []).length ? ' · Partial' : ''}</small></span><span className="vd-row-numbers"><strong>{observedValue(country, 'recorded_attempts')}</strong><small>{hasHistory(country) ? `${compactNumber(country.site_sessions)} sessions` : 'No event history'}</small></span><span className="vd-chevron" aria-hidden="true">⌄</span>{hasHistory(country) && <span className="vd-track" aria-hidden="true"><span style={{ width: `${number(country.recorded_attempts) / maxMarket * 100}%` }} /></span>}</summary>
                        <div className="vd-site-list">{(country.sites || []).map((site) => <div className="vd-site" key={site.platform_id}><div><strong>{site.platform_name}</strong><p><span className={hasHistory(site) ? 'vd-status' : 'vd-status vd-status-missing'}>{hasHistory(site) ? 'Event history' : 'No history'}</span><span>Checkout {site.checkout_configured ? 'on' : 'off'}{site.site_active ? '' : ' · Inactive site'}</span></p></div><div className="vd-site-count"><strong>{observedValue(site, 'recorded_attempts')}</strong><small>{hasHistory(site) ? `${compactNumber(site.site_sessions)} sessions` : 'Tracking unavailable'}</small></div></div>)}</div>
                    </details>)}
                    {!filteredMarkets.length && <Empty title={marketSearch ? 'No matching markets' : coverageFilter === 'history' ? 'No event history in this selection' : coverageFilter === 'missing' ? 'All selected websites have event history' : 'No sites in this selection'}>{marketSearch ? 'Try another country or website name.' : coverageFilter === 'history' ? 'Choose All or Coverage gaps to inspect your websites. Missing collection does not establish zero interest.' : 'Choose another coverage filter or country.'}</Empty>}
                </div>
                <Pager page={safeMarketPage} count={filteredMarkets.length} onChange={setMarketPage} label="markets" />
                <footer className="vd-panel-footer">Expand a country for website counts and checkout settings. Export includes all selected websites, including those hidden by search or coverage filters.</footer>
            </section>

            <section className="vd-panel" aria-labelledby="vd-cities-title">
                <header className="vd-panel-heading"><div><span className="vd-eyebrow">Requested locations</span><h3 id="vd-cities-title">Profile cities requested</h3><p>The cities of profiles visitors tried to contact.</p></div><button type="button" className="vd-action" disabled={!locations.length} onClick={() => copyText(locations.map((row) => `${row.country} / ${row.platform_name} / ${row.city}: ${row.recorded_attempts}`).join('\n'), toast)} title="Copies all returned cities in the current selection">Copy all cities</button></header>
                <div className="vd-tools"><Search label="Search cities, countries or websites" value={citySearch} onChange={(value) => { setCitySearch(value); setCityPage(1); }} /><p className="vd-city-context">{locations.length} returned locations{meta.total !== undefined ? ` of ${compactNumber(meta.total)}` : ''} · Ranked by attempts</p></div>
                <div className="vd-list-caption"><span>Profile city / website</span><span>Attempts</span></div>
                <ol className="vd-city-list" start={(safeCityPage - 1) * PAGE_SIZE + 1}>{filteredCities.slice((safeCityPage - 1) * PAGE_SIZE, safeCityPage * PAGE_SIZE).map((row, index) => <li className="vd-city" key={`${row.platform_id}-${row.city}-${index}`}><span className="vd-rank" aria-hidden="true">{String((safeCityPage - 1) * PAGE_SIZE + index + 1).padStart(2, '0')}</span><div className="vd-city-label"><strong>{row.city}</strong><small>{row.country}{row.platform_name && row.platform_name !== row.country ? ` · ${row.platform_name}` : ''}</small><div className="vd-city-track" aria-hidden="true"><span style={{ width: `${number(row.recorded_attempts) / maxCity * 100}%` }} /></div></div><strong className="vd-city-count">{compactNumber(row.recorded_attempts)}</strong></li>)}</ol>
                {!filteredCities.length && <Empty title={citySearch ? 'No matching profile cities' : 'No requested cities recorded'}>{citySearch ? 'Try a different city, country or website name.' : 'No attempt events were returned for this period. Collection may be missing; this does not establish zero interest.'}</Empty>}
                <Pager page={safeCityPage} count={filteredCities.length} onChange={setCityPage} label="cities" />
                <footer className="vd-panel-footer">{meta.truncated ? `Showing the top ${locations.length} of ${compactNumber(meta.total)} locations. ` : ''}Cities use each profile’s current cached location, rather than the visitor’s location. Bars compare counts; they do not represent a share of all demand. Copy includes all returned cities.</footer>
            </section>
        </div>
        <details className="vd-method"><summary>How to read this report <span aria-hidden="true">＋</span></summary><div><p><strong>Attempts.</strong> {demand.definition || 'Clicks to open contact unlock, including repeat clicks.'}</p><p><strong>Sessions.</strong> {demand.session_definition || 'Counted per website; not unique people across websites.'}</p><p><strong>Coverage.</strong> {demand.coverage_note} With history includes sites with past events even when this period has no attempts.</p><p><strong>Checkout.</strong> Settings show whether checkout is configured. They do not establish payment-provider readiness.</p></div></details>
    </div>;
}
