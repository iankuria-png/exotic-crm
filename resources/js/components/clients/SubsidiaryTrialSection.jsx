import React, { useEffect, useMemo, useState } from 'react';
import { useQuery } from '@tanstack/react-query';
import api from '../../services/api';
import SubsidiaryClientPreview from './SubsidiaryClientPreview';
import SubsidiaryClientSearchPopover from './SubsidiaryClientSearchPopover';

function configMessage(errors = []) {
    if (errors.includes('free_trial_disabled')) return 'Free trials are disabled in Billing subscription rules for this market.';
    if (errors.includes('missing_matching_product')) return 'No matching subscription package is available in this market.';
    if (errors.includes('missing_trial_product')) return 'No matching subscription package is available in this market.';
    if (errors.includes('missing_wp_api_credentials')) return 'WordPress API credentials are incomplete for this market.';
    if (errors.includes('missing_wp_db_credentials')) return 'WordPress database credentials are incomplete for new client provisioning.';
    return null;
}

function optionSuffix(errors = []) {
    if (errors.includes('free_trial_disabled')) return 'free trial off';
    if (errors.includes('missing_matching_product') || errors.includes('missing_trial_product')) return 'missing package';
    if (errors.includes('missing_wp_api_credentials')) return 'WP API missing';
    return 'not ready';
}

export default function SubsidiaryTrialSection({
    dealId,
    value,
    onChange,
    onReadyChange,
    disabled = false,
}) {
    const [searchOpen, setSearchOpen] = useState(false);

    const targetsQuery = useQuery({
        queryKey: ['subsidiary-trial-targets', dealId],
        queryFn: () => api.get(`/crm/deals/${dealId}/subsidiary-trial-targets`).then((response) => response.data),
        enabled: Boolean(value.enabled && dealId),
        staleTime: 60_000,
    });

    const targets = targetsQuery.data?.targets || [];
    const selectedTarget = targets.find((target) => Number(target.id) === Number(value.platform_id)) || null;

    const previewQuery = useQuery({
        queryKey: ['subsidiary-trial-preview', dealId, value.platform_id],
        queryFn: () => api.get(`/crm/deals/${dealId}/subsidiary-trial-preview`, {
            params: { platform_id: Number(value.platform_id) },
        }).then((response) => response.data),
        enabled: Boolean(value.enabled && dealId && value.platform_id),
        staleTime: 15_000,
    });

    const preview = previewQuery.data || null;
    const selectedClientName = value.selected_client_name || preview?.match?.name || '';
    const selectedErrors = selectedTarget?.config_errors || preview?.config_errors || [];
    const needsCreate = Boolean(preview?.will_create && !value.client_id);
    const blockingErrors = selectedErrors.filter((error) => error !== 'missing_wp_db_credentials' || needsCreate);
    const configError = configMessage(blockingErrors);
    const ready = Boolean(value.enabled
        && value.platform_id
        && value.duration_days
        && String(value.pin || '').trim().length >= 4
        && preview
        && !configError
        && (preview.match || value.client_id || (needsCreate && value.create_confirmed)));

    useEffect(() => {
        onReadyChange?.(ready || !value.enabled);
    }, [onReadyChange, ready, value.enabled]);

    useEffect(() => {
        if (preview?.default_duration_days && !value.duration_days) {
            onChange({ duration_days: String(preview.default_duration_days) });
        }
    }, [onChange, preview?.default_duration_days, value.duration_days]);

    const targetOptions = useMemo(() => targets.map((target) => ({
        ...target,
        disabled: (target.config_errors || []).includes('free_trial_disabled')
            || (target.config_errors || []).includes('missing_matching_product')
            || (target.config_errors || []).includes('missing_trial_product')
            || (target.config_errors || []).includes('missing_wp_api_credentials'),
    })), [targets]);

    return (
        <div className="space-y-3 rounded-md border border-slate-200 bg-white p-3">
            <label className="flex items-center gap-2 text-sm font-semibold text-slate-800">
                <input
                    type="checkbox"
                    checked={Boolean(value.enabled)}
                    disabled={disabled}
                    onChange={(event) => onChange({
                        enabled: event.target.checked,
                        platform_id: '',
                        client_id: null,
                        selected_client_name: '',
                        create_confirmed: false,
                        duration_days: '',
                        pin: '',
                    })}
                    className="h-4 w-4 rounded border-slate-300 text-teal-600 focus:ring-teal-200"
                />
                Also activate free trial on a subsidiary market
            </label>

            {value.enabled ? (
                <div className="space-y-3">
                    <div>
                        <label htmlFor="subsidiary-market" className="mb-1 block text-sm font-medium text-slate-700">Subsidiary market</label>
                        <select
                            id="subsidiary-market"
                            className="crm-select"
                            value={value.platform_id || ''}
                            onChange={(event) => onChange({
                                platform_id: event.target.value,
                                client_id: null,
                                selected_client_name: '',
                                create_confirmed: false,
                                duration_days: '',
                            })}
                            disabled={targetsQuery.isLoading || disabled}
                        >
                            <option value="">{targetsQuery.isLoading ? 'Loading markets...' : 'Choose market'}</option>
                            {targetOptions.map((target) => (
                                <option key={target.id} value={target.id} disabled={target.disabled} title={configMessage(target.config_errors)}>
                                    {target.name}{target.disabled ? ` (${optionSuffix(target.config_errors)})` : ''}
                                </option>
                            ))}
                        </select>
                        {selectedTarget && configMessage(selectedTarget.config_errors) ? (
                            <p className="mt-1 text-xs text-amber-700">{configMessage(selectedTarget.config_errors)}</p>
                        ) : null}
                    </div>

                    <div className="relative">
                        <SubsidiaryClientPreview
                            isLoading={previewQuery.isFetching}
                            error={previewQuery.error?.response?.data?.message || configError}
                            preview={preview}
                            selectedClientName={selectedClientName}
                            onUseDifferent={() => setSearchOpen(true)}
                            createConfirmed={Boolean(value.create_confirmed)}
                            onConfirmCreate={(checked) => onChange({ create_confirmed: checked })}
                        />
                        <SubsidiaryClientSearchPopover
                            open={searchOpen}
                            platformId={value.platform_id}
                            onClose={() => setSearchOpen(false)}
                            onSelect={(client) => {
                                onChange({
                                    client_id: client.id,
                                    selected_client_name: client.name || 'Client',
                                    create_confirmed: false,
                                });
                                setSearchOpen(false);
                            }}
                        />
                    </div>

                    <div className="grid gap-3 sm:grid-cols-2">
                        <div>
                            <label htmlFor="subsidiary-duration" className="mb-1 block text-sm font-medium text-slate-700">Trial duration</label>
                            <input
                                id="subsidiary-duration"
                                type="number"
                                min="1"
                                max="90"
                                value={value.duration_days || ''}
                                onChange={(event) => onChange({ duration_days: event.target.value })}
                                className="crm-input"
                            />
                        </div>
                        <div>
                            <label htmlFor="subsidiary-pin" className="mb-1 block text-sm font-medium text-slate-700">Free-trial PIN</label>
                            <input
                                id="subsidiary-pin"
                                type="password"
                                inputMode="numeric"
                                maxLength={6}
                                value={value.pin || ''}
                                onChange={(event) => onChange({ pin: event.target.value.replace(/\D/g, '').slice(0, 6) })}
                                className="crm-input"
                                placeholder="Enter PIN"
                            />
                        </div>
                    </div>
                </div>
            ) : null}
        </div>
    );
}
