/**
 * Sentinel — CV1-WIZARD-COMPOSABLE-001 T-WC-POS-RUNTIME-01.
 *
 * Verrouille le code path composer-aware ajouté à public/js/pos-wizard.js
 * (5832 lignes vanilla IIFE). Test source-level robuste — évite la complexité
 * d'instancier l'IIFE complète avec mock DOM modal.
 *
 * Pattern aligné avec autres sentinels source-level du projet
 * (ex: kioskWizardStepRegistry.spec.js).
 *
 * Plan : plans/PLAN_CV1-WIZARD-COMPOSABLE-ADMIN-V1-2026-05-02.md Phase D.
 * Audit : reports/audit/CV1_WIZARD_AXE_A4_KIOSK_PAGE_DECOMPOSITION_2026-05-02.md
 *         (gap fermé : POS pos-wizard.js avait 0 ref composer avant ce fix).
 */

import { describe, it, expect } from 'vitest';
import { readFileSync } from 'fs';
import { resolve } from 'path';

const SOURCE = readFileSync(
    resolve(__dirname, '../../public/js/pos-wizard.js'),
    'utf8'
);

describe('pos-wizard composer-aware path (T-WC-POS-RUNTIME-01)', () => {
    it('exposes isComposerAwareEnabled helper reading window.foodkingConfig', () => {
        expect(SOURCE).toMatch(/function isComposerAwareEnabled/);
        expect(SOURCE).toMatch(/window\.foodkingConfig\s*\.\s*posWizardComposerAware/);
    });

    it('exposes getComposerProfileFromData helper that requires non-empty steps array', () => {
        expect(SOURCE).toMatch(/function getComposerProfileFromData/);
        expect(SOURCE).toMatch(/Array\.isArray\(profile\.steps\)/);
    });

    it('exposes buildStepsFromComposerProfile helper with addon_role priority before step_key', () => {
        expect(SOURCE).toMatch(/function buildStepsFromComposerProfile/);
        // addon_role check must appear before step_key fallback
        const fnMatch = SOURCE.match(/function buildStepsFromComposerProfile[\s\S]*?return result;\s*\}/);
        expect(fnMatch).toBeTruthy();
        const body = fnMatch[0];
        const addonIdx = body.indexOf('COMPOSER_ADDON_ROLE_MAP');
        const stepKeyIdx = body.indexOf('COMPOSER_STEP_KEY_MAP');
        expect(addonIdx).toBeGreaterThan(0);
        expect(stepKeyIdx).toBeGreaterThan(0);
        expect(addonIdx).toBeLessThan(stepKeyIdx);
    });

    it('buildStepsFromComposerProfile filters visible_on (skip when pos not in array)', () => {
        expect(SOURCE).toMatch(/visible_on[\s\S]{0,80}indexOf\(['"]pos['"]\)\s*===\s*-1/);
    });

    it('buildStepsFromComposerProfile falls back to generic_choices when step has choices but no kind match', () => {
        expect(SOURCE).toMatch(/internalType\s*=\s*['"]generic_choices['"]/);
    });

    it('buildSteps has early-return composer-aware path before legacy logic', () => {
        // The composer early-return must appear BEFORE the legacy var s = [], var category = detectCategory pattern
        const buildStepsMatch = SOURCE.match(/function buildSteps\(data\)\s*\{[\s\S]*?detectCategory\(data\)/);
        expect(buildStepsMatch).toBeTruthy();
        const body = buildStepsMatch[0];
        expect(body).toMatch(/isComposerAwareEnabled\(\)/);
        expect(body).toMatch(/getComposerProfileFromData\(data\)/);
        expect(body).toMatch(/buildStepsFromComposerProfile/);
        // Verify early-return order: composer check appears before legacy `var s = [];`
        const composerCheckIdx = body.indexOf('isComposerAwareEnabled');
        const legacyVarIdx = body.indexOf('var s = []');
        expect(composerCheckIdx).toBeGreaterThan(0);
        expect(legacyVarIdx).toBeGreaterThan(composerCheckIdx);
    });

    it('logs warning + skips step when no kind match and no choices (degraded gracefully)', () => {
        expect(SOURCE).toMatch(/console\.warn[\s\S]{0,200}pos-wizard\.composer[\s\S]{0,80}step skipped/);
    });
});
