import React from 'react';
import { Link } from 'react-router-dom';
import { cashMoney, CashChange, CashState, RevenueBasis } from './ProductRevenueView';
import MetricCard from '../MetricCard';
import { deltaTone, formatDelta, moneyFromBreakdown } from './ceoFormatters';

function metricValue(key, metric, reporting) {
    if (!metric) return '--';

    if (key === 'collected_revenue') {
        return moneyFromBreakdown(
            metric.value?.source_breakdown,
            metric.value?.normalized_total,
            metric.value?.normalized_currency || reporting?.targetCurrency,
            reporting?.displayMode
        );
    }

    if (key === 'new_user_revenue' || key === 'existing_user_revenue') {
        return moneyFromBreakdown(
            metric.value?.source_breakdown,
            metric.value?.normalized_amount,
            metric.value?.normalized_currency || reporting?.targetCurrency,
            reporting?.displayMode
        );
    }

    if (key === 'active_clients') {
        return Number(metric.value?.count || 0).toLocaleString();
    }

    if (key === 'failed_payment_recovery') {
        return `${Number(metric.value?.payment_recovery_rate || 0).toFixed(1)}%`;
    }

    return Number(metric.value?.count || 0).toLocaleString();
}

function metricSubHint(key, metric) {
    if (!metric) return '';

    if (key === 'active_clients' && metric.value?.approximate) {
        return `Snapshot as of ${metric.value?.as_of || 'nearest prior date'}`;
    }

    if (key === 'new_user_revenue' || key === 'existing_user_revenue') {
        const share = Number(metric.value?.share_percent || 0).toFixed(1);
        const payments = Number(metric.value?.payments_count || 0).toLocaleString();
        const clients = Number(metric.value?.clients_count || 0).toLocaleString();

        return `${share}% of subscription revenue · ${payments} payments · ${clients} clients`;
    }

    if (key === 'collected_revenue') {
        return `${Number(metric.value?.payments_count || 0).toLocaleString()} successful payments`;
    }

    if (key === 'failed_payment_recovery') {
        const recovered = Number(metric.value?.recovered_payments || 0).toLocaleString();
        const failed = Number(metric.value?.failed_payments || 0).toLocaleString();
        const lost = Number(metric.value?.lost_payments || 0).toLocaleString();
        const customerRate = Number(metric.value?.customer_recovery_rate || 0).toFixed(1);

        return `${recovered} recovered · ${lost} lost of ${failed} failed · ${customerRate}% customers`;
    }

    return 'Compared with the prior matching window';
}

export default function CeoMetricStrip({ metrics = {}, reporting, isLoading, onOpen, productRevenue, productLoading, productError, onRetryRevenue, onOpenSources }) {
    const order = [
        ['active_clients', 'Active Subscribers'],
        ['failed_payment_recovery', 'Failed Payment Recovery'],
        ['new_user_revenue', 'New User Revenue'],
        ['existing_user_revenue', 'Existing User Revenue'],
    ];

    return (
        <section className="ceo-metric-strip" aria-label="Business metrics">
            <article className="cash-card" aria-label="Collected revenue">
                {productLoading || productError || !productRevenue ? <><p className="cash-eyebrow">Collected revenue</p><CashState loading={productLoading} error={productError} onRetry={onRetryRevenue} /></> : <>
                    <button type="button" className="cash-card-open" onClick={onOpenSources} aria-label="Collected revenue · open revenue sources">
                        <span className="cash-eyebrow">Collected revenue</span>
                        <span className="block mt-1 text-[11px] text-teal-800">Provisional · all products</span>
                        <strong className="cash-card-total">{cashMoney(productRevenue.selected, reporting?.displayMode)}</strong>
                        <CashChange value={productRevenue.selected?.change_percent} />
                        <span className="block mt-1 text-[10px] text-slate-600">{productRevenue.selected?.payments_count?.toLocaleString()} cash payments · selected period</span>
                    </button>
                    <dl className="cash-card-periods">{[['today', 'Today'], ['week', 'This week'], ['month', 'This month']].map(([period, label]) => <div key={period}><dt>{label}</dt><dd>{cashMoney(productRevenue[period], reporting?.displayMode)}</dd></div>)}</dl>
                    <div className="mt-3"><RevenueBasis data={productRevenue} /></div>
                </>}
                <div className="cash-card-footer"><Link to="/payments">View payments ↗</Link>{productRevenue && !productError && <button type="button" onClick={onOpenSources}>Sources →</button>}</div>
            </article>
            <div className="ceo-metric-secondary">
                {order.map(([key, fallbackLabel]) => {
                    const metric = metrics[key];
                    const tone = deltaTone(metric?.delta_percent);
                    return (
                        <MetricCard
                            key={key}
                            label={metric?.label || fallbackLabel}
                            value={metricValue(key, metric, reporting)}
                            hint={formatDelta(metric?.delta_percent)}
                            subHint={metricSubHint(key, metric)}
                            tone={tone}
                            isLoading={isLoading}
                            onClick={metric?.href ? () => onOpen(metric.href) : undefined}
                        />
                    );
                })}
            </div>
        </section>
    );
}
