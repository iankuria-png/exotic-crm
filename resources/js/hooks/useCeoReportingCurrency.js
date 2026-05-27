import useReportingCurrency from './useReportingCurrency';

export default function useCeoReportingCurrency() {
    return useReportingCurrency({
        preferFlat: true,
        storageKeys: {
            mode: 'exoticcrm.dashboard.ceo.reporting_currency.mode',
            target: 'exoticcrm.dashboard.ceo.reporting_currency.target',
        },
    });
}
