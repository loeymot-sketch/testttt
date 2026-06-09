/**
 * [Wave 5 — i18n/labels] Role-display localizer — frontend SSOT.
 *
 * The backend `EmployeeResource` returns `role = roles[0]->name`, the raw
 * EN Spatie role name seeded in `database/seeders/RoleTableSeeder.php`
 * ("Admin", "Branch Manager", "Chef", "POS Operator", ...). Those raw EN
 * strings leaked verbatim into the FR admin staff lists. FR labels already
 * exist in `resources/js/languages/fr.json` under `label.*` but were unused.
 *
 * This helper maps a known EN role name → its i18n key, then resolves it via
 * the injected translator. Unknown / unmapped roles fall back to the raw
 * name so nothing ever renders empty.
 *
 * Pure + translator-injected (no Vue `this` binding, no i18n singleton import)
 * so it is trivially unit-testable: pass `t = (k) => k` and assert the key.
 *
 * @see database/seeders/RoleTableSeeder.php (role-name SSOT)
 * @see resources/js/languages/fr.json (label.* FR strings)
 */

/**
 * EN Spatie role name → fr.json `label.*` i18n key.
 * Keys MUST match the seeded role names byte-for-byte.
 */
export const ROLE_LABEL_KEYS = Object.freeze({
    "Admin": "label.admin",
    "Branch Manager": "label.branch_manager",
    "Chef": "label.chef",
    "POS Operator": "label.pos_operator",
    "Customer": "label.customer",
    "Delivery Boy": "label.delivery_boy",
    "Waiter": "label.waiter",
});

/**
 * Resolve a raw EN role name to its localized display string.
 *
 * @param {string|null|undefined} name  Raw EN Spatie role name.
 * @param {(key: string) => string} t   Translator (e.g. vue-i18n `$t`).
 * @returns {string} Localized label, or the raw name for unknown roles, or ''.
 */
export function roleDisplay(name, t) {
    if (name === null || name === undefined || name === "") {
        return "";
    }
    const key = ROLE_LABEL_KEYS[name];
    if (key && typeof t === "function") {
        return t(key);
    }
    return name;
}
