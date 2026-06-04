import React from 'react';
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

        return `${share}% of revenue · ${payments} payments · ${clients} clients`;
    }

    if (key === 'collected_revenue') {
        return `${Number(metric.value?.payments_count || 0).toLocaleString()} successful payments`;
    }

    if (key === 'failed_payment_recovery') {
        const recovered = Number(metric.value?.recovered_payments || 0).toLocaleString();
        const failed = Number(metric.value?.failed_payments || 0).toLocaleString();
        const customerRate = Number(metric.value?.customer_recovery_rate || 0).toFixed(1);

        return `${recovered}/${failed} failed payments · ${customerRate}% of customers`;
    }

    return 'Compared with the prior matching window';
}

export default function CeoMetricStrip({ metrics = {}, reporting, isLoading, onOpen }) {
    const order = [
        ['collected_revenue', 'Collected Revenue'],
        ['active_clients', 'Active Subscribers'],
        ['new_user_revenue', 'New User Revenue'],
        ['existing_user_revenue', 'Existing User Revenue'],
        ['failed_payment_recovery', 'Failed Payment Recovery'],
    ];

    return (
        <section className="grid gap-4 md:grid-cols-2 xl:grid-cols-5">
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
        </section>
    );
}
