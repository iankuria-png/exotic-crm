import React, { useEffect, useMemo, useState } from 'react';

const documentOptions = [
    { key: 'id_front', label: 'Government ID front' },
    { key: 'id_back', label: 'Government ID back' },
    { key: 'selfie', label: 'Selfie' },
];

const escalationOptions = [
    { value: 'notify_only', label: 'Notify only' },
    { value: 'remove_badge', label: 'Remove badge' },
    { value: 'auto_suspend', label: 'Auto suspend' },
];

function normalizeSettings(settings = {}) {
    return {
        enabled_platform_ids: Array.isArray(settings.enabled_platform_ids) ? settings.enabled_platform_ids.map(Number) : [],
        required_document_kinds: Array.isArray(settings.required_document_kinds) ? settings.required_document_kinds : ['id_front', 'selfie'],
        max_doc_bytes: Number(settings.max_doc_bytes || 20 * 1024 * 1024),
        reject_reason_options: Array.isArray(settings.reject_reason_options) ? settings.reject_reason_options : [],
        search_boost_enabled: settings.search_boost_enabled !== false,
        active_storage_driver: settings.active_storage_driver || 'db',
        s3_bucket: settings.s3_bucket || '',
        s3_region: settings.s3_region || '',
        s3_kms_key_arn: settings.s3_kms_key_arn || '',
        s3_endpoint_override: settings.s3_endpoint_override || '',
        exempt_plan_keys: Array.isArray(settings.exempt_plan_keys) ? settings.exempt_plan_keys : ['forever'],
        grace_days_default: Number(settings.grace_days_default || 30),
        grace_days_per_platform: settings.grace_days_per_platform || {},
        email_warning_days: Array.isArray(settings.email_warning_days) ? settings.email_warning_days : [0, 7, 14, 21, 29],
        escalation_rule_per_platform: settings.escalation_rule_per_platform || {},
        reverify_interval_days: Number(settings.reverify_interval_days || 365),
        reverify_auto_sweep_enabled: settings.reverify_auto_sweep_enabled !== false,
        reverify_dispatch_pace_seconds: Number(settings.reverify_dispatch_pace_seconds || 5),
        fanout_queue_concurrency: Number(settings.fanout_queue_concurrency || 4),
        reviewer_notification_channels: Array.isArray(settings.reviewer_notification_channels) ? settings.reviewer_notification_channels : ['in_app_badge'],
        audit_retention_days: Number(settings.audit_retention_days || 365),
    };
}

export default function KycSetupWizard({
    settings,
    platforms = [],
    onSave,
    onTestS3,
    isSaving = false,
    isTestingS3 = false,
    totalBlobBytes = 0,
    s3Health = null,
}) {
    const [form, setForm] = useState(() => normalizeSettings(settings));
    const [showAdvanced, setShowAdvanced] = useState(false);

    useEffect(() => {
        setForm(normalizeSettings(settings));
    }, [settings]);

    const enabledPlatformSet = useMemo(() => new Set((form.enabled_platform_ids || []).map(Number)), [form.enabled_platform_ids]);

    const updateField = (key, value) => {
        setForm((current) => ({ ...current, [key]: value }));
    };

    const togglePlatform = (platformId) => {
        setForm((current) => {
            const next = new Set((current.enabled_platform_ids || []).map(Number));
            if (next.has(platformId)) {
                next.delete(platformId);
            } else {
                next.add(platformId);
            }
            return { ...current, enabled_platform_ids: Array.from(next).sort((a, b) => a - b) };
        });
    };

    const toggleRequiredDocument = (kind) => {
        setForm((current) => {
            const next = new Set(current.required_document_kinds || []);
            if (next.has(kind)) {
                next.delete(kind);
            } else {
                next.add(kind);
            }
            return { ...current, required_document_kinds: Array.from(next) };
        });
    };

    const savePayload = {
        ...form,
        max_doc_bytes: Math.max(1, Number(form.max_doc_bytes || 0)),
        exempt_plan_keys: String(form.exempt_plan_keys || []).split(',').map((item) => item.trim()).filter(Boolean),
        email_warning_days: String(form.email_warning_days || []).split(',').map((item) => Number(item.trim())).filter((value) => Number.isFinite(value)),
        reviewer_notification_channels: ['in_app_badge'],
    };

    return (
        <div className="space-y-5">
            <section className="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm">
                <div className="flex flex-col gap-3 lg:flex-row lg:items-start lg:justify-between">
                    <div>
                        <h3 className="text-lg font-semibold text-slate-900">KYC setup</h3>
                        <p className="mt-1 max-w-3xl text-sm text-slate-500">Keep rollout soft by default: choose the live markets, the required proof set, and where new documents are stored. Escalations remain opt-in per market.</p>
                    </div>
                    <div className="rounded-xl border border-slate-200 bg-slate-50 px-4 py-3 text-sm text-slate-600">
                        <p><span className="font-semibold text-slate-900">Stored blob volume:</span> {(totalBlobBytes / (1024 * 1024)).toFixed(2)} MB</p>
                        {s3Health ? <p className="mt-1"><span className="font-semibold text-slate-900">S3 health:</span> {s3Health.ok ? 'Connected' : (s3Health.message || 'Not active')}</p> : null}
                    </div>
                </div>

                <div className="mt-5 grid gap-5 lg:grid-cols-[1.1fr_0.9fr]">
                    <div className="space-y-4">
                        <div>
                            <label className="mb-2 block text-sm font-medium text-slate-700">Enabled markets</label>
                            <div className="grid gap-2 sm:grid-cols-2">
                                {platforms.map((platform) => {
                                    const checked = enabledPlatformSet.has(Number(platform.id));
                                    return (
                                        <label key={platform.id} className={`flex items-start gap-3 rounded-xl border px-3 py-3 transition ${checked ? 'border-teal-300 bg-teal-50/70' : 'border-slate-200 bg-slate-50/60 hover:bg-white'}`}>
                                            <input
                                                type="checkbox"
                                                checked={checked}
                                                onChange={() => togglePlatform(Number(platform.id))}
                                                className="mt-1 h-4 w-4 rounded border-slate-300 text-teal-600"
                                            />
                                            <span>
                                                <span className="block text-sm font-semibold text-slate-900">{platform.name || platform.platform_name}</span>
                                                <span className="block text-xs text-slate-500">{platform.country || platform.domain || `Platform #${platform.id}`}</span>
                                            </span>
                                        </label>
                                    );
                                })}
                            </div>
                        </div>

                        <div>
                            <label className="mb-2 block text-sm font-medium text-slate-700">Required documents</label>
                            <div className="grid gap-2 sm:grid-cols-2">
                                {documentOptions.map((option) => {
                                    const checked = (form.required_document_kinds || []).includes(option.key);
                                    return (
                                        <label key={option.key} className={`flex items-center gap-3 rounded-xl border px-3 py-3 transition ${checked ? 'border-teal-300 bg-teal-50/70' : 'border-slate-200 bg-slate-50/60 hover:bg-white'}`}>
                                            <input
                                                type="checkbox"
                                                checked={checked}
                                                onChange={() => toggleRequiredDocument(option.key)}
                                                className="h-4 w-4 rounded border-slate-300 text-teal-600"
                                            />
                                            <span className="text-sm font-medium text-slate-800">{option.label}</span>
                                        </label>
                                    );
                                })}
                            </div>
                        </div>
                    </div>

                    <div className="space-y-4 rounded-2xl border border-slate-200 bg-slate-50/70 p-4">
                        <div>
                            <label className="mb-1 block text-sm font-medium text-slate-700">Storage driver</label>
                            <select
                                value={form.active_storage_driver}
                                onChange={(event) => updateField('active_storage_driver', event.target.value)}
                                className="crm-select w-full"
                            >
                                <option value="db">Database (encrypted in Laravel)</option>
                                <option value="s3">S3 bucket</option>
                            </select>
                            <p className="mt-1 text-xs text-slate-500">DB mode is rollout-safe by default. S3 affects new uploads only.</p>
                        </div>

                        <div className="grid gap-4 sm:grid-cols-2">
                            <div>
                                <label className="mb-1 block text-sm font-medium text-slate-700">Max file size (MB)</label>
                                <input
                                    type="number"
                                    min="1"
                                    value={Math.round(Number(form.max_doc_bytes || 0) / (1024 * 1024))}
                                    onChange={(event) => updateField('max_doc_bytes', Number(event.target.value || 1) * 1024 * 1024)}
                                    className="crm-input w-full"
                                />
                            </div>
                            <div>
                                <label className="mb-1 block text-sm font-medium text-slate-700">Re-verify interval (days)</label>
                                <input
                                    type="number"
                                    min="1"
                                    value={form.reverify_interval_days}
                                    onChange={(event) => updateField('reverify_interval_days', Number(event.target.value || 365))}
                                    className="crm-input w-full"
                                />
                            </div>
                        </div>

                        <label className="flex items-center gap-3 rounded-xl border border-slate-200 bg-white px-3 py-3">
                            <input
                                type="checkbox"
                                checked={Boolean(form.search_boost_enabled)}
                                onChange={(event) => updateField('search_boost_enabled', event.target.checked)}
                                className="h-4 w-4 rounded border-slate-300 text-teal-600"
                            />
                            <span>
                                <span className="block text-sm font-semibold text-slate-900">Boost verified profiles in discovery</span>
                                <span className="block text-xs text-slate-500">Applies both to custom discovery SQL and WP_Query-backed surfaces.</span>
                            </span>
                        </label>
                    </div>
                </div>

                <div className="mt-5 flex flex-wrap items-center gap-3">
                    <button
                        type="button"
                        onClick={() => onSave?.(savePayload)}
                        disabled={isSaving}
                        className="inline-flex items-center rounded-lg bg-teal-700 px-4 py-2 text-sm font-semibold text-white transition hover:bg-teal-800 disabled:cursor-not-allowed disabled:opacity-60"
                    >
                        {isSaving ? 'Saving…' : 'Save KYC settings'}
                    </button>
                    <button
                        type="button"
                        onClick={() => setShowAdvanced((current) => !current)}
                        className="inline-flex items-center rounded-lg border border-slate-200 bg-white px-4 py-2 text-sm font-medium text-slate-700 transition hover:bg-slate-50"
                    >
                        {showAdvanced ? 'Hide advanced' : 'Show advanced'}
                    </button>
                </div>
            </section>

            {showAdvanced ? (
                <section className="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm">
                    <h4 className="text-base font-semibold text-slate-900">Advanced controls</h4>
                    <div className="mt-4 grid gap-4 lg:grid-cols-2">
                        <div>
                            <label className="mb-1 block text-sm font-medium text-slate-700">Exempt plan keys</label>
                            <input
                                value={Array.isArray(form.exempt_plan_keys) ? form.exempt_plan_keys.join(', ') : form.exempt_plan_keys}
                                onChange={(event) => updateField('exempt_plan_keys', event.target.value)}
                                className="crm-input w-full"
                                placeholder="forever, seo_seed"
                            />
                            <p className="mt-1 text-xs text-slate-500">Comma-separated plan/product keys that should stay out of the queue.</p>
                        </div>
                        <div>
                            <label className="mb-1 block text-sm font-medium text-slate-700">Reminder days</label>
                            <input
                                value={Array.isArray(form.email_warning_days) ? form.email_warning_days.join(', ') : form.email_warning_days}
                                onChange={(event) => updateField('email_warning_days', event.target.value)}
                                className="crm-input w-full"
                                placeholder="0, 7, 14, 21, 29"
                            />
                        </div>
                        <div>
                            <label className="mb-1 block text-sm font-medium text-slate-700">Default grace days</label>
                            <input
                                type="number"
                                min="0"
                                value={form.grace_days_default}
                                onChange={(event) => updateField('grace_days_default', Number(event.target.value || 0))}
                                className="crm-input w-full"
                            />
                        </div>
                        <div>
                            <label className="mb-1 block text-sm font-medium text-slate-700">Fanout pacing</label>
                            <div className="grid gap-3 sm:grid-cols-2">
                                <input
                                    type="number"
                                    min="1"
                                    value={form.reverify_dispatch_pace_seconds}
                                    onChange={(event) => updateField('reverify_dispatch_pace_seconds', Number(event.target.value || 1))}
                                    className="crm-input w-full"
                                    placeholder="Dispatch pace (sec)"
                                />
                                <input
                                    type="number"
                                    min="1"
                                    value={form.fanout_queue_concurrency}
                                    onChange={(event) => updateField('fanout_queue_concurrency', Number(event.target.value || 1))}
                                    className="crm-input w-full"
                                    placeholder="Concurrency"
                                />
                            </div>
                        </div>
                    </div>

                    <div className="mt-5 rounded-2xl border border-slate-200 bg-slate-50/70 p-4">
                        <div className="flex items-center justify-between gap-3">
                            <div>
                                <h5 className="text-sm font-semibold text-slate-900">Per-market escalation</h5>
                                <p className="mt-1 text-xs text-slate-500">Notify only is the default. Auto suspend remains an explicit admin choice.</p>
                            </div>
                        </div>
                        <div className="mt-4 space-y-3">
                            {platforms.map((platform) => (
                                <div key={platform.id} className="grid gap-3 rounded-xl border border-slate-200 bg-white px-3 py-3 lg:grid-cols-[minmax(0,1fr)_160px] lg:items-center">
                                    <div>
                                        <p className="text-sm font-semibold text-slate-900">{platform.name || platform.platform_name}</p>
                                        <p className="text-xs text-slate-500">{platform.country || platform.domain || `Platform #${platform.id}`}</p>
                                    </div>
                                    <select
                                        value={form.escalation_rule_per_platform?.[platform.id] || 'notify_only'}
                                        onChange={(event) => updateField('escalation_rule_per_platform', {
                                            ...(form.escalation_rule_per_platform || {}),
                                            [platform.id]: event.target.value,
                                        })}
                                        className="crm-select w-full"
                                    >
                                        {escalationOptions.map((option) => (
                                            <option key={option.value} value={option.value}>{option.label}</option>
                                        ))}
                                    </select>
                                </div>
                            ))}
                        </div>
                    </div>

                    {form.active_storage_driver === 's3' ? (
                        <div className="mt-5 rounded-2xl border border-slate-200 bg-slate-50/70 p-4">
                            <h5 className="text-sm font-semibold text-slate-900">S3 target</h5>
                            <div className="mt-4 grid gap-4 lg:grid-cols-2">
                                <input value={form.s3_bucket} onChange={(event) => updateField('s3_bucket', event.target.value)} className="crm-input w-full" placeholder="Bucket" />
                                <input value={form.s3_region} onChange={(event) => updateField('s3_region', event.target.value)} className="crm-input w-full" placeholder="Region" />
                                <input value={form.s3_kms_key_arn} onChange={(event) => updateField('s3_kms_key_arn', event.target.value)} className="crm-input w-full" placeholder="KMS key ARN (optional)" />
                                <input value={form.s3_endpoint_override} onChange={(event) => updateField('s3_endpoint_override', event.target.value)} className="crm-input w-full" placeholder="Endpoint override (optional)" />
                            </div>
                            <div className="mt-4 flex flex-wrap items-center gap-3">
                                <button
                                    type="button"
                                    onClick={() => onTestS3?.(savePayload)}
                                    disabled={isTestingS3}
                                    className="inline-flex items-center rounded-lg border border-slate-200 bg-white px-4 py-2 text-sm font-medium text-slate-700 transition hover:bg-slate-50 disabled:cursor-not-allowed disabled:opacity-60"
                                >
                                    {isTestingS3 ? 'Testing…' : 'Test S3 connection'}
                                </button>
                                {s3Health?.ok ? <span className="text-sm font-medium text-emerald-700">S3 probe passed.</span> : null}
                            </div>
                        </div>
                    ) : null}
                </section>
            ) : null}
        </div>
    );
}
