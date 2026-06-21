import React, { useEffect, useId, useMemo, useRef, useState } from 'react';
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
const RATE_FIELD_KEYS = [
    'incall',
    'outcall',
    ...RATE_DURATION_OPTIONS.flatMap(([key]) => [`rate${key}_incall`, `rate${key}_outcall`]),
];

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
        location_allows_region_only: false,
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
        rate30min_incall: '',
        rate30min_outcall: '',
        rate1h_incall: '',
        rate1h_outcall: '',
        rate2h_incall: '',
        rate2h_outcall: '',
        rate3h_incall: '',
        rate3h_outcall: '',
        rate6h_incall: '',
        rate6h_outcall: '',
        rate12h_incall: '',
        rate12h_outcall: '',
        rate24h_incall: '',
        rate24h_outcall: '',
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

function humanizeFieldName(field) {
    return String(field || '')
        .replace(/([a-z])([A-Z])/g, '$1 $2')
        .replace(/[0-9]/g, ' $&')
        .replace(/_/g, ' ')
        .replace(/\s+/g, ' ')
        .trim()
        .replace(/\b\w/g, (char) => char.toUpperCase());
}

function isAdultBirthday(value) {
    const raw = String(value || '').trim();
    if (!raw) {
        return true;
    }

    const birthday = new Date(raw);
    if (Number.isNaN(birthday.getTime())) {
        return false;
    }

    const adultDate = new Date(birthday);
    adultDate.setFullYear(adultDate.getFullYear() + 18);
    return adultDate <= new Date();
}

function hasAnyRate(form) {
    return RATE_FIELD_KEYS.some((field) => String(form[field] || '').trim() !== '');
}

function resolveInitialPlatformId(lockedPlatformId, initialPlatformId) {
    return String(lockedPlatformId || initialPlatformId || '');
}

function buildFullProfilePayload(form) {
    if (!form.full_profile) {
        return {};
    }

    return {
        birthday: form.birthday || null,
        height: form.height.trim() || null,
        weight: form.weight.trim() || null,
        bio: form.bio.trim() || null,
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
    };
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
    const dialogRef = useRef(null);
    const clientNameRef = useRef(null);
    const titleId = useId();
    const [form, setForm] = useState(() => defaultForm(resolveInitialPlatformId(lockedPlatformId, initialPlatformId), initialMode));
    const [imagePreviews, setImagePreviews] = useState([]);
    const [duplicateMatches, setDuplicateMatches] = useState([]);

    useEffect(() => {
        if (!open) {
            return;
        }

        setForm(defaultForm(resolveInitialPlatformId(lockedPlatformId, initialPlatformId), initialMode));
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

    useEffect(() => {
        if (!open) {
            return undefined;
        }

        const dialogNode = dialogRef.current;
        if (!dialogNode) {
            return undefined;
        }

        const focusFirstField = () => clientNameRef.current?.focus();
        const handleKeyDown = (event) => {
            if (event.key === 'Escape') {
                event.preventDefault();
                if (!createMutation.isPending) {
                    onClose?.();
                }
                return;
            }

            if (event.key !== 'Tab') {
                return;
            }

            const focusable = Array.from(dialogNode.querySelectorAll(
                'button:not([disabled]), input:not([disabled]), select:not([disabled]), textarea:not([disabled]), [tabindex]:not([tabindex="-1"])',
            )).filter((element) => element.tabIndex !== -1 && element.offsetParent !== null);

            if (focusable.length === 0) {
                return;
            }

            const first = focusable[0];
            const last = focusable[focusable.length - 1];

            if (event.shiftKey && document.activeElement === first) {
                event.preventDefault();
                last.focus();
            } else if (!event.shiftKey && document.activeElement === last) {
                event.preventDefault();
                first.focus();
            }
        };

        const timer = window.setTimeout(focusFirstField, 0);
        dialogNode.addEventListener('keydown', handleKeyDown);

        return () => {
            window.clearTimeout(timer);
            dialogNode.removeEventListener('keydown', handleKeyDown);
        };
    }, [open, onClose]);

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
    const requiresProvisionLocation = isWpProvision && (
        !form.region_id || (!form.city_id && !form.location_allows_region_only)
    );
    const birthdayIsValid = !form.full_profile || isAdultBirthday(form.birthday);
    const ratesNeedCurrency = form.full_profile && hasAnyRate(form) && !form.currency;
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
            onCreated?.(createdClient, {
                duplicateMatches: matches,
                onboardingMode: variables?.onboarding_mode || null,
                imagesQueued: images.length,
            });
            setForm(defaultForm(resolveInitialPlatformId(lockedPlatformId, initialPlatformId), initialMode));
        },
        onError: (error) => {
            toast.error(error?.response?.data?.message || 'Client creation failed. Please review the form.');
        },
    });
    const canSubmit = Boolean(form.platform_id)
        && form.name.trim().length >= 2
        && !requiresProvisionContact
        && !requiresProvisionLocation
        && birthdayIsValid
        && !ratesNeedCurrency
        && !createMutation.isPending;
    const selectedServiceCodes = useMemo(() => parseProfileServices(form.services), [form.services]);

    const locationRequirementMessage = !form.region_id
        ? 'Choose a region before provisioning this WordPress profile.'
        : form.location_allows_region_only
            ? 'This region does not require a child city.'
            : 'Choose a city within the selected region before provisioning this WordPress profile.';

    const bodySubtitle = subtitle || (isWpProvision
        ? 'Provision a real WordPress profile and link it to CRM in one flow.'
        : 'Create a CRM client record for outreach and deal tracking.');
    const resolvedSubmitLabel = submitLabel || (createMutation.isPending
        ? (isWpProvision ? 'Provisioning...' : 'Creating...')
        : isWpProvision
            ? 'Provision and create client'
            : 'Create client');

    const fieldSourceBanner = useMemo(() => {
        if (signupSource !== 'field') {
            return null;
        }

        return (
            <div className="rounded-md border border-teal-200 bg-teal-50 px-3 py-2 text-xs text-teal-800 md:col-span-2">
                Field account creation is optimized for speed. Duplicate phone numbers are allowed.
            </div>
        );
    }, [signupSource]);

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

    const handleMarketChange = (event) => {
        const nextPlatformId = event.target.value;
        if (lockedPlatformId) {
            return;
        }

        setForm((current) => ({
            ...current,
            platform_id: nextPlatformId,
            assigned_to: '',
            region_id: null,
            city_id: null,
            location_allows_region_only: false,
            currency: null,
        }));

        window.setTimeout(() => {
            event.target.blur();
            clientNameRef.current?.focus();
        }, 0);
    };

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

    const toggleMultiValue = (field, value) => {
        setForm((current) => {
            const currentValues = field === 'services'
                ? parseProfileServices(current[field], field)
                : Array.isArray(current[field]) ? [...current[field]] : [];

            return {
                ...current,
                [field]: currentValues.includes(value)
                    ? currentValues.filter((currentValue) => currentValue !== value)
                    : [...currentValues, value],
            };
        });
    };

    const applyDefaultRates = (direction) => {
        const sourceValue = String(form[direction] || '').trim();
        if (!sourceValue) {
            toast.warning(`Add a default ${direction} rate first.`);
            return;
        }

        setForm((current) => {
            const next = { ...current };
            RATE_DURATION_OPTIONS.forEach(([key]) => {
                next[`rate${key}_${direction}`] = sourceValue;
            });
            return next;
        });
    };

    const validateBeforeSubmit = () => {
        if (!form.platform_id) {
            return 'Choose a market before creating this client.';
        }

        if (form.name.trim().length < 2) {
            return 'Add a client name before creating this client.';
        }

        if (requiresProvisionContact) {
            return 'Add at least one contact channel to continue with WordPress provisioning.';
        }

        if (requiresProvisionLocation) {
            return locationRequirementMessage;
        }

        if (!birthdayIsValid) {
            return 'Birthday must belong to an adult profile owner.';
        }

        if (ratesNeedCurrency) {
            return 'Choose a currency before saving rates.';
        }

        return null;
    };

    const handleSubmit = () => {
        const validationError = validateBeforeSubmit();
        if (validationError) {
            toast.warning(validationError);
            return;
        }

        createMutation.mutate({
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
            region_id: isWpProvision && form.region_id ? Number(form.region_id) : undefined,
            city_id: isWpProvision && form.city_id ? Number(form.city_id) : undefined,
            profile_images: isWpProvision && form.full_profile ? [...form.profile_images] : [],
            ...buildFullProfilePayload(form),
            reason,
        });
    };

    const renderEnumSelect = (field) => (
        <label key={field} className="space-y-1">
            <span className="text-xs font-semibold uppercase tracking-[0.08em] text-slate-500">{humanizeFieldName(field)}</span>
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
    );

    if (!open) {
        return null;
    }

    return (
        <div className="fixed inset-0 z-50 flex items-center justify-center bg-slate-900/45 p-4" onClick={() => !createMutation.isPending && onClose?.()}>
            <div
                ref={dialogRef}
                role="dialog"
                aria-modal="true"
                aria-labelledby={titleId}
                className="flex max-h-[90vh] w-full max-w-4xl flex-col overflow-hidden rounded-lg border border-slate-200 bg-white shadow-xl"
                onClick={(event) => event.stopPropagation()}
            >
                <header className="crm-panel-header shrink-0">
                    <div>
                        <h3 id={titleId} className="crm-panel-title">{title}</h3>
                        <p className="crm-panel-subtitle">{bodySubtitle}</p>
                    </div>
                </header>

                <div className="min-h-0 flex-1 overflow-y-auto">
                    <div className="grid gap-4 p-4 md:grid-cols-2">
                        {fieldSourceBanner}

                        <div className="md:col-span-2">
                            <label htmlFor="client-create-market" className="mb-1 block text-sm font-medium text-slate-700">Market</label>
                            <select
                                id="client-create-market"
                                value={form.platform_id}
                                onChange={handleMarketChange}
                                className="crm-select w-full"
                                disabled={Boolean(lockedPlatformId) || integrationsQuery.isLoading}
                            >
                                <option value="">{integrationsQuery.isLoading ? 'Loading markets...' : 'Select market'}</option>
                                {platformOptions.map((platform) => (
                                    <option key={platform.platform_id} value={platform.platform_id}>
                                        {platform.platform_name}
                                    </option>
                                ))}
                            </select>
                            {integrationsQuery.isError ? (
                                <p className="mt-1 text-xs font-medium text-rose-700">Markets could not load. Retry in a moment.</p>
                            ) : (
                                <p className="mt-1 text-xs text-slate-500">Pick the WordPress market first. The form moves to client name after selection.</p>
                            )}
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
                                            full_profile: false,
                                            region_id: null,
                                            city_id: null,
                                            location_allows_region_only: false,
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
                                ref={clientNameRef}
                                type="text"
                                value={form.name}
                                autoComplete="off"
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
                                autoComplete="tel"
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
                                autoComplete="email"
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
                            <div className="md:col-span-2 rounded-lg border border-slate-200 bg-white p-4">
                                <div className="mb-3 flex flex-wrap items-start justify-between gap-3">
                                    <div>
                                        <p className="text-sm font-semibold text-slate-900">Profile location</p>
                                        <p className="mt-1 text-xs text-slate-500">Choose the region first, then city when that region has child cities.</p>
                                    </div>
                                    <span className={`rounded-full px-2.5 py-1 text-[11px] font-semibold uppercase tracking-[0.12em] ${requiresProvisionLocation ? 'bg-amber-100 text-amber-700' : 'bg-emerald-100 text-emerald-700'}`}>
                                        {requiresProvisionLocation ? 'Required' : 'Ready'}
                                    </span>
                                </div>
                                <RegionCitySelect
                                    platformId={form.platform_id ? Number(form.platform_id) : null}
                                    regionId={form.region_id}
                                    cityId={form.city_id}
                                    onChange={({ region_id, city_id, location_allows_region_only = false }) => setForm((current) => ({
                                        ...current,
                                        region_id,
                                        city_id,
                                        location_allows_region_only,
                                    }))}
                                />
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
                            <>
                                <div>
                                    <label htmlFor="client-create-wp-username" className="mb-1 block text-sm font-medium text-slate-700">WP username</label>
                                    <input
                                        id="client-create-wp-username"
                                        type="text"
                                        value={form.wp_username}
                                        autoComplete="off"
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
                                        autoComplete="new-password"
                                        onChange={(event) => setForm((current) => ({ ...current, wp_password: event.target.value }))}
                                        className="crm-input"
                                        placeholder="Auto-generated if blank"
                                    />
                                </div>

                                <div className="md:col-span-2 rounded-lg border border-slate-200 bg-slate-50/70 p-4">
                                    <div className="flex flex-col gap-3 md:flex-row md:items-center md:justify-between">
                                        <div>
                                            <p className="text-sm font-semibold text-slate-900">Optional profile details</p>
                                            <p className="mt-1 text-xs text-slate-500">Keep this off for fast client creation. Turn it on to add bio, photos, services, rates, socials, and lifestyle now.</p>
                                        </div>
                                        <label className="inline-flex min-h-[44px] items-center gap-3 rounded-lg border border-slate-200 bg-white px-3 py-2 text-sm font-medium text-slate-700 shadow-sm">
                                            <input
                                                type="checkbox"
                                                checked={Boolean(form.full_profile)}
                                                onChange={(event) => setForm((current) => ({ ...current, full_profile: event.target.checked }))}
                                                className="h-4 w-4 rounded border-slate-300 text-teal-600 focus:ring-teal-200"
                                            />
                                            Capture full profile now
                                        </label>
                                    </div>
                                </div>
                            </>
                        ) : null}

                        {isWpProvision && form.full_profile ? (
                            <>
                                <section className="border-t border-slate-100 pt-4 md:col-span-2">
                                    <div className="mb-3 flex items-center justify-between gap-3">
                                        <div>
                                            <h4 className="text-sm font-semibold text-slate-900">Profile basics</h4>
                                            <p className="mt-1 text-xs text-slate-500">Public profile copy, images, and core appearance details.</p>
                                        </div>
                                        <span className="rounded-full bg-slate-100 px-2.5 py-1 text-[11px] font-semibold uppercase tracking-[0.12em] text-slate-500">Optional</span>
                                    </div>

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
                                            {!birthdayIsValid ? (
                                                <p className="mt-1 text-xs font-medium text-rose-700">Birthday must be 18+.</p>
                                            ) : null}
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
                                            rows={4}
                                            maxLength={5000}
                                            placeholder="Short public profile introduction"
                                        />
                                        <div className="mt-2">
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

                                    <div className="mt-4 grid gap-3 md:grid-cols-2 lg:grid-cols-4">
                                        {['gender', 'ethnicity', 'build', 'haircolor', 'hairlength', 'bustsize', 'looks', 'smoker'].map(renderEnumSelect)}
                                    </div>
                                </section>

                                <section className="border-t border-slate-100 pt-4 md:col-span-2">
                                    <div className="mb-3">
                                        <h4 className="text-sm font-semibold text-slate-900">Services and rates</h4>
                                        <p className="mt-1 text-xs text-slate-500">Use approved service codes and choose a currency when entering rates.</p>
                                    </div>

                                    <div className="space-y-2">
                                        <span className="text-xs font-semibold uppercase tracking-[0.08em] text-slate-500">Services</span>
                                        <div className="flex flex-wrap gap-2 rounded-lg border border-slate-200 bg-white p-3">
                                            {PROFILE_ENUM_OPTIONS.services.map((option) => {
                                                const selected = selectedServiceCodes.includes(option.value);
                                                return (
                                                    <button
                                                        key={option.value}
                                                        type="button"
                                                        onClick={() => toggleMultiValue('services', option.value)}
                                                        className={`rounded-full border px-3 py-1.5 text-sm transition ${selected ? 'border-teal-600 bg-teal-50 text-teal-700' : 'border-slate-300 bg-white text-slate-700 hover:border-teal-400'}`}
                                                    >
                                                        {option.label}
                                                    </button>
                                                );
                                            })}
                                        </div>
                                    </div>

                                    <div className="mt-4 space-y-2">
                                        <span className="text-xs font-semibold uppercase tracking-[0.08em] text-slate-500">Availability</span>
                                        <div className="flex flex-wrap gap-2">
                                            {PROFILE_ENUM_OPTIONS.availability.map((option) => {
                                                const selected = (form.availability || []).includes(option.value);
                                                return (
                                                    <button
                                                        key={option.value}
                                                        type="button"
                                                        onClick={() => toggleMultiValue('availability', option.value)}
                                                        className={`rounded-full border px-3 py-1.5 text-sm transition ${selected ? 'border-teal-600 bg-teal-50 text-teal-700' : 'border-slate-300 bg-white text-slate-700 hover:border-teal-400'}`}
                                                    >
                                                        {option.plainLabel}
                                                    </button>
                                                );
                                            })}
                                        </div>
                                    </div>

                                    <div className="mt-4 grid gap-4 md:grid-cols-3">
                                        <CurrencySelect
                                            platformId={form.platform_id ? Number(form.platform_id) : null}
                                            value={form.currency}
                                            onChange={(currency) => setForm((current) => ({ ...current, currency }))}
                                            className="md:col-span-3"
                                        />
                                        {ratesNeedCurrency ? (
                                            <p className="-mt-2 text-xs font-medium text-rose-700 md:col-span-3">Choose a currency before saving rates.</p>
                                        ) : null}
                                        <div>
                                            <div className="mb-1 flex items-center justify-between gap-2">
                                                <label htmlFor="client-create-incall" className="block text-sm font-medium text-slate-700">Default incall</label>
                                                <button type="button" onClick={() => applyDefaultRates('incall')} className="text-xs font-semibold text-teal-700 hover:text-teal-800">Copy</button>
                                            </div>
                                            <input id="client-create-incall" value={form.incall} onChange={(event) => setForm((current) => ({ ...current, incall: event.target.value }))} className="crm-input" placeholder="e.g. 1500" />
                                        </div>
                                        <div>
                                            <div className="mb-1 flex items-center justify-between gap-2">
                                                <label htmlFor="client-create-outcall" className="block text-sm font-medium text-slate-700">Default outcall</label>
                                                <button type="button" onClick={() => applyDefaultRates('outcall')} className="text-xs font-semibold text-teal-700 hover:text-teal-800">Copy</button>
                                            </div>
                                            <input id="client-create-outcall" value={form.outcall} onChange={(event) => setForm((current) => ({ ...current, outcall: event.target.value }))} className="crm-input" placeholder="e.g. 2000" />
                                        </div>
                                        <div>
                                            <label htmlFor="client-create-extra-services" className="mb-1 block text-sm font-medium text-slate-700">Additional services</label>
                                            <input
                                                id="client-create-extra-services"
                                                value={form.extraservices}
                                                onChange={(event) => setForm((current) => ({ ...current, extraservices: event.target.value }))}
                                                className="crm-input"
                                                placeholder="Optional notes"
                                            />
                                        </div>
                                    </div>

                                    <div className="mt-4 overflow-hidden rounded-lg border border-slate-200 bg-white">
                                        <div className="border-b border-slate-100 px-4 py-3">
                                            <p className="text-xs font-semibold uppercase tracking-[0.08em] text-slate-500">Rates by duration</p>
                                            <p className="mt-1 text-xs text-slate-500">Fill only the durations you need.</p>
                                        </div>
                                        <div className="overflow-auto">
                                            <table className="w-full text-sm">
                                                <thead>
                                                    <tr className="bg-slate-50 text-slate-600">
                                                        <th className="px-4 py-2 text-left font-semibold">Duration</th>
                                                        <th className="px-4 py-2 text-left font-semibold">Incall</th>
                                                        <th className="px-4 py-2 text-left font-semibold">Outcall</th>
                                                    </tr>
                                                </thead>
                                                <tbody>
                                                    {RATE_DURATION_OPTIONS.map(([key, label]) => (
                                                        <tr key={key} className="border-t border-slate-100">
                                                            <td className="px-4 py-3 text-slate-700">{label}</td>
                                                            <td className="px-4 py-2">
                                                                <input value={form[`rate${key}_incall`]} onChange={(event) => setForm((current) => ({ ...current, [`rate${key}_incall`]: event.target.value }))} className="crm-input" placeholder="-" />
                                                            </td>
                                                            <td className="px-4 py-2">
                                                                <input value={form[`rate${key}_outcall`]} onChange={(event) => setForm((current) => ({ ...current, [`rate${key}_outcall`]: event.target.value }))} className="crm-input" placeholder="-" />
                                                            </td>
                                                        </tr>
                                                    ))}
                                                </tbody>
                                            </table>
                                        </div>
                                    </div>
                                </section>

                                <section className="border-t border-slate-100 pt-4 md:col-span-2">
                                    <div className="mb-3">
                                        <h4 className="text-sm font-semibold text-slate-900">Socials and lifestyle</h4>
                                        <p className="mt-1 text-xs text-slate-500">Optional public channels and profile details.</p>
                                    </div>
                                    <div className="grid gap-3 md:grid-cols-2">
                                        {['whatsapp', 'instagram', 'twitter', 'telegram', 'website', 'facebook', 'snapchat', 'education', 'occupation', 'sports', 'hobbies', 'zodiacsign', 'sexualorientation', 'language1', 'language2', 'language3'].map((field) => (
                                            <input
                                                key={field}
                                                value={form[field] || ''}
                                                onChange={(event) => setForm((current) => ({ ...current, [field]: event.target.value }))}
                                                className="crm-input"
                                                placeholder={humanizeFieldName(field)}
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
                                    </div>
                                </section>
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
                                {locationRequirementMessage}
                            </p>
                        ) : ratesNeedCurrency ? (
                            <p className="text-xs font-medium text-amber-700">
                                Choose a currency before saving rates.
                            </p>
                        ) : createMutation.isPending ? (
                            <p className="text-xs font-medium text-teal-700">
                                Creating the CRM client and WordPress profile now.
                            </p>
                        ) : (
                            <p className="text-xs text-slate-500">
                                This is a single form. Use the optional profile toggle only when the full profile is ready now.
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
                    <button type="button" className="crm-btn-secondary" onClick={() => !createMutation.isPending && onClose?.()}>
                        Cancel
                    </button>
                    <button
                        type="button"
                        disabled={!canSubmit}
                        onClick={handleSubmit}
                        className="crm-btn-primary disabled:cursor-not-allowed disabled:opacity-50"
                    >
                        {resolvedSubmitLabel}
                    </button>
                </footer>
            </div>
        </div>
    );
}
