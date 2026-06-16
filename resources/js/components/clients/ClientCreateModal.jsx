import React, { useEffect, useMemo, useState } from 'react';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import api from '../../services/api';
import { normalizePhone } from '../../utils/phone';
import GenerateBioButton from '../seo/GenerateBioButton';
import { useToast } from '../ToastProvider';
import RegionCitySelect from './profile-fields/RegionCitySelect';
import CurrencySelect from './profile-fields/CurrencySelect';
import {
    PROFILE_ENUM_OPTIONS,
    RATE_DURATION_OPTIONS,
    parseProfileServices,
} from './profile-fields/profileFieldCatalog';

const PROFILE_IMAGE_LIMIT = 6;
const PROFILE_IMAGE_MAX_BYTES = 5 * 1024 * 1024;
const PROFILE_IMAGE_TYPES = new Set(['image/jpeg', 'image/png', 'image/webp']);
const PROFILE_IMAGE_EXTENSIONS = new Set(['jpg', 'jpeg', 'png', 'webp']);

function generateProvisionRequestId() {
    if (typeof globalThis.crypto?.randomUUID === 'function') {
        return globalThis.crypto.randomUUID();
    }

    return `crm-provision-${Date.now()}-${Math.random().toString(16).slice(2, 10)}`;
}

function defaultForm(platformId = '', onboardingMode = 'wp_provision') {
    return {
        platform_id: platformId,
        name: '',
        phone_normalized: '',
        email: '',
        region_id: null,
        city_id: null,
        profile_status: 'private',
        assigned_to: '',
        onboarding_mode: onboardingMode,
        full_profile: false,
        provision_request_id: generateProvisionRequestId(),
        wp_username: '',
        wp_password: '',
        birthday: '',
        gender: '',
        ethnicity: '',
        height: '',
        build: '',
        haircolor: '',
        hairlength: '',
        bustsize: '',
        weight: '',
        looks: '',
        smoker: '',
        availability: [],
        services: [],
        extraservices: '',
        incall: '',
        outcall: '',
        currency: null,
        rate30min_incall: '', rate30min_outcall: '',
        rate1h_incall: '', rate1h_outcall: '',
        rate2h_incall: '', rate2h_outcall: '',
        rate3h_incall: '', rate3h_outcall: '',
        rate6h_incall: '', rate6h_outcall: '',
        rate12h_incall: '', rate12h_outcall: '',
        rate24h_incall: '', rate24h_outcall: '',
        whatsapp: '',
        instagram: '',
        twitter: '',
        telegram: '',
        website: '',
        facebook: '',
        snapchat: '',
        bio: '',
        education: '',
        occupation: '',
        sports: '',
        hobbies: '',
        zodiacsign: '',
        sexualorientation: '',
        language1: '',
        language1level: '',
        language2: '',
        language2level: '',
        language3: '',
        language3level: '',
        profile_images: [],
    };
}

function fileExtension(filename) {
    const parts = String(filename || '').split('.');
    return parts.length > 1 ? parts.pop().toLowerCase() : '';
}

export default function ClientCreateModal({
    open,
    onClose,
    initialPlatformId = '',
    lockedPlatformId = null,
    lockedOnboardingMode = null,
    signupSource = null,
    title = 'Add Client',
    subtitle = null,
    submitLabel = null,
    reason = 'Client create from CRM modal',
    onCreated = null,
}) {
    const toast = useToast();
    const queryClient = useQueryClient();
    const initialMode = lockedOnboardingMode || 'wp_provision';
    const [form, setForm] = useState(() => defaultForm(initialPlatformId, initialMode));
    const [imagePreviews, setImagePreviews] = useState([]);
    const [duplicateMatches, setDuplicateMatches] = useState([]);

    useEffect(() => {
        if (!open) {
            return;
        }

        setForm(defaultForm(String(lockedPlatformId || initialPlatformId || ''), initialMode));
        setDuplicateMatches([]);
    }, [open, initialPlatformId, initialMode, lockedPlatformId]);

    useEffect(() => {
        const previews = form.profile_images.map((file) => ({
            name: file.name,
            url: URL.createObjectURL(file),
        }));

        setImagePreviews(previews);

        return () => {
            previews.forEach((preview) => URL.revokeObjectURL(preview.url));
        };
    }, [form.profile_images]);

    const integrationsQuery = useQuery({
        queryKey: ['settings-integrations', 'client-create-shared'],
        queryFn: () => api.get('/crm/settings/integrations').then((response) => response.data),
        enabled: open,
    });

    const platformOptions = integrationsQuery.data?.platforms || [];
    const selectedPlatform = platformOptions.find(
        (platform) => String(platform.platform_id) === String(form.platform_id),
    ) || null;
    const phonePrefix = selectedPlatform?.phone_prefix || platformOptions[0]?.phone_prefix || '254';

    const ownersQuery = useQuery({
        queryKey: ['settings-owners', 'client-create-shared', form.platform_id],
        queryFn: () => api.get('/crm/settings/owners', {
            params: { platform_id: Number(form.platform_id) },
        }).then((response) => response.data),
        enabled: open && Boolean(form.platform_id),
    });

    const owners = ownersQuery.data?.owners || [];
    const isWpProvision = form.onboarding_mode === 'wp_provision';
    const requiresProvisionContact = isWpProvision && !form.email.trim() && !form.phone_normalized.trim();
    const requiresProvisionLocation = isWpProvision && (!form.region_id || !form.city_id);
    const canSubmit = Boolean(form.platform_id)
        && form.name.trim().length >= 2
        && !requiresProvisionContact
        && !requiresProvisionLocation;

    const uploadProfileImages = async (client, files) => {
        if (!client?.id || files.length === 0) {
            return;
        }

        const formData = new FormData();
        files.forEach((file) => formData.append('files[]', file));
        formData.append('set_main', files.length === 1 ? '1' : '0');
        formData.append('reason', 'Background image upload from client create flow');

        try {
            await api.post(`/crm/clients/${client.id}/media`, formData, {
                headers: { 'Content-Type': 'multipart/form-data' },
            });
            queryClient.invalidateQueries({ queryKey: ['clients'] });
            toast.success('Images uploaded.');
        } catch {
            toast.warning('Client was created. Some images may need retry from the Media tab.', { duration: 7000 });
        }
    };

    const createMutation = useMutation({
        mutationFn: (payload) => {
            const requestPayload = { ...payload };
            delete requestPayload.profile_images;

            return api.post('/crm/clients', requestPayload).then((response) => response.data);
        },
        onSuccess: (createdClient, variables) => {
            const images = Array.isArray(variables?.profile_images) ? variables.profile_images : [];
            const matches = Array.isArray(createdClient?.duplicate_phone_matches)
                ? createdClient.duplicate_phone_matches
                : [];

            queryClient.invalidateQueries({ queryKey: ['clients'] });
            queryClient.invalidateQueries({ queryKey: ['dashboard'] });
            queryClient.invalidateQueries({ queryKey: ['field-home'] });
            setDuplicateMatches(matches);

            if (images.length > 0) {
                void uploadProfileImages(createdClient, images);
            }

            toast.success(isWpProvision ? 'Client provisioned.' : 'Client created.');
            onCreated?.(createdClient, { duplicateMatches: matches });
            setForm(defaultForm(String(lockedPlatformId || initialPlatformId || ''), initialMode));
        },
        onError: (error) => {
            toast.error(error?.response?.data?.message || 'Client creation failed. Please review the form.');
        },
    });

    const handleProfileImageSelect = (event) => {
        const files = Array.from(event.target.files || []);
        if (files.length === 0) {
            return;
        }

        setForm((current) => {
            const accepted = [];
            const rejected = [];

            [...current.profile_images, ...files].forEach((file) => {
                const extension = fileExtension(file.name);
                const validType = PROFILE_IMAGE_TYPES.has(file.type) || PROFILE_IMAGE_EXTENSIONS.has(extension);
                const validSize = file.size <= PROFILE_IMAGE_MAX_BYTES;

                if (!validType || !validSize) {
                    rejected.push(file.name);
                    return;
                }

                if (accepted.length < PROFILE_IMAGE_LIMIT) {
                    accepted.push(file);
                }
            });

            if (rejected.length > 0) {
                toast.warning('Some images were skipped. Use JPG, PNG, or WEBP up to 5MB.');
            }

            if (current.profile_images.length + files.length > PROFILE_IMAGE_LIMIT) {
                toast.warning(`Only ${PROFILE_IMAGE_LIMIT} images can be attached during creation.`);
            }

            return { ...current, profile_images: accepted };
        });

        event.target.value = '';
    };

    const removeProfileImage = (index) => {
        setForm((current) => ({
            ...current,
            profile_images: current.profile_images.filter((_, currentIndex) => currentIndex !== index),
        }));
    };

    const bodySubtitle = subtitle || (isWpProvision
        ? 'Provision a WordPress profile and link it to CRM in one flow.'
        : 'Create a CRM client record for outreach and deal tracking.');

    const resolvedSubmitLabel = submitLabel || (createMutation.isPending
        ? 'Creating...'
        : isWpProvision
            ? 'Provision and create client'
            : 'Create client');

    const fieldSourceBanner = useMemo(() => {
        if (signupSource !== 'field') {
            return null;
        }

        return (
            <div className="rounded-md border border-teal-200 bg-teal-50 px-3 py-2 text-xs text-teal-800">
                Field account creation is optimized for speed. Duplicate phone numbers are allowed.
            </div>
        );
    }, [signupSource]);
    const selectedServiceCodes = Array.isArray(form.services)
        ? form.services.map((value) => String(value || '').trim()).filter(Boolean)
        : [];

    if (!open) {
        return null;
    }

    return (
        <div className="fixed inset-0 z-50 flex items-center justify-center bg-slate-900/45 p-4" onClick={onClose}>
            <div className="flex max-h-[90vh] w-full max-w-2xl flex-col rounded-lg border border-slate-200 bg-white shadow-xl" onClick={(event) => event.stopPropagation()}>
                <header className="crm-panel-header shrink-0">
                    <div>
                        <h3 className="crm-panel-title">{title}</h3>
                        <p className="crm-panel-subtitle">{bodySubtitle}</p>
                    </div>
                </header>

                <div className="min-h-0 flex-1 overflow-y-auto">
                    <div className="grid gap-3 p-4 md:grid-cols-2">
                        {fieldSourceBanner ? <div className="md:col-span-2">{fieldSourceBanner}</div> : null}

                        <div className="md:col-span-2">
                            <label htmlFor="client-create-market" className="mb-1 block text-sm font-medium text-slate-700">Market</label>
                            <select
                                id="client-create-market"
                                value={form.platform_id}
                                onChange={(event) => setForm((current) => ({ ...current, platform_id: event.target.value, assigned_to: '' }))}
                                className="crm-select w-full"
                                disabled={Boolean(lockedPlatformId)}
                            >
                                <option value="">Select market</option>
                                {platformOptions.map((platform) => (
                                    <option key={platform.platform_id} value={platform.platform_id}>
                                        {platform.platform_name}
                                    </option>
                                ))}
                            </select>
                        </div>

                        {!lockedOnboardingMode ? (
                            <div className="md:col-span-2">
                                <label className="mb-1 block text-sm font-medium text-slate-700">Onboarding mode</label>
                                <div className="inline-flex rounded-lg border border-slate-200 bg-slate-50 p-1">
                                    <button
                                        type="button"
                                        onClick={() => setForm((current) => ({
                                            ...current,
                                            onboarding_mode: 'manual',
                                            wp_username: '',
                                            wp_password: '',
                                            birthday: '',
                                            height: '',
                                            weight: '',
                                            bio: '',
                                            profile_images: [],
                                        }))}
                                        className={`rounded-md px-3 py-1.5 text-sm font-medium transition ${form.onboarding_mode === 'manual' ? 'bg-white text-slate-900 shadow-sm' : 'text-slate-600 hover:text-slate-900'}`}
                                    >
                                        CRM only
                                    </button>
                                    <button
                                        type="button"
                                        onClick={() => setForm((current) => ({ ...current, onboarding_mode: 'wp_provision' }))}
                                        className={`rounded-md px-3 py-1.5 text-sm font-medium transition ${form.onboarding_mode === 'wp_provision' ? 'bg-white text-slate-900 shadow-sm' : 'text-slate-600 hover:text-slate-900'}`}
                                    >
                                        Provision in WordPress
                                    </button>
                                </div>
                            </div>
                        ) : null}

                        <div>
                            <label htmlFor="client-create-name" className="mb-1 block text-sm font-medium text-slate-700">Client name</label>
                            <input
                                id="client-create-name"
                                type="text"
                                value={form.name}
                                onChange={(event) => setForm((current) => ({ ...current, name: event.target.value }))}
                                className="crm-input"
                                placeholder="Enter client name"
                            />
                        </div>

                        <div>
                            <label htmlFor="client-create-phone" className="mb-1 block text-sm font-medium text-slate-700">Phone</label>
                            <input
                                id="client-create-phone"
                                type="text"
                                value={form.phone_normalized}
                                onChange={(event) => setForm((current) => ({ ...current, phone_normalized: event.target.value }))}
                                className="crm-input"
                                placeholder={`e.g. ${phonePrefix}712345678`}
                            />
                        </div>

                        <div>
                            <label htmlFor="client-create-email" className="mb-1 block text-sm font-medium text-slate-700">Email</label>
                            <input
                                id="client-create-email"
                                type="email"
                                value={form.email}
                                onChange={(event) => setForm((current) => ({ ...current, email: event.target.value }))}
                                className="crm-input"
                                placeholder="name@example.com"
                            />
                        </div>

                        <div>
                            <label htmlFor="client-create-status" className="mb-1 block text-sm font-medium text-slate-700">Status</label>
                            <select
                                id="client-create-status"
                                value={form.profile_status}
                                onChange={(event) => setForm((current) => ({ ...current, profile_status: event.target.value }))}
                                className="crm-select w-full"
                            >
                                <option value="private">Inactive</option>
                                <option value="publish">Active</option>
                                <option value="draft">Draft</option>
                                <option value="pending">Pending</option>
                            </select>
                        </div>

                        {isWpProvision ? (
                            <div className="md:col-span-2">
                                <RegionCitySelect
                                    platformId={form.platform_id ? Number(form.platform_id) : null}
                                    regionId={form.region_id}
                                    cityId={form.city_id}
                                    onChange={({ region_id, city_id }) => setForm((current) => ({
                                        ...current,
                                        region_id,
                                        city_id,
                                    }))}
                                />
                                {requiresProvisionLocation ? (
                                    <p className="mt-2 text-xs font-medium text-amber-700">
                                        Choose both a region and a city before provisioning this WordPress profile.
                                    </p>
                                ) : null}
                            </div>
                        ) : null}

                        <div>
                            <label htmlFor="client-create-owner" className="mb-1 block text-sm font-medium text-slate-700">Owner</label>
                            <select
                                id="client-create-owner"
                                value={form.assigned_to}
                                onChange={(event) => setForm((current) => ({ ...current, assigned_to: event.target.value }))}
                                className="crm-select w-full"
                                disabled={!form.platform_id || ownersQuery.isLoading}
                            >
                                <option value="">{ownersQuery.isLoading ? 'Loading owners...' : 'Auto-assign owner'}</option>
                                {owners.map((owner) => (
                                    <option key={owner.id} value={owner.id}>
                                        {owner.name} ({owner.role})
                                    </option>
                                ))}
                            </select>
                        </div>

                        {isWpProvision ? (
                            <div className="md:col-span-2 rounded-lg border border-slate-200 bg-slate-50/70 p-3">
                                <label className="flex items-center justify-between gap-3">
                                    <span>
                                        <span className="block text-sm font-semibold text-slate-800">Full profile mode</span>
                                        <span className="block text-xs text-slate-500">Quick provision keeps this short. Turn this on only when you want to capture appearance, services, rates, socials, bio, and media now.</span>
                                    </span>
                                    <input
                                        type="checkbox"
                                        checked={Boolean(form.full_profile)}
                                        onChange={(event) => setForm((current) => ({ ...current, full_profile: event.target.checked }))}
                                        className="h-5 w-5 rounded border-slate-300 text-teal-600 focus:ring-teal-200"
                                    />
                                </label>
                                {!form.full_profile ? (
                                    <div className="mt-3 rounded-md border border-dashed border-slate-200 bg-white px-3 py-2 text-xs text-slate-600">
                                        Quick provision will create the client with market, contact, location, status, and owner first. You can complete the full WordPress profile right after from the Edit Profile tab.
                                    </div>
                                ) : null}
                            </div>
                        ) : null}

                        {isWpProvision ? (
                            <>
                                <div>
                                    <label htmlFor="client-create-wp-username" className="mb-1 block text-sm font-medium text-slate-700">WP username</label>
                                    <input
                                        id="client-create-wp-username"
                                        type="text"
                                        value={form.wp_username}
                                        onChange={(event) => setForm((current) => ({ ...current, wp_username: event.target.value }))}
                                        className="crm-input"
                                        placeholder="Auto-generated if blank"
                                    />
                                </div>

                                <div>
                                    <label htmlFor="client-create-wp-password" className="mb-1 block text-sm font-medium text-slate-700">Temp password</label>
                                    <input
                                        id="client-create-wp-password"
                                        type="text"
                                        value={form.wp_password}
                                        onChange={(event) => setForm((current) => ({ ...current, wp_password: event.target.value }))}
                                        className="crm-input"
                                        placeholder="Auto-generated if blank"
                                    />
                                </div>

                                <div className={`border-t border-slate-100 pt-3 md:col-span-2 ${form.full_profile ? '' : 'hidden'}`}>
                                    <div className="grid gap-3 md:grid-cols-3">
                                        <div>
                                            <label htmlFor="client-create-birthday" className="mb-1 block text-sm font-medium text-slate-700">Birthday</label>
                                            <input
                                                id="client-create-birthday"
                                                type="date"
                                                value={form.birthday}
                                                onChange={(event) => setForm((current) => ({ ...current, birthday: event.target.value }))}
                                                className="crm-input"
                                            />
                                        </div>
                                        <div>
                                            <label htmlFor="client-create-height" className="mb-1 block text-sm font-medium text-slate-700">Height</label>
                                            <input
                                                id="client-create-height"
                                                type="text"
                                                inputMode="numeric"
                                                value={form.height}
                                                onChange={(event) => setForm((current) => ({ ...current, height: event.target.value }))}
                                                className="crm-input"
                                                placeholder="e.g. 167"
                                            />
                                        </div>
                                        <div>
                                            <label htmlFor="client-create-weight" className="mb-1 block text-sm font-medium text-slate-700">Weight</label>
                                            <input
                                                id="client-create-weight"
                                                type="text"
                                                inputMode="numeric"
                                                value={form.weight}
                                                onChange={(event) => setForm((current) => ({ ...current, weight: event.target.value }))}
                                                className="crm-input"
                                                placeholder="e.g. 55"
                                            />
                                        </div>
                                    </div>

                                    <div className="mt-3">
                                        <label htmlFor="client-create-bio" className="mb-1 block text-sm font-medium text-slate-700">Profile bio</label>
                                        <textarea
                                            id="client-create-bio"
                                            value={form.bio}
                                            onChange={(event) => setForm((current) => ({ ...current, bio: event.target.value }))}
                                            className="crm-input"
                                            rows={3}
                                            maxLength={5000}
                                            placeholder="Short public profile introduction"
                                        />
                                        <div className="mt-1">
                                            <GenerateBioButton
                                                platformId={form.platform_id ? Number(form.platform_id) : null}
                                                snapshot={form}
                                                mode="preview"
                                                onAccept={(bioHtml) => setForm((current) => ({ ...current, bio: bioHtml }))}
                                            />
                                        </div>
                                    </div>

                                    <div className="mt-3">
                                        <div className="flex flex-wrap items-center justify-between gap-2">
                                            <label htmlFor="client-create-profile-images" className="block text-sm font-medium text-slate-700">Profile images</label>
                                            <span className="text-xs text-slate-500">{form.profile_images.length}/{PROFILE_IMAGE_LIMIT} selected</span>
                                        </div>
                                        <input
                                            id="client-create-profile-images"
                                            type="file"
                                            accept="image/jpeg,image/png,image/webp"
                                            multiple
                                            onChange={handleProfileImageSelect}
                                            className="crm-input mt-1"
                                        />
                                        {imagePreviews.length > 0 ? (
                                            <div className="mt-3 grid grid-cols-3 gap-2 sm:grid-cols-6">
                                                {imagePreviews.map((preview, index) => (
                                                    <div key={`${preview.name}-${index}`} className="group relative overflow-hidden rounded-md border border-slate-200 bg-slate-50">
                                                        <img src={preview.url} alt="" className="aspect-square w-full object-cover" />
                                                        <button
                                                            type="button"
                                                            onClick={() => removeProfileImage(index)}
                                                            className="absolute right-1 top-1 rounded bg-white/90 px-1.5 py-0.5 text-[11px] font-semibold text-slate-700 shadow-sm transition hover:bg-white hover:text-rose-700"
                                                        >
                                                            Remove
                                                        </button>
                                                    </div>
                                                ))}
                                            </div>
                                        ) : null}
                                    </div>

                                    {form.full_profile ? (
                                        <div className="mt-4 space-y-4 border-t border-slate-100 pt-4">
                                            <div className="grid gap-3 md:grid-cols-2">
                                                {['gender', 'ethnicity', 'build', 'haircolor', 'hairlength', 'bustsize', 'looks', 'smoker'].map((field) => (
                                                    <label key={field} className="space-y-1">
                                                        <span className="text-xs font-semibold uppercase tracking-[0.08em] text-slate-500">
                                                            {field === 'haircolor' ? 'Hair Color' : field === 'hairlength' ? 'Hair Length' : field === 'bustsize' ? 'Bust Size' : field === 'looks' ? 'Looks' : field.charAt(0).toUpperCase() + field.slice(1)}
                                                        </span>
                                                        <select
                                                            value={form[field] || ''}
                                                            onChange={(event) => setForm((current) => ({ ...current, [field]: event.target.value }))}
                                                            className="crm-input"
                                                        >
                                                            <option value="">Select</option>
                                                            {PROFILE_ENUM_OPTIONS[field]?.map((option) => (
                                                                <option key={option.value} value={option.value}>{option.label}</option>
                                                            ))}
                                                        </select>
                                                    </label>
                                                ))}
                                            </div>

                                            <div className="space-y-2">
                                                <span className="text-xs font-semibold uppercase tracking-[0.08em] text-slate-500">Services</span>
                                                <div className="flex flex-wrap gap-2">
                                                    {PROFILE_ENUM_OPTIONS.services.map((option) => {
                                                        const selected = selectedServiceCodes.includes(option.value);
                                                        return (
                                                            <button
                                                                key={option.value}
                                                                type="button"
                                                                onClick={() => setForm((current) => {
                                                                    const currentValues = parseProfileServices(current.services);
                                                                    const nextValues = currentValues.includes(option.value)
                                                                        ? currentValues.filter((value) => value !== option.value)
                                                                        : [...currentValues, option.value];

                                                                    return { ...current, services: nextValues };
                                                                })}
                                                                className={`rounded-full border px-3 py-1.5 text-sm transition ${selected ? 'border-teal-600 bg-teal-50 text-teal-700' : 'border-slate-300 bg-white text-slate-700 hover:border-teal-400'}`}
                                                            >
                                                                {option.label}
                                                            </button>
                                                        );
                                                    })}
                                                </div>
                                            </div>

                                            <div className="space-y-2">
                                                <span className="text-xs font-semibold uppercase tracking-[0.08em] text-slate-500">Availability</span>
                                                <div className="flex flex-wrap gap-2">
                                                    {PROFILE_ENUM_OPTIONS.availability.map((option) => {
                                                        const selected = (form.availability || []).includes(option.value);
                                                        return (
                                                            <button
                                                                key={option.value}
                                                                type="button"
                                                                onClick={() => setForm((current) => {
                                                                    const currentValues = Array.isArray(current.availability) ? [...current.availability] : [];
                                                                    return {
                                                                        ...current,
                                                                        availability: selected
                                                                            ? currentValues.filter((value) => value !== option.value)
                                                                            : [...currentValues, option.value],
                                                                    };
                                                                })}
                                                                className={`rounded-full border px-3 py-1.5 text-sm transition ${selected ? 'border-teal-600 bg-teal-50 text-teal-700' : 'border-slate-300 bg-white text-slate-700 hover:border-teal-400'}`}
                                                            >
                                                                {option.plainLabel}
                                                            </button>
                                                        );
                                                    })}
                                                </div>
                                            </div>

                                            <div className="grid gap-3 md:grid-cols-2">
                                                <CurrencySelect
                                                    platformId={form.platform_id ? Number(form.platform_id) : null}
                                                    value={form.currency}
                                                    onChange={(currency) => setForm((current) => ({ ...current, currency }))}
                                                    className="md:col-span-2"
                                                />
                                                <input value={form.incall} onChange={(event) => setForm((current) => ({ ...current, incall: event.target.value }))} className="crm-input" placeholder="Default incall rate" />
                                                <input value={form.outcall} onChange={(event) => setForm((current) => ({ ...current, outcall: event.target.value }))} className="crm-input" placeholder="Default outcall rate" />
                                                {RATE_DURATION_OPTIONS.map(([key, label]) => (
                                                    <React.Fragment key={key}>
                                                        <input value={form[`rate${key}_incall`]} onChange={(event) => setForm((current) => ({ ...current, [`rate${key}_incall`]: event.target.value }))} className="crm-input" placeholder={`${label} incall`} />
                                                        <input value={form[`rate${key}_outcall`]} onChange={(event) => setForm((current) => ({ ...current, [`rate${key}_outcall`]: event.target.value }))} className="crm-input" placeholder={`${label} outcall`} />
                                                    </React.Fragment>
                                                ))}
                                            </div>

                                            <div className="grid gap-3 md:grid-cols-2">
                                                {['whatsapp', 'instagram', 'twitter', 'telegram', 'website', 'facebook', 'snapchat', 'education', 'occupation', 'sports', 'hobbies', 'zodiacsign', 'sexualorientation', 'language1', 'language2', 'language3'].map((field) => (
                                                    <input
                                                        key={field}
                                                        value={form[field] || ''}
                                                        onChange={(event) => setForm((current) => ({ ...current, [field]: event.target.value }))}
                                                        className="crm-input"
                                                        placeholder={field.replace(/[0-9]/g, ' $&').replace(/_/g, ' ')}
                                                    />
                                                ))}
                                                {[1, 2, 3].map((index) => (
                                                    <label key={`language${index}level`} className="space-y-1">
                                                        <span className="text-xs font-semibold uppercase tracking-[0.08em] text-slate-500">Language {index} level</span>
                                                        <select
                                                            value={form[`language${index}level`] || ''}
                                                            onChange={(event) => setForm((current) => ({ ...current, [`language${index}level`]: event.target.value }))}
                                                            className="crm-input"
                                                        >
                                                            <option value="">Select</option>
                                                            {PROFILE_ENUM_OPTIONS.languagelevel.map((option) => (
                                                                <option key={option.value} value={option.value}>{option.label}</option>
                                                            ))}
                                                        </select>
                                                    </label>
                                                ))}
                                                <textarea value={form.extraservices} onChange={(event) => setForm((current) => ({ ...current, extraservices: event.target.value }))} className="crm-input md:col-span-2" rows={2} placeholder="Additional services" />
                                            </div>
                                        </div>
                                    ) : null}
                                </div>
                            </>
                        ) : null}
                    </div>

                    <div className="border-t border-slate-100 px-4 py-3">
                        {requiresProvisionContact ? (
                            <p className="text-xs font-medium text-amber-700">
                                Add at least one contact channel to continue with WordPress provisioning.
                            </p>
                        ) : requiresProvisionLocation ? (
                            <p className="text-xs font-medium text-amber-700">
                                Choose both a region and a city to finish WordPress provisioning.
                            </p>
                        ) : (
                            <p className="text-xs text-slate-500">
                                WordPress provisioning creates a real user/profile now. Quick provision keeps this short; Full profile mode captures rates, currency, media, and socials in one pass.
                            </p>
                        )}
                        {duplicateMatches.length > 0 ? (
                            <p className="mt-2 text-xs text-slate-500">
                                {duplicateMatches.length} existing client record{duplicateMatches.length === 1 ? '' : 's'} use this phone number.
                            </p>
                        ) : null}
                    </div>
                </div>

                <footer className="flex shrink-0 items-center justify-end gap-2 border-t border-slate-100 p-4">
                    <button type="button" className="crm-btn-secondary" onClick={onClose}>
                        Cancel
                    </button>
                    <button
                        type="button"
                        disabled={!canSubmit || createMutation.isPending}
                        onClick={() => {
                            const basePayload = {
                                platform_id: Number(form.platform_id),
                                name: form.name.trim(),
                                phone_normalized: normalizePhone(form.phone_normalized.trim(), phonePrefix),
                                email: form.email.trim() || null,
                                profile_status: form.profile_status,
                                assigned_to: form.assigned_to ? Number(form.assigned_to) : null,
                                onboarding_mode: form.onboarding_mode,
                                signup_source: signupSource || undefined,
                                provision_request_id: isWpProvision ? form.provision_request_id : undefined,
                                wp_username: isWpProvision ? (form.wp_username.trim() || null) : null,
                                wp_password: isWpProvision ? (form.wp_password.trim() || null) : null,
                                birthday: isWpProvision ? (form.birthday || null) : null,
                                height: isWpProvision ? (form.height.trim() || null) : null,
                                weight: isWpProvision ? (form.weight.trim() || null) : null,
                                bio: isWpProvision ? (form.bio.trim() || null) : null,
                                region_id: isWpProvision && form.region_id ? Number(form.region_id) : undefined,
                                city_id: isWpProvision && form.city_id ? Number(form.city_id) : undefined,
                                profile_images: isWpProvision ? [...form.profile_images] : [],
                                reason,
                            };

                            const fullProfilePayload = form.full_profile ? {
                                gender: form.gender || null,
                                ethnicity: form.ethnicity || null,
                                build: form.build || null,
                                haircolor: form.haircolor || null,
                                hairlength: form.hairlength || null,
                                bustsize: form.bustsize || null,
                                looks: form.looks || null,
                                smoker: form.smoker || null,
                                availability: form.availability?.length ? form.availability : null,
                                services: form.services?.length ? form.services : null,
                                extraservices: form.extraservices.trim() || null,
                                incall: form.incall.trim() || null,
                                outcall: form.outcall.trim() || null,
                                currency: form.currency ? Number(form.currency) : null,
                                rate30min_incall: form.rate30min_incall.trim() || null,
                                rate30min_outcall: form.rate30min_outcall.trim() || null,
                                rate1h_incall: form.rate1h_incall.trim() || null,
                                rate1h_outcall: form.rate1h_outcall.trim() || null,
                                rate2h_incall: form.rate2h_incall.trim() || null,
                                rate2h_outcall: form.rate2h_outcall.trim() || null,
                                rate3h_incall: form.rate3h_incall.trim() || null,
                                rate3h_outcall: form.rate3h_outcall.trim() || null,
                                rate6h_incall: form.rate6h_incall.trim() || null,
                                rate6h_outcall: form.rate6h_outcall.trim() || null,
                                rate12h_incall: form.rate12h_incall.trim() || null,
                                rate12h_outcall: form.rate12h_outcall.trim() || null,
                                rate24h_incall: form.rate24h_incall.trim() || null,
                                rate24h_outcall: form.rate24h_outcall.trim() || null,
                                whatsapp: form.whatsapp.trim() || null,
                                instagram: form.instagram.trim() || null,
                                twitter: form.twitter.trim() || null,
                                telegram: form.telegram.trim() || null,
                                website: form.website.trim() || null,
                                facebook: form.facebook.trim() || null,
                                snapchat: form.snapchat.trim() || null,
                                education: form.education.trim() || null,
                                occupation: form.occupation.trim() || null,
                                sports: form.sports.trim() || null,
                                hobbies: form.hobbies.trim() || null,
                                zodiacsign: form.zodiacsign.trim() || null,
                                sexualorientation: form.sexualorientation.trim() || null,
                                language1: form.language1.trim() || null,
                                language1level: form.language1level || null,
                                language2: form.language2.trim() || null,
                                language2level: form.language2level || null,
                                language3: form.language3.trim() || null,
                                language3level: form.language3level || null,
                            } : {};

                            createMutation.mutate({
                                ...basePayload,
                                ...fullProfilePayload,
                            });
                        }}
                        className="crm-btn-primary disabled:cursor-not-allowed disabled:opacity-50"
                    >
                        {resolvedSubmitLabel}
                    </button>
                </footer>
            </div>
        </div>
    );
}
