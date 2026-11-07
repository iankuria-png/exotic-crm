import React from 'react';

function formatCurrency(value, currency = 'KES') {
    return `${currency} ${Number(value || 0).toLocaleString()}`;
}

export function normalizeDiscountPercentage(value) {
    const parsed = Number.parseFloat(String(value ?? '').trim());
    if (!Number.isFinite(parsed) || parsed <= 0) return 0;
    return roundPercent(parsed);
}

export function roundMoney(value) {
    const parsed = Number(value || 0);
    if (!Number.isFinite(parsed)) return 0;
    return Math.round(parsed * 100) / 100;
}

export function roundPercent(value) {
    const parsed = Number(value || 0);
    if (!Number.isFinite(parsed)) return 0;
    return Math.round(parsed * 100) / 100;
}

export function discountedAmount(baseAmount, discountPercentage) {
    const safeBase = Number(baseAmount || 0);
    const safeDiscount = normalizeDiscountPercentage(discountPercentage);
    if (safeDiscount <= 0) return safeBase;
    return roundMoney(safeBase * (1 - safeDiscount / 100));
}

export function normalizePayableAmount(value) {
    const raw = String(value ?? '').trim();
    if (raw === '') return null;

    const parsed = Number.parseFloat(raw);
    if (!Number.isFinite(parsed) || parsed <= 0) return null;

    return roundMoney(parsed);
}

export function discountPercentageFromPayable(baseAmount, payableAmount) {
    const safeBase = Number(baseAmount || 0);
    const safePayable = Number(payableAmount || 0);
    if (!Number.isFinite(safeBase) || !Number.isFinite(safePayable) || safeBase <= 0) return 0;
    return roundPercent(((safeBase - safePayable) / safeBase) * 100);
}

export function effectiveDiscountedAmount(baseAmount, discountPercentage, payableAmount) {
    const payable = normalizePayableAmount(payableAmount);
    const payableDiscount = payable !== null ? discountPercentageFromPayable(baseAmount, payable) : 0;
    if (payable !== null && payableDiscount >= 1 && payableDiscount <= 99) {
        return payable;
    }

    return discountedAmount(baseAmount, discountPercentage);
}

export function formatNumericInput(value) {
    const parsed = Number(value);
    if (!Number.isFinite(parsed)) return '';
    return String(roundMoney(parsed));
}

export function formatPercentInput(value) {
    const parsed = Number(value);
    if (!Number.isFinite(parsed)) return '';
    return String(roundPercent(parsed));
}

export default function DiscountPricingEditor({
    idPrefix,
    applyDiscount,
    onToggle,
    baseAmount,
    currency,
    payableAmount,
    discountPercentage,
    discountPin,
    discountedTotal,
    savingsAmount,
    onPayableChange,
    onPayableBlur,
    onPercentageChange,
    onPinChange,
}) {
    const discountValue = normalizeDiscountPercentage(discountPercentage);
    const baseLabel = formatCurrency(baseAmount, currency);
    const payableLabel = formatCurrency(discountedTotal, currency);
    const savingsLabel = formatCurrency(Math.max(0, savingsAmount), currency);
    const minimumPayableAmount = roundMoney(Number(baseAmount || 0) * 0.01);
    const discountOutOfRange = discountValue > 99;
    const payableInputId = `${idPrefix}-discount-payable`;
    const percentageInputId = `${idPrefix}-discount-percentage`;
    const pinInputId = `${idPrefix}-discount-pin`;

    return (
        <div className="space-y-3 rounded-md border border-amber-200 bg-amber-50/60 p-3">
            <label className="flex items-center gap-2 text-sm font-medium text-slate-700">
                <input
                    type="checkbox"
                    checked={applyDiscount}
                    onChange={(event) => onToggle(event.target.checked)}
                    className="h-4 w-4 rounded border-slate-300 text-amber-600 focus:ring-amber-200"
                />
                Apply Discount
            </label>

            {applyDiscount ? (
                <>
                    <div className="grid gap-3 sm:grid-cols-[minmax(0,1fr)_minmax(0,0.8fr)]">
                        <div>
                            <label htmlFor={payableInputId} className="mb-1 block text-sm font-medium text-slate-700">Final payable amount</label>
                            <input
                                id={payableInputId}
                                type="number"
                                min="0"
                                max={baseAmount || undefined}
                                step="0.01"
                                value={payableAmount}
                                onChange={(event) => onPayableChange(event.target.value)}
                                onBlur={onPayableBlur}
                                className="crm-input"
                                placeholder={`e.g. ${formatNumericInput(discountedAmount(baseAmount, 10)) || '2500'}`}
                            />
                            {discountOutOfRange ? (
                                <p className="mt-1 text-xs text-rose-600">
                                    Minimum payable is {formatCurrency(minimumPayableAmount, currency)} for the 99% discount limit.
                                </p>
                            ) : null}
                        </div>
                        <div>
                            <label htmlFor={percentageInputId} className="mb-1 block text-sm font-medium text-slate-700">Discount %</label>
                            <input
                                id={percentageInputId}
                                type="number"
                                min="1"
                                max="99"
                                step="0.01"
                                value={discountPercentage}
                                onChange={(event) => onPercentageChange(event.target.value)}
                                className="crm-input"
                                placeholder="e.g. 20"
                            />
                        </div>
                    </div>

                    <div>
                        <label htmlFor={pinInputId} className="mb-1 block text-sm font-medium text-slate-700">Discount PIN</label>
                        <input
                            id={pinInputId}
                            type="password"
                            inputMode="numeric"
                            maxLength={6}
                            value={discountPin}
                            onChange={(event) => onPinChange(event.target.value.replace(/\D/g, '').slice(0, 6))}
                            className="crm-input"
                            placeholder="Enter discount PIN"
                        />
                    </div>

                    <div className="rounded-md border border-amber-200 bg-white px-3 py-2 text-sm text-slate-700">
                        <div className="flex flex-wrap items-start justify-between gap-3">
                            <div>
                                <p className="font-medium text-slate-900">Price preview</p>
                                <p className="mt-1 flex flex-wrap items-center gap-2">
                                    <span className="line-through text-slate-500">{baseLabel}</span>
                                    <span className="text-base font-semibold text-slate-900">{payableLabel}</span>
                                    {discountValue > 0 ? (
                                        <span className="text-xs font-semibold uppercase tracking-wide text-amber-700">{discountValue}% off</span>
                                    ) : null}
                                </p>
                            </div>
                            <div className="text-right">
                                <p className="text-[11px] font-semibold uppercase tracking-[0.08em] text-slate-400">Savings</p>
                                <p className="mt-1 text-sm font-semibold text-slate-900">{savingsLabel}</p>
                            </div>
                        </div>
                        <p className="mt-2 text-xs text-slate-500">Final payable is saved exactly when entered. Percentage is used for approvals and audit display.</p>
                    </div>
                </>
            ) : null}
        </div>
    );
}
