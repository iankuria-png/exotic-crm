import React from 'react';

function formatShortDate(value) {
    if (!value) {
        return null;
    }

    const date = new Date(`${value}T00:00:00`);
    if (Number.isNaN(date.getTime())) {
        return value;
    }

    return date.toLocaleDateString('en-KE', {
        day: 'numeric',
        month: 'short',
    });
}

function formatInverseRate(rate) {
    const numeric = Number(rate || 0);
    if (!Number.isFinite(numeric) || numeric <= 0) {
        return null;
    }

    const inverted = 1 / numeric;

    if (inverted >= 100) {
        return inverted.toFixed(1);
    }

    if (inverted >= 10) {
        return inverted.toFixed(2);
    }

    if (inverted >= 1) {
        return inverted.toFixed(3);
    }

    return inverted.toFixed(4);
}

function uniqueRateRows(meta) {
    const rows = Array.isArray(meta?.rows) ? meta.rows : [];
    const seen = new Set();

    return rows.filter((row) => {
        const source = String(row?.source_currency || '').trim();
        const rate = Number(row?.rate || 0);

        if (!source || !Number.isFinite(rate) || rate <= 0) {
            return false;
        }

        const key = `${source}:${rate}:${row?.rate_date || ''}`;
        if (seen.has(key)) {
            return false;
        }

        seen.add(key);
        return true;
    });
}

function describeMissing(meta) {
    const currencies = Array.isArray(meta?.missing_currencies) ? meta.missing_currencies.filter(Boolean) : [];
    if (currencies.length === 0) {
        return 'Some FX rates are unavailable; native values remain authoritative.';
    }

    const visible = currencies.slice(0, 3).join(', ');
    const extra = currencies.length > 3 ? ` +${currencies.length - 3}` : '';
    return `Missing FX for ${visible}${extra}; native values remain authoritative.`;
}

function describeAliases(meta) {
    const aliases = Array.isArray(meta?.currency_aliases) ? meta.currency_aliases : [];
    if (aliases.length === 0) {
        return [];
    }

    return aliases
        .filter((row) => row?.source_currency && row?.canonical_currency)
        .map((row) => `${row.source_currency} via ${row.canonical_currency}`);
}

function buildStaleSummary(meta) {
    const rows = uniqueRateRows(meta);
    const targetCurrency = String(meta?.target_currency || '').trim().toUpperCase() || 'USD';

    if (rows.length === 0) {
        return {
            label: 'FX: cached rate',
            title: `Using cached FX as of ${formatShortDate(meta?.as_of) || 'the last available rate'}.`,
        };
    }

    const first = rows[0];
    const inverse = formatInverseRate(first.rate);
    const dateLabel = formatShortDate(first.rate_date || meta?.as_of);
    const sourceCurrency = String(first.source_currency || '').trim().toUpperCase();
    const extraCount = rows.length - 1;
    const summary = inverse
        ? `FX: ${targetCurrency} 1 = ${sourceCurrency} ${inverse}${extraCount > 0 ? ` +${extraCount}` : ''}`
        : 'FX: cached rate';

    const titleLines = [
        'Using cached FX rates.',
        ...rows.map((row) => {
            const nativePerTarget = formatInverseRate(row.rate);
            const rowTargetCurrency = String(meta?.target_currency || targetCurrency).trim().toUpperCase() || targetCurrency;
            const rowSourceCurrency = String(row.source_currency || '').trim().toUpperCase();
            const rowDate = formatShortDate(row.rate_date);

            if (!nativePerTarget) {
                return null;
            }

            return `${rowTargetCurrency} 1 = ${rowSourceCurrency} ${nativePerTarget}${rowDate ? ` on ${rowDate}` : ''}`;
        }).filter(Boolean),
    ];

    const aliases = describeAliases(meta);
    if (aliases.length > 0) {
        titleLines.push(`Aliases: ${aliases.join(', ')}`);
    }

    return {
        label: `${summary}${dateLabel ? ` · ${dateLabel}` : ''}`,
        title: titleLines.join('\n'),
    };
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

    const staleSummary = buildStaleSummary(meta);
    const title = isPartial
        ? [describeMissing(meta), staleSummary.title].filter(Boolean).join('\n')
        : staleSummary.title;
    const label = isPartial
        ? `FX partial${meta?.missing_currencies?.length ? ` · ${meta.missing_currencies.slice(0, 2).join(', ')}${meta.missing_currencies.length > 2 ? ` +${meta.missing_currencies.length - 2}` : ''}` : ''}`
        : staleSummary.label;

    return (
        <span
            className={`inline-flex max-w-full items-center gap-1 text-[11px] font-medium ${
                isPartial
                    ? 'text-amber-700'
                    : 'text-slate-500'
            } ${className}`}
            title={title}
        >
            <span aria-hidden="true" className={`h-1.5 w-1.5 shrink-0 rounded-full ${isPartial ? 'bg-amber-500' : 'bg-slate-400'}`} />
            <span className="truncate">{label}</span>
        </span>
    );
}
