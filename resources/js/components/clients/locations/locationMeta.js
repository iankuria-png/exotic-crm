export const PERIOD_OPTIONS = [
    { key: '7d', label: '7d', days: 7 },
    { key: '30d', label: '30d', days: 30 },
    { key: '90d', label: '90d', days: 90 },
];

export const BANDS = {
    strong: {
        dot: 'bg-emerald-500',
        chip: 'bg-emerald-50 text-emerald-700 border-emerald-200',
        marker: '#10b981',
        label: 'Strong',
    },
    moderate: {
        dot: 'bg-amber-500',
        chip: 'bg-amber-50 text-amber-700 border-amber-200',
        marker: '#f59e0b',
        label: 'Moderate',
    },
    weak: {
        dot: 'bg-rose-500',
        chip: 'bg-rose-50 text-rose-700 border-rose-200',
        marker: '#f43f5e',
        label: 'Weak',
    },
    insufficient: {
        dot: 'bg-slate-300',
        chip: 'bg-slate-50 text-slate-500 border-slate-200',
        marker: '#94a3b8',
        label: 'Low sample',
    },
    unavailable: {
        dot: 'bg-slate-300',
        chip: 'bg-slate-100 text-slate-400 border-slate-200',
        marker: '#cbd5e1',
        label: 'No data',
    },
};

export const CHANNEL_META = {
    whatsapp: {
        key: 'whatsapp',
        label: 'WhatsApp',
        color: '#10b981',
        dot: 'bg-emerald-500',
        text: 'text-emerald-700',
    },
    phone: {
        key: 'phone',
        label: 'Phone',
        color: '#06b6d4',
        dot: 'bg-cyan-500',
        text: 'text-cyan-700',
    },
    viber: {
        key: 'viber',
        label: 'Viber',
        color: '#8b5cf6',
        dot: 'bg-violet-500',
        text: 'text-violet-700',
    },
};

export const CHANNEL_ORDER = ['whatsapp', 'phone', 'viber'];
