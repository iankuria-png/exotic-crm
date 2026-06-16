export const PROFILE_ENUM_CHOICES = {
    gender: [
        { code: '1', label: 'Female' },
        { code: '2', label: 'Male' },
        { code: '3', label: 'Couple' },
        { code: '4', label: 'Gay' },
        { code: '5', label: 'Transsexual' },
    ],
    ethnicity: [
        { code: '1', label: 'Latin' },
        { code: '2', label: 'Caucasian' },
        { code: '3', label: 'Black' },
        { code: '4', label: 'White' },
        { code: '5', label: 'MiddleEast' },
        { code: '6', label: 'Asian' },
        { code: '7', label: 'Indian' },
        { code: '8', label: 'Aborigine' },
        { code: '9', label: 'Native American' },
        { code: '10', label: 'Other' },
    ],
    build: [
        { code: '1', label: 'Skinny' },
        { code: '2', label: 'Slim' },
        { code: '3', label: 'Regular' },
        { code: '4', label: 'Curvy' },
        { code: '5', label: 'Fat' },
    ],
    services: [
        { code: '1', label: 'BDSM' },
        { code: '2', label: 'Couples' },
        { code: '3', label: 'Domination' },
        { code: '4', label: 'Escort' },
        { code: '5', label: 'Massage' },
        { code: '6', label: 'Fetish' },
        { code: '7', label: 'Mature' },
        { code: '8', label: 'GFE' },
    ],
    haircolor: [
        { code: '1', label: 'Black' }, { code: '2', label: 'Blonde' },
        { code: '3', label: 'Brown' }, { code: '4', label: 'Brunette' },
        { code: '5', label: 'Chestnut' }, { code: '6', label: 'Auburn' },
        { code: '7', label: 'Dark-blonde' }, { code: '8', label: 'Golden' },
        { code: '9', label: 'Red' }, { code: '10', label: 'Grey' },
        { code: '11', label: 'Silver' }, { code: '12', label: 'White' },
        { code: '13', label: 'Other' },
    ],
    hairlength: [
        { code: '1', label: 'Bald' }, { code: '2', label: 'Short' },
        { code: '3', label: 'Shoulder' }, { code: '4', label: 'Long' },
        { code: '5', label: 'Very Long' },
    ],
    bustsize: [
        { code: '1', label: 'Very small' }, { code: '2', label: 'Small (A)' },
        { code: '3', label: 'Medium (B)' }, { code: '4', label: 'Large (C)' },
        { code: '5', label: 'Very Large (D)' }, { code: '6', label: 'Enormous (E+)' },
    ],
    looks: [
        { code: '1', label: 'Nothing Special' }, { code: '2', label: 'Average' },
        { code: '3', label: 'Sexy' }, { code: '4', label: 'Ultra Sexy' },
    ],
    smoker: [
        { code: '1', label: 'Yes' }, { code: '2', label: 'No' },
    ],
    availability: [
        { code: '1', label: 'Incall' }, { code: '2', label: 'Outcall' },
    ],
    languagelevel: [
        { code: '1', label: 'Minimal' }, { code: '2', label: 'Conversational' },
        { code: '3', label: 'Fluent' },
    ],
};

export const RATE_DURATION_OPTIONS = [
    ['30min', '30 min'],
    ['1h', '1 hour'],
    ['2h', '2 hours'],
    ['3h', '3 hours'],
    ['6h', '6 hours'],
    ['12h', '12 hours'],
    ['24h', '24 hours'],
];

const LEGACY_HEIGHT_CODE_TO_CM = {
    1: '128',
    2: '134',
    3: '140',
    4: '146',
    5: '152',
    6: '155',
    7: '158',
    8: '162',
    9: '165',
    10: '168',
    11: '171',
    12: '174',
    13: '177',
    14: '180',
    15: '183',
    16: '189',
    17: '195',
    18: '201',
    19: '207',
    20: '213',
};

export const PROFILE_ENUM_OPTIONS = Object.fromEntries(
    Object.entries(PROFILE_ENUM_CHOICES).map(([field, options]) => [
        field,
        options.map((option) => ({
            value: option.code,
            plainLabel: option.label,
            label: `${option.label} (${option.code})`,
        })),
    ]),
);

const PROFILE_ENUM_LOOKUP = Object.fromEntries(
    Object.entries(PROFILE_ENUM_OPTIONS).map(([field, options]) => {
        const byCode = new Map();
        const byLabel = new Map();

        options.forEach((option) => {
            byCode.set(option.value, option.value);
            byLabel.set(normalizeLookupToken(option.plainLabel), option.value);
            byLabel.set(normalizeLookupToken(option.label), option.value);
            byLabel.set(normalizeLookupToken(`${option.value}`), option.value);
            byLabel.set(normalizeLookupToken(`${option.plainLabel} ${option.value}`), option.value);
            byLabel.set(normalizeLookupToken(`${option.value} ${option.plainLabel}`), option.value);
        });

        return [field, { byCode, byLabel }];
    }),
);

export function normalizeLookupToken(value) {
    return String(value || '')
        .toLowerCase()
        .replace(/[^a-z0-9]+/g, ' ')
        .trim();
}

export function resolveProfileEnumValue(field, value) {
    const raw = String(value ?? '').trim();
    if (!raw) return '';

    const lookup = PROFILE_ENUM_LOOKUP[field];
    if (!lookup) return raw;

    if (lookup.byCode.has(raw)) {
        return lookup.byCode.get(raw);
    }

    const numericCode = raw.replace(/[^0-9]/g, '');
    if (numericCode) {
        const normalizedCode = String(Number.parseInt(numericCode, 10));
        if (lookup.byCode.has(normalizedCode)) {
            return normalizedCode;
        }
    }

    const token = normalizeLookupToken(raw);
    if (lookup.byLabel.has(token)) {
        return lookup.byLabel.get(token);
    }

    return raw;
}

export function isKnownProfileEnumCode(field, value) {
    const raw = String(value ?? '').trim();
    if (!raw) return true;

    const lookup = PROFILE_ENUM_LOOKUP[field];
    if (!lookup) return true;

    return lookup.byCode.has(raw);
}

export function parseProfileServices(value, field = 'services') {
    const tokens = Array.isArray(value)
        ? value
        : String(value ?? '')
            .split(',')
            .map((item) => item.trim());

    const normalized = [];
    tokens.forEach((token) => {
        const raw = String(token ?? '').trim();
        if (!raw) return;

        const resolved = resolveProfileEnumValue(field, raw);
        if (!normalized.includes(resolved)) {
            normalized.push(resolved);
        }
    });

    return normalized;
}

function toDateInputValue(year, month, day) {
    const y = Number.parseInt(year, 10);
    const m = Number.parseInt(month, 10);
    const d = Number.parseInt(day, 10);
    if (!Number.isInteger(y) || !Number.isInteger(m) || !Number.isInteger(d)) return '';
    if (m < 1 || m > 12 || d < 1 || d > 31) return '';

    const paddedMonth = String(m).padStart(2, '0');
    const paddedDay = String(d).padStart(2, '0');
    return `${y}-${paddedMonth}-${paddedDay}`;
}

export function normalizeBirthdayForEditor(value) {
    const raw = String(value ?? '').trim();
    if (!raw) return '';

    const ymdMatch = raw.match(/^(\d{4})[-/](\d{1,2})[-/](\d{1,2})$/);
    if (ymdMatch) {
        return toDateInputValue(ymdMatch[1], ymdMatch[2], ymdMatch[3]);
    }

    const dmyMatch = raw.match(/^(\d{1,2})[-/](\d{1,2})[-/](\d{4})$/);
    if (dmyMatch) {
        const parsed = new Date(raw);
        if (!Number.isNaN(parsed.getTime())) {
            return toDateInputValue(parsed.getFullYear(), parsed.getMonth() + 1, parsed.getDate());
        }

        return toDateInputValue(dmyMatch[3], dmyMatch[2], dmyMatch[1]);
    }

    if (/^\d{10,13}$/.test(raw)) {
        const numeric = Number.parseInt(raw, 10);
        const millis = raw.length === 13 ? numeric : numeric * 1000;
        const parsed = new Date(millis);
        if (!Number.isNaN(parsed.getTime())) {
            return toDateInputValue(parsed.getFullYear(), parsed.getMonth() + 1, parsed.getDate());
        }
    }

    const parsed = new Date(raw);
    if (Number.isNaN(parsed.getTime())) {
        return '';
    }

    return toDateInputValue(parsed.getFullYear(), parsed.getMonth() + 1, parsed.getDate());
}

export function normalizeBirthdayForSave(value) {
    const normalized = normalizeBirthdayForEditor(value);
    return normalized || null;
}

export function normalizeHeightForEditor(value) {
    const raw = String(value ?? '').trim();
    if (!raw) return '';
    if (LEGACY_HEIGHT_CODE_TO_CM[raw]) return LEGACY_HEIGHT_CODE_TO_CM[raw];

    const cmInParens = raw.match(/\((\d+(?:\.\d+)?)\)/);
    if (cmInParens) {
        return String(Math.round(Number.parseFloat(cmInParens[1])));
    }

    const explicitCm = raw.match(/(\d+(?:\.\d+)?)\s*cm/i);
    if (explicitCm) {
        return String(Math.round(Number.parseFloat(explicitCm[1])));
    }

    const feetInches = raw.match(/(\d+)\s*(?:ft|')\s*(\d+)?/i);
    if (feetInches) {
        const feet = Number.parseInt(feetInches[1], 10);
        const inches = Number.parseInt(feetInches[2] || '0', 10);
        if (Number.isFinite(feet) && Number.isFinite(inches)) {
            return String(Math.round((feet * 12 + inches) * 2.54));
        }
    }

    const numeric = raw.match(/^\d+(?:\.\d+)?$/);
    if (numeric) {
        return String(Math.round(Number.parseFloat(raw)));
    }

    return raw;
}

export function normalizeHeightForSave(value) {
    const normalized = normalizeHeightForEditor(value);
    if (!normalized) return null;
    return normalized;
}
