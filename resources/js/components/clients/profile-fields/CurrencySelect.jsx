import React, { useEffect, useMemo } from 'react';
import { useQuery } from '@tanstack/react-query';
import api from '../../../services/api';
import Combobox from '../../shared/Combobox';

function decodeHtmlEntities(value) {
    const raw = String(value ?? '');
    if (!raw || typeof document === 'undefined') {
        return raw;
    }

    const textarea = document.createElement('textarea');
    textarea.innerHTML = raw;
    return textarea.value;
}

function uniquePieces(values) {
    return values.filter((value, index) => value && values.indexOf(value) === index);
}

export function formatCurrencyBadge(currency) {
    const symbol = decodeHtmlEntities(currency?.symbol || '').trim();
    const code = String(currency?.code || '').trim();
    return uniquePieces([symbol, code])[0] || `#${currency?.id || ''}`;
}

export function formatCurrencyInputLabel(currency) {
    const symbol = decodeHtmlEntities(currency?.symbol || '').trim();
    const code = String(currency?.code || '').trim();
    const name = String(currency?.name || '').trim();
    return uniquePieces([symbol, code, name]).join(' · ');
}

function buildCurrencyOption(currency) {
    const symbol = decodeHtmlEntities(currency?.symbol || '').trim();
    const code = String(currency?.code || '').trim();
    const name = String(currency?.name || '').trim();

    return {
        value: currency.id,
        label: code || `Currency #${currency.id}`,
        inputLabel: formatCurrencyInputLabel(currency),
        secondaryLabel: uniquePieces([name, symbol]).join(' · '),
        badge: formatCurrencyBadge(currency),
        searchText: `${code} ${name} ${symbol}`,
    };
}

export default function CurrencySelect({
    platformId,
    value,
    onChange,
    disabled = false,
    className = '',
    onCatalogStatusChange = null,
}) {
    const currenciesQuery = useQuery({
        queryKey: ['wp-profile-currencies', platformId],
        queryFn: () => api.get(`/crm/platforms/${platformId}/currencies`).then((response) => response.data),
        enabled: Boolean(platformId),
        staleTime: 15 * 60 * 1000,
        retry: false,
    });

    const currencies = useMemo(() => {
        return [...(currenciesQuery.data?.currencies || [])].sort((left, right) => {
            const leftKey = `${left.code || ''} ${left.name || ''}`.trim();
            const rightKey = `${right.code || ''} ${right.name || ''}`.trim();
            return leftKey.localeCompare(rightKey);
        });
    }, [currenciesQuery.data?.currencies]);
    const defaultCurrencyId = currenciesQuery.data?.default_currency_id || null;
    const known = currencies.find((currency) => String(currency.id) === String(value)) || null;
    const defaultCurrency = currencies.find((currency) => String(currency.id) === String(defaultCurrencyId)) || null;

    useEffect(() => {
        onCatalogStatusChange?.({
            available: platformId ? !currenciesQuery.isError : null,
            error: currenciesQuery.isError,
            loading: currenciesQuery.isLoading,
        });
    }, [currenciesQuery.isError, currenciesQuery.isLoading, platformId]);

    const groups = useMemo(() => {
        const featuredOptions = [];
        const featuredIds = new Set();

        if (known) {
            featuredIds.add(String(known.id));
            featuredOptions.push(buildCurrencyOption(known));
        } else if (value) {
            featuredOptions.push({
                value,
                label: `Legacy #${value}`,
                inputLabel: `Legacy currency · #${value}`,
                secondaryLabel: 'This profile is keeping an old currency ID until you choose a replacement.',
                badge: `#${value}`,
                searchText: `legacy ${value}`,
            });
        }

        if (defaultCurrency && !featuredIds.has(String(defaultCurrency.id))) {
            featuredIds.add(String(defaultCurrency.id));
            featuredOptions.push(buildCurrencyOption(defaultCurrency));
        }

        const otherOptions = currencies
            .filter((currency) => !featuredIds.has(String(currency.id)))
            .map((currency) => buildCurrencyOption(currency));

        const nextGroups = [];
        if (featuredOptions.length > 0) {
            nextGroups.push({ label: 'Suggested', options: featuredOptions });
        }
        nextGroups.push({ label: 'All currencies', options: otherOptions });
        return nextGroups;
    }, [currencies, defaultCurrency, known, value]);

    let hint = null;
    if (!known && value) {
        hint = `Legacy currency (#${value}) is preserved until you choose a replacement.`;
    } else if (!value && defaultCurrency) {
        hint = `New rates default to ${formatCurrencyInputLabel(defaultCurrency)} for this market.`;
    }

    return (
        <Combobox
            label="Currency"
            value={value || defaultCurrencyId || ''}
            onChange={(nextValue) => onChange?.(nextValue ? Number(nextValue) : null)}
            groups={groups}
            placeholder="Choose currency"
            searchPlaceholder="Search currencies"
            loading={currenciesQuery.isLoading}
            disabled={disabled || !platformId}
            emptyMessage={currenciesQuery.isError ? 'Could not load currencies. Retry in a moment.' : 'No currencies found.'}
            hint={hint}
            className={className}
        />
    );
}
