import React from 'react';

function describeMissing(meta) {
    const currencies = Array.isArray(meta?.missing_currencies) ? meta.missing_currencies.filter(Boolean) : [];
    if (currencies.length === 0) {
        return 'Some rates are unavailable; native values remain authoritative.';
    }

    const visible = currencies.slice(0, 3).join(', ');
    const extra = currencies.length > 3 ? ` +${currencies.length - 3}` : '';
    return `Missing FX for ${visible}${extra}; native values remain authoritative.`;
}

export default function FxNormalizationNotice({ meta, className = '' }) {
    if (!meta) {
        return null;
    }

    const isPartial = Boolean(meta.partial);
    const isStale = Boolean(meta.stale);

    if (!isPartial && !isStale) {
        return null;
    }

    const title = isPartial
        ? describeMissing(meta)
        : `Using cached FX as of ${meta.as_of || 'the last available rate'}.`;

    return (
        <span
            className={`inline-flex max-w-full items-center gap-1 rounded border px-2 py-1 text-[11px] font-semibold ${
                isPartial
                    ? 'border-amber-200 bg-amber-50 text-amber-800'
                    : 'border-slate-200 bg-slate-50 text-slate-600'
            } ${className}`}
            title={title}
        >
            <span aria-hidden="true">{isPartial ? '!' : 'i'}</span>
            <span className="truncate">{isPartial ? 'Partial FX' : 'Stale FX'}</span>
        </span>
    );
}
