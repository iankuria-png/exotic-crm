import React, { useMemo, useState } from 'react';
import { useQuery } from '@tanstack/react-query';
import { useNavigate } from 'react-router-dom';
import api from '../services/api';
import CountryRevenueWidget from '../components/dashboard/CountryRevenueWidget';
import RevenueByPackageWidget from '../components/dashboard/RevenueByPackageWidget';
import CeoHeader from '../components/dashboard/CeoHeader';
import CeoMetricStrip from '../components/dashboard/CeoMetricStrip';
import InsightStrip from '../components/dashboard/InsightStrip';
import MarketRevenuePieWidget from '../components/dashboard/MarketRevenuePieWidget';
import RevenueTrendWidget from '../components/dashboard/RevenueTrendWidget';
import RecentPaymentsWidget from '../components/dashboard/RecentPaymentsWidget';
import AgentPerformanceWidget from '../components/dashboard/AgentPerformanceWidget';
import FxNormalizationNotice from '../components/FxNormalizationNotice';
import useCeoReportingCurrency from '../hooks/useCeoReportingCurrency';

function toInputDate(date) {
    const year = date.getFullYear();
    const month = String(date.getMonth() + 1).padStart(2, '0');
    const day = String(date.getDate()).padStart(2, '0');
    return `${year}-${month}-${day}`;
}

function defaultCustomRange() {
    const to = new Date();
    const from = new Date();
    from.setDate(to.getDate() - 29);
    return { from: toInputDate(from), to: toInputDate(to) };
}

function countryRangeMode(horizon) {
    if (horizon === '30d') return 'month';
    if (horizon === '90d') return 'custom';
    if (horizon === 'ytd') return 'custom';
    return 'custom';
}

function apiError(error, fallback) {
    return error?.response?.data?.message || fallback;
}

function compactWindowLabel(window) {
    if (!window?.from || !window?.to) return 'Selected period';
    return `${window.from} to ${window.to}`;
}

export default function CeoDashboard({ user, onSwitchAdminView }) {
    const navigate = useNavigate();
    const reporting = useCeoReportingCurrency();
    const [horizon, setHorizon] = useState('30d');
    const [customRange, setCustomRange] = useState(defaultCustomRange);
    const [platformFilter, setPlatformFilter] = useState(null);
    const [focusedAgentId, setFocusedAgentId] = useState(null);
    const [trendMetric, setTrendMetric] = useState('revenue');
    const [trendBucket, setTrendBucket] = useState('auto');
    const [trendComparison, setTrendComparison] = useState(true);
    const [recentLimit, setRecentLimit] = useState(10);
    const [recentChannel, setRecentChannel] = useState('all');

    const queryParams = useMemo(() => ({
        horizon,
        ...(horizon === 'custom' ? { from: customRange.from, to: customRange.to } : {}),
        ...(platformFilter ? { platform_id: platformFilter } : {}),
        ...reporting.queryParams,
    }), [customRange.from, customRange.to, horizon, platformFilter, reporting.queryParams]);

    const marketsQuery = useQuery({
        queryKey: ['ceo-dashboard', 'markets'],
        queryFn: () => api.get('/crm/dashboard/my-markets').then((response) => response.data),
        staleTime: 120_000,
    });

    const summaryQuery = useQuery({
        queryKey: ['ceo-dashboard', 'summary', queryParams],
        queryFn: () => api.get('/crm/dashboard/ceo/summary', { params: queryParams }).then((response) => response.data),
        staleTime: 45_000,
    });

    const marketPieQuery = useQuery({
        queryKey: ['ceo-dashboard', 'market-pie', queryParams],
        queryFn: () => api.get('/crm/dashboard/ceo/market-pie', { params: queryParams }).then((response) => response.data),
        staleTime: 45_000,
    });

    const trendQuery = useQuery({
        queryKey: ['ceo-dashboard', 'revenue-trend', queryParams, trendBucket],
        queryFn: () => api.get('/crm/dashboard/ceo/revenue-trend', {
            params: {
                ...queryParams,
                ...(trendBucket !== 'auto' ? { bucket: trendBucket } : {}),
            },
        }).then((response) => response.data),
        staleTime: 45_000,
    });

    const recentPaymentsQuery = useQuery({
        queryKey: ['ceo-dashboard', 'recent-payments', queryParams, recentLimit, recentChannel],
        queryFn: () => api.get('/crm/dashboard/ceo/recent-payments', {
            params: {
                ...queryParams,
                limit: recentLimit,
                channel: recentChannel,
            },
        }).then((response) => response.data),
        refetchInterval: typeof document === 'undefined' || document.visibilityState === 'visible' ? 60_000 : false,
        staleTime: 20_000,
    });

    const agentPerformanceQuery = useQuery({
        queryKey: ['ceo-dashboard', 'agent-performance', queryParams],
        queryFn: () => api.get('/crm/dashboard/ceo/agent-performance', { params: queryParams }).then((response) => response.data),
        staleTime: 60_000,
    });

    const countryRevenueQuery = useQuery({
        queryKey: ['ceo-dashboard', 'top-markets', queryParams, summaryQuery.data?.window?.from, summaryQuery.data?.window?.to],
        queryFn: () => api.get('/crm/dashboard/country-revenue', {
            params: {
                platform_id: platformFilter || undefined,
                from: summaryQuery.data?.window?.from,
                to: summaryQuery.data?.window?.to,
                country_period: countryRangeMode(horizon),
                ...reporting.queryParams,
            },
        }).then((response) => response.data),
        enabled: Boolean(summaryQuery.data?.window?.from && summaryQuery.data?.window?.to),
        staleTime: 60_000,
    });

    const marketOptions = Array.isArray(marketsQuery.data) ? marketsQuery.data : (marketsQuery.data?.markets || []);
    const selectedMarket = summaryQuery.data?.selected_market
        || marketOptions.find((market) => Number(market.id || market.platform_id) === Number(platformFilter))
        || null;
    const window = summaryQuery.data?.window || trendQuery.data?.window;
    const normalizationMeta = summaryQuery.data?.metrics?.collected_revenue?.value?.normalization_meta;

    const handleMarketScope = (marketId) => {
        setPlatformFilter(marketId ? String(marketId) : null);
    };

    return (
        <div className="space-y-4">
            <CeoHeader
                user={user}
                horizon={horizon}
                onHorizonChange={setHorizon}
                customRange={customRange}
                onCustomRangeChange={setCustomRange}
                selectedMarket={selectedMarket}
                markets={marketOptions}
                platformFilter={platformFilter}
                onPlatformChange={handleMarketScope}
                reporting={reporting}
                onSwitchAdmin={onSwitchAdminView}
            />

            <div className="flex flex-wrap items-center justify-between gap-2 px-1 text-xs text-slate-500">
                <span>Window: <span className="font-semibold text-slate-700">{compactWindowLabel(window)}</span></span>
                <FxNormalizationNotice meta={normalizationMeta} />
            </div>

            <CeoMetricStrip
                metrics={summaryQuery.data?.metrics || {}}
                reporting={reporting}
                isLoading={summaryQuery.isLoading}
                onOpen={(href) => navigate(href)}
            />

            <InsightStrip
                insights={summaryQuery.data?.insights || []}
                isLoading={summaryQuery.isLoading}
                onMarketClick={handleMarketScope}
                onAgentClick={setFocusedAgentId}
            />

            <section className="grid gap-4 2xl:grid-cols-2">
                <div>
                    <RevenueTrendWidget
                        data={trendQuery.data}
                        isLoading={trendQuery.isLoading}
                        errorMessage={trendQuery.isError ? apiError(trendQuery.error, 'Revenue trend could not be loaded.') : null}
                        currency={trendQuery.data?.window?.target_currency || reporting.targetCurrency}
                        metric={trendMetric}
                        onMetricChange={setTrendMetric}
                        bucket={trendBucket}
                        onBucketChange={setTrendBucket}
                        showComparison={trendComparison}
                        onShowComparisonChange={setTrendComparison}
                        customerMix={summaryQuery.data?.customer_mix}
                    />
                </div>
                <div>
                    <MarketRevenuePieWidget
                        data={marketPieQuery.data}
                        reporting={reporting}
                        isLoading={marketPieQuery.isLoading}
                        errorMessage={marketPieQuery.isError ? apiError(marketPieQuery.error, 'Market revenue could not be loaded.') : null}
                        onSelectMarket={handleMarketScope}
                        selectedMarket={selectedMarket}
                        onClearMarket={() => handleMarketScope(null)}
                    />
                </div>
            </section>

            <section className="grid gap-4 xl:grid-cols-12">
                <div className="space-y-4 xl:col-span-7">
                    <CountryRevenueWidget
                        data={countryRevenueQuery.data || []}
                        fromDate={window?.from || customRange.from}
                        toDate={window?.to || customRange.to}
                        rangeMode={countryRangeMode(horizon)}
                        isLoading={countryRevenueQuery.isLoading}
                        errorMessage={countryRevenueQuery.isError ? apiError(countryRevenueQuery.error, 'Top markets could not be loaded.') : null}
                        currencyMode={reporting.displayMode}
                        targetCurrency={reporting.targetCurrency}
                        title="Top Performing Markets"
                        hideOwnControls
                    />

                    <RevenueByPackageWidget
                        platformFilter={platformFilter}
                        fromDate={window?.from || customRange.from}
                        toDate={window?.to || customRange.to}
                        reportingCurrency={reporting}
                        onOpenReport={() => navigate('/reports')}
                    />
                </div>

                <div className="xl:col-span-5">
                    <RecentPaymentsWidget
                        data={recentPaymentsQuery.data}
                        isLoading={recentPaymentsQuery.isLoading}
                        errorMessage={recentPaymentsQuery.isError ? apiError(recentPaymentsQuery.error, 'Recent payments could not be loaded.') : null}
                        onOpenPayment={(paymentId) => navigate(`/payments?search=${paymentId}`)}
                        limit={recentLimit}
                        onLimitChange={setRecentLimit}
                        channel={recentChannel}
                        onChannelChange={setRecentChannel}
                    />
                </div>
            </section>

            <AgentPerformanceWidget
                data={agentPerformanceQuery.data}
                reporting={reporting}
                isLoading={agentPerformanceQuery.isLoading}
                errorMessage={agentPerformanceQuery.isError ? apiError(agentPerformanceQuery.error, 'Agent performance could not be loaded.') : null}
                focusedAgentId={focusedAgentId}
                onOpenTeam={() => navigate('/team')}
            />
        </div>
    );
}
