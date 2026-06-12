import { describe, it, expect } from 'vitest';
import fr from '../../resources/js/languages/fr.json';
import en from '../../resources/js/languages/en.json';

/**
 * [W-REM T-R2.1 2026-06-12] Parité section `label` en → fr.
 *
 * Dette mesurée GOAL PRODUCTION TOTALE §0.1 : 163 clés `label.*` présentes
 * dans en.json et ABSENTES de fr.json. Toute surface FR qui référence une de
 * ces clés rend le fallback EN (ou la clé brute selon la config i18n).
 * Heal : miroir mécanique en→fr (traduction FR propre, sans polissage
 * rédactionnel des intitulés payment-gateway hors-scope V1).
 *
 * Sentinelle ratchet : 0 clé manquante — toute future clé en.json ajoutée
 * sans son miroir fr.json casse ce spec (modèle studioFrontendI18nParity).
 *
 * NOTE : les clés fr orphelines (présentes en fr, absentes de en) sont
 * VOLONTAIREMENT tolérées ici — beaucoup sont des clés FR-first légitimes
 * (kds_*, ws_session_*, composer…). Liste documentée au rapport R2 ; leur
 * arbitrage (backport en.json vs suppression) = décision séparée, pas de
 * suppression aveugle.
 */

describe('i18n parity en→fr — section label (T-R2.1)', () => {
    it('every en.json label.* key exists in fr.json (0 missing)', () => {
        const missing = Object.keys(en.label).filter(
            (k) => !(k in fr.label)
        );
        expect(
            missing,
            `fr.json label section is missing ${missing.length} key(s): ${missing.slice(0, 20).join(', ')}${missing.length > 20 ? '…' : ''}`
        ).toEqual([]);
    });

    it('no fr label.* value is an empty string (mechanical mirror must not blank out)', () => {
        const blank = Object.keys(fr.label).filter(
            (k) => typeof fr.label[k] === 'string' && fr.label[k].trim() === ''
        );
        expect(blank).toEqual([]);
    });
});
