const COUNTRY_FLAGS = {
    Kenya: '\u{1F1F0}\u{1F1EA}',
    Tanzania: '\u{1F1F9}\u{1F1FF}',
    Uganda: '\u{1F1FA}\u{1F1EC}',
    Nigeria: '\u{1F1F3}\u{1F1EC}',
    'South Africa': '\u{1F1FF}\u{1F1E6}',
    Ghana: '\u{1F1EC}\u{1F1ED}',
    Ethiopia: '\u{1F1EA}\u{1F1F9}',
    Rwanda: '\u{1F1F7}\u{1F1FC}',
};

/**
 * Get the flag emoji for a country name.
 * Falls back to a globe emoji for unknown countries.
 */
export function getCountryFlag(country) {
    if (!country) return '\u{1F30D}';
    return COUNTRY_FLAGS[country] || '\u{1F30D}';
}

/**
 * Format a platform option label with its country flag.
 * E.g. "🇰🇪 ExoticKenya" or "ExoticKenya" if no country.
 */
export function flaggedPlatformLabel(platform) {
    const name = platform.platform_name || platform.name || '';
    const flag = getCountryFlag(platform.country);
    return `${flag}  ${name}`;
}

/**
 * Map an array of platform objects to FilterSelect options with flags.
 * Prepends an "All markets" option with a globe icon.
 */
export function platformOptionsWithFlags(platforms, allLabel = 'All markets') {
    return [
        { value: '', label: `\u{1F30D}  ${allLabel}` },
        ...platforms.map((p) => ({
            value: p.platform_id,
            label: flaggedPlatformLabel(p),
        })),
    ];
}
