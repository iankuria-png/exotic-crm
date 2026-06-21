import React, { useEffect, useId, useMemo, useRef, useState } from 'react';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import api from '../../services/api';
import { normalizePhone } from '../../utils/phone';
import GenerateBioButton from '../seo/GenerateBioButton';
import { useToast } from '../ToastProvider';
import Combobox from '../shared/Combobox';
import RegionCitySelect from './profile-fields/RegionCitySelect';
import CurrencySelect, { formatCurrencyBadge } from './profile-fields/CurrencySelect';
import {
    PROFILE_ENUM_OPTIONS,
    RATE_DURATION_OPTIONS,
    parseProfileServices,
} from './profile-fields/profileFieldCatalog';

const PROFILE_IMAGE_LIMIT = 6;
const PROFILE_IMAGE_MAX_BYTES = 5 * 1024 * 1024;
const PROFILE_IMAGE_TYPES = new Set(['image/jpeg', 'image/png', 'image/webp']);
const PROFILE_IMAGE_EXTENSIONS = new Set(['jpg', 'jpeg', 'png', 'webp']);
const DRAFT_STORAGE_PREFIX = 'client-create-draft-v4';
const STEP_SETUP = 'setup';
const STEP_PROFILE = 'profile';
const STEP_RATES = 'rates';
const STEP_SOCIALS = 'socials';
const STEP_REVIEW = 'review';
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

function buildDraftStorageKey(platformId, onboardingMode, signupSource) {
    return [
        DRAFT_STORAGE_PREFIX,
        platformId || 'shared',
        onboardingMode || 'wp_provision',
        signupSource || 'default',
    ].join(':');
}

function storageSafeForm(form) {
    return {
        ...form,
        profile_images: [],
    };
}

function parseStoredDraft(rawDraft) {
    if (!rawDraft) {
        return { form: null, savedAt: null };
    }

    const parsed = JSON.parse(rawDraft);
    if (!parsed || typeof parsed !== 'object') {
        return { form: null, savedAt: null };
    }

    if (parsed.form && typeof parsed.form === 'object') {
        return {
            form: parsed.form,
            savedAt: typeof parsed.saved_at === 'string' ? parsed.saved_at : null,
        };
    }

    return { form: parsed, savedAt: null };
}

function formatDraftSavedAt(value) {
    if (!value) {
        return '';
    }

    const date = new Date(value);
    if (Number.isNaN(date.getTime())) {
        return '';
    }

    return date.toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' });
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

function optionLabelFor(field, value) {
    const raw = String(value || '').trim();
    if (!raw) {
        return '';
    }

    const option = (PROFILE_ENUM_OPTIONS[field] || []).find((candidate) => String(candidate.value) === raw);
    return option?.plainLabel || option?.label || raw;
}

function summarizeCodeList(field, values) {
    return parseProfileServices(values, field)
        .map((code) => optionLabelFor(field, code) || String(code))
        .filter(Boolean);
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

    const today = new Date();
    const adultDate = new Date(birthday);
    adultDate.setFullYear(adultDate.getFullYear() + 18);
    return adultDate <= today;
}

function buildRatePreviewRows(form) {
    return RATE_DURATION_OPTIONS.map(([key, label]) => ({
        label,
        incall: String(form[`rate${key}_incall`] || '').trim(),
        outcall: String(form[`rate${key}_outcall`] || '').trim(),
    })).filter((row) => row.incall || row.outcall);
}

function compactSummary(values) {
    return values.filter(Boolean).join(' · ');
}

function resolveInitialPlatformId(lockedPlatformId, initialPlatformId) {
    return String(lockedPlatformId || initialPlatformId || '');
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
    const [basePlatformId, setBasePlatformId] = useState(() => resolveInitialPlatformId(lockedPlatformId, initialPlatformId));
    const [form, setForm] = useState(() => defaultForm(basePlatformId, initialMode));
    const [imagePreviews, setImagePreviews] = useState([]);
    const [duplicateMatches, setDuplicateMatches] = useState([]);
    const [wizardStep, setWizardStep] = useState(STEP_SETUP);
    const [stepErrors, setStepErrors] = useState([]);
    const [draftState, setDraftState] = useState({ status: 'idle', savedAt: null, restored: false });
    const dialogRef = useRef(null);
    const primaryFocusRef = useRef(null);
    const clientNameRef = useRef(null);
    const skipNextDraftWriteRef = useRef(false);
    const wasOpenRef = useRef(open);
    const titleId = useId();

    const createFreshForm = (modeOverride = initialMode) => defaultForm(basePlatformId, modeOverride);
    const draftStorageKey = useMemo(
        () => buildDraftStorageKey(basePlatformId, initialMode, signupSource),
        [basePlatformId, initialMode, signupSource],
    );

    useEffect(() => {
        const wasOpen = wasOpenRef.current;
        wasOpenRef.current = open;

        if (open && !wasOpen) {
            setBasePlatformId(resolveInitialPlatformId(lockedPlatformId, initialPlatformId));
        }
    }, [initialPlatformId, lockedPlatformId, open]);

    useEffect(() => {
        if (!open) {
            return;
        }

        const fallbackForm = createFreshForm();
        let nextForm = fallbackForm;

        try {
            const rawDraft = globalThis.localStorage?.getItem(draftStorageKey);
            if (rawDraft) {
                const parsed = parseStoredDraft(rawDraft);
                if (parsed.form) {
                    nextForm = {
                        ...fallbackForm,
                        ...parsed.form,
                        profile_images: [],
                    };
                    setDraftState({ status: 'restored', savedAt: parsed.savedAt, restored: true });
                } else {
                    setDraftState({ status: 'idle', savedAt: null, restored: false });
                }
            } else {
                setDraftState({ status: 'idle', savedAt: null, restored: false });
            }
        } catch {
            nextForm = fallbackForm;
            setDraftState({ status: 'idle', savedAt: null, restored: false });
        }

        if (lockedPlatformId) {
            nextForm.platform_id = String(lockedPlatformId);
        } else if (basePlatformId) {
            nextForm.platform_id = String(basePlatformId);
        }
        if (lockedOnboardingMode) {
            nextForm.onboarding_mode = lockedOnboardingMode;
        }

        skipNextDraftWriteRef.current = true;
        setForm(nextForm);
        setDuplicateMatches([]);
        setWizardStep(STEP_SETUP);
        setStepErrors([]);
    }, [open, draftStorageKey, lockedPlatformId, lockedOnboardingMode, basePlatformId, initialMode]);

    useEffect(() => {
        if (!open) {
            return;
        }

        if (skipNextDraftWriteRef.current) {
            skipNextDraftWriteRef.current = false;
            return;
        }

        try {
            const savedAt = new Date().toISOString();
            setDraftState((current) => ({ ...current, status: 'saving' }));
            globalThis.localStorage?.setItem(draftStorageKey, JSON.stringify({
                saved_at: savedAt,
                form: storageSafeForm(form),
            }));
            setDraftState({ status: 'saved', savedAt, restored: false });
        } catch {
            setDraftState((current) => ({ ...current, status: 'failed' }));
        }
    }, [draftStorageKey, form, open]);

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
    const marketGroups = useMemo(() => [
        {
            label: 'Accessible markets',
            options: platformOptions.map((platform) => ({
                value: String(platform.platform_id),
                label: platform.platform_name,
                inputLabel: platform.platform_name,
                secondaryLabel: platform.domain || platform.currency_code || '',
                searchText: `${platform.platform_name || ''} ${platform.name || ''} ${platform.domain || ''} ${platform.currency_code || ''}`,
            })),
        },
    ], [platformOptions]);
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
    const locationRequirementMessage = !form.region_id
        ? 'Choose a region before provisioning this WordPress profile.'
        : form.location_allows_region_only
            ? 'This region does not require a child city.'
            : 'Choose a city within the selected region before provisioning this WordPress profile.';
    const selectedServiceCodes = Array.isArray(form.services)
        ? form.services.map((value) => String(value || '').trim()).filter(Boolean)
        : [];
    const ratePreviewRows = useMemo(() => buildRatePreviewRows(form), [form]);
    const rateCurrencyLabel = form.currency ? `#${form.currency}` : (selectedPlatform?.currency_code || 'Market default');

    const wizardSteps = useMemo(() => {
        if (!isWpProvision || !form.full_profile) {
            return [{ key: STEP_SETUP, label: isWpProvision ? 'Quick provision' : 'Quick add', eyebrow: 'Single-step flow' }];
        }

        return [
            { key: STEP_SETUP, label: 'Setup', eyebrow: 'Market, contact, location' },
            { key: STEP_PROFILE, label: 'About', eyebrow: 'Appearance, bio, media' },
            { key: STEP_RATES, label: 'Rates', eyebrow: 'Services, currency, pricing' },
            { key: STEP_SOCIALS, label: 'Socials', eyebrow: 'Reach, lifestyle, languages' },
            { key: STEP_REVIEW, label: 'Review', eyebrow: 'Final check before provisioning' },
        ];
    }, [form.full_profile, isWpProvision]);

    useEffect(() => {
        if (!wizardSteps.some((step) => step.key === wizardStep)) {
            setWizardStep(wizardSteps[0]?.key || STEP_SETUP);
            setStepErrors([]);
        }
    }, [wizardStep, wizardSteps]);

    const currentStepIndex = wizardSteps.findIndex((step) => step.key === wizardStep);
    const currentStepMeta = wizardSteps[currentStepIndex] || wizardSteps[0];
    const isReviewStep = wizardStep === STEP_REVIEW;
    const progressPercent = wizardSteps.length > 1
        ? Math.round(((currentStepIndex + 1) / wizardSteps.length) * 100)
        : 100;

    const baseComparisonForm = useMemo(() => storageSafeForm(createFreshForm()), [basePlatformId, initialMode]);
    const isDraftDirty = useMemo(
        () => JSON.stringify(storageSafeForm(form)) !== JSON.stringify(baseComparisonForm),
        [baseComparisonForm, form],
    );

    useEffect(() => {
        if (!open || !isDraftDirty) {
            return undefined;
        }

        const handleBeforeUnload = (event) => {
            event.preventDefault();
            event.returnValue = '';
        };

        globalThis.addEventListener('beforeunload', handleBeforeUnload);
        return () => globalThis.removeEventListener('beforeunload', handleBeforeUnload);
    }, [isDraftDirty, open]);

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

    const resetDraft = () => {
        const nextForm = createFreshForm();
        skipNextDraftWriteRef.current = true;
        setForm(nextForm);
        setDuplicateMatches([]);
        setWizardStep(STEP_SETUP);
        setStepErrors([]);
        try {
            globalThis.localStorage?.removeItem(draftStorageKey);
        } catch {
            // Ignore storage cleanup failures.
        }
        setDraftState({ status: 'idle', savedAt: null, restored: false });
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

            try {
                globalThis.localStorage?.removeItem(draftStorageKey);
            } catch {
                // Ignore storage cleanup failures.
            }
            skipNextDraftWriteRef.current = true;
            setDraftState({ status: 'idle', savedAt: null, restored: false });

            toast.success(isWpProvision ? 'Client provisioned.' : 'Client created.');
            onCreated?.(createdClient, {
                duplicateMatches: matches,
                onboardingMode: variables?.onboarding_mode || null,
                imagesQueued: images.length,
            });
            setForm(createFreshForm());
            setWizardStep(STEP_SETUP);
            setStepErrors([]);
        },
        onError: (error) => {
            toast.error(error?.response?.data?.message || 'Client creation failed. Please review the form.');
        },
    });

    const handleRequestClose = () => {
        if (createMutation.isPending) {
            return;
        }

        if (isDraftDirty && !globalThis.confirm('Close this draft? Your text fields will stay saved in this browser, but unsaved image attachments will need to be re-added.')) {
            return;
        }

        onClose?.();
    };

    useEffect(() => {
        if (!open) {
            return undefined;
        }

        const dialogNode = dialogRef.current;
        if (!dialogNode) {
            return undefined;
        }

        const focusFirstField = () => {
            const fallbackTarget = dialogNode.querySelector(
                'button:not([disabled]), input:not([disabled]), select:not([disabled]), textarea:not([disabled]), [tabindex]:not([tabindex="-1"])',
            );
            (primaryFocusRef.current || fallbackTarget)?.focus();
        };

        const handleKeyDown = (event) => {
            if (event.key === 'Escape') {
                event.preventDefault();
                handleRequestClose();
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
    }, [open, handleRequestClose]);

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

    const validateSetupStep = () => {
        const errors = [];
        if (!form.platform_id) {
            errors.push('Choose a market before continuing.');
        }
        if (form.name.trim().length < 2) {
            errors.push('Add a client name before continuing.');
        }
        if (isWpProvision && requiresProvisionContact) {
            errors.push('Add at least one contact channel for WordPress provisioning.');
        }
        if (isWpProvision && requiresProvisionLocation) {
            errors.push(locationRequirementMessage);
        }
        return errors;
    };

    const validateProfileStep = () => {
        const errors = [];
        if (form.birthday && !isAdultBirthday(form.birthday)) {
            errors.push('Birthday must belong to an adult profile owner.');
        }
        return errors;
    };

    const validateRatesStep = () => {
        const errors = [];
        const hasAnyRate = RATE_FIELD_KEYS.some((field) => String(form[field] || '').trim() !== '');
        if (hasAnyRate && !form.currency) {
            errors.push('Choose a currency before saving rates.');
        }
        return errors;
    };

    const validateStep = (stepKey) => {
        switch (stepKey) {
            case STEP_SETUP:
                return validateSetupStep();
            case STEP_PROFILE:
                return validateProfileStep();
            case STEP_RATES:
                return validateRatesStep();
            default:
                return [];
        }
    };

    const goToStep = (nextStep) => {
        setWizardStep(nextStep);
        setStepErrors([]);
    };

    const handleMarketChange = (value) => {
        const nextPlatformId = value ? String(value) : '';
        setForm((current) => ({
            ...current,
            platform_id: nextPlatformId,
            assigned_to: '',
            region_id: null,
            city_id: null,
            location_allows_region_only: false,
            currency: null,
        }));

        if (nextPlatformId) {
            window.setTimeout(() => clientNameRef.current?.focus(), 0);
        }
    };

    const handleNext = () => {
        const errors = validateStep(wizardStep);
        if (errors.length > 0) {
            setStepErrors(errors);
            toast.warning(errors[0]);
            return;
        }

        const nextStep = wizardSteps[currentStepIndex + 1];
        if (nextStep) {
            goToStep(nextStep.key);
        }
    };

    const handleSubmit = () => {
        const errors = validateStep(wizardStep);
        if (errors.length > 0) {
            setStepErrors(errors);
            toast.warning(errors[0]);
            return;
        }

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
            birthday: isWpProvision && form.full_profile ? (form.birthday || null) : null,
            height: isWpProvision && form.full_profile ? (form.height.trim() || null) : null,
            weight: isWpProvision && form.full_profile ? (form.weight.trim() || null) : null,
            bio: isWpProvision && form.full_profile ? (form.bio.trim() || null) : null,
            region_id: isWpProvision && form.region_id ? Number(form.region_id) : undefined,
            city_id: isWpProvision && form.city_id ? Number(form.city_id) : undefined,
            profile_images: isWpProvision && form.full_profile ? [...form.profile_images] : [],
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

        setStepErrors([]);
        createMutation.mutate({
            ...basePayload,
            ...fullProfilePayload,
        });
    };

    const bodySubtitle = subtitle || (isWpProvision
        ? 'Create the WordPress profile and CRM record in one guided flow.'
        : 'Create a CRM client record for outreach and deal tracking.');
    const resolvedSubmitLabel = submitLabel || (createMutation.isPending
        ? (isWpProvision ? 'Provisioning WordPress profile…' : 'Creating client…')
        : isWpProvision
            ? (form.full_profile && isReviewStep ? 'Provision profile now' : 'Provision and create client')
            : 'Create client');
    const draftSavedTime = formatDraftSavedAt(draftState.savedAt);
    const draftStatusLabel = draftState.status === 'failed'
        ? 'Draft not saved'
        : draftState.status === 'saving'
            ? 'Saving draft...'
            : draftState.status === 'restored'
                ? (draftSavedTime ? `Draft restored from ${draftSavedTime}` : 'Draft restored')
                : draftSavedTime
                    ? `Draft saved at ${draftSavedTime}`
                    : 'Draft saves automatically';

    const fieldSourceBanner = useMemo(() => {
        if (signupSource !== 'field') {
            return null;
        }

        return (
            <div className="rounded-xl border border-teal-200 bg-teal-50 px-3 py-2 text-xs text-teal-800">
                Field account creation is optimized for speed. Duplicate phone numbers are allowed.
            </div>
        );
    }, [signupSource]);

    const reviewSections = useMemo(() => {
        const services = summarizeCodeList('services', form.services);
        const availability = summarizeCodeList('availability', form.availability);
        const languages = [1, 2, 3]
            .map((index) => {
                const language = form[`language${index}`]?.trim();
                const level = optionLabelFor('languagelevel', form[`language${index}level`]);
                return language ? compactSummary([language, level]) : '';
            })
            .filter(Boolean);

        return [
            {
                title: 'Quick setup',
                stepKey: STEP_SETUP,
                items: [
                    ['Market', selectedPlatform?.platform_name || 'Not selected'],
                    ['Mode', isWpProvision ? (form.full_profile ? 'WordPress provisioning · full profile' : 'WordPress provisioning · quick provision') : 'CRM only'],
                    ['Client', form.name.trim() || 'Not provided'],
                    ['Contact', compactSummary([form.phone_normalized.trim(), form.email.trim()]) || 'No contact captured yet'],
                    ['Location', compactSummary([
                        form.region_id ? 'Region selected' : '',
                        form.city_id
                            ? 'City selected'
                            : form.location_allows_region_only
                                ? 'No city required'
                                : '',
                    ]) || 'Not selected'],
                    ['Owner', owners.find((owner) => String(owner.id) === String(form.assigned_to))?.name || 'Auto-assign owner'],
                ],
            },
            {
                title: 'Profile',
                stepKey: STEP_PROFILE,
                items: [
                    ['Birthday', form.birthday || 'Not set'],
                    ['Appearance', compactSummary([
                        optionLabelFor('gender', form.gender),
                        optionLabelFor('ethnicity', form.ethnicity),
                        optionLabelFor('build', form.build),
                        optionLabelFor('looks', form.looks),
                    ]) || 'Kept blank for now'],
                    ['Bio', form.bio.trim() ? 'Ready' : 'Not added yet'],
                    ['Images', form.profile_images.length ? `${form.profile_images.length} ready to upload` : 'No new images attached'],
                ],
            },
            {
                title: 'Rates & services',
                stepKey: STEP_RATES,
                items: [
                    ['Services', services.length ? services.join(', ') : 'No services selected'],
                    ['Availability', availability.length ? availability.join(', ') : 'No availability selected'],
                    ['Currency', form.currency ? `Currency #${form.currency}` : 'Platform default / not set'],
                    ['Default rates', compactSummary([
                        form.incall ? `Incall ${form.incall}` : '',
                        form.outcall ? `Outcall ${form.outcall}` : '',
                    ]) || 'No default rates yet'],
                    ['Duration rows', ratePreviewRows.length ? `${ratePreviewRows.length} duration rows configured` : 'No duration rates yet'],
                ],
            },
            {
                title: 'Reach & lifestyle',
                stepKey: STEP_SOCIALS,
                items: [
                    ['Channels', compactSummary([
                        form.whatsapp.trim() ? 'WhatsApp' : '',
                        form.instagram.trim() ? 'Instagram' : '',
                        form.telegram.trim() ? 'Telegram' : '',
                        form.website.trim() ? 'Website' : '',
                    ]) || 'No extra channels yet'],
                    ['Lifestyle', compactSummary([
                        form.education.trim(),
                        form.occupation.trim(),
                        form.hobbies.trim(),
                    ]) || 'Not added yet'],
                    ['Languages', languages.length ? languages.join(', ') : 'No languages added'],
                ],
            },
        ];
    }, [form, isWpProvision, owners, ratePreviewRows.length, selectedPlatform?.platform_name]);

    const renderSetupStep = () => (
        <div className="space-y-6">
            <div className="grid gap-4 md:grid-cols-2">
                <div className="md:col-span-2">
                    <Combobox
                        label="Market"
                        value={form.platform_id}
                        inputRef={primaryFocusRef}
                        onChange={handleMarketChange}
                        groups={marketGroups}
                        placeholder="Choose market"
                        searchPlaceholder="Search markets"
                        emptyMessage={integrationsQuery.isError ? 'Could not load markets. Retry in a moment.' : 'No accessible markets found.'}
                        loading={integrationsQuery.isLoading}
                        disabled={Boolean(lockedPlatformId)}
                        allowClear={!lockedPlatformId}
                        hint="Pick the WordPress market first. Typing in client fields will not change this selection."
                    />
                </div>

                {!lockedOnboardingMode ? (
                    <div className="md:col-span-2">
                        <label className="mb-1 block text-sm font-medium text-slate-700">Onboarding mode</label>
                        <div className="inline-flex rounded-xl border border-slate-200 bg-slate-50 p-1">
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
                                className={`rounded-lg px-3 py-1.5 text-sm font-medium transition ${form.onboarding_mode === 'manual' ? 'bg-white text-slate-900 shadow-sm' : 'text-slate-600 hover:text-slate-900'}`}
                            >
                                CRM only
                            </button>
                            <button
                                type="button"
                                onClick={() => setForm((current) => ({ ...current, onboarding_mode: 'wp_provision' }))}
                                className={`rounded-lg px-3 py-1.5 text-sm font-medium transition ${form.onboarding_mode === 'wp_provision' ? 'bg-white text-slate-900 shadow-sm' : 'text-slate-600 hover:text-slate-900'}`}
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
                        autoComplete="off"
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
                    <div className="md:col-span-2 rounded-lg border border-slate-200 bg-slate-50/70 p-4">
                        <div className="flex flex-col gap-3 md:flex-row md:items-center md:justify-between">
                            <div>
                                <p className="text-sm font-semibold text-slate-900">Optional profile details</p>
                                <p className="mt-1 text-xs text-slate-500">Keep this off for fast client creation. Turn it on only when rates, bio, services, socials, and media are ready now.</p>
                            </div>
                            <label className="inline-flex items-center gap-3 rounded-lg border border-slate-200 bg-white px-3 py-2 text-sm font-medium text-slate-700 shadow-sm">
                                <input
                                    type="checkbox"
                                    checked={Boolean(form.full_profile)}
                                    onChange={(event) => setForm((current) => ({ ...current, full_profile: event.target.checked }))}
                                    className="h-4 w-4 rounded border-slate-300 text-teal-600 focus:ring-teal-200"
                                />
                                Capture full profile now
                            </label>
                        </div>
                        {!form.full_profile ? (
                            <div className="mt-3 rounded-md border border-dashed border-slate-200 bg-white px-3 py-2 text-xs text-slate-600">
                                This creates the WordPress profile first. The team can finish richer details from Edit Profile immediately after.
                            </div>
                        ) : null}
                    </div>
                ) : null}

                {isWpProvision ? (
                    <div className="md:col-span-2 rounded-2xl border border-slate-200 bg-white/70 p-4">
                        <div className="mb-3 flex items-center justify-between gap-3">
                            <div>
                                <p className="text-sm font-semibold text-slate-900">Profile location</p>
                                <p className="text-xs text-slate-500">
                                    Choose where the profile should appear. Some markets save a region directly when no city list exists.
                                </p>
                            </div>
                            <span className={`rounded-full px-2.5 py-1 text-[11px] font-semibold uppercase tracking-[0.14em] ${requiresProvisionLocation ? 'bg-amber-100 text-amber-700' : 'bg-emerald-100 text-emerald-700'}`}>
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
                    </>
                ) : null}
            </div>
        </div>
    );

    const renderProfileStep = () => (
        <div className="space-y-6">
            <div className="grid gap-4 md:grid-cols-3">
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

            <div>
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
                <div className="mt-2 flex flex-wrap items-center justify-between gap-3">
                    <GenerateBioButton
                        platformId={form.platform_id ? Number(form.platform_id) : null}
                        snapshot={form}
                        mode="preview"
                        onAccept={(bioHtml) => setForm((current) => ({ ...current, bio: bioHtml }))}
                    />
                    <p className="text-xs text-slate-500">Draft text is saved in this browser. Image attachments still need to be re-added after a reload.</p>
                </div>
            </div>

            <div className="rounded-2xl border border-slate-200 bg-slate-50/60 p-4">
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
                    className="crm-input mt-2"
                />
                {imagePreviews.length > 0 ? (
                    <div className="mt-3 grid grid-cols-3 gap-2 sm:grid-cols-6">
                        {imagePreviews.map((preview, index) => (
                            <div key={`${preview.name}-${index}`} className="group relative overflow-hidden rounded-xl border border-slate-200 bg-slate-50">
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

            <div className="grid gap-4 md:grid-cols-2">
                {['gender', 'ethnicity', 'build', 'haircolor', 'hairlength', 'bustsize', 'looks', 'smoker'].map((field) => (
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
                ))}
            </div>
        </div>
    );

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

    const renderRatesStep = () => (
        <div className="space-y-6">
            <div className="space-y-2">
                <span className="text-xs font-semibold uppercase tracking-[0.08em] text-slate-500">Services</span>
                <div className="flex flex-wrap gap-2 rounded-2xl border border-slate-200 bg-white p-3">
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
                <p className="text-xs text-slate-500">Click a service chip to add or remove it. Selected: {selectedServiceCodes.length}</p>
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

            <CurrencySelect
                platformId={form.platform_id ? Number(form.platform_id) : null}
                value={form.currency}
                onChange={(currency) => setForm((current) => ({ ...current, currency }))}
            />

            <div className="grid gap-4 md:grid-cols-[minmax(0,1fr)_minmax(0,1fr)_300px]">
                <div className="space-y-2 rounded-2xl border border-slate-200 bg-white p-4">
                    <div className="flex items-center justify-between gap-2">
                        <span className="text-xs font-semibold uppercase tracking-[0.08em] text-slate-500">Default incall</span>
                        <button type="button" onClick={() => applyDefaultRates('incall')} className="text-xs font-semibold text-teal-700 hover:text-teal-800">Copy to all durations</button>
                    </div>
                    <input value={form.incall} onChange={(event) => setForm((current) => ({ ...current, incall: event.target.value }))} className="crm-input" placeholder="e.g. 1500" />
                </div>
                <div className="space-y-2 rounded-2xl border border-slate-200 bg-white p-4">
                    <div className="flex items-center justify-between gap-2">
                        <span className="text-xs font-semibold uppercase tracking-[0.08em] text-slate-500">Default outcall</span>
                        <button type="button" onClick={() => applyDefaultRates('outcall')} className="text-xs font-semibold text-teal-700 hover:text-teal-800">Copy to all durations</button>
                    </div>
                    <input value={form.outcall} onChange={(event) => setForm((current) => ({ ...current, outcall: event.target.value }))} className="crm-input" placeholder="e.g. 2000" />
                </div>
                <div className="rounded-2xl border border-teal-100 bg-teal-50/60 p-4">
                    <p className="text-xs font-semibold uppercase tracking-[0.08em] text-teal-700">Rate card preview</p>
                    <p className="mt-2 text-sm font-medium text-slate-900">Currency</p>
                    <p className="text-sm text-slate-600">{rateCurrencyLabel}</p>
                    <div className="mt-3 space-y-1 text-sm text-slate-700">
                        <div className="flex items-center justify-between gap-3">
                            <span>Default incall</span>
                            <span className="font-semibold">{form.incall || '—'}</span>
                        </div>
                        <div className="flex items-center justify-between gap-3">
                            <span>Default outcall</span>
                            <span className="font-semibold">{form.outcall || '—'}</span>
                        </div>
                    </div>
                    {ratePreviewRows.length > 0 ? (
                        <div className="mt-3 border-t border-teal-100 pt-3 text-xs text-slate-600">
                            {ratePreviewRows.slice(0, 4).map((row) => (
                                <div key={row.label} className="flex items-center justify-between gap-2 py-1">
                                    <span>{row.label}</span>
                                    <span>{compactSummary([row.incall ? `I ${row.incall}` : '', row.outcall ? `O ${row.outcall}` : ''])}</span>
                                </div>
                            ))}
                        </div>
                    ) : null}
                </div>
            </div>

            <div className="overflow-hidden rounded-2xl border border-slate-200 bg-white">
                <div className="border-b border-slate-100 px-4 py-3">
                    <p className="text-xs font-semibold uppercase tracking-[0.08em] text-slate-500">Rates by duration</p>
                    <p className="mt-1 text-xs text-slate-500">Fill only the durations you want to override from the default rate card.</p>
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
                                        <input value={form[`rate${key}_incall`]} onChange={(event) => setForm((current) => ({ ...current, [`rate${key}_incall`]: event.target.value }))} className="crm-input" placeholder="—" />
                                    </td>
                                    <td className="px-4 py-2">
                                        <input value={form[`rate${key}_outcall`]} onChange={(event) => setForm((current) => ({ ...current, [`rate${key}_outcall`]: event.target.value }))} className="crm-input" placeholder="—" />
                                    </td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    );

    const renderSocialsStep = () => (
        <div className="space-y-6">
            <div className="grid gap-4 md:grid-cols-2">
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
                <textarea value={form.extraservices} onChange={(event) => setForm((current) => ({ ...current, extraservices: event.target.value }))} className="crm-input md:col-span-2" rows={3} placeholder="Additional services" />
            </div>
        </div>
    );

    const renderReviewStep = () => (
        <div className="space-y-4">
            <div className="rounded-2xl border border-teal-100 bg-teal-50/70 px-4 py-3 text-sm text-teal-900">
                Review everything once more before we create the WordPress user, profile post, taxonomy links, and CRM client record.
            </div>
            <div className="grid gap-4 lg:grid-cols-2">
                {reviewSections.map((section) => (
                    <div key={section.title} className="rounded-2xl border border-slate-200 bg-white p-4">
                        <div className="flex items-center justify-between gap-3">
                            <p className="text-xs font-semibold uppercase tracking-[0.08em] text-slate-500">{section.title}</p>
                            {section.stepKey ? (
                                <button
                                    type="button"
                                    onClick={() => goToStep(section.stepKey)}
                                    className="text-xs font-semibold text-teal-700 transition hover:text-teal-800"
                                >
                                    Edit step
                                </button>
                            ) : null}
                        </div>
                        <dl className="mt-3 space-y-2 text-sm text-slate-700">
                            {section.items.map(([label, value]) => (
                                <div key={label} className="flex items-start justify-between gap-4 border-b border-slate-100 pb-2 last:border-0 last:pb-0">
                                    <dt className="text-slate-500">{label}</dt>
                                    <dd className="max-w-[65%] text-right font-medium text-slate-900">{value || '—'}</dd>
                                </div>
                            ))}
                        </dl>
                    </div>
                ))}
            </div>
        </div>
    );

    const renderStepContent = () => {
        switch (wizardStep) {
            case STEP_PROFILE:
                return renderProfileStep();
            case STEP_RATES:
                return renderRatesStep();
            case STEP_SOCIALS:
                return renderSocialsStep();
            case STEP_REVIEW:
                return renderReviewStep();
            case STEP_SETUP:
            default:
                return renderSetupStep();
        }
    };

    if (!open) {
        return null;
    }

    return (
        <div className="fixed inset-0 z-50 flex items-center justify-center bg-slate-900/45 p-4" onClick={handleRequestClose}>
            <div
                ref={dialogRef}
                role="dialog"
                aria-modal="true"
                aria-labelledby={titleId}
                className="flex max-h-[92vh] w-full max-w-5xl flex-col overflow-hidden rounded-[28px] border border-slate-200 bg-white shadow-2xl"
                onClick={(event) => event.stopPropagation()}
            >
                <header className="border-b border-slate-100 px-5 py-4">
                    <div className="flex flex-col gap-4 md:flex-row md:items-start md:justify-between">
                        <div>
                            <h3 id={titleId} className="text-3xl font-semibold tracking-tight text-slate-900">{title}</h3>
                            <p className="mt-1 max-w-2xl text-sm text-slate-500">{bodySubtitle}</p>
                        </div>
                        <div className="flex flex-wrap items-center gap-2">
                            <span className={`rounded-full border px-3 py-1 text-xs font-semibold ${draftState.status === 'failed' ? 'border-rose-200 bg-rose-50 text-rose-700' : draftState.status === 'restored' ? 'border-sky-200 bg-sky-50 text-sky-700' : 'border-slate-200 bg-slate-50 text-slate-500'}`}>
                                {draftStatusLabel}
                            </span>
                            <button type="button" onClick={resetDraft} className="rounded-full border border-slate-200 px-3 py-1.5 text-xs font-semibold text-slate-600 transition hover:border-slate-300 hover:text-slate-900" title="Clear the saved browser draft and start this form again.">
                                Reset draft
                            </button>
                        </div>
                    </div>
                    {form.full_profile && wizardSteps.length > 1 ? (
                        <div className="mt-4 space-y-3">
                            <div className="h-2 overflow-hidden rounded-full bg-slate-100" aria-hidden="true">
                                <div
                                    className="h-full rounded-full bg-teal-600 transition-all duration-300"
                                    style={{ width: `${progressPercent}%` }}
                                />
                            </div>
                            <div className="flex flex-wrap gap-2" role="tablist" aria-label="Client creation steps">
                            {wizardSteps.map((step, index) => {
                                const isActive = step.key === wizardStep;
                                const isCompleted = index < currentStepIndex;
                                return (
                                    <button
                                        key={step.key}
                                        type="button"
                                        onClick={() => {
                                            if (index <= currentStepIndex) {
                                                goToStep(step.key);
                                            }
                                        }}
                                        aria-current={isActive ? 'step' : undefined}
                                        className={`rounded-2xl border px-3 py-2 text-left transition ${isActive ? 'border-teal-200 bg-teal-50 text-teal-800' : isCompleted ? 'border-emerald-200 bg-emerald-50 text-emerald-700' : 'border-slate-200 bg-white text-slate-500'}`}
                                    >
                                        <span className="block text-[11px] font-semibold uppercase tracking-[0.14em]">Step {index + 1}</span>
                                        <span className="mt-1 block text-sm font-semibold">{step.label}</span>
                                        <span className="mt-0.5 block text-xs opacity-80">{step.eyebrow}</span>
                                    </button>
                                );
                            })}
                            </div>
                        </div>
                    ) : null}
                </header>

                <div className="min-h-0 flex-1 overflow-y-auto px-5 py-5">
                    <div className="mx-auto w-full max-w-4xl space-y-5">
                        {fieldSourceBanner}

                        {draftState.restored ? (
                            <div className="rounded-2xl border border-sky-200 bg-sky-50 px-4 py-3 text-sm text-sky-800">
                                A saved browser draft was restored. Review the market and contact details before provisioning.
                            </div>
                        ) : null}

                        <div className="flex flex-wrap items-center justify-between gap-3">
                            <div>
                                <p className="text-xs font-semibold uppercase tracking-[0.14em] text-slate-500">{currentStepMeta?.label || 'Setup'}</p>
                                <p className="mt-1 text-sm text-slate-600">{currentStepMeta?.eyebrow || 'Capture the essentials first.'}</p>
                            </div>
                            <div className="rounded-full bg-slate-50 px-3 py-1 text-xs font-semibold text-slate-500">
                                {wizardSteps.length > 1 ? `Step ${currentStepIndex + 1} of ${wizardSteps.length}` : 'Single-step flow'}
                            </div>
                        </div>

                        {stepErrors.length > 0 ? (
                            <div className="rounded-2xl border border-amber-200 bg-amber-50 px-4 py-3">
                                <p className="text-xs font-semibold uppercase tracking-[0.12em] text-amber-700">Fix these before continuing</p>
                                <ul className="mt-2 list-disc space-y-1 pl-5 text-sm text-amber-900">
                                    {stepErrors.map((error) => <li key={error}>{error}</li>)}
                                </ul>
                            </div>
                        ) : null}

                        {renderStepContent()}
                    </div>
                </div>

                <div className="border-t border-slate-100 px-5 py-3">
                    {requiresProvisionContact ? (
                        <p className="text-xs font-medium text-amber-700">
                            Add at least one contact channel to continue with WordPress provisioning.
                        </p>
                    ) : requiresProvisionLocation ? (
                        <p className="text-xs font-medium text-amber-700">
                            {locationRequirementMessage}
                        </p>
                    ) : createMutation.isPending ? (
                        <p className="text-xs font-medium text-teal-700">
                            Provisioning is in progress. We are creating the WordPress user, profile, taxonomy links, and CRM record now.
                        </p>
                    ) : (
                        <p className="text-xs text-slate-500">
                            {form.full_profile
                                ? 'Full profile mode saves more of the WordPress profile up front and ends with a review step before the irreversible provision call.'
                                : 'Quick provision keeps the first pass short, then lets the team finish the rest inside Edit Profile right after creation.'}
                        </p>
                    )}
                    {duplicateMatches.length > 0 ? (
                        <p className="mt-2 text-xs text-slate-500">
                            {duplicateMatches.length} existing client record{duplicateMatches.length === 1 ? '' : 's'} use this phone number.
                        </p>
                    ) : null}
                </div>

                <footer className="flex shrink-0 items-center justify-between gap-3 border-t border-slate-100 px-5 py-4">
                    <div className="flex items-center gap-2">
                        <button type="button" className="crm-btn-secondary" onClick={handleRequestClose}>
                            Cancel
                        </button>
                        {wizardSteps.length > 1 && currentStepIndex > 0 ? (
                            <button type="button" className="crm-btn-secondary" onClick={() => goToStep(wizardSteps[currentStepIndex - 1].key)}>
                                Back
                            </button>
                        ) : null}
                    </div>
                    <div className="flex items-center gap-2">
                        {wizardSteps.length > 1 && !isReviewStep ? (
                            <button type="button" className="crm-btn-secondary" onClick={handleNext}>
                                Next step
                            </button>
                        ) : null}
                        <button
                            type="button"
                            disabled={createMutation.isPending || (wizardSteps.length === 1 && validateSetupStep().length > 0)}
                            onClick={wizardSteps.length > 1 && !isReviewStep ? handleNext : handleSubmit}
                            className="crm-btn-primary disabled:cursor-not-allowed disabled:opacity-50"
                        >
                            {wizardSteps.length > 1 && !isReviewStep ? 'Continue' : resolvedSubmitLabel}
                        </button>
                    </div>
                </footer>
            </div>
        </div>
    );
}
