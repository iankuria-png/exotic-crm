import React, { useMemo, useState } from 'react';
import { useMutation, useQueryClient } from '@tanstack/react-query';
import api from '../../services/api';
import { useToast } from '../ToastProvider';

const defaultForm = {
    name: '',
    domain: '',
    default_source_platform_id: '',
    source_platform_ids: [],
    country: '',
    timezone: 'Africa/Nairobi',
    currency_code: 'KES',
    phone_prefix: '254',
    wp_api_url: '',
    wp_api_user: '',
    wp_api_password: '',
    db_host: '',
    db_name: '',
    db_user: '',
    db_pass: '',
    db_prefix: 'wp_',
    legacy_self_upload_secret_option: true,
    is_active: false,
};

export default function PbnSiteCreateModal({ open, onClose, platforms = [], onCreated }) {
    const toast = useToast();
    const queryClient = useQueryClient();
    const [form, setForm] = useState(defaultForm);
    const selectedSources = useMemo(() => new Set(form.source_platform_ids.map(String)), [form.source_platform_ids]);

    const createMutation = useMutation({
        mutationFn: (payload) => api.post('/crm/settings/integrations/pbn-sites', payload).then((response) => response.data),
        onSuccess: (response) => {
            queryClient.invalidateQueries({ queryKey: ['settings-pbn-sites'] });
            toast.success(response?.message || 'PBN site created.');
            setForm(defaultForm);
            onCreated?.(response?.site);
            onClose();
        },
        onError: (error) => {
            toast.error(error?.response?.data?.message || 'Could not create PBN site.');
        },
    });

    if (!open) return null;

    const update = (key, value) => setForm((current) => ({ ...current, [key]: value }));
    const toggleSource = (platformId) => {
        const id = String(platformId);
        setForm((current) => {
            const currentSet = new Set(current.source_platform_ids.map(String));
            if (currentSet.has(id)) {
                currentSet.delete(id);
            } else if (currentSet.size < 5) {
                currentSet.add(id);
            }

            const sourceIds = Array.from(currentSet);
            return {
                ...current,
                source_platform_ids: sourceIds,
                default_source_platform_id: sourceIds.includes(String(current.default_source_platform_id))
                    ? current.default_source_platform_id
                    : (sourceIds[0] || ''),
            };
        });
    };
    const submit = () => {
        const { legacy_self_upload_secret_option: legacySelfUploadSecret, ...fields } = form;

        createMutation.mutate({
            ...fields,
            default_source_platform_id: form.default_source_platform_id ? Number(form.default_source_platform_id) : null,
            source_platform_ids: form.source_platform_ids.map(Number),
            currency_code: form.currency_code.toUpperCase(),
            wp_compatibility_settings: { legacy_self_upload_secret_option: legacySelfUploadSecret },
        });
    };

    return (
        <div className="fixed inset-0 z-50 flex items-center justify-center bg-slate-900/45 p-4" role="dialog" aria-modal="true" onClick={createMutation.isPending ? undefined : onClose}>
            <div className="crm-surface max-h-[92vh] w-full max-w-4xl overflow-hidden" onClick={(event) => event.stopPropagation()}>
                <header className="crm-panel-header">
                    <div>
                        <h3 className="crm-panel-title">Add PBN Site</h3>
                        <p className="crm-panel-subtitle">Configure a destination WordPress site outside the main market list.</p>
                    </div>
                    <button type="button" className="crm-btn-secondary px-3 py-2" onClick={onClose} disabled={createMutation.isPending}>Close</button>
                </header>

                <div className="max-h-[70vh] space-y-4 overflow-y-auto p-4">
                    <section className="grid gap-3 md:grid-cols-2">
                        <input value={form.name} onChange={(event) => update('name', event.target.value)} className="crm-input" placeholder="Site label" />
                        <input value={form.domain} onChange={(event) => update('domain', event.target.value)} className="crm-input" placeholder="ugandahotgirls.com" />
                        <input value={form.country} onChange={(event) => update('country', event.target.value)} className="crm-input" placeholder="Country" />
                        <input value={form.timezone} onChange={(event) => update('timezone', event.target.value)} className="crm-input" placeholder="Africa/Kampala" />
                        <input value={form.currency_code} onChange={(event) => update('currency_code', event.target.value.toUpperCase())} className="crm-input" placeholder="UGX" maxLength={3} />
                        <input value={form.phone_prefix} onChange={(event) => update('phone_prefix', event.target.value)} className="crm-input" placeholder="256" />
                    </section>

                    <section className="rounded-lg border border-slate-200 bg-white p-3">
                        <p className="text-sm font-semibold text-slate-900">Source Markets</p>
                        <p className="mt-1 text-xs text-slate-500">Choose up to 5 Exotic markets that can seed this PBN site.</p>
                        <div className="mt-3 grid gap-2 sm:grid-cols-2 xl:grid-cols-3">
                            {platforms.map((platform) => (
                                <label key={platform.platform_id} className="flex min-h-11 items-center gap-2 rounded-md border border-slate-200 bg-slate-50 px-3 py-2 text-sm text-slate-700">
                                    <input
                                        type="checkbox"
                                        checked={selectedSources.has(String(platform.platform_id))}
                                        onChange={() => toggleSource(platform.platform_id)}
                                        className="h-4 w-4 rounded border-slate-300 text-teal-700 focus:ring-teal-200"
                                    />
                                    <span>
                                        <span className="block font-medium text-slate-900">{platform.platform_name}</span>
                                        <span className="block text-xs text-slate-500">{platform.country || platform.domain}</span>
                                    </span>
                                </label>
                            ))}
                        </div>
                        <select
                            value={form.default_source_platform_id}
                            onChange={(event) => update('default_source_platform_id', event.target.value)}
                            className="crm-select mt-3 w-full"
                        >
                            <option value="">Default source market</option>
                            {form.source_platform_ids.map((id) => {
                                const platform = platforms.find((row) => String(row.platform_id) === String(id));
                                return <option key={id} value={id}>{platform?.platform_name || `Market #${id}`}</option>;
                            })}
                        </select>
                    </section>

                    <section className="grid gap-3 md:grid-cols-2">
                        <input value={form.wp_api_url} onChange={(event) => update('wp_api_url', event.target.value)} className="crm-input md:col-span-2" placeholder="https://site.com/wp-json/exotic-crm-sync/v1" />
                        <input value={form.wp_api_user} onChange={(event) => update('wp_api_user', event.target.value)} className="crm-input" placeholder="WP API user" />
                        <input type="password" value={form.wp_api_password} onChange={(event) => update('wp_api_password', event.target.value)} className="crm-input" placeholder="WP API password" />
                        <input value={form.db_host} onChange={(event) => update('db_host', event.target.value)} className="crm-input" placeholder="DB host" />
                        <input value={form.db_name} onChange={(event) => update('db_name', event.target.value)} className="crm-input" placeholder="DB name" />
                        <input value={form.db_user} onChange={(event) => update('db_user', event.target.value)} className="crm-input" placeholder="DB user" />
                        <input type="password" value={form.db_pass} onChange={(event) => update('db_pass', event.target.value)} className="crm-input" placeholder="DB password" />
                        <input value={form.db_prefix} onChange={(event) => update('db_prefix', event.target.value)} className="crm-input" placeholder="wp_" />
                        <label className="flex min-h-11 items-center gap-2 text-sm text-slate-700">
                            <input type="checkbox" checked={form.is_active} onChange={(event) => update('is_active', event.target.checked)} className="h-4 w-4 rounded border-slate-300 text-teal-700 focus:ring-teal-200" />
                            Site active for seed previews
                        </label>
                        <label className="flex min-h-11 items-start gap-2 text-sm text-slate-700 md:col-span-2">
                            <input
                                type="checkbox"
                                checked={form.legacy_self_upload_secret_option}
                                onChange={(event) => update('legacy_self_upload_secret_option', event.target.checked)}
                                className="mt-1 h-4 w-4 rounded border-slate-300 text-teal-700 focus:ring-teal-200"
                            />
                            <span>
                                <span className="block font-medium text-slate-900">Legacy self-upload secret</span>
                                <span className="block text-xs text-slate-500">
                                    Writes the WordPress option the theme's photo and video uploaders use to find a profile. Leave on unless this site
                                    runs a newer uploader — without it, seeded owners cannot upload media.
                                </span>
                            </span>
                        </label>
                    </section>
                </div>

                <footer className="flex flex-wrap justify-end gap-2 border-t border-slate-100 p-4">
                    <button type="button" className="crm-btn-secondary px-3 py-2" onClick={onClose} disabled={createMutation.isPending}>Cancel</button>
                    <button
                        type="button"
                        className="crm-btn-primary px-3 py-2 disabled:cursor-not-allowed disabled:opacity-60"
                        onClick={submit}
                        disabled={createMutation.isPending || !form.name.trim() || !form.domain.trim() || form.source_platform_ids.length < 1}
                    >
                        {createMutation.isPending ? 'Creating...' : 'Create PBN site'}
                    </button>
                </footer>
            </div>
        </div>
    );
}
