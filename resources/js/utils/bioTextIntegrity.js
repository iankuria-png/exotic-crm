// Finds text in a profile bio that visitors and Google would see as broken:
// garbled accents ("dÃ©tour" for "détour"), lost characters, escaped HTML,
// Markdown and AI leftovers, invisible characters and emoji. Repairs what can
// be repaired without guessing.
//
// Mirror of app/Support/BioTextIntegrity.php — the server scans and repairs
// markets with that copy; editors warn with this one. Both run
// tests/Fixtures/bio-text-integrity-cases.json. Change them together.
//
// PHP's \s and trim() only know ASCII whitespace, so this file spells the
// classes out: JS's \s would also match the non-breaking space inside "Ã ".

export const FORMAT_HTML = 'html';
export const FORMAT_TEXT = 'text';

export const BROKEN_ACCENTS = 'broken_accents';
export const LOST_CHARACTERS = 'lost_characters';
export const ESCAPED_HTML = 'escaped_html';
export const MARKDOWN = 'markdown';
export const AI_TEXT = 'ai_text';
export const INVISIBLE_CHARACTERS = 'invisible_characters';
export const EMOJI = 'emoji';

/** Checked and fixed in this order: later checks see earlier repairs. */
export const KINDS = {
    [BROKEN_ACCENTS]: { severity: 'error', fixable: true },
    [LOST_CHARACTERS]: { severity: 'error', fixable: false },
    [ESCAPED_HTML]: { severity: 'error', fixable: true },
    [MARKDOWN]: { severity: 'warning', fixable: true },
    [AI_TEXT]: { severity: 'error', fixable: false },
    [INVISIBLE_CHARACTERS]: { severity: 'warning', fixable: true },
    [EMOJI]: { severity: 'warning', fixable: true },
};

/** Fixes that only restore what the writer meant: safe without review. */
export const SAFE_FIXES = [BROKEN_ACCENTS, ESCAPED_HTML, INVISIBLE_CHARACTERS];

const KIND_ORDER = Object.keys(KINDS);
const MAX_SAMPLES = 4;
const WS = ' \\t\\n\\r\\f\\v';

/** Windows-1252 characters for bytes 0x80–0x9F (unassigned bytes omitted). */
const CP1252 = new Map([
    [0x20AC, 0x80], [0x201A, 0x82], [0x0192, 0x83], [0x201E, 0x84], [0x2026, 0x85],
    [0x2020, 0x86], [0x2021, 0x87], [0x02C6, 0x88], [0x2030, 0x89], [0x0160, 0x8A],
    [0x2039, 0x8B], [0x0152, 0x8C], [0x017D, 0x8E], [0x2018, 0x91], [0x2019, 0x92],
    [0x201C, 0x93], [0x201D, 0x94], [0x2022, 0x95], [0x2013, 0x96], [0x2014, 0x97],
    [0x02DC, 0x98], [0x2122, 0x99], [0x0161, 0x9A], [0x203A, 0x9B], [0x0153, 0x9C],
    [0x017E, 0x9E], [0x0178, 0x9F],
]);

/** Named entities for U+00A0–U+00FF, in code point order. */
const LATIN1_ENTITIES = ('nbsp iexcl cent pound curren yen brvbar sect uml copy ordf laquo not shy reg macr '
    + 'deg plusmn sup2 sup3 acute micro para middot cedil sup1 ordm raquo frac14 frac12 frac34 iquest '
    + 'Agrave Aacute Acirc Atilde Auml Aring AElig Ccedil Egrave Eacute Ecirc Euml Igrave Iacute Icirc Iuml '
    + 'ETH Ntilde Ograve Oacute Ocirc Otilde Ouml times Oslash Ugrave Uacute Ucirc Uuml Yacute THORN szlig '
    + 'agrave aacute acirc atilde auml aring aelig ccedil egrave eacute ecirc euml igrave iacute icirc iuml '
    + 'eth ntilde ograve oacute ocirc otilde ouml divide oslash ugrave uacute ucirc uuml yacute thorn yuml').split(' ');

const CP1252_ENTITIES = {
    euro: 0x20AC, sbquo: 0x201A, fnof: 0x0192, bdquo: 0x201E, hellip: 0x2026,
    dagger: 0x2020, Dagger: 0x2021, circ: 0x02C6, permil: 0x2030, Scaron: 0x0160,
    lsaquo: 0x2039, OElig: 0x0152, lsquo: 0x2018, rsquo: 0x2019, ldquo: 0x201C,
    rdquo: 0x201D, bull: 0x2022, ndash: 0x2013, mdash: 0x2014, tilde: 0x02DC,
    trade: 0x2122, scaron: 0x0161, rsaquo: 0x203A, oelig: 0x0153, Yuml: 0x0178,
};

const BASIC_ENTITIES = {
    amp: 0x26, lt: 0x3C, gt: 0x3E, quot: 0x22, apos: 0x27, zwsp: 0x200B, zwj: 0x200D, zwnj: 0x200C, lrm: 0x200E, rlm: 0x200F,
};

const CONT = '\\u0080-\\u00BF\\u20AC\\u201A\\u0192\\u201E\\u2026\\u2020\\u2021\\u02C6'
    + '\\u2030\\u0160\\u2039\\u0152\\u017D\\u2018\\u2019\\u201C\\u201D\\u2022\\u2013\\u2014\\u02DC'
    + '\\u2122\\u0161\\u203A\\u0153\\u017E\\u0178';

const MOJIBAKE_SEQUENCE = () => new RegExp(`[\\u00C2-\\u00DF][${CONT}]|[\\u00E0-\\u00EF][${CONT}]{2}|[\\u00F0-\\u00F4][${CONT}]{3}`, 'gu');
const LONE_A_TILDE = () => /(?<!\p{Lu})Ã(?=[ \t])/gu;
const LONE_A_CIRCUMFLEX = () => /(?<!\p{Lu})Â(?=[ \t])/gu;
const LONE_QUOTE = () => new RegExp(`â€(?![${CONT}])`, 'gu');
const C1_CONTROL = () => /[\u0080-\u009F]/gu;

// Joiners and direction marks are left alone: they hold emoji sequences
// and right-to-left text together.
const INVISIBLE = () => /[\u0000-\u0008\u000B\u000C\u000E-\u001F\u007F-\u009F\u00AD\u200B\u2060-\u2064\uFEFF]/gu;
const INVISIBLE_ENTITY = () => /&(?:shy|zwsp|#173|#8203|#65279|#x(?:ad|200b|feff));/gi;

const MARKDOWN_RULES = [
    [() => /```[a-z]*/giu, ''],
    [() => /\*\*([^*\n<>]+?)\*\*/gu, '$1'],
    [() => /(?<![\p{L}\p{N}_])__([^_\n<>]+?)__(?![\p{L}\p{N}_])/gu, '$1'],
    [() => /(^|\n)[ \t]*#{1,6}[ \t]+(?=[^ \t\n\r\f\v])/gu, '$1'],
    [() => /\[([^\]\n<>]+)\]\((?:https?:\/\/|\/)[^)\t\n\r\f\v <>]*\)/gu, '$1'],
];

/** MARKDOWN_RULES as whole matches, so samples show the heading text too. */
const MARKDOWN_SAMPLES = [
    () => /```[a-z]*/giu,
    () => /\*\*[^*\n<>]+?\*\*/gu,
    () => /(?<![\p{L}\p{N}_])__[^_\n<>]+?__(?![\p{L}\p{N}_])/gu,
    () => /(?:^|\n)[ \t]*#{1,6}[ \t]+[^ \t\n\r\f\v][^\n]*/gu,
    () => /\[[^\]\n<>]+\]\((?:https?:\/\/|\/)[^)\t\n\r\f\v <>]*\)/gu,
];

const EMOJI_PATTERN = () => /[\u{1F000}-\u{1FAFF}\u{2600}-\u{27BF}\u{2B00}-\u{2BFF}\u{FE0E}\u{FE0F}\u{20E3}]/gu;

const AI_PATTERNS = [
    () => new RegExp(`(?<![\\p{L}\\p{N}_])(?:I will not|I won['’]t|I cannot|I can['’]t|I am unable to|I['’]m unable to|I am not able to|I['’]m not able to)[${WS}]+(?:write|create|produce|generate|provide|help with|assist with)(?![\\p{L}\\p{N}_])(?:[ \\t]+[\\p{L}'’-]+){0,3}?[ \\t]+(?:content|profile|profiles|bio|bios|biography|description|that|this|such|explicit|sexual|promotional|material|request|text|advert|advertisement)(?![\\p{L}\\p{N}_])[^.!?\\n<]*`, 'giu'),
    () => /(?<![\p{L}\p{N}_])as an AI(?![\p{L}\p{N}_])[^.!?\n<]*|(?<![\p{L}\p{N}_])(?:an|a) (?:AI|large) language model(?![\p{L}\p{N}_])/giu,
    () => /(?<![\p{L}\p{N}_])promotional content for sexual services(?![\p{L}\p{N}_])|(?<![\p{L}\p{N}_])If you have other writing projects(?![\p{L}\p{N}_])/giu,
    () => new RegExp(`(?:^|>|\\n)[${WS}]*(?:(?:Sure|Certainly|Of course)[!,.]?[${WS}]+)?Here(?:['’]s| is) (?:a|an|the|your) (?:[a-z-]+ ){0,3}(?:bio|biography|profile|description|version|rewrite)(?![\\p{L}\\p{N}_])[^:\\n<]*:?`, 'giu'),
    () => new RegExp(`(?:^|>|\\n)[${WS}]*Voici (?:une|la|votre|ta) (?:[a-zé-]+ ){0,3}(?:bio|biographie|description|présentation)(?![\\p{L}\\p{N}_])[^:\\n<]*:?`, 'giu'),
    () => new RegExp(`(?:^|>|\\n)[${WS}]*Aqui está (?:a|uma|sua|tua) (?:[a-zçã-]+ ){0,3}(?:bio|biografia|descrição|apresentação)(?![\\p{L}\\p{N}_])[^:\\n<]*:?`, 'giu'),
    () => /\[(?:name|nom|nome|city|ville|cidade|area|location|phone|number|age|insert[^\]]*|your [a-z ]+)\]|\{\{?[ \t\n\r\f\v]*(?:name|city|phone|location)[ \t\n\r\f\v]*\}?\}|(?<![\p{L}\p{N}_])lorem ipsum(?![\p{L}\p{N}_])/giu,
];

// ─── Public API ──────────────────────────────────────────────────────────────

/**
 * @returns {{clean: boolean, errors: number, warnings: number, issues: Array<{kind: string, severity: string, fixable: boolean, count: number, samples: Array<{found: string, fixed: string|null}>}>}}
 */
export function inspectBioText(content, format = FORMAT_HTML, kinds = null) {
    const issues = [];
    let current = String(content ?? '');

    for (const kind of KIND_ORDER) {
        if (kinds && !kinds.includes(kind)) continue;

        const found = detect(kind, current, format);
        if (found.count > 0) {
            issues.push({
                kind,
                severity: KINDS[kind].severity,
                fixable: KINDS[kind].fixable,
                count: found.count,
                samples: found.samples,
            });
        }

        if (KINDS[kind].fixable) {
            current = fixKind(kind, current, format);
        }
    }

    const errors = issues.filter((issue) => issue.severity === 'error').length;

    return { clean: issues.length === 0, errors, warnings: issues.length - errors, issues };
}

/** Apply the given fixes (all fixable kinds by default), in catalogue order. */
export function fixBioText(content, format = FORMAT_HTML, kinds = null) {
    let requested = kinds ? [...kinds] : null;
    // Garbled accents hide control characters inside them ("’" read as
    // Latin-1 is "â" plus two); stripping those first would lose the accent.
    if (requested && requested.includes(INVISIBLE_CHARACTERS)) {
        requested.push(BROKEN_ACCENTS);
    }

    // One repair can reveal another (a decoded entity may itself be
    // garbled), so repeat until the text settles.
    let result = String(content ?? '');
    for (let round = 0; round < 3; round += 1) {
        const before = result;
        for (const kind of KIND_ORDER) {
            if (!KINDS[kind].fixable || (requested && !requested.includes(kind))) continue;
            result = fixKind(kind, result, format);
        }
        if (result === before) break;
    }

    return result;
}

/** Whether any of the given kinds (safe fixes by default) would change this content. */
export function needsBioFix(content, format = FORMAT_HTML, kinds = SAFE_FIXES) {
    return fixBioText(content, format, kinds) !== String(content ?? '');
}

// ─── Detection ───────────────────────────────────────────────────────────────

function detect(kind, content, format) {
    switch (kind) {
        case BROKEN_ACCENTS: return detectByWords(content, format, repairMojibake);
        case LOST_CHARACTERS: return detectMatches(content, format, () => /[^ \t\n\r\f\v]*�[^ \t\n\r\f\v]*/gu, () => /�/gu);
        case ESCAPED_HTML: return detectEscapedHtml(content, format);
        case MARKDOWN: return detectMarkdown(content, format);
        case AI_TEXT: return detectAiText(content);
        case INVISIBLE_CHARACTERS: return detectInvisible(content, format);
        case EMOJI: return detectMatches(content, format, EMOJI_PATTERN);
        default: return { count: 0, samples: [] };
    }
}

function detectByWords(content, format, repair) {
    let count = 0;
    const samples = [];

    for (const segment of textSegments(content, format)) {
        const text = format === FORMAT_HTML ? decodeLatinEntities(segment) : segment;
        const [, changes] = repair(text);
        if (changes === 0) continue;
        count += changes;

        // Pair up the words that differ; the repair keeps word boundaries.
        const words = phpTrim(text).split(/[ \t\r\n]+/u);
        for (const word of words) {
            const fixedWord = repair(`${word} `)[0].replace(/ +$/u, '');
            if (fixedWord !== word) addSample(samples, word, fixedWord);
        }
    }

    return { count, samples };
}

function detectMatches(content, format, pattern, countPattern = null) {
    let count = 0;
    const samples = [];

    for (const segment of textSegments(content, format)) {
        count += (segment.match((countPattern ?? pattern)()) || []).length;
        for (const match of segment.match(pattern()) || []) {
            addSample(samples, excerpt(match), null);
        }
    }

    return { count, samples };
}

function detectEscapedHtml(content, format) {
    let count = 0;
    const samples = [];

    for (const segment of textSegments(content, format)) {
        const pattern = format === FORMAT_HTML ? escapedHtmlPattern() : literalHtmlPattern();
        for (const match of segment.match(pattern) || []) {
            const fixed = fixEscapedHtml(match, format);
            if (fixed === match) continue;
            count += 1;
            addSample(samples, match, phpTrim(fixed) === '' ? null : fixed);
        }
    }

    return { count, samples };
}

function detectMarkdown(content, format) {
    let count = 0;
    const samples = [];

    for (const segment of textSegments(content, format)) {
        for (const pattern of MARKDOWN_SAMPLES) {
            for (const match of segment.match(pattern()) || []) {
                count += 1;
                const fixed = phpTrim(stripMarkdown(match)[0]);
                addSample(samples, excerpt(match), fixed === '' ? null : excerpt(fixed));
            }
        }
    }

    return { count, samples };
}

function detectAiText(content) {
    let count = 0;
    const samples = [];

    for (const pattern of AI_PATTERNS) {
        for (const raw of content.match(pattern()) || []) {
            const match = phpTrim(raw.replace(/^[>\n]+/u, ''));
            if (match === '') continue;
            count += 1;
            addSample(samples, excerpt(match), null);
        }
    }

    return { count, samples };
}

function detectInvisible(content, format) {
    let count = 0;
    const names = new Set();

    for (const segment of textSegments(content, format)) {
        for (const char of segment.match(INVISIBLE()) || []) {
            count += 1;
            names.add(invisibleName(char));
        }
        if (format === FORMAT_HTML) {
            for (const entity of segment.match(INVISIBLE_ENTITY()) || []) {
                count += 1;
                names.add(invisibleName(decodeEntity(entity) ?? '\u200B'));
            }
        }
    }

    const samples = [];
    names.forEach((name) => addSample(samples, name, null));

    return { count, samples };
}

// ─── Fixes ───────────────────────────────────────────────────────────────────

function fixKind(kind, content, format) {
    switch (kind) {
        case BROKEN_ACCENTS:
            return mapSegments(content, format, (segment) => {
                const text = format === FORMAT_HTML ? decodeLatinEntities(segment) : segment;
                const [fixed, changes] = repairMojibake(text);
                // Untouched segments keep their original entity spelling.
                return changes > 0 ? fixed : segment;
            });
        case ESCAPED_HTML:
            return mapSegments(content, format, (segment) => {
                const pattern = format === FORMAT_HTML ? escapedHtmlPattern() : literalHtmlPattern();
                return segment.replace(pattern, (match) => fixEscapedHtml(match, format));
            });
        case MARKDOWN:
            return mapSegments(content, format, (segment) => stripMarkdown(segment)[0]);
        case INVISIBLE_CHARACTERS:
            return mapSegments(content, format, (segment) => {
                const stripped = segment.replace(INVISIBLE(), '');
                return format === FORMAT_HTML ? stripped.replace(INVISIBLE_ENTITY(), '') : stripped;
            });
        case EMOJI:
            return mapSegments(content, format, (segment) => {
                let stripped = segment.replace(EMOJI_PATTERN(), '');
                if (stripped === segment) return segment;
                // Joiners that held the removed emoji together have nothing left to join.
                stripped = stripped.split(String.fromCharCode(0x200D)).join('');
                // Close the gap an emoji leaves between words, and before punctuation.
                stripped = stripped.replace(/[ \t]{2,}/gu, ' ');
                return stripped.replace(/[ \t]+([.,!?;:])/gu, '$1');
            });
        default:
            return content;
    }
}

/**
 * Undo UTF-8 text that was decoded as Latin-1 or Windows-1252, up to three
 * layers deep. Only byte runs that form valid UTF-8 for a character a bio
 * could plausibly contain are replaced, so real accents are never touched.
 */
function repairMojibake(input) {
    let text = input;
    let total = 0;

    for (let pass = 0; pass < 3; pass += 1) {
        let changes = 0;
        text = text.replace(MOJIBAKE_SEQUENCE(), (match) => {
            const decoded = decodeSequence(match);
            if (decoded === null) return match;
            changes += 1;
            return decoded;
        });
        if (changes === 0) break;
        total += changes;
    }

    // Forms left once a non-breaking space or an unassigned byte was lost
    // on the way: "Ã " was "à", a stray "Â " was a space, a lone "â€" was ”.
    text = text.replace(LONE_A_TILDE(), () => { total += 1; return 'à'; });
    text = text.replace(LONE_A_CIRCUMFLEX(), () => { total += 1; return ''; });
    text = text.replace(LONE_QUOTE(), () => { total += 1; return String.fromCharCode(0x201D); });

    // A lone C1 control is a single Windows-1252 byte read as Latin-1:
    // U+0092 was "’", U+0080 was "€". Unassigned bytes are left for the
    // invisible-character check.
    text = text.replace(C1_CONTROL(), (char) => {
        const byte = char.codePointAt(0);
        for (const [cp, value] of CP1252) {
            if (value === byte) {
                total += 1;
                return String.fromCodePoint(cp);
            }
        }
        return char;
    });

    return [text, total];
}

const utf8 = typeof TextDecoder !== 'undefined' ? new TextDecoder('utf-8', { fatal: true }) : null;

function decodeSequence(chars) {
    const bytes = [];
    for (const char of Array.from(chars)) {
        const cp = char.codePointAt(0);
        if (cp <= 0xFF) bytes.push(cp);
        else if (CP1252.has(cp)) bytes.push(CP1252.get(cp));
        else return null;
    }

    let decoded;
    try {
        decoded = utf8.decode(new Uint8Array(bytes));
    } catch {
        return null;
    }

    const points = Array.from(decoded);
    if (points.length !== 1) return null;

    return isPlausible(points[0].codePointAt(0)) ? decoded : null;
}

/** Characters a bio could have meant: accents, punctuation, symbols, emoji. */
function isPlausible(cp) {
    return (cp >= 0xA0 && cp <= 0x17F)
        || (cp >= 0x600 && cp <= 0x6FF)
        || (cp >= 0x2000 && cp <= 0x206F)
        || (cp >= 0x20A0 && cp <= 0x20CF)
        || (cp >= 0x2100 && cp <= 0x215F)
        || (cp >= 0x2190 && cp <= 0x21FF)
        || (cp >= 0x2600 && cp <= 0x27BF)
        || (cp >= 0xFB00 && cp <= 0xFB06)
        || cp === 0xFE0F || cp === 0xFEFF || cp === 0xFFFD
        || (cp >= 0x1F000 && cp <= 0x1FAFF);
}

function stripMarkdown(input) {
    let text = input;
    let count = 0;

    for (const [pattern, replacement] of MARKDOWN_RULES) {
        text = text.replace(pattern(), (...args) => {
            count += 1;
            return replacement === '$1' ? args[1] : replacement;
        });
    }

    return [text, count];
}

function escapedHtmlPattern() {
    return /&amp;(?:#\d{2,6}|#x[0-9a-f]{2,5}|[a-z][a-z0-9]{1,7});|&lt;\/?(?:p|br|a|strong|em|b|i|u|ul|ol|li|h[1-6]|span|div)(?![\p{L}\p{N}_])(?:(?!&gt;)[^<>\n]){0,200}&gt;/giu;
}

function literalHtmlPattern() {
    return /&(?:#\d{2,6}|#x[0-9a-f]{2,5}|[a-z][a-z0-9]{1,7});|<\/?(?:p|br|a|strong|em|b|i|u|ul|ol|li|h[1-6]|span|div)(?![\p{L}\p{N}_])[^<>\n]{0,200}>/giu;
}

function fixEscapedHtml(match, format) {
    if (format === FORMAT_HTML) {
        // One level of escaping too many: show the entity or tag as intended.
        if (match.startsWith('&amp;')) return `&${match.slice(5)}`;
        const map = { '&lt;': '<', '&gt;': '>', '&quot;': '"', '&#39;': "'", '&apos;': "'", '&amp;': '&' };
        return match.replace(/&lt;|&gt;|&quot;|&#39;|&apos;|&amp;/g, (entity) => map[entity]);
    }

    if (match[0] === '&') return decodeEntity(match) ?? match;

    // Plain text shows tags literally; keep the line breaks they meant.
    if (/^<br(?![\p{L}\p{N}_])/iu.test(match)) return '\n';
    return /^<\/(?:p|div|li|h[1-6])(?![\p{L}\p{N}_])/iu.test(match) ? '\n\n' : '';
}

// ─── Text helpers ────────────────────────────────────────────────────────────

function textSegments(content, format) {
    if (format !== FORMAT_HTML) return [content];

    return content.split(/(<[^>]*>)/u).filter((part) => part !== '' && part[0] !== '<');
}

function mapSegments(content, format, callback) {
    if (format !== FORMAT_HTML) return callback(content);

    return content
        .split(/(<[^>]*>)/u)
        .map((part) => (part === '' || part[0] === '<' ? part : callback(part)))
        .join('');
}

/** Turn Latin-1/Windows-1252 entities into characters so garbled runs can be read. */
function decodeLatinEntities(text) {
    if (!text.includes('&')) return text;

    return text.replace(/&(?:#(\d{2,6})|#x([0-9a-f]{2,5})|([a-z][a-z0-9]{1,7}));/gi, (entity) => {
        const decoded = decodeEntity(entity);
        if (decoded === null) return entity;
        const cp = decoded.codePointAt(0);
        return (cp >= 0x80 && cp <= 0xFF) || CP1252.has(cp) ? decoded : entity;
    });
}

function decodeEntity(entity) {
    let cp = null;
    let match;
    if ((match = /^&#(\d+);$/.exec(entity))) {
        cp = parseInt(match[1], 10);
    } else if ((match = /^&#x([0-9a-f]+);$/i.exec(entity))) {
        cp = parseInt(match[1], 16);
    } else if ((match = /^&([a-z][a-z0-9]*);$/i.exec(entity))) {
        const name = match[1];
        const latin = LATIN1_ENTITIES.indexOf(name);
        cp = latin !== -1 ? 0xA0 + latin : (CP1252_ENTITIES[name] ?? BASIC_ENTITIES[name] ?? null);
        if (cp === null) return null;
    } else {
        return null;
    }

    return cp > 0 && cp <= 0x10FFFF && (cp < 0xD800 || cp > 0xDFFF) ? String.fromCodePoint(cp) : null;
}

function invisibleName(char) {
    const cp = char.codePointAt(0);
    if (cp === 0xAD) return 'Soft hyphen';
    if (cp === 0x200B) return 'Zero-width space';
    if (cp === 0x200C || cp === 0x200D) return 'Zero-width joiner';
    if (cp === 0x200E || cp === 0x200F || (cp >= 0x202A && cp <= 0x202E) || (cp >= 0x2066 && cp <= 0x2069)) return 'Text direction mark';
    if (cp === 0xFEFF) return 'Byte-order mark';
    if (cp >= 0x2060 && cp <= 0x2064) return 'Word joiner';
    return 'Control character';
}

/** PHP's trim(): ASCII whitespace and NUL only, never the non-breaking space. */
function phpTrim(value) {
    return value.replace(/^[ \t\n\r\0\x0B]+|[ \t\n\r\0\x0B]+$/gu, '');
}

function excerpt(text, length = 80) {
    const collapsed = phpTrim(text.replace(/[ \t\n\r\f\v]+/gu, ' '));
    const chars = Array.from(collapsed);
    return chars.length > length ? `${chars.slice(0, length - 1).join('').replace(/[ \t\n\r\0\x0B]+$/u, '')}…` : collapsed;
}

function addSample(samples, found, fixed) {
    if (samples.length >= MAX_SAMPLES) return;
    if (samples.some((sample) => sample.found === found)) return;
    samples.push({ found, fixed });
}
