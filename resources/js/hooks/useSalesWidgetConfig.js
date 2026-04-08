import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import api from '../services/api';

export const SALES_WIDGET_DEFAULTS = {
    todos: true,
    goals: true,
    expiring_subs: true,
    payment_recovery: true,
    top_countries: true,
    top_packages: true,
    profile_engagement: true,
    missed_chats: true,
};

export const SALES_WIDGET_LABELS = {
    todos: {
        name: 'Action List',
        description: 'Personal checklist and fast follow-up composer for sales agents.',
    },
    goals: {
        name: 'Current Goals',
        description: 'Progress trackers for weekly and monthly targets in the active sales view.',
    },
    expiring_subs: {
        name: 'Expiring Subscriptions',
        description: 'Clients approaching expiry so renewal outreach stays visible.',
    },
    payment_recovery: {
        name: 'Payment Recovery',
        description: 'Recovery queue with failed, pending, and unmatched payment signals.',
    },
    top_countries: {
        name: 'Top Countries',
        description: 'Country-level momentum and revenue trends for active markets.',
    },
    top_packages: {
        name: 'Top Packages',
        description: 'Best-selling packages in the selected dashboard window.',
    },
    profile_engagement: {
        name: 'Profile Engagement',
        description: 'WordPress profile performance for the selected market.',
    },
    missed_chats: {
        name: 'Missed Chats',
        description: 'Open support board conversations that still need attention.',
    },
};

function normalizeConfig(input) {
    const next = { ...SALES_WIDGET_DEFAULTS };

    if (!input || typeof input !== 'object') {
        return next;
    }

    for (const key of Object.keys(SALES_WIDGET_DEFAULTS)) {
        if (Object.prototype.hasOwnProperty.call(input, key)) {
            next[key] = Boolean(input[key]);
        }
    }

    return next;
}

export default function useSalesWidgetConfig(options = {}) {
    const enabled = options.enabled ?? true;

    const query = useQuery({
        queryKey: ['sales-dashboard-widgets'],
        queryFn: () => api.get('/crm/settings/sales-dashboard-widgets').then((response) => response.data),
        enabled,
        staleTime: 300_000,
    });

    return {
        ...query,
        config: normalizeConfig(query.data?.widgets),
        defaults: normalizeConfig(query.data?.defaults),
        editable: Boolean(query.data?.editable),
        labels: SALES_WIDGET_LABELS,
    };
}

export function useUpdateSalesWidgetConfig() {
    const queryClient = useQueryClient();

    return useMutation({
        mutationFn: (widgets) => api.patch('/crm/settings/sales-dashboard-widgets', { widgets }).then((response) => response.data),
        onSuccess: (data) => {
            queryClient.setQueryData(['sales-dashboard-widgets'], data);
        },
    });
}
