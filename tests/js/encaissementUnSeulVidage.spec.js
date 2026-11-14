import { describe, it, expect } from 'vitest';
import fs from 'node:fs';
import path from 'node:path';

/**
 * [INTÉGRATION 2026-10-03] Un seul chemin pour vider les commandes des jours passés.
 *
 * Deux sessions avaient livré chacune leur version sur le même écran d'encaissement :
 *  · 29/09 — bouton d'en-tête « Nettoyer les jours passés » (stale-count / cancel-stale) ;
 *  · 02/10 — onglet « Jours précédents » + « Tout purger » (purge-previous), décision
 *    propriétaire R-061 : un nouveau jour démarre avec une liste vide.
 * Fusionnées, elles affichaient DEUX boutons pour la même action, partageaient la donnée
 * `purging` (déclarée deux fois) et la classe `.enc-purge-btn`. Le bouton du 29/09 est retiré ;
 * ce banc empêche son retour et garde la confirmation motivée du chemin restant.
 */
const VUE = path.resolve(__dirname, '../../resources/js/components/admin/encaissement/EncaissementComponent.vue');
const src = fs.readFileSync(VUE, 'utf8');

describe('Encaissement — un seul chemin de vidage des jours passés', () => {
    it('n\'a plus l\'ancien bouton ni ses appels', () => {
        for (const ancien of ['enc-purge-stale', 'purgeStale', 'refreshStaleCount', 'stale-count', 'cancel-stale', 'purgeArmed']) {
            expect(src.includes(ancien), `« ${ancien} » ne doit plus exister`).toBe(false);
        }
    });

    it('déclare l\'état `purging` une seule fois', () => {
        expect(src.match(/\bpurging: false,/g) || []).toHaveLength(1);
    });

    it('garde l\'onglet « Jours précédents » et sa purge confirmée avec un motif', () => {
        expect(src).toContain('data-testid="enc-scope-previous"');
        expect(src).toContain('data-testid="enc-purge-all"');
        expect(src).toMatch(/axios\.post\('admin\/pos\/counter-collect\/purge-previous'/);
        expect(src).toMatch(/confirm: true,/);
        expect(src).toMatch(/purgeReason\.trim\(\)\.length < 3/);
    });
});
