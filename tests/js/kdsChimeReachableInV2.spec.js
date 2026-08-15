import { describe, it, expect } from 'vitest';
import { readFileSync } from 'node:fs';
import { resolve } from 'node:path';

/**
 * [T-6.1 CARILLON-MORT-V2 2026-08-15 · GOAL_CONFORT_MAX] Le SEUL élément
 * `<audio ref="kdsNewOrderAudio">` vivait dans la branche `v-else` (legacy)
 * du template — `<KdsV2Grid v-if="useV2Layout" ... />` est un composant
 * ENFANT auto-fermant, aucun `<audio>` sibling n'existait dans la branche V2.
 * Comme `useV2Layout` vaut `true` par défaut (KDS-V2-DEFAULT-ENABLED), l'écran
 * cuisine standard n'avait AUCUN moyen de jouer le carillon nouvelle commande :
 * `playKdsNewOrderSound()` lit `this.$refs.kdsNewOrderAudio`, `undefined` en
 * V2, et fait un no-op silencieux (`if (!el) return;`) — sans erreur, sans
 * signal, le cuisinier ratait juste chaque alerte sonore.
 *
 * Convention : scan du SOURCE en texte (composant de 3500+ lignes, un mount()
 * complet exigerait de mocker un écran entier) — même patron que
 * storePersistedPathsSentinel.spec.js.
 */
const source = readFileSync(
    resolve(process.cwd(), 'resources/js/components/admin/kitchenDisplaySystem/KitchenDisplaySystemComponent.vue'),
    'utf8',
);

describe('KDS — le carillon nouvelle commande est atteignable en V2 (layout par défaut)', () => {
    it('au moins 2 éléments <audio ref="kdsNewOrderAudio"> existent (V2 + legacy)', () => {
        const occurrences = (source.match(/ref="kdsNewOrderAudio"/g) || []).length;
        expect(occurrences, 'un seul <audio ref="kdsNewOrderAudio"> = uniquement dans la branche legacy, invisible en V2').toBeGreaterThanOrEqual(2);
    });

    it('un <audio ref="kdsNewOrderAudio"> existe dans la région rendue par la branche V2 (avant <template v-else>)', () => {
        const v2GridOpenIdx = source.indexOf('<KdsV2Grid');
        const templateElseIdx = source.indexOf('<template v-else>');
        expect(v2GridOpenIdx, 'le composant <KdsV2Grid> doit exister dans le template').toBeGreaterThan(-1);
        expect(templateElseIdx, 'la branche legacy <template v-else> doit exister').toBeGreaterThan(v2GridOpenIdx);

        const v2Region = source.slice(v2GridOpenIdx, templateElseIdx);
        expect(v2Region).toContain('ref="kdsNewOrderAudio"');
        // La garde useV2Layout doit accompagner CET audio (pas un simple élément
        // orphelin toujours monté même quand la grille legacy est active aussi).
        expect(v2Region).toMatch(/<audio[^>]*v-if="useV2Layout"[^>]*ref="kdsNewOrderAudio"/);
    });

    it('la branche legacy garde SON propre <audio ref="kdsNewOrderAudio"> (non-régression : le fallback continue de fonctionner)', () => {
        const templateElseIdx = source.indexOf('<template v-else>');
        const legacyRegion = source.slice(templateElseIdx);
        expect(legacyRegion).toContain('ref="kdsNewOrderAudio"');
    });

    it('playKdsNewOrderSound() ne fait toujours rien silencieusement SI aucun élément monté (garde défensive conservée)', () => {
        // Non-régression de la garde `if (!el) return;` elle-même — utile si
        // jamais aucune branche n'a encore été rendue (état transitoire du mount).
        expect(source).toMatch(/const el = this\.\$refs\.kdsNewOrderAudio;\s*\n\s*if \(!el\) \{\s*\n\s*return;/);
    });
});
