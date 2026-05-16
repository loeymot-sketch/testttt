/**
 * kioskFrLockImmutable.spec.js
 * -----------------------------------------------------------------------------
 * [ADR-007 / iter15-P1a] Régression FR-lock kiosk immutable.
 *
 * Vérifie qu'aucun composant kiosk ne réintroduit un appel runtime à
 * `setLocale(...)` qui contredirait la politique ADR-007 (KIOSK_LOCALE='fr',
 * resources/js/i18n.js).
 *
 * Couverture :
 *  1. Sources : `KioskIdleScreenComponent.vue` et `KioskAppComponent.vue` ne
 *     contiennent plus d'appel `setLocale(...)` ni d'import `setLocale` depuis
 *     `i18n`.
 *  2. Boot : après `applyKioskA11yFromStore` avec un store par défaut, la
 *     locale i18n reste 'fr'.
 *  3. Voice/Speech : aucun assignment `<obj>.lang = '<non-fr>'` codé en dur
 *     dans les composants kiosk (forward-guard pour futures additions). Les
 *     constantes `'fr-FR'` (Intl, recognition, utter.lang) restent acceptées.
 *
 * Ne touche pas à `useKioskA11y.js` (hors scope) ni à `useKioskSpeech.js`
 * (gère déjà sa locale via paramètre, pas via i18n global).
 */

import { readFileSync, readdirSync, statSync } from 'node:fs';
import { dirname, join } from 'node:path';
import { fileURLToPath } from 'node:url';
import { afterEach, describe, expect, it } from 'vitest';

import { applyKioskA11yFromStore } from '../../resources/js/composables/useKioskA11y.js';
import i18n, { setLocale, getCurrentLocale } from '../../resources/js/i18n.js';

const __filenameSpec = fileURLToPath(import.meta.url);
const __dirnameSpec  = dirname(__filenameSpec);
const REPO_ROOT      = join(__dirnameSpec, '..', '..');
const KIOSK_DIR      = join(REPO_ROOT, 'resources/js/components/frontend/kiosk');

const TARGETS_NO_SETLOCALE = [
    join(KIOSK_DIR, 'KioskIdleScreenComponent.vue'),
    join(KIOSK_DIR, 'KioskAppComponent.vue'),
    // [Sprint 3D 2026-05-16] KsA11ySettings.vue ne doit plus dispatcher
    // `kioskSettings/setLocale` ni rendre de radiogroup langue (K-001 P0).
    join(KIOSK_DIR, 'ds', 'KsA11ySettings.vue'),
];

/**
 * Strip JS/Vue comments avant scan : on tolère les références dans les
 * commentaires explicatifs (`// ni setLocale(), ni dispatch...`) sans pour
 * autant relâcher la garde sur le code exécutable.
 */
function stripComments(src) {
    // Block comments /* ... */
    let out = src.replace(/\/\*[\s\S]*?\*\//g, '');
    // Line comments // ...
    out = out.replace(/(^|[^:'"`])\/\/[^\n]*/g, '$1');
    return out;
}

function listKioskComponentFiles() {
    const out = [];
    function walk(dir) {
        for (const entry of readdirSync(dir)) {
            const full = join(dir, entry);
            const st   = statSync(full);
            if (st.isDirectory()) walk(full);
            else if (full.endsWith('.vue') || full.endsWith('.js')) out.push(full);
        }
    }
    walk(KIOSK_DIR);
    return out;
}

describe('[ADR-007] FR-lock kiosk immutable — sources', () => {
    it('KioskIdleScreenComponent.vue + KioskAppComponent.vue + KsA11ySettings.vue : aucun appel `setLocale(` (hors commentaires)', () => {
        for (const file of TARGETS_NO_SETLOCALE) {
            const rawSrc = readFileSync(file, 'utf8');
            // On exige : ni import nommé `setLocale` depuis i18n, ni appel
            // exécutable `setLocale(...)`. Les commentaires sont stripés pour
            // tolérer les références explicatives (« ni setLocale() »).
            expect(
                /from\s+['"][^'"]*\/i18n['"][^;]*\bsetLocale\b/.test(rawSrc)
                || /import\s*\{[^}]*\bsetLocale\b[^}]*\}\s*from\s*['"][^'"]*\/i18n['"]/.test(rawSrc),
                `${file} importe encore \`setLocale\` depuis i18n.`
            ).toBe(false);

            const codeOnly = stripComments(rawSrc);
            // Tolère uniquement les usages NON-exécutables : actions Vuex
            // (`'kioskSettings/setLocale'` / `"kioskSettings/setLocale"`).
            // Tout `setLocale(` restant après strip = appel exécutable runtime.
            const codeWithoutVuexAction = codeOnly.replace(
                /['"`][^'"`]*\/setLocale[^'"`]*['"`]/g,
                ''
            );
            expect(
                /\bsetLocale\s*\(/.test(codeWithoutVuexAction),
                `${file} contient encore un appel exécutable \`setLocale(\` (violation ADR-007).`
            ).toBe(false);
        }
    });

    it('forward-guard voice/speech : aucun assignment `<obj>.lang = "<non-fr>"` en dur dans les composants kiosk', () => {
        // Tolère 'fr-FR' / 'fr' / variables (voice?.lang || locale) — bloque
        // uniquement les littéraux non-FR codés en dur (ex: "en-GB", "ar-SA").
        const offenders = [];
        const re = /\.lang\s*=\s*['"`]([^'"`]+)['"`]/g;
        for (const file of listKioskComponentFiles()) {
            const src = readFileSync(file, 'utf8');
            let m;
            while ((m = re.exec(src)) !== null) {
                const literal = m[1];
                if (!/^fr(-FR)?$/i.test(literal)) {
                    offenders.push(`${file}: .lang = "${literal}"`);
                }
            }
        }
        expect(offenders, `Locale littérale non-FR détectée :\n${offenders.join('\n')}`).toEqual([]);
    });
});

describe('[ADR-007] FR-lock kiosk immutable — runtime', () => {
    afterEach(() => {
        setLocale('fr');
    });

    it('boot kiosk : applyKioskA11yFromStore(store FR) laisse i18n.locale === "fr"', () => {
        // Reset à un état non-FR pour vérifier que la propagation depuis le
        // store FR force bien le retour à 'fr' (et que rien ne le re-bascule).
        setLocale('en');
        expect(getCurrentLocale()).toBe('en');

        const store = {
            state: {
                kioskSettings: {
                    locale: 'fr',
                    contrast: 'aa',
                    pmr: false,
                    audio: false,
                    audioDescription: false,
                    reducedMotion: false,
                },
            },
        };
        applyKioskA11yFromStore(store);
        expect(i18n.global.locale.value).toBe('fr');
        expect(getCurrentLocale()).toBe('fr');
    });

    it('boot kiosk sans locale dans le store : fallback "fr" appliqué (defensive)', () => {
        setLocale('en');
        const store = { state: { kioskSettings: { contrast: 'aa' } } };
        applyKioskA11yFromStore(store);
        expect(i18n.global.locale.value).toBe('fr');
    });
});

describe('[ADR-007 / Sprint 3D 2026-05-16] FR-lock kiosk — UI surface a11y drawer', () => {
    it('KsA11ySettings.vue : aucun radiogroup langue rendu côté source (FR/EN/AR retirés)', () => {
        const file = join(KIOSK_DIR, 'ds', 'KsA11ySettings.vue');
        const src  = readFileSync(file, 'utf8');
        // Le drawer ne doit plus déclarer le wrapper langue ni les data-testid
        // `kiosk-a11y-lang-*`. Un futur dev qui ré-introduirait l'UI sans
        // passer par le feature flag config/kiosk.php se fera attraper ici.
        expect(src).not.toMatch(/data-testid=["']kiosk-a11y-lang-group["']/);
        expect(src).not.toMatch(/['"`]kiosk-a11y-lang-(?:fr|en|ar)['"`]/);
        expect(src).not.toMatch(/'kiosk-a11y-lang-'\s*\+\s*opt\.code/);
        // La constante `LOCALE_OPTIONS` ne doit plus exister non plus.
        expect(src).not.toMatch(/const\s+LOCALE_OPTIONS\s*=/);
    });

    it('store/index.js : `kioskSettings.locale` retiré de createPersistedState (FR-lock après reload)', () => {
        // Sprint 3D restaure l'invariant ADR-007 contre un localStorage hérité :
        // si l'iter15 antérieur a persisté `kioskSettings.locale="ar"`, ce ne
        // doit plus être réinjecté au boot. La protection vit dans la liste
        // `paths` de createPersistedState : l'absence de la clé garantit que
        // chaque reload réinitialise le store sur le default FR (cf.
        // `state.locale = 'fr'` dans kioskSettings module).
        const storeFile = join(REPO_ROOT, 'resources/js/store/index.js');
        const src       = readFileSync(storeFile, 'utf8');

        // Extrait le bloc `paths: [...]` pour scanner uniquement la liste
        // persistée (et pas les commentaires ADR-007 explicatifs qui peuvent
        // mentionner la clé pour documenter sa suppression). On strip d'abord
        // les commentaires pour éviter qu'un `]` à l'intérieur d'un commentaire
        // (ex: `[AUDIT-P1]`) ne ferme prématurément la capture non-greedy.
        const srcNoComments = stripComments(src);
        const pathsMatch    = srcNoComments.match(/paths\s*:\s*\[([\s\S]*?)\]/);
        expect(pathsMatch, 'createPersistedState paths array introuvable').not.toBeNull();
        const pathsBlock = pathsMatch[1];

        // On ne tolère pas la chaîne entre guillemets — uniquement les
        // commentaires (// ...) sont autorisés à mentionner la clé.
        expect(
            /["']kioskSettings\.locale["']/.test(pathsBlock),
            'kioskSettings.locale est encore persisté (regression ADR-007 / Sprint 3D)'
        ).toBe(false);

        // Sanity-check : les autres clés a11y restent persistées (on ne casse
        // pas le storage des préférences contrast/pmr/audio par effet de bord).
        expect(pathsBlock).toMatch(/["']kioskSettings\.contrast["']/);
        expect(pathsBlock).toMatch(/["']kioskSettings\.pmr["']/);
    });

    it('config/kiosk.php : `locale_switch_allowed` feature flag exposé, default false', () => {
        const cfg = readFileSync(join(REPO_ROOT, 'config/kiosk.php'), 'utf8');
        // La clé doit être présente (les deux branches de retour : requireForm
        // ET le retour standard) et l'env var doit défaulter à false.
        expect(cfg).toMatch(/'locale_switch_allowed'\s*=>\s*\$localeSwitchAllowed/);
        expect(cfg).toMatch(/KIOSK_LOCALE_SWITCH_ALLOWED'?\s*,\s*false/);
    });
});

describe('[ADR-007] FR-lock kiosk — voice recognition lang constant', () => {
    it('useKioskSpeech.js : Web Speech utter.lang dérive d\'une voix ou de la locale (pas de littéral non-FR)', () => {
        // Forward-guard : on relit le fichier pour s'assurer qu'aucune
        // littéralisation 'en-US'/'ar-SA' n'a été introduite côté speech kiosk.
        const speechFile = join(REPO_ROOT, 'resources/js/composables/useKioskSpeech.js');
        const src = readFileSync(speechFile, 'utf8');
        const re = /\.lang\s*=\s*['"`]([^'"`]+)['"`]/g;
        const offenders = [];
        let m;
        while ((m = re.exec(src)) !== null) {
            const literal = m[1];
            if (!/^fr(-FR)?$/i.test(literal)) {
                offenders.push(`useKioskSpeech.js: .lang = "${literal}"`);
            }
        }
        expect(offenders).toEqual([]);
    });
});
