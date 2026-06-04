/**
 * [UR1-002 V1.0.2 Wave B1] Phone-display sanitizer — frontend SSOT.
 *
 * Mirrors `App\Support\PhoneDisplay::safe` (PHP backend SSOT, Ultra-Review
 * heal commit afc094091). Centralizes the policy that drove the cluster of
 * 9+ duplicated inline ternaries:
 *
 *   `phone && !String(phone).startsWith('PENDING_') ? phone : ''`
 *
 * across BackendNavbar / MessageList / CreditBalanceReport / ProfileEdit /
 * KitchenDisplaySystem (4 occurrences) / OnlineOrderReceipt /
 * FrontendOrderReceipt / KdsOrderCard / Vuex auth module (Wave A1).
 *
 * Root cause: `App\Models\User::creating` injects sentinel placeholders
 * (`PENDING_<id>`, `PENDING_CREATE_<hex>`) into the `phone` column for
 * legacy user-bootstrap paths that have no real phone at creation time
 * (admin seeders, soak-test accounts, walk-in customer rows, loyalty
 * stubs). These sentinels MUST NOT leak to any client surface — admin
 * shell, receipts, tel: hrefs, KDS cards, profile forms.
 *
 * Two exports to match two call patterns:
 *
 *   - `safePhone(phone)` returns a SAFE STRING (empty '' when sentinel /
 *     null / undefined). Use in Vue templates where `phone ? ... : ''`
 *     gets a falsy-but-string-safe value that won't break concatenation
 *     with country_code, won't render "null" in DOM, and stays
 *     compatible with `v-if="safePhone(x)"` truthiness checks.
 *
 *   - `sanitizePendingPhone(user)` returns a SHALLOW-CLONED user object
 *     with `phone` rewritten to `null` if it carried the sentinel. Use
 *     at write boundaries (Vuex mutations, localStorage rehydrate) where
 *     the consumer is downstream JS code expecting null absence, not
 *     empty-string presence.
 *
 * @see app/Support/PhoneDisplay.php
 */

/**
 * Return the phone string IF it is real, '' (empty string) otherwise.
 *
 * - null  → ''
 * - undefined → ''
 * - '' → ''
 * - 'PENDING_<…>' → ''
 * - 'PENDING_CREATE_<…>' → ''
 * - '+33612345678' → '+33612345678'
 *
 * Returns '' (not null) so Vue template concatenation
 * `country_code + safePhone(x)` won't render "null" / "undefined"
 * and so `v-if="safePhone(x)"` evaluates falsy on sentinel input.
 *
 * @param {string|null|undefined} phone
 * @returns {string}
 */
export function safePhone(phone) {
    if (phone === null || phone === undefined || phone === '') {
        return '';
    }
    if (String(phone).startsWith('PENDING_')) {
        return '';
    }
    return String(phone);
}

/**
 * [Wave A1] Strip PENDING_ sentinel from a user payload object.
 *
 * Returns a shallow-cloned user with `phone` set to `null` if the
 * sentinel was present. Used at every write boundary in the Vuex
 * `auth` module (`authLogin`, `authRefresh`, `authInfo` mutations) so
 * the polluted sentinel never lands in vuex-persistedstate's
 * localStorage snapshot.
 *
 * Returns null phone (not '') to keep downstream JS distinguishing
 * "no phone known" from "empty string present" — Vue templates that
 * read the result still treat both as falsy.
 *
 * @param {Object|null|undefined} user
 * @returns {Object|null|undefined}
 */
export function sanitizePendingPhone(user) {
    if (!user || typeof user !== 'object') {
        return user;
    }
    const phone = user.phone;
    if (typeof phone === 'string' && phone.startsWith('PENDING_')) {
        return { ...user, phone: null };
    }
    return user;
}
