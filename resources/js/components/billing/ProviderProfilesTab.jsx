import React, { useMemo, useState } from 'react';
import { useQuery } from '@tanstack/react-query';
import api from '../../services/api';
import BillingStateNotice from './BillingStateNotice';

/**
 * ProviderProfilesTab component displays and manages provider profile configurations.
 * Profiles allow storing multiple credentials per provider with market-level scoping.
 * Phase 3 is read-only; write operations deferred to Phase 4.
 */
export default function ProviderProfilesTab({ registryEnabled = true }) {
    const [selectedProvider, setSelectedProvider] = useState(null);

    if (!registryEnabled) {
        return (
            <div className="space-y-4 p-5">
                <BillingStateNotice
                    state="forbidden"
                    eyebrow="Provider Profiles"
                    title="Provider profiles are waiting on the registry rollout"
                    message="Enable the billing registry rollout before reviewing provider profiles and masked credentials in the new Billing workspace."
                />
            </div>
        );
    }

    /**
     * Fetch provider profiles from API.
     * Query key scoped to this component for cache isolation.
     * staleTime: 10 minutes - profiles change less frequently than catalog
     */
    const profilesQuery = useQuery({
        queryKey: ['billing-provider-profiles'],
        queryFn: () => api.get('/crm/settings/billing/provider-profiles').then(
            (response) => response.data
        ),
        staleTime: 10 * 60 * 1000, // 10 minutes
    });

    const { data = {} } = profilesQuery;
    const profiles = useMemo(() => data.profiles || [], [data.profiles]);
    const providers = useMemo(() => data.providers || [], [data.providers]);

    // Group profiles by provider for display
    const profilesByProvider = useMemo(() => {
        const grouped = {};
        profiles.forEach((profile) => {
            if (!grouped[profile.provider_type_key]) {
                grouped[profile.provider_type_key] = [];
            }
            grouped[profile.provider_type_key].push(profile);
        });
        return grouped;
    }, [profiles]);

    // Handle loading state
    if (profilesQuery.isLoading) {
        return (
            <div className="space-y-4 p-5 animate-pulse">
                <div className="space-y-4">
                    {[...Array(4)].map((_, i) => (
                        <div
                            key={i}
                            className="h-40 rounded-xl border border-slate-200 bg-white"
                        />
                    ))}
                </div>
            </div>
        );
    }

    // Handle error state
    if (profilesQuery.isError) {
        return (
            <div className="space-y-4 p-5">
                <BillingStateNotice
                    state="degraded"
                    eyebrow="Provider Profiles"
                    title="Profiles unavailable"
                    message="CRM could not load provider profiles right now. Refresh the page to retry."
                />
            </div>
        );
    }

    // Handle empty state
    if (profiles.length === 0) {
        return (
            <div className="space-y-4 p-5">
                <BillingStateNotice
                    state="empty"
                    eyebrow="Provider Profiles"
                    title="No provider profiles configured"
                    message="Create provider profiles in Phase 4 to add credentials and payment gateway configurations."
                />
            </div>
        );
    }

    return (
        <div className="space-y-4 p-5">
            {/* Introduction section */}
            <section className="rounded-xl border border-slate-200 bg-white p-4">
                <h4 className="text-sm font-semibold text-slate-900">
                    Provider Profiles
                </h4>
                <p className="mt-2 text-sm text-slate-600">
                    Manage multiple provider configurations per payment gateway. Each profile
                    can target specific markets and contains encrypted credentials.
                </p>
            </section>

            {/* Profiles organized by provider */}
            <div className="space-y-4">
                {providers
                    .filter((p) => profilesByProvider[p.key])
                    .map((provider) => (
                        <ProviderProfileGroup
                            key={provider.key}
                            provider={provider}
                            profiles={profilesByProvider[provider.key] || []}
                            isSelected={selectedProvider === provider.key}
                            onSelect={setSelectedProvider}
                        />
                    ))}
            </div>

            {/* Phase 3 Notice */}
            <section className="rounded-xl border border-slate-200 bg-white p-4">
                <h4 className="text-sm font-semibold text-slate-900">
                    Phase 3 Read-Only Mode
                </h4>
                <p className="mt-2 text-sm text-slate-600">
                    Provider profile management (create, edit, delete) is available in Phase 4.
                    This view displays existing configurations and validates credentials.
                </p>
            </section>
        </div>
    );
}

/**
 * ProviderProfileGroup displays all profiles for a single provider.
 */
function ProviderProfileGroup({
    provider,
    profiles,
    isSelected,
    onSelect,
}) {
    return (
        <section className="rounded-xl border border-slate-200 bg-white p-4">
            <div className="flex items-center justify-between">
                <div className="flex-1">
                    <button
                        type="button"
                        onClick={() =>
                            onSelect(
                                isSelected ? null : provider.key
                            )
                        }
                        className="flex items-center gap-2 text-left"
                    >
                        <div
                            className={`transition-transform ${
                                isSelected ? 'rotate-90' : ''
                            }`}
                        >
                            <svg
                                className="h-4 w-4 text-slate-400"
                                fill="currentColor"
                                viewBox="0 0 20 20"
                            >
                                <path
                                    fillRule="evenodd"
                                    d="M7.293 14.707a1 1 0 010-1.414L10.586 10 7.293 6.707a1 1 0 011.414-1.414l4 4a1 1 0 010 1.414l-4 4a1 1 0 01-1.414 0z"
                                    clipRule="evenodd"
                                />
                            </svg>
                        </div>
                        <div>
                            <p className="text-xs font-semibold uppercase tracking-[0.08em] text-slate-500">
                                {provider.family}
                            </p>
                            <h5 className="mt-1 text-sm font-semibold text-slate-900">
                                {provider.label}
                            </h5>
                        </div>
                    </button>
                </div>
                <span className="rounded-full bg-slate-100 px-2 py-1 text-xs font-semibold text-slate-700">
                    {profiles.length} profile{profiles.length !== 1 ? 's' : ''}
                </span>
            </div>

            {/* Expanded profiles list */}
            {isSelected && (
                <div className="mt-4 space-y-3 border-t border-slate-100 pt-4">
                    {profiles.map((profile) => (
                        <ProfileCard
                            key={profile.id}
                            profile={profile}
                            provider={provider}
                        />
                    ))}
                </div>
            )}
        </section>
    );
}

/**
 * ProfileCard displays a single provider profile with status and configuration details.
 */
function ProfileCard({ profile, provider }) {
    const statusColor = profile.active
        ? 'bg-emerald-100 text-emerald-700'
        : 'bg-slate-100 text-slate-700';

    const marketLabel = profile.market_id
        ? `Market ${profile.country_code || 'Global'}`
        : 'All Markets';

    return (
        <div className="rounded-lg border border-slate-200 bg-slate-50 p-3">
            <div className="flex items-start justify-between gap-2">
                <div className="flex-1">
                    <div className="flex items-center gap-2">
                        <h6 className="text-sm font-semibold text-slate-900">
                            {profile.profile_name}
                        </h6>
                        <span
                            className={`rounded-full px-2 py-0.5 text-[10px] font-semibold uppercase tracking-[0.06em] ${statusColor}`}
                        >
                            {profile.active ? 'Active' : 'Inactive'}
                        </span>
                    </div>
                    <p className="mt-1 text-xs text-slate-600">
                        {marketLabel} • {profile.environment || 'production'}
                    </p>

                    {/* Configuration summary */}
                    {profile.config_json && (
                        <div className="mt-2 space-y-1">
                            {Object.entries(profile.config_json)
                                .slice(0, 2)
                                .map(([key, value]) => (
                                    <p
                                        key={key}
                                        className="text-xs text-slate-500"
                                    >
                                        <span className="font-mono text-[9px]">
                                            {key}:
                                        </span>{' '}
                                        <span className="font-mono">
                                            {typeof value === 'string' &&
                                            value.length > 20
                                                ? value.substring(
                                                      0,
                                                      20
                                                  ) + '…'
                                                : String(value)}
                                        </span>
                                    </p>
                                ))}
                            {Object.keys(profile.config_json).length > 2 && (
                                <p className="text-xs text-slate-500">
                                    +{Object.keys(profile.config_json).length - 2}{' '}
                                    more fields
                                </p>
                            )}
                        </div>
                    )}
                </div>

                {/* Secrets indicator */}
                <div className="text-right">
                    {profile.secrets_json && (
                        <div className="flex items-center gap-1">
                            <svg
                                className="h-4 w-4 text-amber-600"
                                fill="currentColor"
                                viewBox="0 0 20 20"
                            >
                                <path
                                    fillRule="evenodd"
                                    d="M5 9V7a5 5 0 0110 0v2a2 2 0 012 2v5a2 2 0 01-2 2H5a2 2 0 01-2-2v-5a2 2 0 012-2zm8-2v2H7V7a3 3 0 016 0z"
                                    clipRule="evenodd"
                                />
                            </svg>
                            <span className="text-[10px] font-semibold text-amber-700">
                                Encrypted
                            </span>
                        </div>
                    )}
                </div>
            </div>

            {/* Test status */}
            {profile.tested_at && (
                <div className="mt-2 border-t border-slate-200 pt-2 text-[11px] text-slate-500">
                    Last tested: {new Date(profile.tested_at).toLocaleDateString()}
                </div>
            )}
        </div>
    );
}
