/**
 * [ETIQUETTES 2026-10-16] Étiquette par produit de commande : numéro de commande, nom, personnalisation
 * EN CLAIR, numéro « k/N ». Les 15 commandes de `__fixtures__/orderLabelOrders.json` sont des commandes
 * RÉELLES de la base (caisse, borne, web, téléphone, Uber), servies par KDSOrderDetailsResource — les cas
 * limites y sont authentiques (formules repliées, sauces en plus, « · » dans l'instruction, notes).
 */
import { describe, it, expect } from 'vitest';
import orders from './__fixtures__/orderLabelOrders.json';
import { collapseBundledAddonItems } from '../../resources/js/helpers/kdsBundledAddons.js';
import { kdsVariationGroupValue } from '../../resources/js/helpers/kdsCustomization.js';
import { classifyVariation, extraName, readExtras, readVariations } from '../../resources/js/helpers/kdsSymbolic.js';
import {
    buildOrderLabels,
    describeItem,
    normalizeInstruction,
    orderLabelBlocks,
    orderNumber,
} from '../../resources/js/helpers/orderLabelBuilder.js';

const byNumber = (n) => orders.find((o) => String(o.queue_number) === n || String(o.id) === n);
const texts = (label) => label.details.map((d) => d.text);

describe('numérotation k/N', () => {
    it('une unité = une étiquette : 2 sandwichs identiques → 2 étiquettes, numérotées dans l’ordre de la commande', () => {
        const r = buildOrderLabels(byNumber('U2RZA'));
        expect(r.total).toBe(4); // tacos + 2 × Cayenne + grande frites
        expect(r.labels.map((l) => `${l.index}/${l.total}`)).toEqual(['1/4', '2/4', '3/4', '4/4']);
        expect(r.labels.map((l) => l.name)).toEqual(['Tacos M', 'Cayenne', 'Cayenne', 'Grande Frites']);
        expect(new Set(r.labels.map((l) => l.key)).size).toBe(4);
    });

    it('le numéro affiché est celui de l’écran cuisine (queue_number, sinon l’id)', () => {
        expect(orderNumber({ queue_number: 'A0032', id: 7593 })).toBe('A0032');
        expect(orderNumber({ queue_number: null, id: 7593 })).toBe('7593');
        expect(buildOrderLabels(byNumber('A0060')).labels.every((l) => l.number === 'A0060')).toBe(true);
    });

    it('une quantité aberrante est plafonnée', () => {
        const o = JSON.parse(JSON.stringify(byNumber('A0114')));
        o.order_items[0].quantity = 9999;
        expect(buildOrderLabels(o).total).toBe(30);
    });
});

describe('boissons', () => {
    it('une boisson vendue seule n’a PAS d’étiquette par défaut, mais est signalée comme ignorée', () => {
        const r = buildOrderLabels(orders[0]);
        expect(r.labels.map((l) => l.name)).toEqual(['Cayenne']);
        expect(r.skipped).toEqual(['Coca-Cola 33cl']);
    });

    it('réglage « inclure les boissons » : elle reçoit son étiquette', () => {
        const r = buildOrderLabels(orders[0], { includeDrinks: true });
        expect(r.labels.map((l) => l.name)).toEqual(['Cayenne', 'Coca-Cola 33cl']);
        expect(r.total).toBe(2);
    });

    it('la boisson d’un menu figure sur l’étiquette du menu', () => {
        const l = buildOrderLabels(byNumber('A0060')).labels[0];
        expect(l.menu.text).toBe('MENU : frites + Coca-Cola 33cl');
    });
});

describe('personnalisation en clair (cas réels)', () => {
    it('Cayenne caisse, menu complet : pain, viande, sauce, crudités, sauce frites — rien d’autre', () => {
        const l = buildOrderLabels(orders[0]).labels[0];
        expect(l.menu.text).toBe('MENU : frites + Coca-Cola 33cl');
        expect(texts(l)).toEqual([
            'Pain',
            'Viande : Poulet mariné',
            'Sauce : Algérienne',
            'Crudités : Salade, Tomate',
            'Sauce frites : Mayonnaise',
        ]);
        expect(l.supplements).toEqual([]);
    });

    it('« · » dans l’instruction : la sauce frites n’est pas collée à la boisson (commande 7593)', () => {
        const r = buildOrderLabels(byNumber('7593'));
        const l = r.labels[0];
        expect(l.menu.text).toBe('MENU : frites + Fanta Orange 33cl');
        expect(texts(l)).toContain('Sauce frites : Samouraï');
        expect(texts(l)).toContain('Galette');
        expect(l.supplements).toEqual(['Jambon', 'Cheddar', 'Raclette']);
        // la rubrique « Boisson menu: … » ne revient PAS en note
        expect(l.notes).toEqual([]);
    });

    it('sauce du produit : le mot « Sauce » n’est pas dupliqué (« Sauce : Samouraï », pas « Sauce : Sauce Samouraï »)', () => {
        const l = buildOrderLabels(byNumber('U2RZA')).labels[1];
        expect(texts(l)).toContain('Sauce : Samouraï');
    });

    it('sauces en plus nommées dans l’instruction, sans fantôme « Sauce supplémentaire » (frites, commande 7580)', () => {
        const l = buildOrderLabels(byNumber('K913')).labels[0];
        expect(texts(l)).toEqual(['Sauces : Algérienne, Andalouse, Harissa']);
        expect(l.supplements).toEqual(['Cheddar', 'Boursin']);
    });

    it('viande supplémentaire : le NOM de la viande est écrit (jamais une ligne générique)', () => {
        const l = buildOrderLabels(byNumber('A0152')).labels[0];
        expect(l.supplements).toEqual(['Viande supplémentaire : Poulet mariné, Cordon Bleu']);
    });

    it('plusieurs viandes : libellé au pluriel', () => {
        const l = buildOrderLabels(byNumber('A0114')).labels[0];
        expect(texts(l)).toContain('Viandes : Tenders, Cordon Bleu');
        expect(l.supplements).toEqual(['Maïs', 'Cheddar', 'Œuf']);
    });

    it('note du client conservée, sans les crochets (commande Uber « SANS OIGNONS SVP »)', () => {
        const l = buildOrderLabels(byNumber('U2RZA')).labels[0];
        expect(l.notes).toEqual(['SANS OIGNONS SVP']);
        expect(l.supplements).toEqual(['Supplément Cheddar']);
    });

    it('plusieurs sauces frites sont toutes écrites', () => {
        const l = buildOrderLabels(byNumber('U9BUJ')).labels[3];
        expect(texts(l)).toContain('Sauce frites : Ketchup, Mayonnaise');
        expect(l.menu.text).toBe('MENU : frites + boisson');
    });

    it('formule revendiquée par l’instruction du parent : boisson repliée, nom de la boisson trouvé (commande 5533)', () => {
        const l = buildOrderLabels(byNumber('A0012')).labels[0];
        expect(l.menu.text).toBe('MENU : frites + Hawaï 33cl');
        expect(texts(l)).toContain('Crudités : Salade, Tomate, Oignons cuits');
    });
});

describe('menu ou seul', () => {
    it('un burger sans formule est marqué SEUL', () => {
        const l = buildOrderLabels(byNumber('U9BUJ')).labels[0];
        expect(l.name).toBe('Cheese Burger');
        expect(l.menu.kind).toBe('seul');
    });

    it('un « Menu Enfant … » n’est JAMAIS « seul » : c’est déjà un menu', () => {
        const labels = buildOrderLabels(byNumber('A0035')).labels;
        const kids = labels.find((x) => /Menu Enfant/.test(x.name));
        expect(kids.menu).toBeNull();
    });

    it('frites, bols et desserts n’affichent aucune ligne menu/seul', () => {
        expect(buildOrderLabels(byNumber('K913')).labels[0].menu).toBeNull();
        expect(buildOrderLabels(byNumber('A0152')).labels[0].menu).toBeNull();
    });

    it('formule « frites » seule (menu_frites) → AVEC FRITES', () => {
        expect(buildOrderLabels(byNumber('A0087')).labels[0].menu.text).toBe('AVEC FRITES');
    });
});

describe('sécurité alimentaire', () => {
    it('les allergènes sont écrits sur l’étiquette', () => {
        const item = { item_name: 'Cayenne', quantity: 1, allergens_snapshot: ['gluten', 'lait'], composition_snapshot: null };
        const d = describeItem(item, { allergenLabel: (c) => ({ gluten: 'Gluten', lait: 'Lait' }[c]) });
        expect(d.allergens).toEqual(['Gluten', 'Lait']);
        const blocks = orderLabelBlocks({ number: '1', index: 1, total: 1, sub: '', name: 'Cayenne', menu: null, details: [], supplements: [], notes: [], allergens: d.allergens });
        expect(blocks.some((b) => b.prefix === 'ALLERGÈNES : ' && b.text === 'Gluten, Lait')).toBe(true);
    });

    it('un code d’allergène numérique n’est jamais perdu', () => {
        expect(describeItem({ item_name: 'X', allergens_snapshot: [7] }).allergens).toEqual(['7']);
    });

    it('un extra sans aucun nom reste visible comme « Supplément » (jamais escamoté)', () => {
        const d = describeItem({
            item_name: 'Cayenne', quantity: 1, instruction: '',
            composition_snapshot: { lines: [], addons: [], extras: [{ quantity: 1, unit_price: 0.9 }] },
        });
        expect(d.supplements).toEqual(['Supplément']);
    });
});

describe('blocs de mise en page', () => {
    it('le bandeau porte le numéro de commande et k/N ; le titre est le nom en majuscules', () => {
        const l = buildOrderLabels(byNumber('U2RZA')).labels[2];
        const blocks = orderLabelBlocks(l);
        expect(blocks[0]).toEqual({ t: 'band', left: 'N° U2RZA', right: '3/4' });
        expect(blocks.find((b) => b.t === 'title').text).toBe('CAYENNE');
        expect(blocks.find((b) => b.t === 'sub').text).toMatch(/UBER EATS/);
    });

    it('normalizeInstruction met chaque rubrique sur sa ligne', () => {
        expect(normalizeInstruction('A: x · B: y')).toBe('A: x\nB: y');
        expect(normalizeInstruction(null)).toBe('');
    });

    it('une commande sans produit étiquetable (boissons seules) donne zéro étiquette, sans erreur', () => {
        const o = { id: 1, queue_number: 'Z1', order_items: [{ item_name: 'Coca-Cola 33cl', quantity: 2, instruction: '' }] };
        const r = buildOrderLabels(o);
        expect(r.total).toBe(0);
        expect(r.labels).toEqual([]);
        expect(r.skipped).toEqual(['Coca-Cola 33cl']);
    });

    it('une commande vide ou absente ne lève pas', () => {
        expect(buildOrderLabels({ id: 2, order_items: [] }).labels).toEqual([]);
        expect(buildOrderLabels(null).labels).toEqual([]);
    });
});

describe('notes du client : une allergie ne se perd jamais (revue adversariale)', () => {
    const item = (instruction) => ({ item_name: 'Cayenne', quantity: 1, instruction, composition_snapshot: null });

    it('note de la BORNE sur la même ligne que la composition', () => {
        expect(describeItem(item('Pain: Pain. Note: sans sel')).notes).toEqual(['sans sel']);
        expect(describeItem(item('Pain: Pain. Sauce: Samouraï. Note: allergie arachide')).notes).toEqual(['allergie arachide']);
        expect(describeItem(item('Pain: Pain. Formule: Menu complet (frites + boisson) (Fanta 33cl). Sauce frites: Mayo. Note: allergie arachide')).notes)
            .toEqual(['allergie arachide']);
    });

    it('« Note: … » seule reste gardée, sans doublon', () => {
        expect(describeItem(item('Note: allergie arachide')).notes).toEqual(['allergie arachide']);
    });

    it('une note de caissier entre crochets qui commence par « Boisson: » reste une note', () => {
        expect(describeItem(item('CAYENNE\n[Boisson: sans glaçons, allergie arachide]')).notes).toEqual(['Boisson: sans glaçons, allergie arachide']);
        // …alors que la rubrique de formule « Boisson menu: X » n'en est pas une
        expect(describeItem(item('Boisson menu: Coca-Cola 33cl')).notes).toEqual([]);
    });

    it('note multi‑lignes du caissier conservée', () => {
        const d = describeItem(item('CAYENNE\n[Allergie:\n- gluten\n- arachide]'));
        expect(d.notes.join(' | ')).toContain('Allergie:');
        expect(d.notes.join(' | ')).toContain('arachide');
    });

    it('la note figure sur l’étiquette imprimée (bloc NOTE)', () => {
        const [label] = buildOrderLabels({ id: 1, queue_number: 'N1', order_items: [{ item_name: 'Cayenne', quantity: 1, instruction: 'Pain: Pain. Note: allergie arachide', composition_snapshot: null }] }).labels;
        const blocks = orderLabelBlocks(label);
        expect(blocks.some((b) => b.prefix === 'NOTE : ' && /allergie arachide/.test(b.text))).toBe(true);
    });
});

describe('menu ou seul — sandwich sans variation de pain', () => {
    it('un Cayenne avec viande mais sans pain est « SEUL » sans formule (commande réelle U2RZA)', () => {
        const l = buildOrderLabels(byNumber('U2RZA')).labels[1];
        expect(l.name).toBe('Cayenne');
        expect(l.menu.kind).toBe('seul');
    });

    it('un bol (viande, sauce) n’est jamais « seul »', () => {
        expect(buildOrderLabels(byNumber('A0152')).labels[0].menu).toBeNull();
        expect(buildOrderLabels(byNumber('A0036')).labels[0].menu).toBeNull();
    });
});

describe('complétude : rien de ce que le client a choisi ne manque sur l’étiquette', () => {
    const norm = (v) => String(v || '').normalize('NFD').replace(/[̀-ͯ]/g, '').replace(/œ/gi, 'oe').toLowerCase().replace(/\s+/g, ' ').trim();

    it('chaque variation (viande, sauce, pain…) et chaque supplément nommé des 15 commandes réelles figure sur l’étiquette', () => {
        let checked = 0;
        orders.forEach((order) => {
            collapseBundledAddonItems(order.order_items).forEach((raw) => {
                const d = describeItem(raw, {});
                if (d.isDrink) return;
                const blob = norm([d.name, d.menu && d.menu.text, ...d.details.map((x) => x.text), ...d.supplements, ...d.notes].join(' | '));
                const tacos = /\btacos?\b/i.test(d.name);
                readVariations(raw).forEach((v) => {
                    const gv = kdsVariationGroupValue(v);
                    const value = gv.value || (v && v.variation_name) || '';
                    if (!value) return;
                    if (tacos && ['support', 'size'].includes(classifyVariation(gv.group, value))) return;
                    checked += 1;
                    const wanted = norm(value).replace(/^sauces?\s+(d['’]\s*|de\s+|du\s+)?/, '');
                    expect(blob, `${order.queue_number} ${d.name} : « ${gv.group} : ${value} »`).toContain(wanted);
                });
                readExtras(raw).forEach((e) => {
                    const n = extraName(e);
                    if (!n || /sauce\s*suppl|viande\s*suppl/i.test(n)) return;
                    checked += 1;
                    expect(blob, `${order.queue_number} ${d.name} : extra « ${n} »`).toContain(norm(n));
                });
            });
        });
        expect(checked, 'le contrôle a bien examiné des choix client').toBeGreaterThan(25);
    });
});
