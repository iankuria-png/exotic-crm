import React, { useMemo } from 'react';
import { useQuery } from '@tanstack/react-query';
import api from '../../../services/api';
import Combobox from '../../shared/Combobox';

function formatCurrencyOption(currency) {
    const pieces = [currency.symbol, currency.code, currency.name].filter(Boolean);
    return pieces.join(' · ');
}

export default function CurrencySelect({
    platformId,
    value,
    onChange,
    disabled = false,
    className = '',
}) {
    const currenciesQuery = useQuery({
        queryKey: ['wp-profile-currencies', platformId],
        queryFn: () => api.get(`/crm/platforms/${platformId}/currencies`).then((response) => response.data),
        enabled: Boolean(platformId),
        staleTime: 15 * 60 * 1000,
    });

    const currencies = currenciesQuery.data?.currencies || [];
    const defaultCurrencyId = currenciesQuery.data?.default_currency_id || null;
    const known = currencies.find((currency) => String(currency.id) === String(value)) || null;
    const groups = useMemo(() => {
        const options = currencies.map((currency) => ({
            value: currency.id,
            label: formatCurrencyOption(currency),
            searchText: `${currency.code || ''} ${currency.name || ''} ${currency.symbol || ''}`,
        }));

        if (!known && value) {
            options.unshift({
                value,
                label: `Legacy currency (#${value})`,
                searchText: `legacy ${value}`,
            });
        }

        return [{ label: 'Currencies', options }];
    }, [currencies, known, value]);

    return (
        <Combobox
            label="Currency"
            value={value || defaultCurrencyId || ''}
            onChange={(nextValue) => onChange?.(nextValue ? Number(nextValue) : null)}
            groups={groups}
            placeholder="Select currency"
            searchPlaceholder="Search currencies"
            loading={currenciesQuery.isLoading}
            disabled={disabled || !platformId}
            emptyMessage={currenciesQuery.isError ? 'Could not load currencies. Retry in a moment.' : 'No currencies found.'}
            hint={!known && value ? `Legacy currency (#${value}) is preserved until you choose a replacement.` : null}
            className={className}
        />
    );
}
