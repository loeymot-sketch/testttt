/**
 * Sentinel — T-WC-KIOSK-REGISTRY-01 (cycle CV1-WIZARD-COMPOSABLE-001).
 *
 * Garde-fou source-level sur le registre explicite step_key→component dans
 * `KioskWizardComponent.vue`. L'audit Axe A.4 #1 a découvert que l'heuristique
 * sous-chaîne pré-existante droppait silencieusement tout step dont le
 * step_key/label/source_type ne contenait aucun mot-clé connu (ex.
 * `step_key='dessert'` était droppé parce qu'aucun mot-clé "menu" n'était
 * présent — alors que `dessert` doit être un step menu addon-based).
 *
 * Ce test vérifie au niveau source que :
 *   1. Le registre `STEP_KEY_REGISTRY` figé existe.
 *   2. Les step_keys standards sont mappés vers leur composant.
 *   3. La fonction `resolveExplicitStepType` est exposée au niveau module.
 *   4. La priorité `addon_role` est bien évaluée AVANT `step_key`.
 *   5. Un step inconnu sans choices loggue un warning et retourne `null`
 *      (pas de fallback récap qui casserait l'UX du wizard).
 *
 * Source-level (lecture fichier) — évite le mount complet du composant Vue
 * (1900+ lignes, runtime asynchrone, dépendances Pinia/router non triviales)
 * et reste cohérent avec les autres sentinels FoodKing du même style
 * (cf. `posWizardComposerProfile.spec.js`, `kioskWizardComposerProfile.spec.js`).
 */
import { describe, it, expect } from 'vitest';
import { readFileSync } from 'fs';
import { resolve } from 'path';

const SOURCE = readFileSync(
    resolve(process.cwd(), 'resources/js/components/frontend/kiosk/KioskWizardComponent.vue'),
    'utf8',
);

describe('KioskWizardComponent — step registry (T-WC-KIOSK-REGISTRY-01)', () => {
    it('exposes STEP_KEY_REGISTRY constant frozen at module level', () => {
        expect(SOURCE).toMatch(/STEP_KEY_REGISTRY\s*=\s*Object\.freeze/);
    });

    it('registry maps standard step keys to their component types', () => {
        expect(SOURCE).toMatch(/pain:\s*['"]pain['"]/);
        expect(SOURCE).toMatch(/viande:\s*['"]viande['"]/);
        expect(SOURCE).toMatch(/sauce:\s*['"]sauce['"]/);
        expect(SOURCE).toMatch(/garnitures:\s*['"]garnitures['"]/);
        expect(SOURCE).toMatch(/supplements:\s*['"]supplements['"]/);
        expect(SOURCE).toMatch(/menu:\s*['"]menu['"]/);
        expect(SOURCE).toMatch(/taille:\s*['"]taille['"]/);
        expect(SOURCE).toMatch(/dessert:\s*['"]menu['"]/);
    });

    it('exposes resolveExplicitStepType function at module level', () => {
        expect(SOURCE).toMatch(/function resolveExplicitStepType/);
    });

    it('uses addon_role priority before step_key in resolveExplicitStepType', () => {
        expect(SOURCE).toMatch(/addonRole.*&&.*ADDON_ROLE_TO_TYPE/s);

        const resolverStart = SOURCE.indexOf('function resolveExplicitStepType');
        expect(resolverStart).toBeGreaterThan(-1);

        const addonRoleIdx = SOURCE.indexOf('ADDON_ROLE_TO_TYPE[addonRole]', resolverStart);
        const stepKeyIdx = SOURCE.indexOf('STEP_KEY_REGISTRY[stepKey]', resolverStart);
        expect(addonRoleIdx).toBeGreaterThan(-1);
        expect(stepKeyIdx).toBeGreaterThan(-1);
        expect(addonRoleIdx).toBeLessThan(stepKeyIdx);
    });

    it('logs warning + returns null when step has no kind match and no choices', () => {
        expect(SOURCE).toMatch(/console\.warn.*kiosk-wizard\.composer.*step skipped/s);
        expect(SOURCE).toMatch(/return null/);

        expect(SOURCE).not.toMatch(/if \(haystack\.includes\('pain'\)/);
        expect(SOURCE).not.toMatch(/if \(haystack\.includes\('viande'\)/);
    });
});
