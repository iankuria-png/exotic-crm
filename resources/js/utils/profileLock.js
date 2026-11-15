// Staff lock: the profile is held Expired — published and indexed, contacts
// hidden, editing locked — by a person, with a reason. Mirrors
// App\Support\ClientLockReason; the dialog loads the live list from the API.
export const LOCK_REASON_LABELS = {
    verification_required: 'Verification needed',
    policy_violation: 'Policy or content violation',
    fraud_or_dispute: 'Fraud or payment dispute',
    client_request: 'Client asked for it',
    quality_review: 'Profile quality review',
    other: 'Other',
};

export function lockReasonLabel(code) {
    return LOCK_REASON_LABELS[code] || (code ? String(code).replace(/_/g, ' ') : 'No reason recorded');
}

/** Locked by staff and WordPress still restricts it (an activation ends the lock). */
export function isClientStaffLocked(client) {
    const state = String(client?.lifecycle_state || '').toLowerCase();
    return Boolean(client?.profile_locked_at) && ['expired', 'archived'].includes(state);
}
