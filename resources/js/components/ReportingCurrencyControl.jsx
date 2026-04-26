const COMMON_TARGETS = ['USD', 'KES', 'GHS', 'NGN', 'TZS'];

export default function ReportingCurrencyControl({ reporting, className = '' }) {
    if (!reporting) {
        return null;
    }

    const badgeLabel = reporting.isFlat
        ? `Converted to ${reporting.targetCurrency}`
        : 'Native currencies';

    return (
        <div className={`flex flex-wrap items-center gap-2 ${className}`}>
            <div className="inline-flex rounded-md border border-slate-300 bg-white p-0.5" role="group" aria-label="Currency display mode">
                <button
                    type="button"
                    onClick={() => reporting.setDisplayMode('flat')}
                    className={`rounded px-3 py-1.5 text-xs font-semibold transition ${reporting.displayMode === 'flat' ? 'bg-teal-700 text-white shadow-sm' : 'text-slate-600 hover:bg-slate-50'}`}
                >
                    Flat
                </button>
                <button
                    type="button"
                    onClick={() => reporting.setDisplayMode('native')}
                    className={`rounded px-3 py-1.5 text-xs font-semibold transition ${reporting.displayMode === 'native' ? 'bg-slate-800 text-white shadow-sm' : 'text-slate-600 hover:bg-slate-50'}`}
                >
                    Native
                </button>
            </div>

            {reporting.allowUserOverride ? (
                <select
                    value={reporting.targetCurrency}
                    onChange={(event) => reporting.setTargetCurrency(event.target.value)}
                    className="h-9 rounded-md border border-slate-300 bg-white px-2 text-xs font-semibold text-slate-700 focus:border-teal-500 focus:outline-none focus:ring-2 focus:ring-teal-100"
                    aria-label="Reporting currency"
                    disabled={!reporting.isFlat}
                >
                    {COMMON_TARGETS.map((currency) => (
                        <option key={currency} value={currency}>{currency}</option>
                    ))}
                </select>
            ) : null}

            <span className="inline-flex h-9 items-center rounded-full border border-teal-200 bg-teal-50 px-3 text-xs font-semibold text-teal-800">
                {badgeLabel}
            </span>
        </div>
    );
}
