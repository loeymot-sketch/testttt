/**
 * [LOCK-W6 2026-06-10] Renderer générique composer dans pos-wizard.js (frozen,
 * sous LOCK_POS_WIZARD_GENERIC_RENDER_2026-06-10.md). Sentinel structurel :
 * pinne le périmètre exact du LOCK — toute disparition d'un de ces points
 * = régression du gate. Le comportement run-time est prouvé par l'e2e :8767
 * flag ON (captures + payload), pas par ce spec.
 */
import { describe, it, expect } from 'vitest';
import { readFileSync } from 'fs';
import { resolve } from 'path';

const src = readFileSync(resolve(__dirname, '../../public/js/pos-wizard.js'), 'utf8');

describe('pos-wizard — LOCK-W6 generic composer render', () => {
    it('definit les 4 helpers du LOCK', () => {
        expect(src).toMatch(/function escWiz\(/);
        expect(src).toMatch(/function composerChoicePrice\(/);
        expect(src).toMatch(/function composerAddonTotal\(/);
        expect(src).toMatch(/function renderGenericChoicesStep\(/);
    });

    it('echappe les champs builder (surface moins trusted)', () => {
        expect(src).toMatch(/option-name">' \+ escWiz\(choice\.name\)/);
        expect(src).toMatch(/data-step-key="' \+ escWiz\(step\.key\)/);
    });

    it('mode composer remplace les sections legacy en single-page', () => {
        expect(src).toMatch(/var composerMode = composerSteps\.length > 0/);
        expect(src).toMatch(/if \(!composerMode\) \{/);
        expect(src).toMatch(/generic-section/);
    });

    it('prix joints par id depuis lastItemData, jamais depuis le step (NF525)', () => {
        const fn = src.match(/function composerChoicePrice\([\s\S]*?\n    \}/)[0];
        expect(fn).toContain('lastItemData.extras');
        expect(fn).toContain('lastItemData.addons');
        expect(fn).toContain('lastItemData.variations');
        expect(fn).not.toContain('choice.price');
    });

    it('total provisoire inclut composerAddonTotal (no-op si vide)', () => {
        expect(src).toMatch(/addonTotal \+= composerAddonTotal\(\)/);
    });

    it('gate min_select sur Ajouter au panier (pages obligatoires builder)', () => {
        expect(src).toMatch(/composer min_select gate/);
        expect(src).toMatch(/total < min;/);
    });

    it('ticket cuisine enumere les selections generiques par page', () => {
        expect(src).toMatch(/genericByStep\[m\.stepLabel\]/);
    });

    it('syncAndSubmit mappe extra/addon/variation par nom vers le form Vue', () => {
        const sect = src.match(/4bis\. Builder generic selections[\s\S]*?5\. Set instruction/)[0];
        expect(sect).toContain(".extra .custom-checkbox-field");
        expect(sect).toContain("querySelectorAll('.addon')");
        expect(sect).toContain("dispatchEvent(new Event('change'");
    });

    it('handlers single-page : toggle carte + steppers allow_repeat', () => {
        expect(src).toMatch(/\.wizard-option\[data-type="generic"\]/);
        expect(src).toMatch(/\.generic-qty/);
        expect(src).toMatch(/refreshWizard\(\);/);
    });

    it('flag OFF intouchable : composer_step pose uniquement via buildStepsFromComposerProfile', () => {
        const matches = src.match(/composer_step: step/g) || [];
        expect(matches.length).toBe(1);
    });
});
