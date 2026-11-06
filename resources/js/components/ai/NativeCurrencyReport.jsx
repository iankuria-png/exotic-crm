import React from 'react';

export function nativeMoney(value, currency) {
    return `${currency} ${Number(value || 0).toLocaleString('en-GB', { maximumFractionDigits: 2 })}`;
}

export function nativeAmountText(amounts, field = 'current') {
    if (!Array.isArray(amounts)) return 'Native data unavailable';
    if (!amounts.length) return 'No payments';
    return amounts.map((row) => `${field === 'delta' && row.delta > 0 ? '+' : ''}${nativeMoney(row[field], row.currency)}`).join(' / ');
}

export function NativeAmounts({ amounts, field = 'current' }) {
    if (!Array.isArray(amounts) || !amounts.length) {
        return <span className="text-xs text-slate-500">{nativeAmountText(amounts, field)}</span>;
    }
    return (
        <div className="space-y-1.5">
            {amounts.map((row) => (
                <div key={row.key} className="whitespace-nowrap">
                    <span className={field === 'delta' ? (row.delta >= 0 ? 'text-emerald-700' : 'text-rose-700') : ''}>
                        {field === 'delta' && row.delta > 0 ? '+' : ''}{nativeMoney(row[field], row.currency)}
                    </span>
                    {field === 'current' ? <span className="block font-sans text-[11px] text-slate-500">{nativeMoney(row.prior, row.currency)} previous</span> : null}
                </div>
            ))}
        </div>
    );
}

export function CurrencyViewControls({ mode, onModeChange, native, selectedKey, onCurrencyChange, reportingCurrency }) {
    const ready = native?.status === 'ready';
    const currencies = native?.currencies || [];
    const historical = native?.source === 'historical_read';
    const stamp = native?.captured_at ? new Date(native.captured_at).toLocaleString('en-GB', {
        day: 'numeric', month: 'short', year: 'numeric', hour: '2-digit', minute: '2-digit', timeZone: 'Africa/Nairobi',
    }) : '';
    return (
        <section aria-label="Currency presentation" className="mb-5 rounded-xl border border-slate-200 bg-white px-4 py-4 shadow-sm sm:px-5">
            <div className="flex flex-wrap items-center justify-between gap-4">
                <div>
                    <p className="text-[10px] font-bold uppercase tracking-[0.18em] text-teal-700">Report currency</p>
                    <p className="mt-1 text-sm font-semibold text-slate-950">Choose your revenue view</p>
                </div>
                <div role="group" aria-label="Currency view" className="briefing-no-print flex w-full rounded-lg bg-slate-100 p-1 sm:w-auto">
                    {[
                        ['reporting', `Reporting ${reportingCurrency}`],
                        ['native', 'Native currencies'],
                    ].map(([value, label]) => (
                        <button key={value} type="button" aria-pressed={mode === value} disabled={value === 'native' && !ready}
                            onClick={() => onModeChange(value)}
                            className={`min-h-11 flex-1 rounded-md px-3 py-2 text-xs font-semibold transition-colors duration-150 motion-reduce:transition-none focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-teal-600 focus-visible:ring-offset-2 disabled:cursor-not-allowed disabled:opacity-45 sm:flex-none ${mode === value ? 'bg-slate-950 text-white shadow-sm' : 'text-slate-600 hover:bg-white hover:text-slate-950'}`}>
                            {label}
                        </button>
                    ))}
                </div>
            </div>
            <div className="mt-3 flex flex-wrap items-center justify-between gap-3 border-t border-slate-100 pt-3">
                <p className="max-w-3xl text-xs leading-5 text-slate-500" aria-live="polite">
                    {mode === 'native'
                        ? `Original payment amounts, grouped by currency. Commentary, portfolio ranking and churn estimates remain in ${reportingCurrency}.`
                        : `A common ${reportingCurrency} basis for comparison across markets. Switch to native currencies to inspect original payment amounts.`}
                    {!ready ? ' Native amounts are unavailable for this archived report.' : ''}
                </p>
                {mode === 'native' && currencies.length ? (
                    <label className="flex w-full items-center gap-2 text-xs font-medium text-slate-600 sm:w-auto">
                        <span className="shrink-0">Revenue cards</span>
                        <select aria-label="Revenue currency" value={selectedKey} onChange={(event) => onCurrencyChange(event.target.value)}
                            className="min-h-11 min-w-0 flex-1 rounded-md border border-slate-300 bg-white px-3 py-2 text-sm font-semibold text-slate-900 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-teal-600 sm:max-w-64">
                            {currencies.map((row) => <option key={row.key} value={row.key}>{row.currency}</option>)}
                        </select>
                    </label>
                ) : null}
            </div>
            {mode === 'native' ? (
                <p className="mt-2 text-[11px] leading-5 text-slate-500">
                    View: Native currencies. {currencies.length ? `${currencies.length} currency group${currencies.length === 1 ? '' : 's'}; revenue cards show the selected group. Markets and staff show their own currencies.` : 'No reportable payments in either week.'}
                    {historical ? ` Historical payments read ${stamp} EAT; amounts may differ from the original publication.` : ` Captured with this scorecard${stamp ? ` · ${stamp} EAT` : ''}.`}
                </p>
            ) : <p className="hidden print:block text-xs">View: Reporting {reportingCurrency}</p>}
        </section>
    );
}

export function sourceCurrencyMoney(recovery, field) {
    const breakdown = recovery?.[`${field}_breakdown`];
    if (!breakdown || typeof breakdown !== 'object' || !Object.keys(breakdown).length) {
        return Number(recovery?.[field]) === 0 ? 'No value recorded' : 'Native data unavailable';
    }
    return Object.entries(breakdown).map(([currency, amount]) => nativeMoney(amount, currency)).join(' / ');
}
