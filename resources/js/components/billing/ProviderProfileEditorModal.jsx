import React, { useEffect, useMemo, useState } from 'react';

function buildInitialState(profile, providers, markets) {
    return {
        provider_type_key: profile?.provider_type_key || providers[0]?.key || '',
        profile_name: profile?.profile_name || '',
        country_code: profile?.country_code || '',
        market_id: profile?.market_id ? String(profile.market_id) : '',
        environment: profile?.environment || 'production',
        active: profile?.active ?? true,
        fields: {},
    };
}

export default function ProviderProfileEditorModal({
    open,
    profile = null,
    providers = [],
    schemas = [],
    markets = [],
    onClose,
    onSubmit,
    isSaving = false,
}) {
    const [form, setForm] = useState(() => buildInitialState(profile, providers, markets));

    const normalizedSchemas = useMemo(() => {
        return Array.isArray(schemas) ? schemas : Object.values(schemas || {});
    }, [schemas]);

    useEffect(() => {
        if (!open) {
            return;
        }

        const nextState = buildInitialState(profile, providers, markets);
        const nextFields = {
            ...(profile?.config_json || {}),
        };

        setForm({
            ...nextState,
            fields: nextFields,
        });
    }, [open, profile, providers, markets]);

    useEffect(() => {
        if (!open || form.provider_type_key || providers.length === 0) {
            return;
        }

        setForm((current) => ({
            ...current,
            provider_type_key: providers[0]?.key || '',
        }));
    }, [open, form.provider_type_key, providers]);

    const schema = useMemo(
        () =>
            normalizedSchemas.find(
                (entry) =>
                    entry.provider_key === form.provider_type_key ||
                    entry.key === form.provider_type_key
            ) || null,
        [normalizedSchemas, form.provider_type_key]
    );

    const selectedProvider = useMemo(
        () => providers.find((entry) => entry.key === form.provider_type_key) || null,
        [providers, form.provider_type_key]
    );

    if (!open) {
        return null;
    }

    const editing = Boolean(profile?.id);

    const updateField = (key, value) => {
        setForm((current) => ({
            ...current,
            fields: {
                ...current.fields,
                [key]: value,
            },
        }));
    };

    return (
        <div className="fixed inset-0 z-[110] flex items-center justify-center bg-slate-900/50 p-4" onClick={isSaving ? undefined : onClose}>
            <div
                role="dialog"
                aria-modal="true"
                className="crm-surface max-h-[92vh] w-full max-w-5xl overflow-hidden"
                onClick={(event) => event.stopPropagation()}
            >
                <div className="crm-panel-header">
                    <div>
                        <h3 className="crm-panel-title">
                            {editing ? 'Edit provider profile' : 'Create provider profile'}
                        </h3>
                        <p className="crm-panel-subtitle">
                            Bind a provider family to a market, environment, and credential set without exposing raw secrets in the CRM.
                        </p>
                    </div>
                    <button
                        type="button"
                        onClick={onClose}
                        disabled={isSaving}
                        className="crm-btn-secondary px-3 py-2 disabled:cursor-not-allowed disabled:opacity-60"
                    >
                        Close
                    </button>
                </div>

                <form
                    className="max-h-[calc(92vh-88px)] overflow-y-auto p-5"
                    onSubmit={(event) => {
                        event.preventDefault();
                        onSubmit({
                            provider_type_key: form.provider_type_key,
                            profile_name: form.profile_name,
                            country_code: form.country_code || null,
                            market_id: form.market_id ? Number(form.market_id) : null,
                            environment: form.environment,
                            active: form.active,
                            merchant_scope_json: [],
                            fields: form.fields,
                        });
                    }}
                >
                    <div className="grid gap-5 xl:grid-cols-[minmax(0,1fr)_minmax(0,1.35fr)]">
                        <section className="space-y-4 rounded-2xl border border-slate-200 bg-slate-50/80 p-4">
                            <div>
                                <h4 className="text-sm font-semibold text-slate-900">Profile identity</h4>
                                <p className="mt-1 text-sm text-slate-600">
                                    This metadata controls where the profile appears in routing, diagnostics, and market-level policy.
                                </p>
                            </div>

                            <label className="block space-y-2">
                                <span className="text-[11px] font-semibold uppercase tracking-[0.08em] text-slate-500">
                                    Provider family
                                </span>
                                <select
                                    value={form.provider_type_key}
                                    onChange={(event) => setForm((current) => ({
                                        ...current,
                                        provider_type_key: event.target.value,
                                        fields: {},
                                    }))}
                                    className="crm-select w-full"
                                    disabled={editing || isSaving}
                                >
                                    <option value="">Select a provider family</option>
                                    {providers.map((provider) => (
                                        <option key={provider.key} value={provider.key}>
                                            {provider.label}
                                        </option>
                                    ))}
                                </select>
                            </label>

                            <label className="block space-y-2">
                                <span className="text-[11px] font-semibold uppercase tracking-[0.08em] text-slate-500">
                                    Profile name
                                </span>
                                <input
                                    value={form.profile_name}
                                    onChange={(event) => setForm((current) => ({ ...current, profile_name: event.target.value }))}
                                    className="crm-input"
                                    placeholder="Kenya primary, Ghana backup, sandbox validation"
                                    disabled={isSaving}
                                    required
                                />
                            </label>

                            <div className="grid gap-4 md:grid-cols-2">
                                <label className="block space-y-2">
                                    <span className="text-[11px] font-semibold uppercase tracking-[0.08em] text-slate-500">
                                        Market
                                    </span>
                                    <select
                                        value={form.market_id}
                                        onChange={(event) => setForm((current) => ({ ...current, market_id: event.target.value }))}
                                        className="crm-select w-full"
                                        disabled={isSaving}
                                    >
                                        <option value="">All visible markets</option>
                                        {markets.map((market) => (
                                            <option key={market.id} value={market.id}>
                                                {market.name}
                                            </option>
                                        ))}
                                    </select>
                                </label>

                                <label className="block space-y-2">
                                    <span className="text-[11px] font-semibold uppercase tracking-[0.08em] text-slate-500">
                                        Country code
                                    </span>
                                    <input
                                        value={form.country_code}
                                        onChange={(event) => setForm((current) => ({
                                            ...current,
                                            country_code: event.target.value.toUpperCase().slice(0, 2),
                                        }))}
                                        className="crm-input"
                                        placeholder="KE"
                                        maxLength={2}
                                        disabled={isSaving}
                                    />
                                </label>
                            </div>

                            <div className="grid gap-4 md:grid-cols-2">
                                <label className="block space-y-2">
                                    <span className="text-[11px] font-semibold uppercase tracking-[0.08em] text-slate-500">
                                        Environment
                                    </span>
                                    <select
                                        value={form.environment}
                                        onChange={(event) => setForm((current) => ({ ...current, environment: event.target.value }))}
                                        className="crm-select w-full"
                                        disabled={isSaving}
                                    >
                                        {(schema?.supported_environments || ['production']).map((environment) => (
                                            <option key={environment} value={environment}>
                                                {environment}
                                            </option>
                                        ))}
                                    </select>
                                </label>

                                <label className="flex items-end gap-3 rounded-2xl border border-slate-200 bg-white px-3 py-3">
                                    <input
                                        type="checkbox"
                                        checked={form.active}
                                        onChange={(event) => setForm((current) => ({ ...current, active: event.target.checked }))}
                                        className="mt-0.5 h-4 w-4 rounded border-slate-300 text-teal-700 focus:ring-teal-600"
                                        disabled={isSaving}
                                    />
                                    <div>
                                        <p className="text-sm font-semibold text-slate-900">Profile active</p>
                                        <p className="text-sm text-slate-500">Expose this profile to routing and diagnostics.</p>
                                    </div>
                                </label>
                            </div>

                            {selectedProvider ? (
                                <div className="rounded-2xl border border-teal-100 bg-teal-50/80 px-4 py-3 text-sm text-teal-900">
                                    <p className="font-semibold">{selectedProvider.label}</p>
                                    <p className="mt-1">
                                        {(selectedProvider.meta?.status || 'active') === 'active'
                                            ? 'Active rollout provider family.'
                                            : (selectedProvider.meta?.status || 'active') === 'compatibility'
                                                ? 'Compatibility bridge retained while the registry replaces legacy flows.'
                                                : 'Catalogued for continuity, but not part of the primary rollout lane.'}
                                    </p>
                                </div>
                            ) : null}
                        </section>

                        <section className="space-y-4 rounded-2xl border border-slate-200 bg-white p-4">
                            <div>
                                <h4 className="text-sm font-semibold text-slate-900">Credential schema</h4>
                                <p className="mt-1 text-sm text-slate-600">
                                    Sensitive fields remain encrypted after save. Leaving a secret blank during edits preserves the currently stored value.
                                </p>
                            </div>

                            {!form.provider_type_key ? (
                                <div className="rounded-2xl border border-slate-200 bg-slate-50 px-4 py-3 text-sm text-slate-600">
                                    Select a provider family to load its credential fields and environment options.
                                </div>
                            ) : !schema ? (
                                <div className="rounded-2xl border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-800">
                                    CRM could not resolve a credential schema for the selected provider. Refresh the provider catalog or verify the schema registry mapping.
                                </div>
                            ) : (
                                <div className="grid gap-4 md:grid-cols-2">
                                    {schema.fields.map((field) => {
                                        const configured = Boolean(profile?.secret_state?.[field.key]);
                                        const fieldValue = form.fields[field.key] ?? '';

                                        return (
                                            <label key={field.key} className="block space-y-2">
                                                <div className="flex items-center justify-between gap-2">
                                                    <span className="text-[11px] font-semibold uppercase tracking-[0.08em] text-slate-500">
                                                        {field.label}
                                                        {field.required ? ' *' : ''}
                                                    </span>
                                                    {field.sensitive && configured ? (
                                                        <span className="rounded-full bg-slate-100 px-2 py-0.5 text-[10px] font-semibold uppercase tracking-[0.08em] text-slate-600">
                                                            Configured
                                                        </span>
                                                    ) : null}
                                                </div>

                                                {field.type === 'select' ? (
                                                    <select
                                                        value={fieldValue || field.default || ''}
                                                        onChange={(event) => updateField(field.key, event.target.value)}
                                                        className="crm-select w-full"
                                                        disabled={isSaving}
                                                    >
                                                        {(field.options || []).map((option) => (
                                                            <option key={option} value={option}>
                                                                {option}
                                                            </option>
                                                        ))}
                                                    </select>
                                                ) : (
                                                    <input
                                                        type={field.sensitive ? 'password' : field.type === 'url' ? 'url' : 'text'}
                                                        value={fieldValue}
                                                        onChange={(event) => updateField(field.key, event.target.value)}
                                                        className="crm-input"
                                                        placeholder={field.sensitive && configured ? 'Leave blank to keep current secret' : field.placeholder}
                                                        disabled={isSaving}
                                                    />
                                                )}

                                                <p className="text-xs text-slate-500">
                                                    {field.sensitive
                                                        ? configured
                                                            ? 'Blank preserves the existing encrypted value.'
                                                            : 'Stored as an encrypted secret after save.'
                                                        : 'Visible in configuration summaries and diagnostics.'}
                                                </p>
                                            </label>
                                        );
                                    })}
                                </div>
                            )}
                        </section>
                    </div>

                    <div className="mt-5 flex flex-wrap items-center justify-end gap-3 border-t border-slate-100 pt-4">
                        <button
                            type="button"
                            onClick={onClose}
                            disabled={isSaving}
                            className="crm-btn-secondary disabled:cursor-not-allowed disabled:opacity-60"
                        >
                            Cancel
                        </button>
                        <button
                            type="submit"
                            disabled={isSaving || !schema}
                            className="crm-btn-primary disabled:cursor-not-allowed disabled:opacity-60"
                        >
                            {isSaving ? 'Saving…' : editing ? 'Save profile' : 'Create profile'}
                        </button>
                    </div>
                </form>
            </div>
        </div>
    );
}
