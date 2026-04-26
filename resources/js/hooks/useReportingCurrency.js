import { useEffect, useMemo, useState } from 'react';
import { useQuery } from '@tanstack/react-query';
import api from '../services/api';

const MODE_STORAGE_KEY = 'exoticcrm.reporting_currency.mode';
const TARGET_STORAGE_KEY = 'exoticcrm.reporting_currency.target';
const DEFAULT_TARGET = 'USD';

function cleanMode(value, fallback) {
    return value === 'native' || value === 'flat' ? value : fallback;
}

function cleanCurrency(value, fallback = DEFAULT_TARGET) {
    const normalized = String(value || '').trim().toUpperCase();
    return normalized ? normalized.slice(0, 8) : fallback;
}

export default function useReportingCurrency({ preferFlat = false } = {}) {
    const fallbackMode = preferFlat ? 'flat' : 'native';
    const [displayMode, setDisplayModeState] = useState(() => {
        if (typeof window === 'undefined') {
            return fallbackMode;
        }

        return cleanMode(window.localStorage.getItem(MODE_STORAGE_KEY), fallbackMode);
    });
    const [targetCurrency, setTargetCurrencyState] = useState(() => {
        if (typeof window === 'undefined') {
            return DEFAULT_TARGET;
        }

        return cleanCurrency(window.localStorage.getItem(TARGET_STORAGE_KEY), DEFAULT_TARGET);
    });

    const settingsQuery = useQuery({
        queryKey: ['reporting-currency-settings'],
        queryFn: () => api.get('/crm/settings/reporting-currency').then((response) => response.data),
        staleTime: 60_000,
    });

    const settings = settingsQuery.data?.settings || {};
    const systemTargetCurrency = cleanCurrency(settings.target_currency, DEFAULT_TARGET);
    const allowUserOverride = settings.allow_user_override !== false;

    useEffect(() => {
        if (typeof window === 'undefined') {
            return;
        }

        const storedTarget = window.localStorage.getItem(TARGET_STORAGE_KEY);
        if (!storedTarget && systemTargetCurrency) {
            setTargetCurrencyState(systemTargetCurrency);
        }
    }, [systemTargetCurrency]);

    const setDisplayMode = (mode) => {
        const next = cleanMode(mode, fallbackMode);
        setDisplayModeState(next);
        if (typeof window !== 'undefined') {
            window.localStorage.setItem(MODE_STORAGE_KEY, next);
        }
    };

    const setTargetCurrency = (currency) => {
        const next = cleanCurrency(currency, systemTargetCurrency);
        setTargetCurrencyState(next);
        if (typeof window !== 'undefined') {
            window.localStorage.setItem(TARGET_STORAGE_KEY, next);
        }
    };

    const effectiveTargetCurrency = allowUserOverride
        ? cleanCurrency(targetCurrency, systemTargetCurrency)
        : systemTargetCurrency;

    const queryParams = useMemo(() => ({
        currency_mode: displayMode,
        reporting_currency: effectiveTargetCurrency,
    }), [displayMode, effectiveTargetCurrency]);

    return {
        displayMode,
        setDisplayMode,
        targetCurrency: effectiveTargetCurrency,
        setTargetCurrency,
        systemTargetCurrency,
        allowUserOverride,
        source: allowUserOverride ? 'user-override' : 'system-default',
        settings,
        isLoading: settingsQuery.isLoading,
        queryParams,
        isFlat: displayMode === 'flat',
    };
}
