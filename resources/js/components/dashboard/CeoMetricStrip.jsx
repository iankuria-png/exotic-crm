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

    if (key === 'active_clients') {
        return Number(metric.value?.count || 0).toLocaleString();
    }

    if (key === 'renewals_recovered') {
        const recovered = Number(metric.value?.recovered || 0);
        const due = Number(metric.value?.due || 0);
        const rate = metric.value?.rate;
        return rate === null || rate === undefined
            ? `${recovered} / ${due}`
            : `${Number(rate).toFixed(1)}%`;
    }

    return Number(metric.value?.count || 0).toLocaleString();
}

function metricSubHint(key, metric) {
    if (!metric) return '';

    if (key === 'active_clients' && metric.value?.approximate) {
        return `Snapshot as of ${metric.value?.as_of || 'nearest prior date'}`;
    }

    if (key === 'renewals_recovered') {
        const due = Number(metric.value?.due || 0);
        if (due <= 0) {
            return `${Number(metric.value?.renewal_payments || 0).toLocaleString()} renewal payments collected`;
        }
        return `${Number(metric.value?.recovered || 0).toLocaleString()} recovered of ${due.toLocaleString()} due-window base`;
    }

    if (key === 'collected_revenue') {
        return `${Number(metric.value?.payments_count || 0).toLocaleString()} successful payments`;
    }

    return 'Compared with the prior matching window';
}

export default function CeoMetricStrip({ metrics = {}, reporting, isLoading, onOpen }) {
    const order = [
        ['collected_revenue', 'Collected Revenue'],
        ['active_clients', 'Active Users'],
        ['new_activations', 'New Activations'],
        ['renewals_recovered', 'Renewals Recovered'],
    ];

    return (
        <section className="grid gap-4 md:grid-cols-2 xl:grid-cols-4">
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
