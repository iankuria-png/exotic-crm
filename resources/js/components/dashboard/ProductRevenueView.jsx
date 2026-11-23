import React from 'react';
import { formatCurrency } from '../../utils/currency';
import './productRevenue.css';

export function cashMoney(money, mode = 'flat') {
    if (!money) return '—';
    if (mode === 'native') {
        const entries = Object.entries(money.native || {});
        return entries.length ? entries.map(([currency, amount]) => formatCurrency(amount, currency)).join(' · ') : 'No cash payments';
    }
    return money.normalized_total === null || money.normalized_total === undefined
        ? 'FX unavailable' : formatCurrency(money.normalized_total, money.normalized_currency || 'USD');
}

export function CashChange({ value }) {
    return <span className={`cash-change ${value == null ? '' : value < 0 ? 'cash-change-down' : 'cash-change-up'}`}>
        {value == null ? 'No comparable baseline' : `${value > 0 ? '↗ +' : value < 0 ? '↘ ' : ''}${Number(value).toFixed(1)}%`}
    </span>;
}

export function cashWindow(window) {
    const date = (value) => value ? new Date(value).toLocaleDateString('en-GB', { timeZone: 'Africa/Nairobi', day: 'numeric', month: 'short', year: 'numeric' }) : '—';
    // Exclusive midnight belongs to the preceding reporting day.
    const end = window?.to ? new Date(new Date(window.to).getTime() - 1).toISOString() : null;
    return `${date(window?.from)} – ${date(end)}`;
}

export function RevenueBasis({ data }) {
    return <details className="cash-basis">
        <summary>Basis & periods</summary>
        <div className="cash-basis-body">
            <p><strong>Provisional external cash.</strong> Successful CRM receipts, counted once. Content and gifts include externally funded payments, even when the linked wallet entry credits proceeds. Wallet spending, top-ups, refunds, ambiguous receipts and unknown funding are excluded.</p>
            <p>Occurrence: completed, then confirmed, then created time. Calendar periods use Africa/Nairobi; this week starts Monday. Cached FX is applied to each payment using its UTC cash date, then summed in cents. Missing FX retains original money.</p>
            {data && <><p>Selected: {cashWindow(data.window)}{data.window?.partial ? ' · in progress' : ''}. Prior: {cashWindow(data.prior_window)} · equal elapsed time.</p>
                <p>As of {new Date(data.as_of).toLocaleString('en-GB', { timeZone: 'Africa/Nairobi' })} EAT. Today, week and month share the selected market scope.</p>
                <p>Coverage: {data.coverage?.ambiguous_receipts?.payments_count || 0} ambiguous receipts; {data.coverage?.unknown_funding?.payments_count || 0} unknown funding; {data.coverage?.missing_account_scope_count || 0} cash records without receiving-account provenance; {data.coverage?.missing_reference_count || 0} without receipt references. These gaps limit assurance.</p></>}
            <p>CRM recorded collections are not provider settlement certification. Subscription charts and customer shares retain their subscription basis.</p>
        </div>
    </details>;
}

export function CashState({ loading, error, onRetry }) {
    if (loading) return <div className="cash-loading" role="status">Loading recorded revenue…<div /><div /><div /></div>;
    return <div className="cash-error" role="alert"><p>{error || 'Revenue is unavailable.'}</p><button type="button" onClick={onRetry}>Try again</button></div>;
}

const colors = ['#0f766e', '#547ca5', '#9a6727', '#9a5a83', '#64748b'];

export default function ProductRevenueView({ data, loading, error, onRetry, displayMode = 'flat' }) {
    if (loading || error || !data) return <CashState loading={loading} error={error} onRetry={onRetry} />;
    const total = data.selected;
    const complete = total?.fx_coverage?.complete;
    const exportCsv = () => {
        const cell = (value) => `"${String(value ?? '').replaceAll('"', '""')}"`;
        const lines = [['Product', 'Payments', 'Reporting currency', 'Cash', 'Share %', 'Change %', 'Original money', 'From', 'To (exclusive)', 'As of'],
            ...data.products.map((row) => [row.label, row.payments_count, row.normalized_currency, row.normalized_total, row.share_percent, row.change_percent, JSON.stringify(row.native), data.window.from, data.window.to, data.as_of])];
        const url = URL.createObjectURL(new Blob(['\uFEFF' + lines.map((r) => r.map(cell).join(',')).join('\r\n')], { type: 'text/csv;charset=utf-8' }));
        const link = document.createElement('a'); link.href = url; link.download = 'revenue-sources.csv'; link.click(); URL.revokeObjectURL(url);
    };
    return <div className="cash-sources">
        <div className="cash-source-intro">
            <div><p className="cash-eyebrow">All-product cash · {cashWindow(data.window)}</p><p className="cash-source-total">{cashMoney(total, displayMode)}</p><p className="cash-muted">{total.payments_count.toLocaleString()} cash payments{data.window.partial ? ' · period in progress' : ''}</p></div>
            <button className="cash-export" type="button" onClick={exportCsv}>Export CSV <span aria-hidden="true">↗</span></button>
        </div>
        {complete && total.normalized_total > 0 && <div className="cash-contribution" aria-label="Product contribution to cash revenue">
            {data.products.filter((row) => row.normalized_total > 0).map((row) => <span key={row.key} title={`${row.label}: ${row.share_percent}%`} style={{ flexGrow: row.normalized_total, backgroundColor: colors[data.products.indexOf(row)] }} />)}
        </div>}
        {!complete && <p className="cash-warning">Some payments have no cached FX rate. Original amounts remain available; normalized totals and shares are unavailable.</p>}
        {data.coverage?.unmapped_products?.payments_count > 0 && <p className="cash-warning">{data.coverage.unmapped_products.payments_count} cash payments have an unmapped product. The total includes them; the five product rows do not fully partition this period.</p>}
        {total.payments_count === 0 && <p className="cash-muted">No external cash payments in this period. Wallet-funded service value is shown separately below.</p>}
        <div className="cash-table-scroll" role="region" aria-label="Revenue sources table, scroll for all columns" tabIndex={0}>
            <table className="cash-table"><thead><tr><th scope="col">Revenue source</th><th scope="col">Cash · {displayMode === 'native' ? 'original currencies' : total.normalized_currency}</th><th scope="col">Share</th><th scope="col">Vs matched prior</th></tr></thead>
                <tbody>{data.products.map((row, i) => <tr key={row.key}>
                    <th scope="row"><div className="cash-product-name"><span className="cash-product-dot" style={{ background: colors[i] }} />{row.label}</div><p>{row.payments_count.toLocaleString()} cash payments</p>{row.wallet_funded?.payments_count > 0 && <p>Wallet-funded service: {cashMoney(row.wallet_funded, displayMode)}</p>}</th>
                    <td><strong>{cashMoney(row, displayMode)}</strong>{Object.keys(row.native || {}).length > 0 && displayMode !== 'native' && <details className="cash-native"><summary>Original money</summary><span>{cashMoney(row, 'native')}</span></details>}</td>
                    <td>{row.share_percent == null ? '—' : row.share_percent > 0 && row.share_percent < 0.1 ? '<0.1%' : `${row.share_percent.toFixed(1)}%`}</td>
                    <td><CashChange value={row.change_percent} /></td>
                </tr>)}</tbody>
                <tfoot><tr><th scope="row">All-product total</th><td>{cashMoney(total, displayMode)}</td><td>{complete && total.normalized_total > 0 ? '100%' : '—'}</td><td><CashChange value={total.change_percent} /></td></tr></tfoot>
            </table>
        </div>
        <div className="cash-source-footer"><p className="cash-muted">Shares and changes use {total.normalized_currency}. {data.window.partial ? 'Current period is in progress. ' : ''}Comparison: {cashWindow(data.prior_window)} · equal elapsed time.</p><RevenueBasis data={data} /></div>
    </div>;
}
