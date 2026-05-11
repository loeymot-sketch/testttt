/**
 * KDS: classify free-text instruction lines for *visual* emphasis (no medical decision).
 * Structured allergens_snapshot from the API always wins in the UI if shown separately;
 * this only highlights likely exclusions / hold / allergy *words* in instruction text.
 */

const EXCLUSION_RE = [
    /\b(sans)\s+[\p{L}0-9'’-]+/iu,
    /\b(exclu|retrait|retirer|enlever|enlevez)\b/iu,
    /^\s*sans\s*[,:]/iu,
    /\bhold\b/iu,
    /\bno\s+[\p{L}]+/iu, // "no onion" in EN menus
    /\b(without|w\/o|w\/)\s+[\p{L}]+/iu,
];

/* Prefix-friendly (e.g. allergique, arachide). Arabic forms added for RTL kitchens. */
const ALLERGEN_RE = [
    /\ballerg/iu,
    /\bintol/iu,
    /\bgluten/iu,
    /\barach/iu,
    /\bcacahu/iu,
    /\blactos/iu,
    /\bsoja/iu,
    // [kds/sprint-2 F-6] Arabic allergen prefixes — حساسية (allergy), غلوتين (gluten), لاكتوز (lactose),
    // فول (peanut/bean), مكسرات (nuts), صويا (soy). RTL marker-free for whole-word fallback.
    /حساسي/u,
    /غلوتين/u,
    /لاكتوز/u,
    /\bفول\b/u,
    /مكسرات/u,
    /صويا/u,
];

function matchesAllergen(text) {
    for (const re of ALLERGEN_RE) {
        if (re.test(text)) {
            return true;
        }
    }
    return false;
}

function matchesExclusion(text) {
    for (const re of EXCLUSION_RE) {
        if (re.test(text)) {
            return true;
        }
    }
    return false;
}

/**
 * @param {string|null|undefined} text
 * @returns {boolean}
 */
export function isLikelyExclusionOrHoldInstruction(text) {
    if (text == null || typeof text !== 'string') {
        return false;
    }
    const t = text.trim();
    if (t.length < 2) {
        return false;
    }
    return matchesAllergen(t) || matchesExclusion(t);
}

/**
 * CSS class (scoped in KDS): allergen (strongest) > exclusion / hold > default note
 * @param {string|null|undefined} text
 * @returns {'kds-instruction--allergen' | 'kds-instruction--exclusion' | 'kds-instruction--note'}
 */
export function kdsInstructionVisualClass(text) {
    if (text == null || typeof text !== 'string') {
        return 'kds-instruction--note';
    }
    const t = text.trim();
    if (t.length < 2) {
        return 'kds-instruction--note';
    }
    if (matchesAllergen(t)) {
        return 'kds-instruction--allergen';
    }
    if (matchesExclusion(t)) {
        return 'kds-instruction--exclusion';
    }
    return 'kds-instruction--note';
}
