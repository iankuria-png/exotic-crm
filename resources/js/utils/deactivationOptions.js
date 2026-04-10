export const DEAL_DEACTIVATION_REASON_OPTIONS = [
    { value: 'payment_reversed', label: 'Payment reversed' },
    { value: 'invalid_reference', label: 'Invalid reference' },
    { value: 'fraud_suspected', label: 'Fraud suspected' },
    { value: 'customer_request', label: 'Customer request' },
    { value: 'duplicate_entry', label: 'Duplicate entry' },
    { value: 'other', label: 'Other' },
];

export const LINKED_PAYMENT_ACTION_OPTIONS = [
    { value: 'none', label: 'No payment update' },
    { value: 'reverse', label: 'Mark payment reversed' },
    { value: 'invalidate', label: 'Invalidate payment' },
];

export function defaultLinkedPaymentAction(reasonCode) {
    if (reasonCode === 'payment_reversed') return 'reverse';
    if (reasonCode === 'invalid_reference') return 'invalidate';
    return 'none';
}
