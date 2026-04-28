import React from 'react';
import SectionFrame from '../SectionFrame';
import { getCountryFlag } from '../../utils/flags';
import { formatCurrency } from '../../utils/currency';
import CurrencyAmount from '../CurrencyAmount';
import FxNormalizationNotice from '../FxNormalizationNotice';

function TrendArrow({ trend }) {
    if (trend === null || trend === undefined) {
        return <span className="text-xs text-slate-400">&mdash;</span>;
    }

    if (trend === 0) {
        return <span className="text-xs font-medium text-slate-500">0%</span>;
    }

    if (trend > 0) {
        return (
            <span className="inline-flex items-center gap-0.5 text-xs font-medium text-emerald-700">
                <svg className="h-3 w-3" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2.5} d="M5 15l7-7 7 7" />
                </svg>
                {Math.abs(trend)}%
            </span>
        );
    }

    return (
        <span className="inline-flex items-center gap-0.5 text-xs font-medium text-rose-700">
            <svg className="h-3 w-3" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2.5} d="M19 9l-7 7-7-7" />
            </svg>
            {Math.abs(trend)}%
        </span>
    );
}

function PeriodToggle({ period, onChange }) {
    return (
        <div className="inline-flex rounded-lg border border-slate-200 bg-slate-50 p-0.5">
            {['week', 'month'].map((option) => (
                <button
                    key={option}
                    type="button"
                    onClick={() => onChange(option)}
                    className={`rounded-md px-3 py-1 text-xs font-semibold capitalize transition ${
                        period === option
                            ? 'bg-white text-slate-900 shadow-sm'
                            : 'text-slate-500 hover:text-slate-700'
                    }`}
                >
                    {option}
                </button>
            ))}
        </div>
    );
}

export default function CountryRevenueWidget({ data = [], period = 'week', onPeriodChange, isLoading, currencyMode = 'native', targetCurrency = 'USD' }) {
    const subtitle = period === 'month'
        ? 'Revenue by market in selected 30-day window'
        : 'Revenue by market in selected 7-day window';

    return (
        <SectionFrame
            title="Top Performing Countries"
            subtitle={subtitle}
            action={<PeriodToggle period={period} onChange={onPeriodChange} />}
        >
            {isLoading ? (
                <div className="space-y-3">
                    {[1, 2].map((item) => (
                        <div key={item} className="h-14 animate-pulse rounded-md bg-slate-100" />
                    ))}
                </div>
            ) : data.length > 0 ? (
                <div className="space-y-1">
                    {data.map((market) => (
                        <div
                            key={market.platform_id}
                            className="flex items-center justify-between gap-3 rounded-lg px-3 py-2.5 transition hover:bg-slate-50"
                        >
                            <div className="flex items-center gap-3">
                                <span className="text-xl" aria-hidden="true">{getCountryFlag(market.country)}</span>
                                <div>
                                    <p className="text-sm font-semibold text-slate-900">{market.country || market.name}</p>
                                    <p className="text-xs text-slate-500">{market.name}</p>
                                </div>
                            </div>
                            <div className="text-right">
                                {currencyMode === 'flat' && market.current_revenue_normalized !== null && market.current_revenue_normalized !== undefined ? (
                                    <>
                                        <p className="crm-mono text-sm font-semibold text-slate-900">
                                            {market.current_revenue_normalized_display || formatCurrency(market.current_revenue_normalized, targetCurrency)}
                                        </p>
                                        <CurrencyAmount
                                            breakdown={market.current_revenue_breakdown}
                                            scalarAmount={market.current_revenue}
                                            fallbackCurrency={market.currency}
                                            className="text-xs font-medium text-slate-500"
                                            stackClassName="text-xs font-medium text-slate-500"
                                        />
                                        <FxNormalizationNotice meta={market.current_revenue_normalization_meta} className="mt-1 justify-end" />
                                    </>
                                ) : currencyMode === 'flat' ? (
                                    <>
                                        <CurrencyAmount
                                            breakdown={market.current_revenue_breakdown}
                                            scalarAmount={market.current_revenue}
                                            fallbackCurrency={market.currency}
                                            className="crm-mono text-sm font-semibold text-slate-900"
                                            stackClassName="crm-mono text-sm font-semibold text-slate-900"
                                        />
                                        <FxNormalizationNotice meta={market.current_revenue_normalization_meta} className="mt-1 justify-end" />
                                    </>
                                ) : (
                                    <CurrencyAmount
                                        breakdown={market.current_revenue_breakdown}
                                        scalarAmount={market.current_revenue}
                                        fallbackCurrency={market.currency}
                                        className="crm-mono text-sm font-semibold text-slate-900"
                                        stackClassName="crm-mono text-sm font-semibold text-slate-900"
                                    />
                                )}
                                <TrendArrow trend={currencyMode === 'flat' ? market.normalized_trend : market.trend} />
                            </div>
                        </div>
                    ))}
                </div>
            ) : (
                <div className="rounded-md border border-dashed border-slate-200 bg-slate-50 px-4 py-8 text-center text-sm text-slate-500">
                    No markets in scope for this view.
                </div>
            )}
        </SectionFrame>
    );
}
