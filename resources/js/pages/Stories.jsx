import React, { useCallback, useEffect, useMemo, useRef, useState } from 'react';
import { useSearchParams } from 'react-router-dom';
import { useQuery, useQueryClient } from '@tanstack/react-query';
import api from '../services/api';
import StoryReviewGrid from '../components/stories/StoryReviewGrid';
import HottestPanel from '../components/stories/HottestPanel';
import BrandStoriesPanel from '../components/stories/BrandStoriesPanel';
import StorySettingsPanel from '../components/stories/StorySettingsPanel';
import AdvertiserPicker from '../components/stories/AdvertiserPicker';
import StoryComposerModal from '../components/clients/StoryComposerModal';

// Market-wide Stories administration: everything wp-admin → Stories offers,
// for any market the user can reach, read live from exotic-crm-sync 1.3.12+.

const MARKET_KEY = 'crm.stories.market';
const TABS = ['review', 'live', 'hottest', 'brand', 'settings'];

const UNAVAILABLE = {
    plugin_outdated: ['This market runs an older CRM sync plugin', 'Upload exotic-crm-sync 1.3.12 or later to manage its stories from the CRM.'],
    theme_unsupported: ['This market\'s theme predates stories', 'Upload the current escortwp-child theme to use stories on this market.'],
};

function readStoredMarket() {
    try {
        return window.localStorage.getItem(MARKET_KEY) || '';
    } catch {
        return '';
    }
}

function Stat({ label, value, tone = 'default', hint }) {
    const tones = {
        default: 'text-slate-900',
        attention: 'text-amber-700',
    };
    return (
        <div className={`rounded-xl border bg-white px-4 py-3 ${tone === 'attention' ? 'border-amber-200 bg-amber-50/40' : 'border-slate-200'}`}>
            <p className="text-[11px] font-semibold uppercase tracking-[0.12em] text-slate-500">{label}</p>
            <p className={`mt-1 text-2xl font-semibold tabular-nums ${tones[tone]}`}>{value}</p>
            {hint ? <p className="mt-0.5 text-xs text-slate-500">{hint}</p> : null}
        </div>
    );
}

function timeSince(timestamp) {
    if (!timestamp) return '';
    const seconds = Math.max(0, Math.round((Date.now() - timestamp) / 1000));
    if (seconds < 10) return 'just now';
    if (seconds < 60) return `${seconds}s ago`;
    return `${Math.floor(seconds / 60)}m ago`;
}

export default function Stories() {
    const queryClient = useQueryClient();
    const [searchParams, setSearchParams] = useSearchParams();
    const tab = TABS.includes(searchParams.get('tab')) ? searchParams.get('tab') : 'review';
    const [pickerOpen, setPickerOpen] = useState(false);
    const [composeFor, setComposeFor] = useState(null);
    const [brandRequest, setBrandRequest] = useState(0);
    const [notice, setNotice] = useState('');
    const [, forceTick] = useState(0);
    const seenIds = useRef({ marketId: null, unreviewed: null });
    const [newSinceOpen, setNewSinceOpen] = useState(0);

    const marketsQuery = useQuery({
        queryKey: ['stories-markets'],
        queryFn: () => api.get('/crm/stories/markets').then((r) => r.data?.data || []),
        staleTime: 5 * 60_000,
    });
    const markets = useMemo(() => marketsQuery.data || [], [marketsQuery.data]);

    const requestedMarket = searchParams.get('market') || readStoredMarket();
    const market = markets.find((item) => String(item.id) === String(requestedMarket)) || markets[0] || null;
    const marketId = market?.id || null;

    const setParams = (updates) => {
        const next = new URLSearchParams(searchParams);
        Object.entries(updates).forEach(([key, value]) => (value ? next.set(key, value) : next.delete(key)));
        setSearchParams(next, { replace: true });
    };

    useEffect(() => {
        if (!marketId) return;
        try { window.localStorage.setItem(MARKET_KEY, String(marketId)); } catch { /* storage may be blocked */ }
        if (searchParams.get('market') !== String(marketId)) setParams({ market: String(marketId) });
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [marketId]);

    const overviewQuery = useQuery({
        queryKey: ['stories-overview', marketId],
        queryFn: () => api.get(`/crm/stories/${marketId}/overview`).then((r) => r.data),
        enabled: Boolean(marketId),
        refetchInterval: 60_000,
        refetchOnWindowFocus: true,
    });
    const overview = overviewQuery.data;
    const stats = overview?.stats || {};
    const abilities = overview?.abilities || {};
    const features = overview?.features || {};

    // "Updates": count stories that arrived in the review queue since this
    // market was opened, and offer to jump to them.
    useEffect(() => {
        if (!overview?.stats) return;
        const current = Number(overview.stats.unreviewed || 0);
        if (seenIds.current.marketId !== marketId || seenIds.current.unreviewed === null) {
            seenIds.current = { marketId, unreviewed: current };
            setNewSinceOpen(0);
            return;
        }
        setNewSinceOpen(Math.max(0, current - seenIds.current.unreviewed));
    }, [overview, marketId]);

    useEffect(() => {
        const timer = setInterval(() => forceTick((n) => n + 1), 15_000);
        return () => clearInterval(timer);
    }, []);

    const clearBrandRequest = useCallback(() => setBrandRequest(0), []);

    const refreshAll = () => {
        queryClient.invalidateQueries({ queryKey: ['stories-overview', marketId] });
        queryClient.invalidateQueries({ queryKey: ['stories-list', marketId] });
        queryClient.invalidateQueries({ queryKey: ['stories-hottest', marketId] });
        queryClient.invalidateQueries({ queryKey: ['stories-brand', marketId] });
    };

    const showNew = () => {
        seenIds.current.unreviewed = Number(stats.unreviewed || 0);
        setNewSinceOpen(0);
        setParams({ tab: null });
        queryClient.invalidateQueries({ queryKey: ['stories-list', marketId] });
    };

    const unavailable = overview && overview.available === false ? (UNAVAILABLE[overview.unavailable_reason] || UNAVAILABLE.plugin_outdated) : null;
    const enabled = Boolean(overview?.enabled);

    const tabs = [
        { key: 'review', label: 'Review', count: stats.unreviewed, attention: Number(stats.unreviewed || 0) > 0 },
        { key: 'live', label: 'Live stories', count: stats.live },
        { key: 'hottest', label: 'Hottest this week' },
        { key: 'brand', label: 'Brand stories', count: stats.brand_live },
        { key: 'settings', label: 'Settings' },
    ];

    return (
        <div className="space-y-5">
            <header className="flex flex-col gap-4 lg:flex-row lg:items-end lg:justify-between">
                <div>
                    <p className="text-[11px] font-semibold uppercase tracking-[0.18em] text-teal-700">Content</p>
                    <h1 className="mt-1 text-2xl font-semibold text-slate-900">Stories</h1>
                    <p className="mt-1 max-w-2xl text-sm text-slate-500">
                        Review what advertisers post, run the weekly Hottest reward, publish brand stories and tune each market's story rules, without opening wp-admin.
                    </p>
                </div>
                <div className="flex flex-wrap items-center gap-2">
                    <label className="sr-only" htmlFor="stories-market">Market</label>
                    <select
                        id="stories-market"
                        className="crm-input min-w-[180px] text-sm font-medium"
                        value={marketId || ''}
                        onChange={(event) => setParams({ market: event.target.value })}
                        disabled={marketsQuery.isLoading || markets.length === 0}
                    >
                        {markets.map((item) => <option key={item.id} value={item.id}>{item.name}{item.is_active === false ? ' (inactive)' : ''}</option>)}
                    </select>
                    <button type="button" className="crm-btn-secondary" onClick={refreshAll} disabled={!marketId || overviewQuery.isFetching} title={overviewQuery.dataUpdatedAt ? `Updated ${timeSince(overviewQuery.dataUpdatedAt)}` : undefined}>
                        {overviewQuery.isFetching ? 'Refreshing…' : 'Refresh'}
                    </button>
                    {enabled && abilities.post_for_client && features.post_for_client ? (
                        <button type="button" className="crm-btn-secondary" onClick={() => setPickerOpen(true)}>Post for an advertiser</button>
                    ) : null}
                    {enabled && abilities.brand && features.brand ? (
                        <button type="button" className="inline-flex items-center gap-1.5 rounded-md bg-teal-700 px-3 py-2 text-sm font-semibold text-white hover:bg-teal-800" onClick={() => { setParams({ tab: 'brand' }); setBrandRequest((n) => n + 1); }}>
                            ＋ Brand story
                        </button>
                    ) : null}
                </div>
            </header>

            {marketsQuery.isLoading ? (
                <div className="h-24 animate-pulse rounded-xl bg-slate-100" />
            ) : markets.length === 0 ? (
                <section className="crm-surface p-10 text-center text-sm text-slate-500">You have no markets with a WordPress connection.</section>
            ) : overviewQuery.isLoading ? (
                <div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-5">{[0, 1, 2, 3, 4].map((k) => <div key={k} className="h-20 animate-pulse rounded-xl bg-slate-100" />)}</div>
            ) : overviewQuery.isError ? (
                <section className="crm-surface p-8 text-center">
                    <p className="text-sm font-semibold text-slate-800">Could not reach {market?.name}.</p>
                    <p className="mt-1 text-sm text-slate-500">{overviewQuery.error?.response?.data?.message || 'The market site did not respond.'}</p>
                    <button type="button" className="crm-btn-secondary mt-4" onClick={() => overviewQuery.refetch()}>Try again</button>
                </section>
            ) : unavailable ? (
                <section className="crm-surface p-10 text-center">
                    <p className="text-base font-semibold text-slate-800">{unavailable[0]}</p>
                    <p className="mx-auto mt-1 max-w-lg text-sm text-slate-500">{unavailable[1]}</p>
                </section>
            ) : (
                <>
                    {enabled ? (
                        <section className="grid gap-3 sm:grid-cols-2 lg:grid-cols-5" aria-label="Summary">
                            <Stat label="Live stories" value={Number(stats.live || 0).toLocaleString()} hint={`${Number(stats.live_advertisers || 0)} advertisers posting`} />
                            <Stat label="Not reviewed" value={Number(stats.unreviewed || 0).toLocaleString()} tone={Number(stats.unreviewed || 0) > 0 ? 'attention' : 'default'} hint={stats.pending ? `${stats.pending} awaiting approval` : (overview.require_approval ? 'Held for approval' : 'Published first')} />
                            <Stat label="Views on live stories" value={Number(stats.live_views || 0).toLocaleString()} />
                            <Stat label="Likes this week" value={Number(stats.week_likes_top10 || 0).toLocaleString()} hint="Top 10 advertisers" />
                            <Stat label="Week closes in" value={overview.week ? `${Math.floor(overview.week.ends_in / 86400)}d ${Math.floor((overview.week.ends_in % 86400) / 3600)}h` : '—'} hint={overview.week?.key} />
                        </section>
                    ) : (
                        <section className="flex flex-col items-start gap-3 rounded-xl border border-amber-200 bg-amber-50 p-5 sm:flex-row sm:items-center sm:justify-between">
                            <div>
                                <p className="text-sm font-semibold text-amber-900">Stories are switched off on {market?.name}</p>
                                <p className="text-sm text-amber-800">Nothing is shown to visitors and advertisers cannot post.{abilities.settings ? ' Turn them on in Settings when this market is ready.' : ''}</p>
                            </div>
                            {abilities.settings ? <button type="button" className="crm-btn-secondary" onClick={() => setParams({ tab: 'settings' })}>Open settings</button> : null}
                        </section>
                    )}

                    {newSinceOpen > 0 && tab !== 'review' ? (
                        <button type="button" onClick={showNew} className="flex w-full items-center justify-between rounded-xl border border-teal-200 bg-teal-50 px-4 py-2.5 text-left text-sm text-teal-900 transition hover:bg-teal-100">
                            <span><strong>{newSinceOpen} new {newSinceOpen === 1 ? 'story' : 'stories'}</strong> to review since you opened this market.</span>
                            <span className="font-semibold">Review now →</span>
                        </button>
                    ) : null}
                    {notice ? <p role="status" className="rounded-lg bg-emerald-50 px-3 py-2 text-sm text-emerald-800">{notice}</p> : null}

                    <nav className="crm-surface flex flex-wrap items-center gap-1 p-2" aria-label="Stories sections">
                        {tabs.filter((item) => item.key === 'settings' || enabled).map((item) => (
                            <button
                                key={item.key}
                                type="button"
                                onClick={() => setParams({ tab: item.key === 'review' ? null : item.key })}
                                aria-current={tab === item.key ? 'page' : undefined}
                                className={`inline-flex items-center gap-2 rounded-md px-3 py-2 text-sm font-medium transition ${tab === item.key ? 'bg-white text-slate-900 ring-1 ring-slate-200' : 'text-slate-500 hover:bg-slate-100 hover:text-slate-700'}`}
                            >
                                {item.label}
                                {item.count ? (
                                    <span className={`rounded-full px-2 py-0.5 text-[11px] font-semibold ring-1 ring-inset ${item.attention ? 'bg-amber-50 text-amber-800 ring-amber-200' : 'bg-slate-50 text-slate-600 ring-slate-200'}`}>{Number(item.count).toLocaleString()}</span>
                                ) : null}
                            </button>
                        ))}
                        <span className="ml-auto pr-2 text-xs text-slate-400">{overviewQuery.dataUpdatedAt ? `Updated ${timeSince(overviewQuery.dataUpdatedAt)}` : ''}</span>
                    </nav>

                    {tab === 'settings' || !enabled ? (
                        <StorySettingsPanel platformId={marketId} canEdit={Boolean(abilities.settings)} onSaved={refreshAll} />
                    ) : tab === 'live' ? (
                        <StoryReviewGrid key={`live-${marketId}`} platformId={marketId} mode="live" canModerate={Boolean(abilities.moderate && features.moderate)} onChanged={refreshAll} />
                    ) : tab === 'hottest' ? (
                        <HottestPanel platformId={marketId} canReward={Boolean(abilities.reward && features.hottest)} />
                    ) : tab === 'brand' ? (
                        <BrandStoriesPanel platformId={marketId} canManage={Boolean(abilities.brand && features.brand)} composeRequest={brandRequest} onComposeConsumed={clearBrandRequest} onChanged={refreshAll} />
                    ) : (
                        <StoryReviewGrid key={`review-${marketId}`} platformId={marketId} mode="review" canModerate={Boolean(abilities.moderate && features.moderate)} onChanged={() => { seenIds.current.unreviewed = null; refreshAll(); }} />
                    )}
                </>
            )}

            <AdvertiserPicker
                open={pickerOpen}
                platformId={marketId}
                marketName={market?.name}
                onClose={() => setPickerOpen(false)}
                onPick={(client) => { setPickerOpen(false); setComposeFor(client); }}
            />
            <StoryComposerModal
                open={Boolean(composeFor)}
                clientId={composeFor?.id}
                limits={overview?.limits}
                liveCount={0}
                onClose={() => setComposeFor(null)}
                onPosted={(result) => {
                    const count = (result?.story_ids || []).length || 1;
                    setNotice(`${count > 1 ? `${count} stories` : 'Story'} posted for ${composeFor?.name}.`);
                    setComposeFor(null);
                    refreshAll();
                }}
            />
        </div>
    );
}
