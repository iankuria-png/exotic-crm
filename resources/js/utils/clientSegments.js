export const CLIENT_SEGMENTS = [
    {
        key: 'active',
        label: 'Active',
        classes: 'border-emerald-200 bg-emerald-50 text-emerald-700',
        activeClasses: 'border-emerald-500 bg-emerald-600 text-white',
    },
    {
        key: 'suspended',
        label: 'Suspended',
        classes: 'border-rose-200 bg-rose-50 text-rose-700',
        activeClasses: 'border-rose-500 bg-rose-600 text-white',
    },
    {
        key: 'duplicate',
        label: 'Duplicate',
        classes: 'border-amber-200 bg-amber-50 text-amber-700',
        activeClasses: 'border-amber-500 bg-amber-500 text-white',
    },
    {
        key: 'churned',
        label: 'Churned',
        classes: 'border-slate-200 bg-slate-50 text-slate-700',
        activeClasses: 'border-slate-600 bg-slate-700 text-white',
    },
    {
        key: 'verification_pending',
        label: 'Verification pending',
        classes: 'border-sky-200 bg-sky-50 text-sky-700',
        activeClasses: 'border-sky-500 bg-sky-600 text-white',
    },
    {
        key: 'never_paid',
        label: 'Never paid',
        classes: 'border-indigo-200 bg-indigo-50 text-indigo-700',
        activeClasses: 'border-indigo-500 bg-indigo-600 text-white',
    },
    {
        key: 'abandoned_other',
        label: 'Abandoned / other',
        classes: 'border-stone-200 bg-stone-50 text-stone-700',
        activeClasses: 'border-stone-600 bg-stone-700 text-white',
    },
];

export const CLIENT_SEGMENT_KEYS = CLIENT_SEGMENTS.map((segment) => segment.key);
