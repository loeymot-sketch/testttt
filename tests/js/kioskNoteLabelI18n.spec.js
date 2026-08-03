import { describe, expect, it } from 'vitest';
import fr from '../../resources/js/languages/fr.json';
import en from '../../resources/js/languages/en.json';

// [DEEP-AUDIT 2026-07-07] KioskWizardComponent.vue:2161 (frozen) fait
// `${$t('label.note')}: ${manualNote}` sur le ticket cuisine borne FR. La clé
// `label.note` (singulier) manquait en FR → vue-i18n renvoyait la clé BRUTE
// « label.note » imprimée sur le ticket. Fix 0-frozen : ajouter la clé i18n
// (le code frozen résout alors correctement). Parité EN déjà présente.
describe('kiosk note label i18n — label.note résout (pas de raw label ticket)', () => {
    it('label.note existe en FR et vaut « Note »', () => {
        expect(fr.label.note).toBe('Note');
    });
    it('parité EN', () => {
        expect(en.label.note).toBe('Note');
    });
    it('label.note != la clé brute (anti-raw-label)', () => {
        expect(fr.label.note).not.toBe('label.note');
    });
});
