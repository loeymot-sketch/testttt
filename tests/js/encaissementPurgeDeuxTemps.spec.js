import { describe, it, expect } from 'vitest';
import fs from 'node:fs';
import path from 'node:path';

/**
 * [AUDIT CAISSE 2026-09-29 · P1] « Nettoyer les jours passés » exige DEUX clics.
 *
 * La première version comptait au montage (`mounted` → refreshStaleCount) : `staleCount`
 * était déjà un nombre quand le caissier arrivait, le bouton s'affichait ARMÉ d'emblée,
 * et le PREMIER clic annulait — alors que le docbloc et le CSS promettaient deux temps.
 * L'annulation est irréversible (CANCELED + REFUNDED, résurrection interdite).
 *
 * Mesuré en navigateur après correctif : 1er clic → 0 POST ; clic isolé après 4 s → 0 ;
 * deux clics rapprochés → 1 POST. Ce banc fige la STRUCTURE qui garantit ce comportement,
 * sur le composant réellement servi (même convention que les autres bancs de cet écran).
 */
const VUE = path.resolve(__dirname, '../../resources/js/components/admin/encaissement/EncaissementComponent.vue');
const source = () => fs.readFileSync(VUE, 'utf8');

function corpsDePurgeStale(src) {
    const i = src.indexOf('purgeStale() {');
    if (i === -1) return null;
    // jusqu'à la méthode suivante déclarée au même niveau
    const fin = src.indexOf('\n        fetchPending(', i);
    return fin === -1 ? src.slice(i) : src.slice(i, fin);
}

describe('encaissement — nettoyage des jours passés en deux temps', () => {
    it('le premier clic ARME et sort avant tout POST', () => {
        const corps = corpsDePurgeStale(source());
        expect(corps, 'purgeStale() introuvable').not.toBeNull();

        const iArme = corps.indexOf('if (!this.purgeArmed)');
        const iPost = corps.indexOf("axios.post('admin/pos/counter-collect/cancel-stale'");
        expect(iArme, 'la branche d\'armement a disparu : un seul clic annulerait').toBeGreaterThan(-1);
        expect(iPost).toBeGreaterThan(-1);
        expect(iArme, 'l\'armement doit précéder le POST').toBeLessThan(iPost);

        const brancheArmement = corps.slice(iArme, iPost);
        expect(/this\.purgeArmed = true/.test(brancheArmement)).toBe(true);
        expect(/return;/.test(brancheArmement), 'le premier clic doit SORTIR sans poster').toBe(true);
    });

    it('l\'armement expire (4 s) — un clic isolé ne peut pas rester armé indéfiniment', () => {
        const corps = corpsDePurgeStale(source());
        expect(/setTimeout\(\(\) => \{ this\.purgeArmed = false; \}, 4000\)/.test(corps)).toBe(true);
    });

    it('le rouge et le mot « Confirmer » suivent purgeArmed, pas « a-t-on déjà compté »', () => {
        const src = source();
        const i = src.indexOf('data-testid="enc-purge-stale"');
        expect(i).toBeGreaterThan(-1);
        const bouton = src.slice(src.lastIndexOf('<button', i), src.indexOf('</button>', i));
        expect(/:class="purgeArmed \?/.test(bouton), 'la classe armée doit être liée à purgeArmed').toBe(true);
        expect(/staleCount === null \? 'enc-purge-btn--idle' : 'enc-purge-btn--armed'/.test(bouton),
            'RÉGRESSION : armé dès que le comptage a eu lieu = armé au chargement').toBe(false);
        expect(/v-else-if="purgeArmed">Confirmer/.test(bouton)).toBe(true);
    });
});
