/**
 * [dispute-r1 D-007 2026-06-12] — téléphone saisi au numpad fidélité NON
 * reporté dans le formulaire d'inscription.
 * -----------------------------------------------------------------------------
 * Round-1 adversarial (D-borne-robustesse, d4c-03-register-prefill.png) :
 * après « Non trouvé » + S'inscrire, le champ TÉLÉPHONE* restait VIDE alors
 * que le client venait de taper son numéro au numpad (registerPhone: ''
 * jamais affecté depuis `code`).
 *
 * Invariants :
 *  1. goToRegister pré-remplit registerPhone quand le code saisi ressemble à
 *     un téléphone (chiffres/espaces/+, 6-15 chiffres).
 *  2. Un code fidélité alphanumérique n'est JAMAIS reporté dans le champ
 *     téléphone.
 *  3. Une saisie déjà présente dans registerPhone n'est pas écrasée.
 */
import { describe, it, expect } from 'vitest';
import KioskLoyaltyComponent from '../../resources/js/components/frontend/kiosk/KioskLoyaltyComponent.vue';

function makeVm({ code = '', registerPhone = '' } = {}) {
    const vm = { code, registerPhone, step: 'input' };
    vm.goToRegister = KioskLoyaltyComponent.methods.goToRegister.bind(vm);
    return vm;
}

describe('[D-007] pré-remplissage téléphone inscription fidélité', () => {
    it('numéro tapé au numpad → reporté dans registerPhone', () => {
        const vm = makeVm({ code: '0612345678' });
        vm.goToRegister();
        expect(vm.registerPhone).toBe('0612345678');
        expect(vm.step).toBe('register');
    });

    it('numéro avec espaces / +33 → reporté tel que saisi', () => {
        const vm = makeVm({ code: '+33 6 12 34 56 78' });
        vm.goToRegister();
        expect(vm.registerPhone).toBe('+33 6 12 34 56 78');
    });

    it('code fidélité alphanumérique → PAS reporté dans le champ téléphone', () => {
        const vm = makeVm({ code: 'VICT1234' });
        vm.goToRegister();
        expect(vm.registerPhone).toBe('');
        expect(vm.step).toBe('register');
    });

    it('saisie trop courte (pas un téléphone) → pas de report', () => {
        const vm = makeVm({ code: '123' });
        vm.goToRegister();
        expect(vm.registerPhone).toBe('');
    });

    it('registerPhone déjà saisi → jamais écrasé', () => {
        const vm = makeVm({ code: '0612345678', registerPhone: '0700000000' });
        vm.goToRegister();
        expect(vm.registerPhone).toBe('0700000000');
    });
});
