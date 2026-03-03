import { useCallback, useSyncExternalStore } from 'react';

const STORAGE_KEY = 'exoticcrm.dashboard.widget_config';

const DEFAULT_CONFIG = {
    country_revenue: true,
    expiring_subs: true,
    follow_ups: true,
    performance_pulse: true,
    quick_stats: true,
    recent_activity: true,
    comms_balance: true,
};

const WIDGET_LABELS = {
    country_revenue: { name: 'Top Performing Countries', description: 'Revenue by market with trend arrows' },
    expiring_subs: { name: 'Expiring Subscriptions', description: 'Subscriptions due for renewal soon' },
    follow_ups: { name: 'Upcoming Follow-ups', description: 'Scheduled client callbacks due soon' },
    performance_pulse: { name: 'Performance Pulse', description: 'Health indicators for match quality, leads, and coverage' },
    quick_stats: { name: 'Quick Stats', description: 'New leads, pending follow-ups, and active campaigns' },
    recent_activity: { name: 'Recent Activity', description: 'Latest timeline events across the system' },
    comms_balance: { name: 'Comms & Delivery', description: 'SMS delivery stats and recent send metrics' },
};

let listeners = [];

function emitChange() {
    for (const listener of listeners) {
        listener();
    }
}

function getSnapshot() {
    try {
        const stored = window.localStorage.getItem(STORAGE_KEY);
        return stored || JSON.stringify(DEFAULT_CONFIG);
    } catch {
        return JSON.stringify(DEFAULT_CONFIG);
    }
}

function subscribe(listener) {
    listeners = [...listeners, listener];
    return () => {
        listeners = listeners.filter((l) => l !== listener);
    };
}

export default function useDashboardWidgets() {
    const raw = useSyncExternalStore(subscribe, getSnapshot);
    const config = { ...DEFAULT_CONFIG, ...JSON.parse(raw) };

    const toggle = useCallback((key) => {
        const current = { ...DEFAULT_CONFIG, ...JSON.parse(getSnapshot()) };
        current[key] = !current[key];
        window.localStorage.setItem(STORAGE_KEY, JSON.stringify(current));
        emitChange();
    }, []);

    const reset = useCallback(() => {
        window.localStorage.setItem(STORAGE_KEY, JSON.stringify(DEFAULT_CONFIG));
        emitChange();
    }, []);

    return { config, toggle, reset, labels: WIDGET_LABELS };
}
