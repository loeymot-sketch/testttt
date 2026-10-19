import { describe, it, expect, beforeEach } from 'vitest';
import { mount } from '@vue/test-utils';
import fs from 'node:fs';
import path from 'node:path';
import KdsOrderCard from '../../resources/js/components/admin/kitchenDisplaySystem/KdsOrderCard.vue';
import KdsV2Grid from '../../resources/js/components/admin/kitchenDisplaySystem/KdsV2Grid.vue';
import {
    modeTabletteActif,
    fichesParEcranTablette,
    KDS_CLE_TABLETTE,
    appareilTablette,
} from '../../resources/js/helpers/kdsTablette.js';

/**
 * [KDS-TABLETTE 2026-10-19 · owner] Second poste de cuisine (pain, sauces, crudités) sur une tablette de
 * 7-8" en paysage : l'écran ne montre QUE les commandes — ni barre du haut, ni menu, ni logo, ni boutons,
 * ni « Prêt ». Le GRAND écran reste exactement comme avant.
 *
 * Deux risques à verrouiller :
 *   1. le mode tablette ne doit JAMAIS s'activer tout seul (l'écran principal tourne lui aussi en 1280×720) ;
 *   2. aucune information de la commande ne doit disparaître de la fiche tablette.
 */

const RACINE = path.resolve(__dirname, '../..');
const lire = (p) => fs.readFileSync(path.join(RACINE, p), 'utf8');

const MQ = (tactile, souris = false) => (q) => ({
    matches: (q === '(pointer: coarse)' && tactile) || (q === '(hover: none)' && tactile && !souris),
});
function fenetre({ search = '', stocke = null, stockageCasse = false, tactile = false, ecran = null } = {}) {
    const mem = new Map(stocke === null ? [] : [[KDS_CLE_TABLETTE, stocke]]);
    return {
        location: { search },
        matchMedia: MQ(tactile),
        screen: ecran || { width: 1920, height: 1080 },
        localStorage: stockageCasse
            ? { getItem() { throw new Error('quota'); }, setItem() { throw new Error('quota'); }, removeItem() { throw new Error('quota'); } }
            : {
                getItem: (k) => (mem.has(k) ? mem.get(k) : null),
                setItem: (k, v) => mem.set(k, String(v)),
                removeItem: (k) => mem.delete(k),
            },
        _mem: mem,
    };
}

describe('mode tablette — activation explicite ou appareil tablette, jamais par la taille de la fenêtre', () => {
    it('par défaut : le grand écran (rien n\'est activé)', () => {
        expect(modeTabletteActif(fenetre())).toBe(false);
    });

    it('?tablette=1 active ET mémorise sur l\'appareil', () => {
        const w = fenetre({ search: '?tablette=1' });
        expect(modeTabletteActif(w)).toBe(true);
        expect(w._mem.get(KDS_CLE_TABLETTE)).toBe('1');
        // Adresse nue ensuite : le poste se souvient.
        expect(modeTabletteActif({ ...w, location: { search: '' } })).toBe(true);
    });

    it('?tablette=0 désactive ET mémorise le refus (la détection ne le réactive pas)', () => {
        const w = fenetre({ search: '?tablette=0', stocke: '1', tactile: true, ecran: { width: 800, height: 1280 } });
        expect(modeTabletteActif(w)).toBe(false);
        expect(w._mem.get(KDS_CLE_TABLETTE)).toBe('0');
        expect(modeTabletteActif({ ...w, location: { search: '' } })).toBe(false);
    });

    it('stockage indisponible : le paramètre vaut pour la page, sinon grand écran (jamais de plantage)', () => {
        expect(modeTabletteActif(fenetre({ search: '?tablette=1', stockageCasse: true }))).toBe(true);
        expect(modeTabletteActif(fenetre({ stockageCasse: true }))).toBe(false);
    });

    it('sans fenêtre (rendu serveur / test) : grand écran', () => {
        expect(modeTabletteActif(null)).toBe(false);
    });

    it('tablette détectée toute seule : tactile sans souris + petit écran physique', () => {
        expect(modeTabletteActif(fenetre({ tactile: true, ecran: { width: 600, height: 960 } }))).toBe(true);
        expect(modeTabletteActif(fenetre({ tactile: true, ecran: { width: 1280, height: 800 } }))).toBe(true);
        // Détection non mémorisée : si l'appareil change, la décision change.
        const w = fenetre({ tactile: true, ecran: { width: 600, height: 960 } });
        modeTabletteActif(w);
        expect(w._mem.has(KDS_CLE_TABLETTE)).toBe(false);
    });

    it('le GRAND écran n\'est jamais pris pour une tablette (souris, ou grand écran physique)', () => {
        // PC à souris, même petite résolution 1280×720
        expect(modeTabletteActif(fenetre({ tactile: false, ecran: { width: 1280, height: 720 } }))).toBe(false);
        // grand écran tactile sans souris (borne, téléviseur) : écran physique trop grand
        expect(modeTabletteActif(fenetre({ tactile: true, ecran: { width: 1920, height: 1080 } }))).toBe(false);
    });

    it('PC tactile AVEC souris (survol disponible) et petite résolution : grand écran, pas tablette', () => {
        const w = { ...fenetre({ ecran: { width: 1280, height: 720 } }), matchMedia: MQ(true, true) };
        expect(modeTabletteActif(w)).toBe(false);
    });

    it('la mémoire « 1 » ou « 0 » prime sur la détection', () => {
        expect(modeTabletteActif(fenetre({ stocke: '1' }))).toBe(true);
        expect(modeTabletteActif(fenetre({ stocke: '0', tactile: true, ecran: { width: 600, height: 960 } }))).toBe(false);
    });

    it('détection impossible (matchMedia / screen absents ou en erreur) : grand écran', () => {
        expect(appareilTablette({})).toBe(false);
        expect(appareilTablette({ matchMedia() { throw new Error('x'); }, screen: { width: 600, height: 900 } })).toBe(false);
        expect(appareilTablette({ matchMedia: MQ(true), screen: { width: NaN, height: NaN } })).toBe(false);
    });

    it('la largeur de la FENÊTRE n\'entre pas dans la détection (le grand écran fait aussi 1280×720)', () => {
        const src = lire('resources/js/helpers/kdsTablette.js');
        const corps = src.slice(src.indexOf('export function appareilTablette'), src.indexOf('export function fichesParEcranTablette'));
        expect(/innerWidth|innerHeight|outerWidth|clientWidth/.test(corps)).toBe(false);
    });
});

describe('mode tablette — nombre de fiches selon la largeur réelle', () => {
    it('autant que possible sans descendre sous la largeur lisible', () => {
        expect(fichesParEcranTablette(1280)).toBe(3);
        expect(fichesParEcranTablette(1024)).toBe(2);
        expect(fichesParEcranTablette(960)).toBe(2);
        expect(fichesParEcranTablette(800)).toBe(2);
        expect(fichesParEcranTablette(1920)).toBe(5);
    });

    it('jamais moins d\'une fiche, valeur absurde → 2', () => {
        expect(fichesParEcranTablette(300)).toBe(1);
        expect(fichesParEcranTablette(0)).toBe(2);
        expect(fichesParEcranTablette(NaN)).toBe(2);
        expect(fichesParEcranTablette(undefined)).toBe(2);
    });
});

const $t = (k) => k;
const ORDRE = {
    id: 9001,
    queue_number: 'A0101',
    status: 4,
    source_surface: 'kiosk',
    created_at_iso: new Date(Date.now() - 4 * 60_000).toISOString(),
    order_items: [],
};
const carte = (props = {}) => mount(KdsOrderCard, {
    props: { order: ORDRE, now: Date.now(), ...props },
    global: { stubs: { KdsOrderLine: true }, mocks: { $t } },
});

describe('fiche tablette — pas de « Prêt », pas de réimpression, le reste intact', () => {
    it('grand écran : « Prêt » et réimpression présents (inchangé)', () => {
        const w = carte();
        expect(w.find('[data-testid="kds-card-cta-ready"]').exists()).toBe(true);
        expect(w.find('[data-testid="kds-card-reprint"]').exists()).toBe(true);
        expect(w.classes()).not.toContain('kds-card--tablette');
    });

    it('tablette : ni « Prêt » ni réimpression, mais n° / attente / état / source restent', () => {
        const w = carte({ tablette: true });
        expect(w.find('[data-testid="kds-card-cta-ready"]').exists()).toBe(false);
        expect(w.find('[data-testid="kds-card-reprint"]').exists()).toBe(false);
        expect(w.find('.kds-card__footer').exists()).toBe(false);
        expect(w.classes()).toContain('kds-card--tablette');
        expect(w.find('.kds-card__queue').text()).toContain('A0101');
        expect(w.find('.kds-card__elapsed').exists()).toBe(true);
        expect(w.find('.kds-card__state-pill').exists()).toBe(true);
        expect(w.find('.kds-card__source-chip').exists()).toBe(true);
    });

    it('tablette : la touche Entrée ne valide pas la commande (aucune validation sur ce poste)', async () => {
        const w = carte({ tablette: true });
        await w.trigger('keydown.enter');
        expect(w.emitted('ready')).toBeUndefined();
        const g = carte();
        await g.trigger('keydown.enter');
        expect(g.emitted('ready')).toBeTruthy(); // le grand écran, lui, valide toujours
    });

    it('tablette : la note « en attente d\'encaissement » ne prend plus de place', () => {
        const w = carte({ tablette: true, order: { ...ORDRE, payment_pending_counter: true } });
        expect(w.find('[data-testid="kds-card-cash-pending"]').exists()).toBe(false);
        const g = carte({ order: { ...ORDRE, payment_pending_counter: true } });
        expect(g.find('[data-testid="kds-card-cash-pending"]').exists()).toBe(true);
    });

    it('tablette : l\'ALLERGIE reste affichée (sécurité alimentaire)', () => {
        const src = lire('resources/js/components/admin/kitchenDisplaySystem/KdsOrderCard.vue');
        const tpl = src.slice(src.indexOf('<template>'), src.indexOf('</template>\n\n<script>'));
        expect(tpl).toContain('v-if="hasAllergen"');
        // … et aucune règle tablette ne la masque.
        const css = src.slice(src.indexOf('.kds-card--tablette {'));
        expect(/kds-card--tablette[^{]*allergen[^{]*\{[^}]*display:\s*none/.test(css)).toBe(false);
    });
});

describe('grille tablette — commandes seules', () => {
    const grille = (props = {}) => mount(KdsV2Grid, {
        props: { orders: [ORDRE, { ...ORDRE, id: 9002, queue_number: 'A0102' }], ...props },
        global: { stubs: { KdsOrderLine: true, KdsStatusBanner: true }, mocks: { $t } },
    });

    it('grand écran : sélecteur de colonnes présent', () => {
        expect(grille().find('.kds-cols-picker').exists()).toBe(true);
    });

    it('tablette : ni sélecteur, ni boutons de défilement, ni bande « servies », ni « Prêt »', () => {
        const w = grille({ tablette: true, orders: [ORDRE, { ...ORDRE, id: 9002 }, { ...ORDRE, id: 9003 }, { ...ORDRE, id: 9004 }, { ...ORDRE, id: 9005 }, { ...ORDRE, id: 9006 }, { ...ORDRE, id: 9007 }, { ...ORDRE, id: 9008 }] });
        expect(w.find('.kds-cols-picker').exists()).toBe(false);
        expect(w.find('.kds-scroll-btn').exists()).toBe(false);
        expect(w.find('.kds-v2__served').exists()).toBe(false);
        expect(w.find('[data-testid="kds-card-cta-ready"]').exists()).toBe(false);
        expect(w.classes()).toContain('kds-v2--tablette');
        // aucun raccourci [A]… sur les fiches
        expect(w.find('.kds-card__shortcut').exists()).toBe(false);
    });

    it('tablette : les raccourcis clavier [A]–[H] ne valident rien', async () => {
        const w = grille({ tablette: true });
        window.dispatchEvent(new KeyboardEvent('keydown', { key: 'a' }));
        expect(w.emitted('change-status')).toBeUndefined();
        w.unmount();
    });

    it('tablette : le nombre de colonnes vient de la largeur de la fenêtre', async () => {
        const w = grille({ tablette: true });
        w.vm.largeurFenetre = 1280;
        await w.vm.$nextTick();
        expect(w.find('.kds-v2__grid').attributes('data-cols')).toBe('3');
        w.vm.largeurFenetre = 960;
        await w.vm.$nextTick();
        expect(w.find('.kds-v2__grid').attributes('data-cols')).toBe('2');
    });
});

describe('écran cuisine — le mode tablette retire la coquille, et seulement lui', () => {
    const cuisine = lire('resources/js/components/admin/kitchenDisplaySystem/KitchenDisplaySystemComponent.vue');
    const css = lire('resources/css/app.css');
    const routes = lire('resources/js/router/index.js');

    it('barre de boutons, légende, historique, rupture et programmées : masqués en tablette', () => {
        expect(cuisine).toMatch(/<div v-if="!modeTablette" class="kds-history-trigger-row kds-toolbar"/);
        expect(cuisine).toContain('v-show="showSymbolLegend && !modeTablette"');
        expect(cuisine).toMatch(/<KdsHistoryDrawer\s+v-if="!modeTablette"/);
        expect(cuisine).toMatch(/<AvailabilityTogglePanel\s+v-if="!modeTablette"/);
        expect(cuisine).toContain('<KdsScheduledBanner v-if="!modeTablette"');
    });

    it('le bandeau « RETIRER DU PASSE » (annulation) RESTE visible en tablette', () => {
        expect(cuisine).toContain('<KdsCanceledBanner :entries="kdsRecentlyCanceled" />');
    });

    it('les bandeaux routiniers (secours, polling, pastilles locales) sont coupés, pas les ERREURS', () => {
        expect(cuisine).toContain(':fallback-mode="!modeTablette && !wsConnected && !kdsSuppressFallbackBanner"');
        expect(cuisine).toContain(':admin-polling-hint="!modeTablette && kdsIsCentralAdmin"');
        expect(cuisine).toContain(':bump-local-only-notice="!modeTablette && showBumpInfo && !kdsHideBumpInfo"');
        // l'erreur applicative, l'hors-ligne et la synchro incertaine restent câblés sans condition tablette
        expect(cuisine).toContain(':error-message="kdsErrorBanner.visible ? kdsErrorBanner.message : \'\'"');
        expect(cuisine).toContain(':offline-since="v2OfflineSince"');
        expect(cuisine).toContain(':sync-uncertain="syncBadgeUncertain"');
    });

    it('le mode ne vaut que pour la mise en page V2 (le secours 4 colonnes reste intact)', () => {
        expect(cuisine).toMatch(/modeTablette\(\)\s*\{\s*return this\.tablette && this\.useV2Layout;/);
    });

    it('les classes posées au montage sont retirées au démontage (rien ne fuit sur les autres pages)', () => {
        expect(cuisine).toContain("classList?.add('db-main--kds-tablette')");
        expect(cuisine).toContain("classList?.add('kds-tablette')");
        expect(cuisine).toContain("classList?.remove('db-main--kds-tablette')");
        expect(cuisine).toContain("classList?.remove('kds-tablette')");
    });

    it('CSS : barre du haut et menu masqués, marges à zéro — uniquement sous html.kds-tablette', () => {
        expect(css).toMatch(/html\.kds-tablette \.db-header,\s*html\.kds-tablette \.db-sidebar\s*\{\s*display:\s*none\s*!important/);
        expect(css).toMatch(/html\.kds-tablette \.db-main\.db-main--kds-tablette\s*\{[^}]*padding:\s*0\s*!important/);
        // la règle ne s'applique jamais sans la classe de mode : aucune règle nue sur .db-header
        expect(/(^|\n)\s*\.db-header[^{]*\{[^}]*display:\s*none/.test(css)).toBe(false);
    });

    it('CSS : la page ne défile jamais en tablette, la pastille de connexion se réduit à un point', () => {
        expect(css).toMatch(/html\.kds-tablette,\s*html\.kds-tablette body\s*\{\s*overflow:\s*hidden\s*!important/);
        expect(css).toMatch(/\[data-testid="connexion-pastille"\]\[data-etat="ok"\] \.cx-texte\s*\{\s*display:\s*none/);
    });

    it('adresse courte /kds-tablette : même écran, mode mémorisé', () => {
        expect(routes).toMatch(/path:\s*"\/kds-tablette",\s*redirect:\s*\{\s*name:\s*"admin\.kitchen-display-system",\s*query:\s*\{\s*tablette:\s*"1"/);
    });

    beforeEach(() => { /* sources statiques : rien à réinitialiser */ });
});
