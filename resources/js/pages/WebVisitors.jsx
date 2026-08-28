import React, { useEffect, useMemo, useState } from 'react';
import { useQuery } from '@tanstack/react-query';
import { Link, useSearchParams } from 'react-router-dom';
import PageHeader from '../components/PageHeader';
import ReportingCurrencyControl from '../components/ReportingCurrencyControl';
import SectionFrame from '../components/SectionFrame';
import Combobox from '../components/shared/Combobox';
import BillingTabNav from '../components/billing/BillingTabNav';
import { useToast } from '../components/ToastProvider';
import useReportingCurrency from '../hooks/useReportingCurrency';
import contactUnlocks from '../services/contactUnlocks';
import VisitorOverviewPanel from '../components/visitors/VisitorOverviewPanel';
import VisitorDemandPanel from '../components/visitors/VisitorDemandPanel';
import VisitorUnlockTrail from '../components/visitors/VisitorUnlockTrail';
import VisitorSetupPanel from '../components/visitors/VisitorSetupPanel';

const VALID_TABS = ['overview', 'demand', 'unlocks', 'setup'];
const DEFAULT_TRAIL_FILTERS = {
    search: '',
    platform_id: 'all',
    status: 'all',
    payment_status: 'all',
    scope: 'all',
    sort: 'id',
    direction: 'desc',
    page: 1,
    per_page: 10,
};

function todayDate() {
    return new Date().toISOString().slice(0, 10);
}

function dateDaysAgo(days) {
    const date = new Date();
    date.setDate(date.getDate() - days);
    return date.toISOString().slice(0, 10);
}

function readTrailFilters(searchParams, sharedPlatformId) {
    return {
        search: searchParams.get('search') || '',
        platform_id: searchParams.get('unlock_market') || sharedPlatformId || 'all',
        status: searchParams.get('status') || 'all',
        payment_status: searchParams.get('payment_status') || 'all',
        scope: searchParams.get('scope') || 'all',
        sort: searchParams.get('sort') || 'id',
        direction: searchParams.get('direction') === 'asc' ? 'asc' : 'desc',
        page: Number(searchParams.get('page') || 1),
        per_page: Number(searchParams.get('per_page') || 10),
    };
}

function buildParams(filters, extras = {}) {
    return Object.entries({ ...filters, ...extras }).reduce((params, [key, value]) => {
        if (value === '' || value === 'all' || value === null || value === undefined) return params;
        params[key] = value;
        return params;
    }, {});
}

export default function WebVisitors() {
    const [searchParams, setSearchParams] = useSearchParams();
    const toast = useToast();
    const reportingCurrency = useReportingCurrency({ preferFlat: true });
    const sharedPlatformId = searchParams.get('platform_id') || 'all';
    const fromDate = searchParams.get('from') || '';
    const toDate = searchParams.get('to') || '';
    const [insightPlatformId, setInsightPlatformId] = useState(sharedPlatformId);
    const requestedTab = searchParams.get('tab') || 'overview';
    const activeTab = VALID_TABS.includes(requestedTab) ? requestedTab : 'overview';
    const trailFilters = useMemo(() => readTrailFilters(searchParams, sharedPlatformId), [searchParams, sharedPlatformId]);

    const overviewParams = useMemo(() => buildParams({
        page: trailFilters.page,
        per_page: trailFilters.per_page,
        platform_id: trailFilters.platform_id,
        status: trailFilters.status,
        payment_status: trailFilters.payment_status,
        scope: trailFilters.scope,
        search: trailFilters.search,
        sort: trailFilters.sort,
        direction: trailFilters.direction,
        from: fromDate,
        to: toDate,
        ...reportingCurrency.queryParams,
    }), [fromDate, reportingCurrency.queryParams, toDate, trailFilters]);

    const unlockQuery = useQuery({
        queryKey: ['contact-unlocks', overviewParams],
        queryFn: () => contactUnlocks.getOverview(overviewParams),
        staleTime: 30_000,
    });

    const data = unlockQuery.data || {};
    const permissions = data.permissions || {};
    const canManage = permissions.can_manage !== false;
    const markets = data.markets || [];
    const visibleTabs = useMemo(() => [
        { id: 'overview', label: 'Overview' },
        { id: 'demand', label: 'Demand' },
        { id: 'unlocks', label: 'Unlocks' },
        ...(canManage ? [{ id: 'setup', label: 'Setup' }] : []),
    ], [canManage]);

    useEffect(() => {
        if (sharedPlatformId !== 'all') {
            setInsightPlatformId(sharedPlatformId);
        }
    }, [sharedPlatformId]);

    useEffect(() => {
        if (activeTab === 'setup' && !canManage) {
            updateParams({ tab: 'overview' });
        }
    }, [activeTab, canManage]);

    const pulseParams = useMemo(() => buildParams({
        platform_id: insightPlatformId,
        range: fromDate && toDate ? 'custom' : 'today',
        from: fromDate,
        to: toDate,
        timezone: Intl.DateTimeFormat().resolvedOptions().timeZone,
        reporting_currency: reportingCurrency.targetCurrency,
    }), [fromDate, insightPlatformId, reportingCurrency.targetCurrency, toDate]);

    const pulseQuery = useQuery({
        queryKey: ['contact-unlock-pulse', pulseParams],
        queryFn: () => contactUnlocks.getPulse(pulseParams),
        enabled: Boolean(unlockQuery.data),
        staleTime: 30_000,
    });

    function updateParams(patch) {
        setSearchParams((current) => {
            const next = new URLSearchParams(current);
            Object.entries(patch).forEach(([key, value]) => {
                if (value === '' || value === 'all' || value === null || value === undefined) {
                    next.delete(key);
                } else {
                    next.set(key, String(value));
                }
            });
            return next;
        }, { replace: true });
    }

    function setTab(tab) {
        updateParams({ tab });
    }

    function setSharedMarket(value) {
        updateParams({ platform_id: value, unlock_market: value, page: 1 });
        setInsightPlatformId(value || 'all');
    }

    function setTrailFilter(key, value) {
        updateParams({ [key === 'platform_id' ? 'unlock_market' : key]: value, page: 1 });
    }

    function setTrailPage(page) {
        updateParams({ page: Math.max(1, Number(page) || 1) });
    }

    function setTrailPerPage(perPage) {
        updateParams({ per_page: perPage, page: 1 });
    }

    function toggleTrailSort(key) {
        updateParams({
            sort: key,
            direction: trailFilters.sort === key && trailFilters.direction === 'desc' ? 'asc' : 'desc',
            page: 1,
        });
    }

    function resetTrailFilters() {
        updateParams({
            search: '',
            unlock_market: sharedPlatformId,
            status: '',
            payment_status: '',
            scope: '',
            sort: 'id',
            direction: 'desc',
            page: 1,
            per_page: 10,
        });
    }

    function applyRangePreset(days) {
        if (days === 'all') {
            updateParams({ from: '', to: '', page: 1 });
            return;
        }
        updateParams({ from: dateDaysAgo(days - 1), to: todayDate(), page: 1 });
    }

    const marketGroups = useMemo(() => [{
        label: 'Markets',
        options: [
            { value: 'all', label: 'All markets' },
            ...markets.map((market) => ({
                value: String(market.id),
                label: market.name,
                secondaryLabel: [market.country, market.currency_code].filter(Boolean).join(' | '),
                badge: market.enabled ? 'On' : '',
            })),
        ],
    }], [markets]);

    const retry = () => {
        unlockQuery.refetch();
        pulseQuery.refetch();
    };

    return (
        <div className="space-y-5">
            <PageHeader
                title="Web Visitors"
                subtitle="Visitor contact-unlock revenue, demand signals, checkout trail, and market setup."
                actions={<Link to="/payments" className="crm-btn-secondary">Payments</Link>}
            />

            <section className="rounded-lg border border-teal-200 bg-teal-50 px-4 py-3 text-sm text-teal-900">
                <div className="flex flex-wrap items-center justify-between gap-3">
                    <p className="font-semibold">Visitor unlock revenue - recorded separately from advertiser subscription revenue. Shared payment rails, separate books.</p>
                    <span className="rounded-md border border-teal-200 bg-white px-2.5 py-1 text-xs font-semibold text-teal-700" title="payments.purpose = visitor_contact_unlock">purpose: visitor_contact_unlock</span>
                </div>
            </section>

            <SectionFrame title="Controls" subtitle="Market, currency and date range apply across overview, demand, unlock trail and export.">
                <div className="flex flex-wrap items-end gap-3">
                    <Combobox
                        label="Market"
                        value={sharedPlatformId}
                        onChange={(value) => setSharedMarket(value || 'all')}
                        groups={marketGroups}
                        className="min-w-[240px]"
                        allowClear={false}
                    />
                    <ReportingCurrencyControl reporting={reportingCurrency} />
                    <label className="flex flex-col gap-1">
                        <span className="text-[10px] font-semibold uppercase tracking-[0.1em] text-slate-400">From</span>
                        <input type="date" className="crm-input w-auto min-w-[140px]" value={fromDate} onChange={(event) => updateParams({ from: event.target.value, page: 1 })} />
                    </label>
                    <label className="flex flex-col gap-1">
                        <span className="text-[10px] font-semibold uppercase tracking-[0.1em] text-slate-400">To</span>
                        <input type="date" className="crm-input w-auto min-w-[140px]" value={toDate} onChange={(event) => updateParams({ to: event.target.value, page: 1 })} />
                    </label>
                    <div className="inline-flex rounded-lg border border-slate-200 bg-slate-50 p-1">
                        {[['today', 1], ['7d', 7], ['30d', 30], ['all', 'all']].map(([label, days]) => (
                            <button key={label} type="button" className="rounded-md px-3 py-2 text-xs font-semibold text-slate-600 transition hover:bg-white hover:text-slate-900" onClick={() => applyRangePreset(days)}>
                                {label === 'all' ? 'All time' : label.toUpperCase()}
                            </button>
                        ))}
                    </div>
                </div>
            </SectionFrame>

            <div className="crm-surface overflow-hidden">
                <BillingTabNav tabs={visibleTabs} activeTab={activeTab === 'setup' && !canManage ? 'overview' : activeTab} onChange={setTab} />
                <div className="p-4 sm:p-5">
                    {unlockQuery.isError ? (
                        <div className="rounded-lg border border-rose-200 bg-rose-50 p-4">
                            <p className="font-semibold text-rose-900">Web Visitors unavailable</p>
                            <p className="mt-1 text-sm text-rose-700">CRM could not load contact unlock data.</p>
                            <button type="button" className="crm-btn-secondary mt-3" onClick={retry}>Retry</button>
                        </div>
                    ) : (
                        <>
                            {(activeTab === 'overview' || (activeTab === 'setup' && !canManage)) ? (
                                <div className="space-y-4">
                                    <Combobox
                                        label="Overview market"
                                        value={insightPlatformId}
                                        onChange={(value) => setInsightPlatformId(value || 'all')}
                                        groups={marketGroups}
                                        className="max-w-sm"
                                        allowClear={false}
                                    />
                                    <VisitorOverviewPanel
                                        summary={data.summary || {}}
                                        pulse={pulseQuery.data || {}}
                                        reportingCurrency={reportingCurrency}
                                        isLoading={unlockQuery.isLoading || pulseQuery.isLoading}
                                    />
                                </div>
                            ) : null}

                            {activeTab === 'demand' ? (
                                <div className="space-y-4">
                                    <Combobox
                                        label="Demand market"
                                        value={insightPlatformId}
                                        onChange={(value) => setInsightPlatformId(value || 'all')}
                                        groups={marketGroups}
                                        className="max-w-sm"
                                        allowClear={false}
                                    />
                                    <VisitorDemandPanel pulse={pulseQuery.data || {}} toast={toast} />
                                </div>
                            ) : null}

                            {activeTab === 'unlocks' ? (
                                <VisitorUnlockTrail
                                    markets={markets}
                                    unlocks={data.recent_unlocks || []}
                                    meta={data.recent_unlocks_meta || {}}
                                    filters={trailFilters}
                                    setFilter={setTrailFilter}
                                    setPage={setTrailPage}
                                    setPerPage={setTrailPerPage}
                                    toggleSort={toggleTrailSort}
                                    isLoading={unlockQuery.isLoading}
                                    isFetching={unlockQuery.isFetching}
                                    onReset={resetTrailFilters}
                                    onExport={() => contactUnlocks.exportUnlocks({ ...overviewParams, page: undefined, per_page: undefined })}
                                    toast={toast}
                                />
                            ) : null}

                            {activeTab === 'setup' && canManage ? (
                                <VisitorSetupPanel data={data} markets={markets} pageParams={overviewParams} toast={toast} />
                            ) : null}
                        </>
                    )}
                </div>
            </div>
        </div>
    );
}
