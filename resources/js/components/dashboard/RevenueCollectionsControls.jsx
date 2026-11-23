import React from 'react';
import { cashMoney, CashChange } from './ProductRevenueView';
import { formatCurrency } from '../../utils/currency';
import './revenueCollections.css';

export const REVENUE_PRODUCTS = [
    { key: 'all', label: 'All products' },
    { key: 'subscription', label: 'Subscriptions' },
    { key: 'contact_unlock', label: 'Contact unlocks' },
    { key: 'premium_content_sale', label: 'Content sales' },
    { key: 'send_love', label: 'Gifts' },
    { key: 'passes', label: 'Passes' },
];

export function collectionWindowLabel(window) {
    if (!window?.from || !window?.to) return '';
    const start = new Date(`${String(window.from).slice(0, 10)}T12:00:00+03:00`);
    const end = new Date(`${String(window.to).slice(0, 10)}T12:00:00+03:00`);
    const format = (date, year) => date.toLocaleDateString('en-GB', { timeZone: 'Africa/Nairobi', day: 'numeric', month: 'short', ...(year ? { year: 'numeric' } : {}) });
    return `${format(start, start.getFullYear() !== end.getFullYear())} – ${format(end, true)}`;
}

export function RevenueProductSelector({ value, onChange }) {
    const selectByKey = (event, index) => {
        const next = event.key === 'ArrowRight' || event.key === 'ArrowDown' ? (index + 1) % REVENUE_PRODUCTS.length
            : event.key === 'ArrowLeft' || event.key === 'ArrowUp' ? (index + REVENUE_PRODUCTS.length - 1) % REVENUE_PRODUCTS.length
                : event.key === 'Home' ? 0 : event.key === 'End' ? REVENUE_PRODUCTS.length - 1 : null;
        if (next === null) return;
        event.preventDefault(); onChange(REVENUE_PRODUCTS[next].key);
        event.currentTarget.parentElement.children[next]?.focus();
    };
    return <div className="collections-product-choice">
        <div className="collections-product-radio" role="radiogroup" aria-label="Revenue product">
            {REVENUE_PRODUCTS.map((product, index) => <button key={product.key} type="button" role="radio"
                aria-checked={value === product.key} tabIndex={value === product.key ? 0 : -1}
                onKeyDown={(event) => selectByKey(event, index)} onClick={() => onChange(product.key)}>
                {product.label}
            </button>)}
        </div>
        <label className="collections-product-mobile"><span>Product</span><select aria-label="Revenue product" value={value} onChange={(event) => onChange(event.target.value)}>
            {REVENUE_PRODUCTS.map((product) => <option key={product.key} value={product.key}>{product.label}</option>)}
        </select></label>
    </div>;
}

export function RevenueCollectionSummary({ collections, loading, error, product, displayMode, currency }) {
    const label = REVENUE_PRODUCTS.find((item) => item.key === product)?.label || 'All products';
    if (loading || !collections || error) return <div className="collections-summary collections-summary-pending" aria-live="polite">
        <p className="collections-kicker">{label}</p><p>{error ? 'Collections could not be loaded.' : `Loading ${label.toLowerCase()}…`}</p>
    </div>;
    const current = collections.selected;
    return <div className="collections-summary" aria-live="polite">
        <div><p className="collections-kicker">{label} <span>· provisional cash</span></p>
            <p className="collections-value">{cashMoney(current, displayMode)}</p>
            <p className="collections-summary-meta">{current.payments_count.toLocaleString()} cash {current.payments_count === 1 ? 'payment' : 'payments'}
                {collections.average_ticket != null && current.payments_count > 0 ? ` · Avg ${formatCurrency(collections.average_ticket, currency)}` : ''}
            </p>
        </div>
        <div className="collections-summary-comparison"><CashChange value={collections.change_percent} />
            <p>vs matched prior period · {currency}</p><p>{cashMoney(collections.prior, displayMode)}</p>
        </div>
    </div>;
}

export function RevenueChartOptions({ open, metric, onMetricChange, metrics, bucket, onBucketChange, buckets, showComparison, onComparisonChange, hourly, onClose }) {
    return <div id="revenue-chart-options" className="collections-options" hidden={!open}
        onKeyDown={(event) => { if (event.key === 'Escape') { event.preventDefault(); onClose(); } }}>
        <div><p>Measure</p><div role="group" aria-label="Trend metric" className="collections-option-segments">
            {metrics.map((item) => <button key={item.key} type="button" aria-pressed={metric === item.key} onClick={() => onMetricChange(item.key)}>{item.label}</button>)}
        </div></div>
        {!hourly && <div><p>Group by</p><div role="group" aria-label="Trend bucket" className="collections-option-segments">
            {buckets.map((item) => <button key={item.key} type="button" aria-pressed={bucket === item.key} onClick={() => onBucketChange(item.key)}>{item.label}</button>)}
        </div></div>}
        <div><p>Comparison</p><button type="button" className="collections-prior-toggle" aria-pressed={showComparison} onClick={() => onComparisonChange(!showComparison)}>
            <span aria-hidden="true">{showComparison ? '✓' : '+'}</span> Prior period
        </button></div>
    </div>;
}
