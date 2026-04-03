import React from 'react';

import MetricCard from '../MetricCard';

export default function IntegrationsMetricsRow({
    connectedServices,
    marketCount,
    packageReadyMarkets,
    syncErrors,
    wpReadyMarkets,
}) {
    return (
        <section className="grid gap-4 md:grid-cols-5">
            <MetricCard
                label="Connected Services"
                value={connectedServices.toLocaleString()}
                meta="runtime integration health"
                tone="success"
            />
            <MetricCard
                label="Markets Configured"
                value={marketCount.toLocaleString()}
                meta="platform runtime profiles"
                tone="accent"
            />
            <MetricCard
                label="WP Sync Ready"
                value={wpReadyMarkets.toLocaleString()}
                meta="markets with credentials"
                tone="default"
            />
            <MetricCard
                label="Sync Errors"
                value={syncErrors.toLocaleString()}
                meta="markets requiring intervention"
                tone={syncErrors > 0 ? 'danger' : 'success'}
            />
            <MetricCard
                label="Packages Ready"
                value={packageReadyMarkets.toLocaleString()}
                meta="markets ready to go live"
                tone={packageReadyMarkets < marketCount ? 'warning' : 'success'}
            />
        </section>
    );
}
