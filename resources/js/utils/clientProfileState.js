function normalizeBoolean(value) {
    if (typeof value === 'string') {
        const normalized = value.trim().toLowerCase();
        return normalized === '1' || normalized === 'true' || normalized === 'yes';
    }

    return Boolean(value);
}

export function deriveClientProfileState(client) {
    const profileStatus = String(client?.profile_status || '').trim().toLowerCase();
    const lifecycleState = String(client?.lifecycle_state || '').trim().toLowerCase();
    const needsPayment = normalizeBoolean(client?.needs_payment);
    const notactive = normalizeBoolean(client?.notactive);

    // Authoritative lifecycle states published to WordPress. Archived is its own
    // terminal badge; Expired reuses the amber "expired_public" tone below. Both keep
    // the profile published/indexed for SEO with contact methods hidden on the site.
    if (lifecycleState === 'archived') {
        return {
            status: 'archived',
            tone: 'archived',
            label: 'Archived',
            detail: 'Indexed for SEO, hidden from listings',
            isConflict: false,
            isPubliclyActive: false,
        };
    }

    if (lifecycleState === 'removed') {
        return {
            status: 'removed',
            tone: 'removed',
            label: 'Removed',
            detail: 'Deleted from the website',
            isConflict: false,
            isPubliclyActive: false,
        };
    }

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
        // Expired lifecycle: published & indexed for SEO, but contacts are hidden and
        // editing is locked on the website. Either the terminal lifecycle=expired state,
        // or a transient stuck profile (server-derived expiry_state) awaiting reconcile.
        if (lifecycleState === 'expired' || String(client?.expiry_state || '') === 'expired_public') {
            return {
                status: 'expired_public',
                tone: 'expired_public',
                label: 'Expired',
                detail: 'Published for SEO — contact methods hidden',
                isConflict: false,
                isPubliclyActive: true,
            };
        }

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
