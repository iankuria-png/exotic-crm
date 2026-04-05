import React, { useMemo, useState } from 'react';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import api from '../../services/api';
import { useToast } from '../ToastProvider';
import BillingStateNotice from './BillingStateNotice';
import ProviderProfileEditorModal from './ProviderProfileEditorModal';
import { isForbiddenQueryError } from './queryState';

function firstErrorMessage(error) {
    const validation = error?.response?.data?.errors;
    if (validation && typeof validation === 'object') {
        const first = Object.values(validation).flat()[0];
        if (first) {
            return String(first);
        }
    }

    return error?.response?.data?.message || 'CRM could not save the provider profile.';
}

function formatKey(value) {
    return String(value || '')
        .replace(/_/g, ' ')
        .replace(/\b\w/g, (char) => char.toUpperCase());
}

export default function ProviderProfilesTab({ registryEnabled = true, markets = [] }) {
    const toast = useToast();
    const queryClient = useQueryClient();
    const [activeProvider, setActiveProvider] = useState('all');
    const [editingProfile, setEditingProfile] = useState(null);
    const [modalOpen, setModalOpen] = useState(false);

    const profilesQuery = useQuery({
        queryKey: ['billing-provider-profiles'],
        queryFn: () => api.get('/crm/settings/billing/provider-profiles').then((response) => response.data),
        staleTime: 60_000,
    });

    const saveProfileMutation = useMutation({
        mutationFn: (payload) => {
            if (payload.id) {
                return api.put(`/crm/settings/billing/provider-profiles/${payload.id}`, payload).then((response) => response.data);
            }

            return api.post('/crm/settings/billing/provider-profiles', payload).then((response) => response.data);
        },
        onSuccess: (_, payload) => {
            queryClient.invalidateQueries({ queryKey: ['billing-provider-profiles'] });
            toast.success(payload.id ? 'Provider profile updated.' : 'Provider profile created.');
            setModalOpen(false);
            setEditingProfile(null);
        },
        onError: (error) => {
            toast.error(firstErrorMessage(error), {
                title: 'Provider profile save failed',
            });
        },
    });

    const data = profilesQuery.data || {};
    const profiles = Array.isArray(data.profiles) ? data.profiles : [];
    const providers = Array.isArray(data.providers) ? data.providers : [];
    const schemas = Array.isArray(data.schemas) ? data.schemas : Object.values(data.schemas || {});
    const editable = Boolean(data.editable);

    const countsByProvider = useMemo(() => {
        return profiles.reduce((carry, profile) => {
            carry[profile.provider_type_key] = (carry[profile.provider_type_key] || 0) + 1;
            return carry;
        }, {});
    }, [profiles]);

    const filteredProfiles = useMemo(() => {
        if (activeProvider === 'all') {
            return profiles;
        }

        return profiles.filter((profile) => profile.provider_type_key === activeProvider);
    }, [activeProvider, profiles]);

    if (!registryEnabled) {
        return (
            <div className="space-y-4 p-5">
                <BillingStateNotice
                    state="forbidden"
                    eyebrow="Provider Profiles"
                    title="Provider profiles stay locked until the registry is active"
                    message="Enable the billing registry rollout before creating market-bound provider credentials in this workspace."
                />
            </div>
        );
    }

    if (profilesQuery.isLoading) {
        return (
            <div className="space-y-4 p-5 animate-pulse">
                <div className="h-28 rounded-xl border border-slate-200 bg-white" />
                <div className="grid gap-4 xl:grid-cols-2">
                    <div className="h-64 rounded-xl border border-slate-200 bg-white" />
                    <div className="h-64 rounded-xl border border-slate-200 bg-white" />
                </div>
            </div>
        );
    }

    if (profilesQuery.isError) {
        if (isForbiddenQueryError(profilesQuery.error)) {
            return (
                <div className="space-y-4 p-5">
                    <BillingStateNotice
                        state="forbidden"
                        eyebrow="Provider Profiles"
                        title="Profile management is restricted"
                        message="This role can open the Billing workspace, but it cannot inspect or edit provider credentials in this environment."
                    />
                </div>
            );
        }

        return (
            <div className="space-y-4 p-5">
                <BillingStateNotice
                    state="degraded"
                    eyebrow="Provider Profiles"
                    title="Provider profile data is unavailable"
                    message="CRM could not load the provider profile registry right now. Retry after the billing endpoints recover."
                />
            </div>
        );
    }

    const activeCount = profiles.filter((profile) => profile.active).length;
    const testedCount = profiles.filter((profile) => profile.tested_at).length;
    const providerFamiliesConfigured = Object.keys(countsByProvider).length;

    return (
        <div className="space-y-5 p-5">
            <section className="rounded-xl border border-slate-200 bg-white p-5 shadow-sm shadow-slate-950/[0.02]">
                <div className="flex flex-col gap-4 xl:flex-row xl:items-end xl:justify-between">
                    <div className="space-y-3">
                        <div>
                            <p className="text-[11px] font-semibold uppercase tracking-[0.1em] text-slate-500">Provider Profiles</p>
                            <h4 className="mt-2 text-xl font-semibold text-slate-950">Credential sets that route real money flows</h4>
                            <p className="mt-2 max-w-3xl text-sm leading-6 text-slate-600">
                                Bind each provider family to an environment, market, and credential set. Use multiple
                                profiles per country when you need controlled fallback, merchant separation, or sandbox validation.
                            </p>
                        </div>

                        <div className="flex flex-wrap gap-2">
                            <FilterPill
                                active={activeProvider === 'all'}
                                label={`All profiles (${profiles.length})`}
                                onClick={() => setActiveProvider('all')}
                            />
                            {providers.map((provider) => (
                                <FilterPill
                                    key={provider.key}
                                    active={activeProvider === provider.key}
                                    label={`${provider.label} (${countsByProvider[provider.key] || 0})`}
                                    onClick={() => setActiveProvider(provider.key)}
                                />
                            ))}
                        </div>
                    </div>

                    <div className="flex flex-col gap-3 xl:min-w-[360px]">
                        <div className="grid gap-3 sm:grid-cols-3">
                            <MetricCard label="Active" value={activeCount} status="online" />
                            <MetricCard label="Verified" value={testedCount} status="verified" />
                            <MetricCard label="Families" value={providerFamiliesConfigured} status="neutral" />
                        </div>
                        {editable ? (
                            <button
                                type="button"
                                onClick={() => {
                                    setEditingProfile(null);
                                    setModalOpen(true);
                                }}
                                className="crm-btn-primary w-full justify-center px-4 py-3 disabled:cursor-not-allowed disabled:opacity-60"
                            >
                                Add provider profile
                            </button>
                        ) : (
                            <div className="rounded-xl border border-slate-200 bg-slate-50 px-4 py-3 text-sm text-slate-600">
                                Provider profiles are visible here, but only admin users can create or update them.
                            </div>
                        )}
                    </div>
                </div>
            </section>

            {filteredProfiles.length === 0 ? (
                <BillingStateNotice
                    state={profiles.length === 0 ? 'empty' : 'degraded'}
                    eyebrow="Provider Profiles"
                    title={profiles.length === 0 ? 'No provider profiles configured yet' : 'No profiles match this filter'}
                    message={
                        profiles.length === 0
                            ? 'Create the first provider profile to bind a provider family to a market and environment. Secrets stay masked after save.'
                            : 'Switch to another provider family filter or clear the filter to view all configured profiles.'
                    }
                />
            ) : (
                <div className="grid gap-4 xl:grid-cols-2">
                    {filteredProfiles.map((profile) => (
                        <ProfileCard
                            key={profile.id}
                            profile={profile}
                            markets={markets}
                            onEdit={
                                editable
                                    ? () => {
                                          setEditingProfile(profile);
                                          setModalOpen(true);
                                      }
                                    : null
                            }
                        />
                    ))}
                </div>
            )}

            <ProviderProfileEditorModal
                open={modalOpen}
                profile={editingProfile}
                providers={providers}
                schemas={schemas}
                markets={markets}
                isSaving={saveProfileMutation.isPending}
                onClose={() => {
                    if (saveProfileMutation.isPending) {
                        return;
                    }

                    setModalOpen(false);
                    setEditingProfile(null);
                }}
                onSubmit={(payload) => {
                    saveProfileMutation.mutate(editingProfile ? { ...payload, id: editingProfile.id } : payload);
                }}
            />
        </div>
    );
}

function MetricCard({ label, value, status = 'neutral' }) {
    return (
        <div className="rounded-lg border border-slate-200 bg-white px-4 py-4 shadow-sm shadow-slate-950/[0.02]">
            <div className="flex items-center justify-between gap-2">
                <p className="max-w-[72%] text-[8px] font-semibold uppercase tracking-[0.16em] text-slate-400">{label}</p>
                <StatusDot status={status} />
            </div>
            <p className="mt-6 tabular-nums text-[1.9rem] font-semibold leading-none tracking-[-0.05em] text-slate-950">{value}</p>
        </div>
    );
}

function FilterPill({ active, label, onClick }) {
    return (
        <button
            type="button"
            onClick={onClick}
            className={`rounded-lg border px-3 py-1.5 text-xs font-semibold transition ${
                active
                    ? 'border-slate-900 bg-slate-900 text-white'
                    : 'border-slate-200 bg-white text-slate-600 hover:border-slate-300 hover:text-slate-900'
            }`}
        >
            {label}
        </button>
    );
}

function ProfileCard({ profile, markets, onEdit }) {
    const market = markets.find((entry) => Number(entry.id) === Number(profile.market_id));
    const configuredSecrets = Object.values(profile.secret_state || {}).filter(Boolean).length;
    const configCount = Object.keys(profile.config_json || {}).length;
    const status = profile.provider_status || 'active';

    return (
        <section className="rounded-xl border border-slate-200 bg-white p-5 shadow-sm shadow-slate-950/[0.02]">
            <div className="flex flex-col gap-4 lg:flex-row lg:items-start lg:justify-between">
                <div className="space-y-2">
                    <div className="flex flex-wrap items-center gap-2">
                        <span className="rounded-full border border-slate-200 bg-slate-50 px-2.5 py-1 text-[11px] font-semibold uppercase tracking-[0.1em] text-slate-600">
                            {formatKey(profile.provider_family || profile.provider_type_key)}
                        </span>
                        <StatusBadge status={status} />
                        <StatusBadge status={profile.active ? 'active' : 'disabled'} />
                    </div>
                    <div>
                        <h5 className="text-lg font-semibold text-slate-950">{profile.profile_name}</h5>
                        <p className="mt-1 text-sm text-slate-600">
                            {profile.provider_label} · {formatKey(profile.environment)}
                            {profile.country_code ? ` · ${profile.country_code}` : ''}
                        </p>
                    </div>
                </div>

                {onEdit ? (
                    <button type="button" onClick={onEdit} className="crm-btn-secondary px-3 py-2 text-sm">
                        Edit profile
                    </button>
                ) : null}
            </div>

            <div className="mt-5 grid gap-4 md:grid-cols-2 xl:grid-cols-4">
                <SummaryChip label="Market" value={market?.name || 'All visible markets'} />
                <SummaryChip label="Secrets configured" value={configuredSecrets > 0 ? String(configuredSecrets) : 'None'} />
                <SummaryChip label="Non-secret fields" value={String(configCount)} />
                <SummaryChip label="Last validation" value={profile.tested_at ? new Date(profile.tested_at).toLocaleString() : 'Not yet tested'} />
            </div>

            {Object.keys(profile.config_json || {}).length > 0 ? (
                <div className="mt-5 border-t border-slate-100 pt-4">
                    <p className="text-[11px] font-semibold uppercase tracking-[0.1em] text-slate-500">Configured fields</p>
                    <div className="mt-3 flex flex-wrap gap-2">
                        {Object.entries(profile.config_json || {}).map(([key, value]) => (
                            <span
                                key={key}
                                className="rounded-lg border border-slate-200 bg-slate-50 px-3 py-1 text-xs text-slate-700"
                            >
                                <span className="font-semibold text-slate-900">{formatKey(key)}:</span>{' '}
                                {String(value || 'Not configured')}
                            </span>
                        ))}
                    </div>
                </div>
            ) : null}
        </section>
    );
}

function SummaryChip({ label, value }) {
    return (
        <div className="rounded-xl border border-slate-200 bg-slate-50 px-4 py-3">
            <p className="text-[11px] font-semibold uppercase tracking-[0.1em] text-slate-500">{label}</p>
            <p className="mt-2 text-sm font-semibold text-slate-900">{value}</p>
        </div>
    );
}

function StatusDot({ status = 'neutral' }) {
    const tones = {
        online: 'bg-emerald-500',
        verified: 'bg-sky-500',
        neutral: 'bg-slate-300',
    };

    return <span className={`h-2 w-2 rounded-full ${tones[status] || tones.neutral}`} />;
}

function StatusBadge({ status }) {
    const normalized = String(status || 'unknown');

    const mapping = {
        active: { border: 'border-emerald-200', text: 'text-emerald-700', dot: 'bg-emerald-500', label: 'Active' },
        disabled: { border: 'border-slate-200', text: 'text-slate-600', dot: 'bg-slate-300', label: 'Disabled' },
        compatibility: { border: 'border-amber-200', text: 'text-amber-700', dot: 'bg-amber-500', label: 'Compatibility' },
        deferred: { border: 'border-slate-200', text: 'text-slate-600', dot: 'bg-slate-300', label: 'Deferred' },
        legacy: { border: 'border-slate-200', text: 'text-slate-600', dot: 'bg-slate-300', label: 'Legacy' },
    };

    const tone = mapping[normalized] || mapping.active;

    return (
        <span className={`inline-flex items-center gap-2 rounded-full border px-2.5 py-1 text-[11px] font-semibold uppercase tracking-[0.1em] ${tone.border} ${tone.text}`}>
            <span className={`h-2 w-2 rounded-full ${tone.dot}`} />
            {tone.label}
        </span>
    );
}
