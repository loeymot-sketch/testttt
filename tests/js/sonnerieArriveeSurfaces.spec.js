import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest';
import { readFileSync } from 'node:fs';
import { resolve } from 'node:path';
import PosComponent from '../../resources/js/components/admin/pos/PosComponent.vue';
import KitchenDisplaySystemComponent from '../../resources/js/components/admin/kitchenDisplaySystem/KitchenDisplaySystemComponent.vue';
import { SONNERIES_PAR_ARRIVEE, INTERVALLE_ENTRE_SONNERIES_MS } from '../../resources/js/helpers/orderArrivalChime.js';

/**
 * [OWNER 2026-08-19] « ajoute une sonnerie lors de commande qui arrive » → 3 sonneries
 * espacées, puis stop, sur les surfaces du personnel.
 *
 * Ce banc vérifie le CÂBLAGE de chaque surface. Le rythme lui-même est couvert par
 * `orderArrivalChime.spec.js` ; ici on prouve que personne ne le réimplémente dans son coin,
 * et que chaque surface émet bien un son audible.
 */
describe('sonnerie d’arrivée — câblage des surfaces', () => {
    beforeEach(() => vi.useFakeTimers());
    afterEach(() => vi.useRealTimers());

    const lire = (chemin) => readFileSync(resolve(process.cwd(), chemin), 'utf8');

    const SURFACES = [
        ['caisse',          'resources/js/components/admin/pos/PosComponent.vue'],
        ['suivi commandes', 'resources/js/components/admin/pos/PosOrdersTrackerComponent.vue'],
        ['écran cuisine',   'resources/js/components/admin/kitchenDisplaySystem/KitchenDisplaySystemComponent.vue'],
        ['écran de statut', 'resources/js/components/admin/orderStatusScreen/PreparingAndReadyComponent.vue'],
    ];

    it.each(SURFACES)('%s : emprunte le rythme partagé au lieu de le réécrire', (_nom, chemin) => {
        const src = lire(chemin);
        expect(src, 'la surface doit importer le séquenceur commun').toMatch(
            /import \{[^}]*creerSequenceurDeSonnerie[^}]*\} from ["'][^"']*orderArrivalChime["']/
        );
        // L'ancien rythme était écrit en dur, deux fois, dans deux fichiers différents.
        expect(src, 'plus aucun rythme codé en dur : il vivrait à côté du partagé et divergerait')
            .not.toMatch(/\[\s*10000\s*,\s*20000\s*\]/);
    });

    it.each(SURFACES)('%s : annule ses sonneries en attente au démontage', (_nom, chemin) => {
        const src = lire(chemin);
        expect(src, 'une minuterie qui survit au composant joue sur un élément détruit')
            .toMatch(/_sequenceurSonnerie[\s\S]{0,40}annuler/);
    });

    /**
     * LA CAISSE N'AVAIT QU'UN SINUS DE 0,4 s, structurellement inaudible derrière un comptoir
     * en service. Elle joue désormais le carillon MP3 déjà livré pour l'écran cuisine — un seul
     * son à reconnaître dans tout le restaurant, aucun fichier de plus à déployer.
     */
    describe('caisse — vraie sonnerie, bip de synthèse en repli', () => {
        function ctxCaisse(audio) {
            return {
                $refs: audio ? { posNewOrderAudio: audio } : {},
                _playNewOrderBeep: vi.fn(),
                _emettreSonnerie: PosComponent.methods._emettreSonnerie,
            };
        }

        it('joue le carillon MP3 quand il est disponible, et PAS le bip par-dessus', () => {
            const audio = { currentTime: 7, play: vi.fn(() => Promise.resolve()) };
            const c = ctxCaisse(audio);

            PosComponent.methods._emettreSonnerie.call(c);

            expect(audio.play).toHaveBeenCalledTimes(1);
            expect(audio.currentTime, 'rembobiné, sinon la 2e sonnerie ne joue rien').toBe(0);
            expect(c._playNewOrderBeep, 'le repli ne doit pas s’ajouter au carillon').not.toHaveBeenCalled();
        });

        it('retombe sur le bip si l’autoplay refuse le carillon', async () => {
            // Minuteries RÉELLES ici : le repli passe par le `.catch()` d'une promesse, donc
            // par la file des micro-tâches — que les minuteries simulées n'écoulent pas.
            vi.useRealTimers();
            const audio = { currentTime: 0, play: vi.fn(() => Promise.reject(new Error('autoplay'))) };
            const c = ctxCaisse(audio);

            PosComponent.methods._emettreSonnerie.call(c);
            await new Promise((r) => setTimeout(r, 0));

            expect(c._playNewOrderBeep, 'mieux vaut un son faible que pas de son').toHaveBeenCalledTimes(1);
        });

        it('retombe sur le bip si l’élément audio est absent du DOM', () => {
            const c = ctxCaisse(null);
            PosComponent.methods._emettreSonnerie.call(c);
            expect(c._playNewOrderBeep).toHaveBeenCalledTimes(1);
        });

        it('retombe sur le bip si play() lève au lieu de rejeter', () => {
            const audio = { currentTime: 0, play: vi.fn(() => { throw new Error('boom'); }) };
            const c = ctxCaisse(audio);
            PosComponent.methods._emettreSonnerie.call(c);
            expect(c._playNewOrderBeep).toHaveBeenCalledTimes(1);
        });
    });

    /**
     * PIÈGE PROPRE À LA CUISINE : elle a un anti-rafale de 2,5 s. Si le séquenceur rappelait la
     * méthode PUBLIQUE, ce seuil avalerait les 2e et 3e sonneries et la commande ne serait
     * annoncée qu'une fois — exactement le défaut qu'on corrige. Le séquenceur doit appeler
     * l'émission BRUTE.
     */
    it('écran cuisine : l’anti-rafale ne doit pas étouffer les 2e et 3e sonneries', () => {
        const el = { volume: 0, currentTime: 5, play: vi.fn(() => Promise.resolve()) };
        const c = {
            soundEnabled: true,
            soundVolume: 80,
            kdsAudioUnlocked: true,
            kdsAudioBlockedHint: false,
            _kdsLastNewOrderSoundAt: null,
            $refs: { kdsNewOrderAudio: el },
            _emettreCarillonKds: KitchenDisplaySystemComponent.methods._emettreCarillonKds,
        };

        KitchenDisplaySystemComponent.methods.playKdsNewOrderSound.call(c);
        expect(el.play).toHaveBeenCalledTimes(1);

        vi.advanceTimersByTime(INTERVALLE_ENTRE_SONNERIES_MS);
        expect(el.play, '2e sonnerie — l’anti-rafale de 2,5 s ne doit pas la bloquer').toHaveBeenCalledTimes(2);

        vi.advanceTimersByTime(INTERVALLE_ENTRE_SONNERIES_MS);
        expect(el.play).toHaveBeenCalledTimes(SONNERIES_PAR_ARRIVEE);

        vi.advanceTimersByTime(60000);
        expect(el.play, 'puis stop').toHaveBeenCalledTimes(SONNERIES_PAR_ARRIVEE);
    });

    it('écran cuisine : le réglage « son coupé » bloque toute la séquence, pas juste la 1re', () => {
        const el = { volume: 0, currentTime: 0, play: vi.fn(() => Promise.resolve()) };
        const c = {
            soundEnabled: false,
            soundVolume: 80,
            _kdsLastNewOrderSoundAt: null,
            $refs: { kdsNewOrderAudio: el },
            _emettreCarillonKds: KitchenDisplaySystemComponent.methods._emettreCarillonKds,
        };

        KitchenDisplaySystemComponent.methods.playKdsNewOrderSound.call(c);
        vi.advanceTimersByTime(60000);
        expect(el.play).not.toHaveBeenCalled();
    });
});
