import { describe, expect, it } from 'vitest';
import { readFileSync } from 'node:fs';
import { resolve } from 'node:path';

/**
 * @FK-ID WT-R1-F2-MENU-I18N | @source Wave T Round 1 F2 P1 cluster
 * @reason
 *   The backend sidebar seeder (database/seeders/MenuTableSeeder.php) emits 5
 *   parent-group headers whose `language` field is consumed client-side by
 *   `BackendMenuComponent.vue` as `$t('menu.' + menu.language)`:
 *     - communications, users, accounts, reports, setup
 *   Before this fix, those 5 keys were missing from every locale JSON, leaving
 *   the admin sidebar to render the raw fallback strings ("menu.communications"
 *   etc.) AND flooding the browser console with
 *     `[intlify] Not found 'menu.<key>' in 'fr' locale messages.`
 *   warnings for every page navigation. This sentinel locks the 5 keys in for
 *   FR (the canonical NF525 surface locale) and additionally pins parity in EN,
 *   AR, BN and DE so the admin sidebar reads cleanly in every shipped locale.
 *
 * Failure scenarios:
 *   - someone removes a key from fr.json (admin sidebar starts leaking again)
 *   - someone renames the language token in MenuTableSeeder.php without adding
 *     the matching i18n key
 */
const LOCALES = ['fr', 'en', 'ar', 'bn', 'de'];
const REQUIRED_MENU_KEYS = [
    'communications',
    'users',
    'accounts',
    'reports',
    'setup',
];

function loadLocale(code) {
    const path = resolve(process.cwd(), `resources/js/languages/${code}.json`);
    return JSON.parse(readFileSync(path, 'utf8'));
}

describe('menu.* parent-group i18n keys (Wave T R1 F2 sentinel)', () => {
    for (const code of LOCALES) {
        describe(`locale=${code}`, () => {
            const json = loadLocale(code);

            it(`exposes a top-level "menu" object`, () => {
                expect(json).toHaveProperty('menu');
                expect(typeof json.menu).toBe('object');
                expect(json.menu).not.toBeNull();
            });

            for (const key of REQUIRED_MENU_KEYS) {
                it(`defines menu.${key} as a non-empty string`, () => {
                    expect(json.menu).toHaveProperty(key);
                    const value = json.menu[key];
                    expect(typeof value).toBe('string');
                    expect(value.trim().length).toBeGreaterThan(0);
                });
            }
        });
    }
});
