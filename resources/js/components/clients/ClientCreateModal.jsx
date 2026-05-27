import React, { useEffect, useMemo, useState } from 'react';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import api from '../../services/api';
import { normalizePhone } from '../../utils/phone';
import GenerateBioButton from '../seo/GenerateBioButton';
import { useToast } from '../ToastProvider';

const PROFILE_IMAGE_LIMIT = 6;
const PROFILE_IMAGE_MAX_BYTES = 5 * 1024 * 1024;
const PROFILE_IMAGE_TYPES = new Set(['image/jpeg', 'image/png', 'image/webp']);
const PROFILE_IMAGE_EXTENSIONS = new Set(['jpg', 'jpeg', 'png', 'webp']);

function defaultForm(platformId = '', onboardingMode = 'wp_provision') {
    return {
        platform_id: platformId,
        name: '',
        phone_normalized: '',
        email: '',
        city: '',
        profile_status: 'private',
        assigned_to: '',
        onboarding_mode: onboardingMode,
        wp_username: '',
        wp_password: '',
        birthday: '',
        height_cm: '',
        weight_kg: '',
        bio: '',
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

    const citiesQuery = useQuery({
        queryKey: ['client-cities', form.platform_id],
        queryFn: () => api.get('/crm/clients/cities', {
            params: form.platform_id ? { platform_id: Number(form.platform_id) } : {},
        }).then((response) => response.data),
        enabled: open && Boolean(form.platform_id),
    });

    const owners = ownersQuery.data?.owners || [];
    const cities = citiesQuery.data?.cities || [];
    const isWpProvision = form.onboarding_mode === 'wp_provision';
    const requiresProvisionContact = isWpProvision && !form.email.trim() && !form.phone_normalized.trim();
    const canSubmit = Boolean(form.platform_id)
        && form.name.trim().length >= 2
        && !requiresProvisionContact;

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
                                            height_cm: '',
                                            weight_kg: '',
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
                            <label htmlFor="client-create-city" className="mb-1 block text-sm font-medium text-slate-700">City</label>
                            <select
                                id="client-create-city"
                                value={form.city}
                                onChange={(event) => setForm((current) => ({ ...current, city: event.target.value }))}
                                className="crm-input"
                            >
                                <option value="">Select city</option>
                                {cities.map((city) => (
                                    <option key={city} value={city}>{city}</option>
                                ))}
                            </select>
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

                                <div className="border-t border-slate-100 pt-3 md:col-span-2">
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
                                                value={form.height_cm}
                                                onChange={(event) => setForm((current) => ({ ...current, height_cm: event.target.value }))}
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
                                                value={form.weight_kg}
                                                onChange={(event) => setForm((current) => ({ ...current, weight_kg: event.target.value }))}
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
                                </div>
                            </>
                        ) : null}
                    </div>

                    <div className="border-t border-slate-100 px-4 py-3">
                        {requiresProvisionContact ? (
                            <p className="text-xs font-medium text-amber-700">
                                Add at least one contact channel to continue with WordPress provisioning.
                            </p>
                        ) : (
                            <p className="text-xs text-slate-500">
                                WordPress provisioning creates a real user/profile now. Credentials can be handled from the next step.
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
                            createMutation.mutate({
                                platform_id: Number(form.platform_id),
                                name: form.name.trim(),
                                phone_normalized: normalizePhone(form.phone_normalized.trim(), phonePrefix),
                                email: form.email.trim() || null,
                                city: form.city.trim() || null,
                                profile_status: form.profile_status,
                                assigned_to: form.assigned_to ? Number(form.assigned_to) : null,
                                onboarding_mode: form.onboarding_mode,
                                signup_source: signupSource || undefined,
                                wp_username: isWpProvision ? (form.wp_username.trim() || null) : null,
                                wp_password: isWpProvision ? (form.wp_password.trim() || null) : null,
                                birthday: isWpProvision ? (form.birthday || null) : null,
                                height: isWpProvision ? (form.height_cm.trim() || null) : null,
                                weight: isWpProvision ? (form.weight_kg.trim() || null) : null,
                                bio: isWpProvision ? (form.bio.trim() || null) : null,
                                profile_images: isWpProvision ? [...form.profile_images] : [],
                                reason,
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
