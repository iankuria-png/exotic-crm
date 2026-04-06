function resolvePolicy(source) {
    if (!source || typeof source !== 'object') {
        return null;
    }

    if (source.billing_method_policy && typeof source.billing_method_policy === 'object') {
        return source.billing_method_policy;
    }

    if (source.platform && typeof source.platform === 'object') {
        return resolvePolicy(source.platform);
    }

    if (source.client && typeof source.client === 'object') {
        return resolvePolicy(source.client);
    }

    return null;
}

export function getBillingMethodPolicy(source) {
    return resolvePolicy(source);
}

export function getAllowedCrmPaymentMethods(source, surface) {
    const policy = resolvePolicy(source);
    const methods = policy?.[surface]?.crm_methods;

    if (Array.isArray(methods)) {
        return methods;
    }

    return ['manual', 'stk', 'link', 'free_trial'];
}

export function getWalletAutoRenewEnabled(source) {
    const policy = resolvePolicy(source);

    return Boolean(policy?.renewal?.wallet_auto_renew);
}

export function getWalletAutoRenewPresentation(source) {
    const state = source?.wallet_auto_renew_state && typeof source.wallet_auto_renew_state === 'object'
        ? source.wallet_auto_renew_state
        : null;

    const fromState = state?.status ? String(state.status) : '';
    const resolvedStatus = fromState || (getWalletAutoRenewEnabled(source) ? 'enabled' : '');

    if (!resolvedStatus) {
        return null;
    }

    const catalogue = {
        enabled: {
            label: 'Enabled',
            tone: 'active',
            detail: 'Market policy allows wallet auto-renew for this subscription.',
        },
        attempted: {
            label: 'Attempted',
            tone: 'pending',
            detail: state?.reason || 'Wallet charge was attempted for this renewal cycle.',
        },
        succeeded: {
            label: 'Wallet Renewed',
            tone: 'renewed',
            detail: state?.new_expires_at
                ? `Renewed through ${new Date(state.new_expires_at).toLocaleString()}.`
                : (state?.reason || 'Wallet auto-renew completed successfully.'),
        },
        failed: {
            label: 'Charge Failed',
            tone: 'failed',
            detail: state?.reason || 'Wallet auto-renew failed and needs operator review.',
        },
        fallback_sent: {
            label: 'Fallback Sent',
            tone: 'manual_review',
            detail: state?.fallback_method
                ? `Fallback routed through ${String(state.fallback_method).replace(/_/g, ' ')}.`
                : (state?.reason || 'Fallback renewal handling was sent.'),
        },
        escalated: {
            label: 'Needs Review',
            tone: 'manual_review',
            detail: state?.reason || 'Wallet auto-renew escalated to manual review.',
        },
    };

    const presentation = catalogue[resolvedStatus];
    if (!presentation) {
        return null;
    }

    return {
        status: resolvedStatus,
        label: presentation.label,
        tone: presentation.tone,
        detail: presentation.detail,
        updatedAt: state?.at || null,
    };
}
