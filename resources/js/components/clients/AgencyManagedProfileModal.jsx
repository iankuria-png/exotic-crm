import React, { useEffect, useState } from 'react';
import GenerateBioButton from '../seo/GenerateBioButton';
import {
    MEDIA_UPLOAD_LIMITS,
    isVideoUploadFile,
} from '../MediaUploadProvider';
import RegionCitySelect from './profile-fields/RegionCitySelect';
import CurrencySelect from './profile-fields/CurrencySelect';
import {
    PROFILE_ENUM_OPTIONS,
    RATE_DURATION_OPTIONS,
} from './profile-fields/profileFieldCatalog';

function humanizeProfileFieldName(field) {
    return String(field || '')
        .replace(/([a-z])([A-Z])/g, '$1 $2')
        .replace(/[0-9]/g, ' $&')
        .replace(/_/g, ' ')
        .replace(/\s+/g, ' ')
        .trim()
        .replace(/\b\w/g, (char) => char.toUpperCase());
}

export default function AgencyManagedProfileModal({
    agency,
    form,
    platformId,
    isPending,
    errorMessage,
    selectedServiceCodes,
    mediaSelectionLabel,
    requiresLocation,
    locationMessage,
    birthdayIsValid,
    ratesNeedCurrency,
    onChange,
    onToggleMultiValue,
    onProfileImageSelect,
    onRemoveProfileImage,
    onApplyDefaultRates,
    onLocationCatalogStatusChange,
    onCurrencyCatalogStatusChange,
    onClose,
    onSubmit,
}) {
    const [mediaPreviews, setMediaPreviews] = useState([]);
    const hasPhone = form.phone_normalized.trim() !== '';
    const hasName = form.name.trim().length >= 2;
    const canSubmit = hasName && hasPhone && !requiresLocation && birthdayIsValid && !ratesNeedCurrency && !isPending;
    const selectedRateCurrencyLabel = form.currency ? `Currency #${form.currency}` : 'Currency';

    useEffect(() => {
        const previews = (form.profile_images || []).map((file) => ({
            name: file.name,
            isVideo: isVideoUploadFile(file),
            url: URL.createObjectURL(file),
        }));

        setMediaPreviews(previews);

        return () => {
            previews.forEach((preview) => URL.revokeObjectURL(preview.url));
        };
    }, [form.profile_images]);

    return (
        <div className="fixed inset-0 z-50 flex items-center justify-center bg-black/40 p-3 sm:p-4">
            <div className="flex max-h-[92vh] w-full max-w-5xl flex-col overflow-hidden rounded-xl bg-white shadow-xl">
                <div className="shrink-0 border-b border-slate-200 px-5 py-4 sm:px-6">
                    <div className="flex items-start justify-between gap-4">
                        <div>
                            <h2 className="text-base font-semibold text-slate-900">Add agency provider</h2>
                            <p className="mt-1 text-sm text-slate-500">
                                Create a WordPress provider post owned by {agency?.name || 'this agency'}.
                            </p>
                        </div>
                        <span className="rounded-md bg-violet-50 px-2.5 py-1 text-xs font-semibold text-violet-700 ring-1 ring-inset ring-violet-200">
                            Agency owned
                        </span>
                    </div>
                </div>

                <div className="min-h-0 flex-1 space-y-5 overflow-y-auto px-5 py-5 sm:px-6">
                    <section className="grid gap-3 md:grid-cols-2">
                        <label className="block space-y-1.5">
                            <span className="text-xs font-semibold uppercase tracking-[0.08em] text-slate-500">Provider name <span className="text-rose-500">*</span></span>
                            <input
                                type="text"
                                value={form.name}
                                onChange={(event) => onChange({ name: event.target.value })}
                                disabled={isPending}
                                className="crm-input"
                                placeholder="e.g. Daniella Muli"
                            />
                        </label>

                        <label className="block space-y-1.5">
                            <span className="text-xs font-semibold uppercase tracking-[0.08em] text-slate-500">Phone <span className="text-rose-500">*</span></span>
                            <input
                                type="tel"
                                value={form.phone_normalized}
                                onChange={(event) => onChange({ phone_normalized: event.target.value })}
                                disabled={isPending}
                                className="crm-input"
                                placeholder={agency?.phone_normalized || '254712345678'}
                            />
                        </label>

                        <label className="block space-y-1.5">
                            <span className="text-xs font-semibold uppercase tracking-[0.08em] text-slate-500">Email</span>
                            <input
                                type="email"
                                value={form.email}
                                onChange={(event) => onChange({ email: event.target.value })}
                                disabled={isPending}
                                className="crm-input"
                                placeholder="Optional provider contact"
                            />
                        </label>

                        <label className="block space-y-1.5">
                            <span className="text-xs font-semibold uppercase tracking-[0.08em] text-slate-500">Visibility</span>
                            <select
                                value={form.profile_status}
                                onChange={(event) => onChange({ profile_status: event.target.value })}
                                disabled={isPending}
                                className="crm-input"
                            >
                                <option value="private">Private</option>
                                <option value="pending">Pending</option>
                                <option value="draft">Draft</option>
                                <option value="publish">Published</option>
                            </select>
                        </label>
                    </section>

                    <section className="rounded-lg border border-slate-200 bg-slate-50 p-4">
                        <div className="mb-3 flex items-start justify-between gap-3">
                            <div>
                                <h3 className="text-sm font-semibold text-slate-900">Profile location</h3>
                                <p className="mt-1 text-xs text-slate-500">Use WordPress region and city taxonomy, not a free-text city.</p>
                            </div>
                            <span className="rounded-full bg-amber-100 px-2.5 py-1 text-[10px] font-semibold uppercase tracking-[0.12em] text-amber-700">Required</span>
                        </div>
                        <RegionCitySelect
                            platformId={platformId}
                            regionId={form.region_id}
                            cityId={form.city_id}
                            disabled={isPending}
                            onCatalogStatusChange={({ available, loading }) => {
                                if (!loading) {
                                    onLocationCatalogStatusChange?.(available);
                                }
                            }}
                            onChange={({ region_id, city_id, location_allows_region_only = false }) => onChange({
                                region_id,
                                city_id,
                                location_allows_region_only,
                            })}
                        />
                        {requiresLocation ? (
                            <p className="mt-2 text-xs font-medium text-amber-700">{locationMessage}</p>
                        ) : null}
                    </section>

                    <section className="space-y-3">
                        <div className="flex items-center justify-between gap-3">
                            <div>
                                <h3 className="text-sm font-semibold text-slate-900">Quick profile details</h3>
                                <p className="mt-1 text-xs text-slate-500">Enough detail for a useful roster profile. You can finish the rest now or later.</p>
                            </div>
                            <span className="rounded-full bg-slate-100 px-2.5 py-1 text-[10px] font-semibold uppercase tracking-[0.12em] text-slate-500">Optional</span>
                        </div>
                        <div className="grid gap-3 md:grid-cols-3">
                            <label className="block space-y-1.5">
                                <span className="text-xs font-semibold uppercase tracking-[0.08em] text-slate-500">Birthday</span>
                                <input
                                    type="date"
                                    value={form.birthday}
                                    onChange={(event) => onChange({ birthday: event.target.value })}
                                    disabled={isPending}
                                    className="crm-input"
                                />
                                {!birthdayIsValid ? <p className="text-xs text-rose-600">Provider must be 18 or older.</p> : null}
                            </label>
                            <label className="block space-y-1.5">
                                <span className="text-xs font-semibold uppercase tracking-[0.08em] text-slate-500">Height (cm)</span>
                                <input value={form.height} onChange={(event) => onChange({ height: event.target.value })} disabled={isPending} className="crm-input" placeholder="e.g. 167" />
                            </label>
                            <label className="block space-y-1.5">
                                <span className="text-xs font-semibold uppercase tracking-[0.08em] text-slate-500">Weight (kg)</span>
                                <input value={form.weight} onChange={(event) => onChange({ weight: event.target.value })} disabled={isPending} className="crm-input" placeholder="e.g. 55" />
                            </label>
                        </div>

                        <label className="block space-y-1.5">
                            <span className="text-xs font-semibold uppercase tracking-[0.08em] text-slate-500">Bio</span>
                            <textarea
                                value={form.bio}
                                onChange={(event) => onChange({ bio: event.target.value })}
                                disabled={isPending}
                                rows={4}
                                className="crm-input min-h-[112px]"
                                placeholder="Short first profile bio"
                            />
                            <div className="mt-2 flex flex-wrap items-center gap-3">
                                <GenerateBioButton
                                    platformId={platformId || null}
                                    snapshot={form}
                                    mode="preview"
                                    onAccept={(bioHtml) => onChange({ bio: bioHtml })}
                                />
                            </div>
                        </label>

                        <div className="space-y-2">
                            <div className="flex items-center justify-between gap-3">
                                <span className="text-xs font-semibold uppercase tracking-[0.08em] text-slate-500">Services</span>
                                <span className="text-xs text-slate-500">{selectedServiceCodes.length} selected</span>
                            </div>
                            <div className="rounded-lg border border-slate-200 bg-white p-3">
                                <div className="flex flex-wrap gap-2">
                                    {PROFILE_ENUM_OPTIONS.services.map((option) => {
                                        const selected = selectedServiceCodes.includes(option.value);

                                        return (
                                            <button
                                                key={option.value}
                                                type="button"
                                                onClick={() => onToggleMultiValue('services', option.value)}
                                                disabled={isPending}
                                                aria-pressed={selected}
                                                className={`rounded-full border px-3 py-1.5 text-sm font-medium transition disabled:cursor-not-allowed disabled:opacity-50 ${
                                                    selected
                                                        ? 'border-teal-600 bg-teal-50 text-teal-700'
                                                        : 'border-slate-300 bg-white text-slate-700 hover:border-teal-400 hover:text-teal-700'
                                                }`}
                                            >
                                                {option.label}
                                            </button>
                                        );
                                    })}
                                </div>
                            </div>
                        </div>

                        <div className="space-y-2">
                            <div className="flex flex-wrap items-center justify-between gap-2">
                                <span className="text-xs font-semibold uppercase tracking-[0.08em] text-slate-500">Profile media</span>
                                <span className="text-xs text-slate-500">{mediaSelectionLabel}</span>
                            </div>
                            <input
                                type="file"
                                accept="image/jpeg,image/png,image/webp,video/mp4"
                                multiple
                                onChange={onProfileImageSelect}
                                disabled={isPending}
                                className="crm-input file:mr-3 file:rounded-md file:border-0 file:bg-slate-100 file:px-3 file:py-1.5 file:text-sm file:font-semibold file:text-slate-700"
                            />
                            <p className="text-xs text-slate-500">
                                Images up to {Math.round(MEDIA_UPLOAD_LIMITS.imageMaxBytes / 1024 / 1024)}MB, videos up to {Math.round(MEDIA_UPLOAD_LIMITS.videoMaxBytes / 1024 / 1024)}MB.
                            </p>
                            {mediaPreviews.length > 0 ? (
                                <div className="grid grid-cols-2 gap-2 sm:grid-cols-3 md:grid-cols-6">
                                    {mediaPreviews.map((preview, index) => (
                                        <div key={`${preview.name}-${index}`} className="relative overflow-hidden rounded-lg border border-slate-200 bg-slate-100">
                                            {preview.isVideo ? <video src={preview.url} className="h-24 w-full object-cover" muted /> : <img src={preview.url} alt="" className="h-24 w-full object-cover" />}
                                            <button type="button" onClick={() => onRemoveProfileImage(index)} disabled={isPending} className="absolute right-1 top-1 rounded-md bg-white/90 px-2 py-1 text-[11px] font-semibold text-rose-600 shadow-sm">
                                                Remove
                                            </button>
                                            <p className="truncate px-2 py-1 text-[11px] text-slate-500" title={preview.name}>{preview.name}</p>
                                        </div>
                                    ))}
                                </div>
                            ) : null}
                        </div>
                    </section>

                    <section className="rounded-lg border border-slate-200 bg-white">
                        <button
                            type="button"
                            onClick={() => onChange({ full_profile: !form.full_profile })}
                            disabled={isPending}
                            className="flex w-full items-center justify-between gap-3 px-4 py-3 text-left transition hover:bg-slate-50 disabled:cursor-not-allowed disabled:opacity-60"
                        >
                            <span>
                                <span className="block text-sm font-semibold text-slate-900">Capture full profile now</span>
                                <span className="mt-0.5 block text-xs text-slate-500">Appearance, rates, availability, socials, and lifestyle fields.</span>
                            </span>
                            <span className={`flex h-5 w-5 items-center justify-center rounded border ${form.full_profile ? 'border-teal-600 bg-teal-600 text-white' : 'border-slate-300 bg-white'}`}>
                                {form.full_profile ? (
                                    <svg className="h-3.5 w-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path strokeLinecap="round" strokeLinejoin="round" strokeWidth={3} d="M5 13l4 4L19 7" /></svg>
                                ) : null}
                            </span>
                        </button>

                        {form.full_profile ? (
                            <div className="space-y-5 border-t border-slate-200 px-4 py-4">
                                <div>
                                    <h3 className="text-sm font-semibold text-slate-900">Appearance</h3>
                                    <div className="mt-3 grid gap-3 md:grid-cols-3">
                                        {['gender', 'ethnicity', 'build', 'haircolor', 'hairlength', 'bustsize', 'looks', 'smoker'].map((field) => (
                                            <label key={field} className="block space-y-1.5">
                                                <span className="text-xs font-semibold uppercase tracking-[0.08em] text-slate-500">{humanizeProfileFieldName(field)}</span>
                                                <select value={form[field] || ''} onChange={(event) => onChange({ [field]: event.target.value })} disabled={isPending} className="crm-input">
                                                    <option value="">Select</option>
                                                    {PROFILE_ENUM_OPTIONS[field]?.map((option) => <option key={option.value} value={option.value}>{option.label}</option>)}
                                                </select>
                                            </label>
                                        ))}
                                    </div>
                                </div>

                                <div>
                                    <div className="flex flex-wrap items-center justify-between gap-2">
                                        <h3 className="text-sm font-semibold text-slate-900">Rates and availability</h3>
                                        <div className="flex flex-wrap gap-2">
                                            <button type="button" onClick={() => onApplyDefaultRates('incall')} disabled={isPending} className="rounded-md border border-slate-200 px-2.5 py-1 text-xs font-semibold text-slate-600 hover:bg-slate-50 disabled:opacity-50">Fill incall</button>
                                            <button type="button" onClick={() => onApplyDefaultRates('outcall')} disabled={isPending} className="rounded-md border border-slate-200 px-2.5 py-1 text-xs font-semibold text-slate-600 hover:bg-slate-50 disabled:opacity-50">Fill outcall</button>
                                        </div>
                                    </div>
                                    <div className="mt-3 grid gap-3 md:grid-cols-3">
                                        <div className="md:col-span-3">
                                            <CurrencySelect
                                                platformId={platformId}
                                                value={form.currency || ''}
                                                disabled={isPending}
                                                onCatalogStatusChange={({ available, loading }) => {
                                                    if (!loading) {
                                                        onCurrencyCatalogStatusChange?.(available);
                                                    }
                                                }}
                                                onChange={(currency) => onChange({ currency })}
                                            />
                                            {ratesNeedCurrency ? <p className="mt-1 text-xs font-medium text-rose-600">Choose a currency before saving rates.</p> : null}
                                        </div>
                                        <label className="block space-y-1.5">
                                            <span className="text-xs font-semibold uppercase tracking-[0.08em] text-slate-500">Incall default</span>
                                            <input value={form.incall} onChange={(event) => onChange({ incall: event.target.value })} disabled={isPending} className="crm-input" placeholder="e.g. 1500" />
                                        </label>
                                        <label className="block space-y-1.5">
                                            <span className="text-xs font-semibold uppercase tracking-[0.08em] text-slate-500">Outcall default</span>
                                            <input value={form.outcall} onChange={(event) => onChange({ outcall: event.target.value })} disabled={isPending} className="crm-input" placeholder="e.g. 2000" />
                                        </label>
                                        <label className="block space-y-1.5">
                                            <span className="text-xs font-semibold uppercase tracking-[0.08em] text-slate-500">Extra services</span>
                                            <input value={form.extraservices} onChange={(event) => onChange({ extraservices: event.target.value })} disabled={isPending} className="crm-input" placeholder="Short extras note" />
                                        </label>
                                    </div>
                                    <div className="mt-3 overflow-auto rounded-lg border border-slate-200">
                                        <table className="w-full min-w-[520px] text-xs">
                                            <thead>
                                                <tr className="bg-slate-50 text-slate-600">
                                                    <th className="px-3 py-2 text-left font-semibold">Duration</th>
                                                    <th className="px-3 py-2 text-left font-semibold">Incall <span className="font-normal text-slate-400">({selectedRateCurrencyLabel})</span></th>
                                                    <th className="px-3 py-2 text-left font-semibold">Outcall <span className="font-normal text-slate-400">({selectedRateCurrencyLabel})</span></th>
                                                </tr>
                                            </thead>
                                            <tbody>
                                                {RATE_DURATION_OPTIONS.map(([key, label]) => (
                                                    <tr key={key} className="border-t border-slate-100">
                                                        <td className="px-3 py-2 font-medium text-slate-700">{label}</td>
                                                        <td className="px-3 py-2"><input value={form[`rate${key}_incall`]} onChange={(event) => onChange({ [`rate${key}_incall`]: event.target.value })} disabled={isPending} className="crm-input py-1.5 text-xs" placeholder="-" /></td>
                                                        <td className="px-3 py-2"><input value={form[`rate${key}_outcall`]} onChange={(event) => onChange({ [`rate${key}_outcall`]: event.target.value })} disabled={isPending} className="crm-input py-1.5 text-xs" placeholder="-" /></td>
                                                    </tr>
                                                ))}
                                            </tbody>
                                        </table>
                                    </div>
                                    <div className="mt-3 flex flex-wrap gap-2">
                                        {PROFILE_ENUM_OPTIONS.availability.map((option) => {
                                            const selected = (form.availability || []).includes(option.value);

                                            return (
                                                <button key={option.value} type="button" onClick={() => onToggleMultiValue('availability', option.value)} disabled={isPending} className={`rounded-full border px-3 py-1.5 text-xs font-semibold transition disabled:opacity-50 ${selected ? 'border-teal-600 bg-teal-50 text-teal-700' : 'border-slate-300 bg-white text-slate-700 hover:border-teal-400'}`}>
                                                    {option.plainLabel}
                                                </button>
                                            );
                                        })}
                                    </div>
                                </div>

                                <div>
                                    <h3 className="text-sm font-semibold text-slate-900">Social and contact links</h3>
                                    <div className="mt-3 grid gap-3 md:grid-cols-3">
                                        {['whatsapp', 'telegram', 'instagram', 'twitter', 'website', 'facebook', 'snapchat'].map((field) => (
                                            <label key={field} className="block space-y-1.5">
                                                <span className="text-xs font-semibold uppercase tracking-[0.08em] text-slate-500">{humanizeProfileFieldName(field)}</span>
                                                <input type="text" value={form[field] || ''} onChange={(event) => onChange({ [field]: event.target.value })} disabled={isPending} className="crm-input" placeholder={field === 'whatsapp' ? 'Defaults to phone' : ''} />
                                            </label>
                                        ))}
                                    </div>
                                </div>

                                <div>
                                    <h3 className="text-sm font-semibold text-slate-900">Lifestyle</h3>
                                    <div className="mt-3 grid gap-3 md:grid-cols-3">
                                        {['education', 'occupation', 'sports', 'hobbies', 'zodiacsign', 'sexualorientation'].map((field) => (
                                            <label key={field} className="block space-y-1.5">
                                                <span className="text-xs font-semibold uppercase tracking-[0.08em] text-slate-500">{humanizeProfileFieldName(field)}</span>
                                                <input type="text" value={form[field] || ''} onChange={(event) => onChange({ [field]: event.target.value })} disabled={isPending} className="crm-input" />
                                            </label>
                                        ))}
                                    </div>
                                    <div className="mt-3 space-y-2">
                                        {[1, 2, 3].map((index) => (
                                            <div key={index} className="grid gap-2 md:grid-cols-2">
                                                <input value={form[`language${index}`] || ''} onChange={(event) => onChange({ [`language${index}`]: event.target.value })} disabled={isPending} className="crm-input" placeholder={`Language ${index}`} />
                                                <select value={form[`language${index}level`] || ''} onChange={(event) => onChange({ [`language${index}level`]: event.target.value })} disabled={isPending} className="crm-input">
                                                    <option value="">Level</option>
                                                    {PROFILE_ENUM_OPTIONS.languagelevel.map((option) => <option key={option.value} value={option.value}>{option.plainLabel}</option>)}
                                                </select>
                                            </div>
                                        ))}
                                    </div>
                                </div>
                            </div>
                        ) : null}
                    </section>

                    <label className="block space-y-1.5">
                        <span className="text-xs font-semibold uppercase tracking-[0.08em] text-slate-500">Audit reason</span>
                        <input type="text" value={form.reason} onChange={(event) => onChange({ reason: event.target.value })} disabled={isPending} className="crm-input" />
                    </label>

                    {!hasName ? <p className="rounded-md border border-amber-200 bg-amber-50 px-3 py-2 text-sm text-amber-800">Provider name must be at least 2 characters.</p> : null}
                    {!hasPhone ? <p className="rounded-md border border-amber-200 bg-amber-50 px-3 py-2 text-sm text-amber-800">Phone is required.</p> : null}
                    {errorMessage ? <p className="rounded-md border border-rose-200 bg-rose-50 px-3 py-2 text-sm text-rose-700">{errorMessage}</p> : null}
                </div>

                <div className="shrink-0 border-t border-slate-200 px-5 py-4 sm:px-6">
                    <div className="flex flex-col gap-2 sm:flex-row sm:items-center sm:justify-between">
                        <p className="text-xs text-slate-500">
                            The provider will appear in {agency?.name || 'the agency'} roster after WordPress provisioning and sync.
                        </p>
                        <div className="flex justify-end gap-2">
                            <button type="button" onClick={onClose} disabled={isPending} className="crm-btn-secondary">
                                Cancel
                            </button>
                            <button type="button" onClick={onSubmit} disabled={!canSubmit} className="crm-btn-primary disabled:cursor-not-allowed disabled:opacity-50">
                                {isPending ? 'Creating...' : 'Create provider'}
                            </button>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    );
}
