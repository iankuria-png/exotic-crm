function normalizeBoolean(value) {
    if (typeof value === 'string') {
        const normalized = value.trim().toLowerCase();
        return normalized === '1' || normalized === 'true' || normalized === 'yes';
    }

    return Boolean(value);
}

export function deriveClientProfileState(client) {
    const profileStatus = String(client?.profile_status || '').trim().toLowerCase();
    const needsPayment = normalizeBoolean(client?.needs_payment);
    const notactive = normalizeBoolean(client?.notactive);
    const isConflict = profileStatus === 'publish' && (needsPayment || notactive);

    if (isConflict) {
        return {
            status: 'manual_review',
            tone: 'manual_review',
            label: 'WP State Conflict',
            detail: needsPayment
                ? 'WordPress says this profile is public but also marked payment required.'
                : 'WordPress says this profile is public but also awaiting admin activation.',
            isConflict: true,
            isPubliclyActive: false,
        };
    }

    if (needsPayment) {
        return {
            status: 'awaiting_payment',
            tone: 'awaiting_payment',
            label: 'Payment Required',
            detail: 'Profile is inactive until payment is made.',
            isConflict: false,
            isPubliclyActive: false,
        };
    }

    if (notactive) {
        return {
            status: 'pending',
            tone: 'pending',
            label: 'Awaiting Activation',
            detail: 'Profile is inactive until an admin activates it.',
            isConflict: false,
            isPubliclyActive: false,
        };
    }

    if (profileStatus === 'publish') {
        return {
            status: 'publish',
            tone: 'publish',
            label: 'Active',
            detail: 'Public',
            isConflict: false,
            isPubliclyActive: true,
        };
    }

    if (profileStatus === 'private') {
        return {
            status: 'private',
            tone: 'private',
            label: 'Inactive',
            detail: 'Private',
            isConflict: false,
            isPubliclyActive: false,
        };
    }

    if (profileStatus === 'draft') {
        return {
            status: 'draft',
            tone: 'draft',
            label: 'Draft',
            detail: 'Draft',
            isConflict: false,
            isPubliclyActive: false,
        };
    }

    if (profileStatus === 'pending') {
        return {
            status: 'pending',
            tone: 'pending',
            label: 'Pending',
            detail: 'Pending review',
            isConflict: false,
            isPubliclyActive: false,
        };
    }

    return {
        status: 'open',
        tone: 'open',
        label: 'Unknown',
        detail: 'Unknown',
        isConflict: false,
        isPubliclyActive: false,
    };
}

export function isClientPubliclyActive(client) {
    return deriveClientProfileState(client).isPubliclyActive;
}

export function isClientTrueForeverPlan(client) {
    const state = deriveClientProfileState(client);

    return state.isPubliclyActive
        && !client?.active_deal
        && Number(client?.deals?.length || 0) === 0
        && !client?.escort_expire
        && !client?.premium_expire
        && !client?.featured_expire;
}
