// [GOAL CAISSE CONTRÔLE 2026-09-02] Le tiroir de contrôle des commandes.
//
// CE QUE CE BANC PROUVE — chaque bloc reprend une phrase du propriétaire, et vérifie que
// l'information demandée est RÉELLEMENT RENDUE, pas qu'un composant existe :
//
//   « les commandes qui étaient pas encore encaissées les visualiser »          → §1
//   « voir leur commande pour que j'ai pris le nom lors de la commande »        → §2
//   « toujours voir ce qu'il y a dedans en mode technique avec le nom de
//      produits ainsi que l'heure de commande »                                 → §3
//   « elle est numéro combien par rapport à la cuisine »                        → §4
//   « toutes les commandes qui sont en cuisine visualiser »                     → §5
//   « contrôler toutes les commandes livrés »                                   → §6
//   « je veux pas que ça ouvre une nouvelle page »                              → §7
//
// Les assertions portent sur le TEXTE RENDU chaque fois que c'est possible. Un test qui
// vérifierait la présence d'un `data-testid` prouverait qu'un élément existe, pas qu'un caissier
// peut lire dedans le nom de son client.

import { describe, it, expect, vi } from 'vitest';
import { mount } from '@vue/test-utils';
import fr from '../../resources/js/languages/fr.json';
import PosControlDrawer from '../../resources/js/components/admin/pos/PosControlDrawer.vue';

const MAINTENANT = Date.parse('2026-09-02T12:00:00+02:00');
const ilYA = (minutes) => new Date(MAINTENANT - minutes * 60000).toISOString();

/** Résolution contre le VRAI catalogue : une clé absente revient telle quelle et fait rougir. */
const $t = (cle, params) => {
    let v = fr;
    for (const p of String(cle).split('.')) v = v?.[p];
    if (typeof v !== 'string') return cle;
    return params ? v.replace(/\{(\w+)\}/g, (m, k) => (k in params ? params[k] : m)) : v;
};

const ligne = (nom, quantite = 1, extra = {}) => ({
    item_id: 1, item_name: nom, quantity: quantite,
    options: [], extras: [], addons: [], ...extra,
});

/** Le service semé en audit (`tests/e2e/helpers/seed-caisse-controle.js`). */
const SERVICE = [
    {
        id: 7119, queue_number: 'K1', status: 7, payment_status: 15, order_type: 25,
        source_surface: 'kiosk', is_cash_pending: true, cash_pending_amount: '14.90', total: 14.9,
        created_at: ilYA(14), customer_name: null, customer_phone: null,
        order_items: [ligne('Tacos M', 1, {
            options: [{ label: 'Viande', value: 'Poulet mariné' }, { label: 'Sauce', value: 'Algérienne' }],
            extras: [{ name: 'Cheddar', quantity: 2 }],
        }), ligne('Coca-Cola 33cl', 1)],
    },
    {
        id: 7120, queue_number: 'K2', status: 7, payment_status: 15, order_type: 25,
        source_surface: 'kiosk', is_cash_pending: true, cash_pending_amount: '9.50', total: 9.5,
        created_at: ilYA(9), order_items: [ligne('Cheese Burger', 1)],
    },
    {
        id: 7121, queue_number: 'P1', status: 7, payment_status: 5, order_type: 15,
        source_surface: 'pos', is_cash_pending: false, total: 12,
        created_at: ilYA(6), customer_name: 'Karim', order_items: [ligne('Galette Cayenne', 1)],
    },
    {
        id: 7122, queue_number: 'T1', status: 4, payment_status: 15, order_type: 10,
        source_surface: 'phone', is_cash_pending: true, cash_pending_amount: '22.60', total: 22.6,
        created_at: ilYA(3), customer_name: 'Mme Diallo', customer_phone: '07 98 76 54 32',
        order_items: [ligne('Tacos L', 2), ligne('Grande Frites', 1), ligne('Oasis', 1), ligne('Tiramisu', 1)],
    },
    {
        id: 7123, queue_number: 'R1', status: 8, payment_status: 5, order_type: 25,
        source_surface: 'kiosk', is_cash_pending: false, total: 18,
        created_at: ilYA(16), order_items: [ligne('Bol Riz Gratiné', 1)],
    },
    {
        id: 7124, queue_number: 'R2', status: 8, payment_status: 5, order_type: 15,
        source_surface: 'pos', is_cash_pending: false, total: 22.6,
        created_at: ilYA(11), customer_name: 'Sofiane', customer_phone: '07 98 76 54 32',
        order_items: [ligne('Cayenne', 2), ligne('Grande Frites', 1)],
    },
    {
        id: 7125, queue_number: 'W1', status: 1, payment_status: 10, order_type: 10,
        source_surface: 'web', is_cash_pending: false, total: 30,
        created_at: ilYA(2), customer_name: 'Julie B.', order_items: [ligne('Menu Enfant', 1)],
    },
    {
        id: 7126, queue_number: 'D1', status: 13, payment_status: 5, order_type: 15,
        source_surface: 'pos', is_cash_pending: false, total: 8,
        created_at: ilYA(40), order_items: [ligne('Panini', 1)],
    },
    {
        id: 7127, queue_number: 'D2', status: 13, payment_status: 5, order_type: 25,
        source_surface: 'kiosk', is_cash_pending: false, total: 11,
        created_at: ilYA(30), order_items: [ligne('Wrap', 1)],
    },
];

function monter(props = {}) {
    vi.setSystemTime(MAINTENANT);
    return mount(PosControlDrawer, {
        props: {
            open: true,
            orders: SERVICE,
            anciennesCount: 584,
            lastRefresh: MAINTENANT - 4000,
            tick: 1,
            cartCount: 3,
            cartTotal: 24.8,
            ...props,
        },
        // Le tiroir est TÉLÉPORTÉ dans `<body>` en production (un ancêtre transformé de la caisse
        // cassait sinon `position: fixed`). Le stub le rend en place pour que les assertions
        // portent sur le même arbre — le contenu et le comportement testés sont identiques.
        global: { mocks: { $t }, stubs: { teleport: true } },
    });
}

beforeEach(() => { vi.useFakeTimers(); vi.setSystemTime(MAINTENANT); });
afterEach(() => { vi.useRealTimers(); });

// ── §1 ────────────────────────────────────────────────────────────────────────────────────
describe('§1 « les commandes pas encore encaissées, les visualiser »', () => {
    it('ouvre par défaut sur la file argent — la seule où l’inaction coûte de l’argent', () => {
        const w = monter();
        expect(w.find('[data-testid="pos-control-panel-encaisser"]').exists()).toBe(true);
        expect(w.find('[data-testid="pos-control-tab-encaisser"]').attributes('aria-selected')).toBe('true');
    });

    it('montre les TROIS commandes à encaisser du service, plus ancienne en tête', () => {
        const w = monter();
        const numeros = w.findAll('.pos-ctrl-carte__numero').map((n) => n.text());
        expect(numeros).toEqual(['N°K1', 'N°K2', 'N°T1']);
    });

    it('affiche le montant dû de chacune, en euros français', () => {
        const w = monter();
        expect(w.find('[data-testid="pos-control-amount-7119"]').text()).toContain('14,90');
        expect(w.find('[data-testid="pos-control-amount-7122"]').text()).toContain('22,60');
    });

    it('annonce les 584 plus anciennes SANS les mêler aux trois du jour', () => {
        // Le défaut d'origine : deux commandes mortes de 20 h 09 occupaient les deux premières
        // lignes, et la commande téléphone du jour était reléguée derrière « Voir plus ».
        const w = monter();
        const bandeau = w.find('[data-testid="pos-control-older-pending"]');
        expect(bandeau.exists()).toBe(true);
        expect(bandeau.text()).toContain('584');
        // Elles ne fabriquent AUCUNE carte : trois cartes, pas 587.
        expect(w.findAll('.pos-ctrl-carte')).toHaveLength(3);
        // Et elles sont en pied de liste, jamais en tête.
        const html = w.find('[data-testid="pos-control-panel-encaisser"]').html();
        expect(html.indexOf('pos-control-older-pending')).toBeGreaterThan(html.indexOf('pos-control-card-7119'));
    });

    it('ne montre pas le bandeau quand il n’y a rien avant aujourd’hui', () => {
        expect(monter({ anciennesCount: 0 }).find('[data-testid="pos-control-older-pending"]').exists()).toBe(false);
    });

    it('émet « encaisser » avec la commande, sans rien décider lui-même', () => {
        const w = monter();
        w.find('[data-testid="pos-control-collect-7119"]').trigger('click');
        expect(w.emitted('encaisser')[0][0].id).toBe(7119);
    });
});

// ── §2 ────────────────────────────────────────────────────────────────────────────────────
describe('§2 « voir LEUR commande pour que j’ai pris le nom »', () => {
    it('rend le nom et le téléphone quand ils sont connus', () => {
        const w = monter();
        const carte = w.find('[data-testid="pos-control-card-7122"]');
        expect(carte.text()).toContain('Mme Diallo');
        expect(carte.text()).toContain('07 98 76 54 32');
    });

    it('ne rend NI ligne vide NI libellé de remplacement quand le nom est inconnu', () => {
        // Une ligne « — » ou « Client » serait pire que rien : elle occupe la place du seul
        // repère utilisable (le numéro) sans rien apprendre.
        const w = monter();
        const carte = w.find('[data-testid="pos-control-card-7119"]');
        expect(carte.find('.pos-ctrl-carte__client').exists()).toBe(false);
        expect(carte.text()).not.toContain('null');
        expect(carte.text()).not.toContain('undefined');
    });

    it('le numéro est présent sur chaque carte — c’est le seul jeton que le client prononce', () => {
        // Sa TAILLE (22 px / 900, au-dessus des noms de produits) relève de la revue visuelle ;
        // ici on garantit seulement qu'il est rendu, et rendu seul dans son élément.
        const w = monter();
        expect(w.find('[data-testid="pos-control-card-7119"]').find('.pos-ctrl-carte__numero').text()).toBe('N°K1');
    });

    it('le canal est porté par une couleur ET un pictogramme ET un nom lu à voix haute', () => {
        // WCAG 1.4.1 : jamais d'information portée par la seule couleur.
        const w = monter();
        const carte = w.find('[data-testid="pos-control-card-7122"]');
        expect(carte.find('.pos-canal--phone').exists()).toBe(true);
        expect(carte.text()).toContain('📞');
        expect(carte.find('.pos-sr-only').text()).toBe('Téléphone');
    });
});

// ── §3 ────────────────────────────────────────────────────────────────────────────────────
describe('§3 « voir ce qu’il y a dedans, en mode technique, avec l’heure »', () => {
    it('rend le nom des produits ET leur composition', () => {
        const w = monter();
        const carte = w.find('[data-testid="pos-control-card-7119"]');
        expect(carte.text()).toContain('Tacos M');
        expect(carte.text()).toContain('Poulet mariné');
        expect(carte.text()).toContain('Algérienne');
        expect(carte.text()).toContain('+2 Cheddar');
    });

    it('rend l’heure de commande ET l’âge — les deux, pas l’un ou l’autre', () => {
        const w = monter();
        const carte = w.find('[data-testid="pos-control-card-7119"]');
        expect(carte.text()).toMatch(/commandée à \d{2}:\d{2}/);
        expect(w.find('[data-testid="pos-control-age-7119"]').text()).toBe('14 min');
    });

    it('l’âge vire à l’ambre à 10 min et au rouge à 20 min — mesuré, jamais prédit', () => {
        const w = monter();
        expect(w.find('[data-testid="pos-control-age-7119"]').classes()).toContain('pos-ctrl-age--ambre');
        expect(w.find('[data-testid="pos-control-age-7120"]').classes()).not.toContain('pos-ctrl-age--ambre');
    });

    it('annonce combien d’articles sont hors de l’aperçu au lieu de les taire', () => {
        // T1 porte quatre lignes ; la carte en montre trois.
        const w = monter();
        expect(w.find('[data-testid="pos-control-more-7122"]').text()).toBe('+ 1 article');
    });

    it('« Voir tout » ouvre le contenu COMPLET sans le moindre appel réseau', () => {
        const w = monter();
        w.find('[data-testid="pos-control-open-7122"]').trigger('click');
        return w.vm.$nextTick().then(() => {
            const detail = w.find('[data-testid="pos-control-detail"]');
            expect(detail.exists()).toBe(true);
            ['Tacos L', 'Grande Frites', 'Oasis', 'Tiramisu'].forEach((produit) => {
                expect(detail.text()).toContain(produit);
            });
        });
    });

    it('« Annuler » n’existe QUE derrière « Voir tout », jamais à côté d’« Encaisser »', () => {
        // Sur une dalle tactile en coup de feu, deux boutons voisins dont l'un annule la vente,
        // c'est une annulation par erreur qui attend son heure.
        const w = monter();
        expect(w.find('[data-testid="pos-control-detail-cancel"]').exists()).toBe(false);
        w.find('[data-testid="pos-control-open-7119"]').trigger('click');
        return w.vm.$nextTick().then(() => {
            expect(w.find('[data-testid="pos-control-detail-cancel"]').exists()).toBe(true);
        });
    });
});

// ── §4 ────────────────────────────────────────────────────────────────────────────────────
describe('§4 « elle est numéro combien par rapport à la cuisine »', () => {
    it('donne le rang ET la profondeur sur la carte à encaisser', () => {
        const w = monter();
        expect(w.find('[data-testid="pos-control-rank-7119"]').text()).toContain('1ᵉʳ sur 4 en cuisine');
        expect(w.find('[data-testid="pos-control-rank-7122"]').text()).toContain('4ᵉ sur 4 en cuisine');
    });

    it('n’annonce JAMAIS une durée d’attente estimée', () => {
        // Rejet argumenté : l'âge de la plus ancienne est le temps écoulé pour QUELQU'UN
        // D'AUTRE, et ce dépôt n'a aucun modèle de débit cuisine.
        const w = monter();
        const texte = w.text();
        expect(texte).not.toMatch(/≈/);
        expect(texte).not.toMatch(/attente estimée|temps estimé|environ \d+ min/i);
    });

    it('le tiroir n’affiche AUCUN total agrégé — les compteurs ne s’additionnent pas', () => {
        // K1, K2 et T1 sont à encaisser ET en cuisine, parce que la règle serveur
        // (`isReleasedForBoard`) admet PENDING_COUNTER : la borne cuit pendant que le client
        // paie. Un total inviterait à une somme fausse.
        const w = monter();
        const compteurs = ['encaisser', 'cuisine', 'pretes', 'livrees']
            .map((id) => Number(w.find(`[data-testid="pos-control-count-${id}"]`).text()));
        expect(compteurs).toEqual([3, 4, 2, 2]);
        expect(w.text()).not.toContain('11 commandes');
    });
});

// ── §5 ────────────────────────────────────────────────────────────────────────────────────
describe('§5 « toutes les commandes qui sont en cuisine, visualiser »', () => {
    const cuisine = () => {
        const w = monter();
        w.find('[data-testid="pos-control-tab-cuisine"]').trigger('click');
        return w.vm.$nextTick().then(() => w);
    };

    it('compte QUATRE commandes en cuisine — pas une', async () => {
        // Le tableau de suivi affichait « EN PRÉPARATION 1 » pendant que quatre commandes
        // cuisaient, parce qu'il classe toute commande à encaisser dans la voie argent.
        const w = await cuisine();
        expect(w.findAll('.pos-ctrl-ligne')).toHaveLength(4);
    });

    it('les numérote dans l’ordre où la cuisine les prépare', async () => {
        const w = await cuisine();
        const rangs = w.findAll('.pos-ctrl-ligne__rang').map((n) => n.text());
        expect(rangs).toEqual(['1ᵉʳ', '2ᵉ', '3ᵉ', '4ᵉ']);
        const numeros = w.findAll('.pos-ctrl-ligne__numero').map((n) => n.text());
        expect(numeros).toEqual(['N°K1', 'N°K2', 'N°P1', 'N°T1']);
    });

    it('marque d’une cloche celles qui sont AUSSI à encaisser', async () => {
        const w = await cuisine();
        expect(w.find('[data-testid="pos-control-bell-7119"]').exists()).toBe(true);
        expect(w.find('[data-testid="pos-control-bell-7121"]').exists()).toBe(false);
    });

    it('n’offre AUCUNE action : le bump appartient au chef', async () => {
        // Un bouton qui doublerait le KDS créerait deux vérités sur l'état d'une commande.
        const w = await cuisine();
        expect(w.find('[data-testid="pos-control-panel-cuisine"]').findAll('.pos-ctrl-cta')).toHaveLength(0);
    });

    it('la commande web en attente d’acceptation n’y figure pas', async () => {
        const w = await cuisine();
        expect(w.find('[data-testid="pos-control-row-7125"]').exists()).toBe(false);
    });
});

// ── §6 ────────────────────────────────────────────────────────────────────────────────────
describe('§6 « contrôler toutes les commandes livrées » et les prêtes', () => {
    it('la file « Prêtes » montre la commande COMPTOIR, invisible avant ce chantier', async () => {
        const w = monter();
        w.find('[data-testid="pos-control-tab-pretes"]').trigger('click');
        await w.vm.$nextTick();
        const panneau = w.find('[data-testid="pos-control-panel-pretes"]');
        expect(panneau.text()).toContain('N°R2');
        expect(panneau.text()).toContain('Sofiane');
    });

    it('« Livré » émet l’action, il ne la décide pas', async () => {
        const w = monter();
        w.find('[data-testid="pos-control-tab-pretes"]').trigger('click');
        await w.vm.$nextTick();
        w.find('[data-testid="pos-control-deliver-7124"]').trigger('click');
        expect(w.emitted('livrer')[0][0].id).toBe(7124);
    });

    it('les livrées sont en ordre inverse : la dernière servie d’abord', async () => {
        const w = monter();
        w.find('[data-testid="pos-control-tab-livrees"]').trigger('click');
        await w.vm.$nextTick();
        const numeros = w.findAll('.pos-ctrl-ligne__numero').map((n) => n.text());
        expect(numeros).toEqual(['N°D2', 'N°D1']);
    });
});

// ── §7 ────────────────────────────────────────────────────────────────────────────────────
describe('§7 « je veux pas que ça ouvre une nouvelle page »', () => {
    it('ne fait AUCUNE requête : aucun cycle de vie réseau, tout arrive en propriétés', () => {
        // C'est la garantie qui rend le tiroir gratuit pour le budget réseau de la caisse.
        expect(PosControlDrawer.created).toBeUndefined();
        expect(PosControlDrawer.mounted).toBeUndefined();
        const source = JSON.stringify(Object.keys(PosControlDrawer.methods));
        expect(source).not.toContain('fetch');
        expect(source).not.toContain('load');
    });

    it('rassure sur le ticket en cours, qui est recouvert et non détruit', () => {
        const w = monter();
        const bandeau = w.find('[data-testid="pos-control-ticket-preserved"]');
        expect(bandeau.text()).toContain('3 articles');
        expect(bandeau.text()).toContain('24,80');
    });

    it('le bandeau reste rendu à panier vide — son absence serait ambiguë', () => {
        const w = monter({ cartCount: 0 });
        expect(w.find('[data-testid="pos-control-ticket-preserved"]').text()).toContain('Aucun ticket');
    });

    it('offre la page complète en pied, pour ce que le tiroir ne fait pas', () => {
        const w = monter();
        const lien = w.find('[data-testid="pos-control-full-page"]');
        expect(lien.text()).toContain('Ouvrir la page complète');
        lien.trigger('click');
        expect(w.emitted('ouvrir-suivi')).toBeTruthy();
    });
});

// ── Fraîcheur, états vides, accessibilité ────────────────────────────────────────────────
describe('la fraîcheur des données ne ment jamais, et ne crie jamais', () => {
    it('dit quand la mesure a été faite', () => {
        expect(monter().find('[data-testid="pos-control-freshness"]').text()).toContain('Vérifié il y a 4 s');
    });

    it('passe en alerte au-delà de 90 s, avec un bouton pour réessayer', () => {
        const w = monter({ lastRefresh: MAINTENANT - 125000 });
        const ligneF = w.find('[data-testid="pos-control-freshness"]');
        expect(ligneF.classes()).toContain('pos-ctrl__fraicheur--figee');
        expect(ligneF.text()).toContain('Données figées depuis 2 min');
        expect(w.find('[data-testid="pos-control-refresh"]').exists()).toBe(true);
    });

    it('ne crie PAS « temps réel perdu » : en production il n’y a aucun serveur de sockets, ce bandeau serait allumé toute la journée', () => {
        expect(monter().text()).not.toMatch(/temps réel|websocket|hors ligne/i);
    });

    it('un état vide DATE sa mesure au lieu d’affirmer le calme', async () => {
        const w = monter({ orders: [] });
        const vide = w.find('[data-testid="pos-control-empty-encaisser"]');
        expect(vide.text()).toContain('Aucune commande à encaisser.');
        expect(vide.text()).toContain('Vérifié il y a');
    });
});

describe('accessibilité — ce qui manquait au panneau existant', () => {
    it('est un dialogue nommé, modal', () => {
        const w = monter();
        const t = w.find('[data-testid="pos-control-drawer"]');
        expect(t.attributes('role')).toBe('dialog');
        expect(t.attributes('aria-modal')).toBe('true');
        expect(t.attributes('aria-labelledby')).toBe('pos-ctrl-titre');
        expect(w.find('#pos-ctrl-titre').text()).toBe('Contrôle des commandes');
    });

    it('Échap ferme — le panneau borne existant ne le fait toujours pas', async () => {
        const w = monter();
        await w.find('[data-testid="pos-control-drawer"]').trigger('keydown.esc');
        expect(w.emitted('close')).toBeTruthy();
    });

    it('Échap referme d’abord le détail, pas tout le tiroir', async () => {
        const w = monter();
        await w.find('[data-testid="pos-control-open-7119"]').trigger('click');
        await w.find('[data-testid="pos-control-drawer"]').trigger('keydown.esc');
        expect(w.emitted('close')).toBeFalsy();
        expect(w.find('[data-testid="pos-control-detail"]').exists()).toBe(false);
    });

    it('les onglets suivent le motif ARIA : un seul tabulable, flèches pour naviguer', async () => {
        const w = monter();
        const onglets = w.findAll('[role="tab"]');
        expect(onglets).toHaveLength(4);
        expect(onglets.filter((o) => o.attributes('tabindex') === '0')).toHaveLength(1);
        await w.find('[role="tablist"]').trigger('keydown.right');
        expect(w.find('[data-testid="pos-control-tab-cuisine"]').attributes('aria-selected')).toBe('true');
    });

    it('annonce les compteurs, pas chaque carte — à 5 s de cadence ce serait du bavardage', () => {
        const w = monter();
        expect(w.find('[data-testid="pos-control-live"]').attributes('aria-live')).toBe('polite');
        expect(w.find('[data-testid="pos-control-live"]').text()).toBe('3 à encaisser, 2 prêtes');
    });

    it('aucun libellé brut, aucune clé de traduction non résolue', () => {
        const texte = monter().text();
        expect(texte).not.toMatch(/pos\.controle\./);
        expect(texte).not.toMatch(/pos\.tracker\./);
        expect(texte).not.toMatch(/label\./);
    });
});

describe('l’onglet ne se souvient pas d’une ouverture à l’autre', () => {
    it('rouvre toujours sur l’onglet demandé par l’appelant', async () => {
        const w = monter({ open: false });
        await w.setProps({ initialTab: 'cuisine', open: true });
        expect(w.find('[data-testid="pos-control-tab-cuisine"]').attributes('aria-selected')).toBe('true');
        // On navigue ailleurs, on ferme, on rouvre : on retrouve l'onglet demandé, pas le dernier vu.
        await w.find('[data-testid="pos-control-tab-livrees"]').trigger('click');
        await w.setProps({ open: false });
        await w.setProps({ open: true });
        expect(w.find('[data-testid="pos-control-tab-cuisine"]').attributes('aria-selected')).toBe('true');
    });
});
