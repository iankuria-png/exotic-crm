import { formatCurrency } from '../utils/currency';

/**
 * Renders a currency amount safely.
 *
 * - Single currency:  one line, e.g. "KES 5,000"
 * - Multiple currencies: stacked lines, one per currency (alphabetical)
 * - Empty breakdown + null scalar: shows "0" in fallbackCurrency
 *
 * Props
 * ─────
 * breakdown        {object|null}   e.g. { GHS: 380, KES: 2000 }
 * scalarAmount     {number|null}   pre-computed scalar (non-null only when single-currency)
 * fallbackCurrency {string}        used only when breakdown is empty/null
 * className        {string}        applied to the wrapper element
 * stackClassName   {string}        applied to each line in mixed mode
 */
export default function CurrencyAmount({
    breakdown,
    scalarAmount,
    fallbackCurrency = 'KES',
    className,
    stackClassName,
}) {
    const entries = Object.entries(breakdown ?? {});

    if (entries.length === 0) {
        return (
            <span className={className}>
                {formatCurrency(scalarAmount ?? 0, fallbackCurrency)}
            </span>
        );
    }

    if (entries.length === 1) {
        const [code, amount] = entries[0];
        return (
            <span className={className}>
                {formatCurrency(amount, code)}
            </span>
        );
    }

    // Mixed currencies — render stacked
    return (
        <div className={className}>
            {entries.map(([code, amount]) => (
                <div key={code} className={stackClassName}>
                    {formatCurrency(amount, code)}
                </div>
            ))}
        </div>
    );
}
