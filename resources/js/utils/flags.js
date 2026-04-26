const COUNTRY_FLAGS = {
    // East Africa
    Kenya: '\u{1F1F0}\u{1F1EA}',
    Tanzania: '\u{1F1F9}\u{1F1FF}',
    Uganda: '\u{1F1FA}\u{1F1EC}',
    Rwanda: '\u{1F1F7}\u{1F1FC}',
    Ethiopia: '\u{1F1EA}\u{1F1F9}',
    Burundi: '\u{1F1E7}\u{1F1EE}',
    Djibouti: '\u{1F1E9}\u{1F1EF}',
    Eritrea: '\u{1F1EA}\u{1F1F7}',
    Somalia: '\u{1F1F8}\u{1F1F4}',
    'South Sudan': '\u{1F1F8}\u{1F1F8}',

    // West Africa
    Nigeria: '\u{1F1F3}\u{1F1EC}',
    Ghana: '\u{1F1EC}\u{1F1ED}',
    Senegal: '\u{1F1F8}\u{1F1F3}',
    Benin: '\u{1F1E7}\u{1F1EF}',
    'Benin Republic': '\u{1F1E7}\u{1F1EF}',
    Togo: '\u{1F1F9}\u{1F1EC}',
    Niger: '\u{1F1F3}\u{1F1EA}',
    Mali: '\u{1F1F2}\u{1F1F1}',
    'Burkina Faso': '\u{1F1E7}\u{1F1EB}',
    Guinea: '\u{1F1EC}\u{1F1F3}',
    'Guinea-Bissau': '\u{1F1EC}\u{1F1FC}',
    Liberia: '\u{1F1F1}\u{1F1F7}',
    'Sierra Leone': '\u{1F1F8}\u{1F1F1}',
    Gambia: '\u{1F1EC}\u{1F1F2}',
    'Cape Verde': '\u{1F1E8}\u{1F1FB}',
    Mauritania: '\u{1F1F2}\u{1F1F7}',
    'Côte d\'Ivoire': '\u{1F1E8}\u{1F1EE}',
    'Cote d\'Ivoire': '\u{1F1E8}\u{1F1EE}',

    // Central Africa
    Cameroon: '\u{1F1E8}\u{1F1F2}',
    'DR Congo': '\u{1F1E8}\u{1F1E9}',
    'Democratic Republic of Congo': '\u{1F1E8}\u{1F1E9}',
    DRC: '\u{1F1E8}\u{1F1E9}',
    'Republic of the Congo': '\u{1F1E8}\u{1F1EC}',
    Congo: '\u{1F1E8}\u{1F1EC}',
    Gabon: '\u{1F1EC}\u{1F1E6}',
    'Central African Republic': '\u{1F1E8}\u{1F1EB}',
    Chad: '\u{1F1F9}\u{1F1E9}',
    'Equatorial Guinea': '\u{1F1EC}\u{1F1F6}',
    'São Tomé and Príncipe': '\u{1F1F8}\u{1F1F9}',
    'Sao Tome and Principe': '\u{1F1F8}\u{1F1F9}',

    // North Africa
    Egypt: '\u{1F1EA}\u{1F1EC}',
    Algeria: '\u{1F1E9}\u{1F1FF}',
    Morocco: '\u{1F1F2}\u{1F1E6}',
    Tunisia: '\u{1F1F9}\u{1F1F3}',
    Libya: '\u{1F1F1}\u{1F1FE}',
    Sudan: '\u{1F1F8}\u{1F1E9}',

    // Southern Africa
    'South Africa': '\u{1F1FF}\u{1F1E6}',
    Zimbabwe: '\u{1F1FF}\u{1F1FC}',
    Zambia: '\u{1F1FF}\u{1F1F2}',
    Mozambique: '\u{1F1F2}\u{1F1FF}',
    Malawi: '\u{1F1F2}\u{1F1FC}',
    Botswana: '\u{1F1E7}\u{1F1FC}',
    Namibia: '\u{1F1F3}\u{1F1E6}',
    Lesotho: '\u{1F1F1}\u{1F1F8}',
    Swaziland: '\u{1F1F8}\u{1F1FF}',
    Eswatini: '\u{1F1F8}\u{1F1FF}',
    Madagascar: '\u{1F1F2}\u{1F1EC}',
    Comoros: '\u{1F1F0}\u{1F1F2}',
    Seychelles: '\u{1F1F8}\u{1F1E8}',
    Mauritius: '\u{1F1F2}\u{1F1FA}',
    Angola: '\u{1F1E6}\u{1F1F4}',

    // ISO 2-letter code aliases (used by some platform records)
    TZ: '\u{1F1F9}\u{1F1FF}',
    UG: '\u{1F1FA}\u{1F1EC}',
    KE: '\u{1F1F0}\u{1F1EA}',
    NG: '\u{1F1F3}\u{1F1EC}',
    GH: '\u{1F1EC}\u{1F1ED}',
    ET: '\u{1F1EA}\u{1F1F9}',
    RW: '\u{1F1F7}\u{1F1FC}',
    ZA: '\u{1F1FF}\u{1F1E6}',
    ZW: '\u{1F1FF}\u{1F1FC}',
    ZM: '\u{1F1FF}\u{1F1F2}',
    SN: '\u{1F1F8}\u{1F1F3}',
    CD: '\u{1F1E8}\u{1F1E9}',
    CM: '\u{1F1E8}\u{1F1F2}',
    CI: '\u{1F1E8}\u{1F1EE}',
    BJ: '\u{1F1E7}\u{1F1EF}',
    BW: '\u{1F1E7}\u{1F1FC}',
    AO: '\u{1F1E6}\u{1F1F4}',
    MW: '\u{1F1F2}\u{1F1FC}',
    MZ: '\u{1F1F2}\u{1F1FF}',
};

/**
 * Get the flag emoji for a country name or ISO code.
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
