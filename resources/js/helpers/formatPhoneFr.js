/**
 * [W-REM T-R2.3 Q-8/D-B3-03 2026-06-12] Shared FR phone renderer.
 *
 * Root cause D-B3-03: every team/customer list rendered
 * `(x.country_code || '') + x.phone` — a naive concat that keeps the
 * national leading 0 after the +33 prefix ("+330600000003" = invalid
 * international form), and renders prefix-less numbers inconsistently.
 *
 * Contract:
 *   FR numbers   → national grouped by 2: "06 00 00 00 03"
 *                  (inputs accepted: "0600000003", "600000003" with cc=33,
 *                   "+330600000003", "33600000003")
 *   non-FR       → "<+cc> <national-without-leading-0>": "+32 471234567"
 *   empty/odd    → '' for empty; unformattable digits returned as-is
 *                  (never "+330…", never "undefined").
 *
 * V1 LOCAL Le Cayenne is FR-only (ADR-007) — FR is the happy path, the
 * non-FR branch only exists so stray data never renders glued garbage.
 */

/**
 * @param {string|null|undefined} countryCode - e.g. "+33", "33", "".
 * @param {string|number|null|undefined} phone - raw phone column value.
 * @returns {string} display-ready phone.
 */
export function formatPhoneFr(countryCode, phone) {
    const raw = String(phone ?? '').replace(/[^\d+]/g, '');
    if (!raw) return '';

    const cc = String(countryCode ?? '').trim();
    const ccDigits = cc.replace(/\D/g, '');

    // Normalise any +33/33-international input down to the national form.
    let national = raw;
    if (national.startsWith('+33')) {
        national = '0' + national.slice(3).replace(/^0/, '');
    } else if (ccDigits === '33' || ccDigits === '') {
        if (national.startsWith('33') && national.length === 11) {
            national = '0' + national.slice(2);
        }
    }

    const frContext = ccDigits === '' || ccDigits === '33' || raw.startsWith('+33');
    if (frContext) {
        if (!national.startsWith('0') && national.length === 9) {
            national = '0' + national;
        }
        if (/^0\d{9}$/.test(national)) {
            return national.match(/.{1,2}/g).join(' ');
        }
        // Unformattable (short/odd) — return digits untouched, never glued.
        return national;
    }

    // Non-FR: clean international form, national 0 dropped, space separator.
    const subscriber = national.replace(/^0/, '');
    const prefix = cc.startsWith('+') ? cc : `+${ccDigits}`;
    return `${prefix} ${subscriber}`;
}

/** Vue mixin: adds `this.formatPhoneFr(cc, phone)` to admin components. */
export const adminPhoneMixin = {
    methods: {
        formatPhoneFr,
    },
};

export default formatPhoneFr;
