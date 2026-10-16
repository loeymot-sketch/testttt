/**
 * [ETIQUETTES 2026-10-16] Mise en page : retour à la ligne, hauteur du ruban continu, réduction pour une
 * étiquette de hauteur fixe, et — surtout — JAMAIS de troncature silencieuse (sécurité alimentaire).
 */
import { describe, it, expect } from 'vitest';
import { wrapText, layoutLabel, SIZES, MIN_LABEL_DOTS } from '../../resources/js/helpers/labelLayout.js';
import { formatDlcDate, shelfLifeText, dlcLabelBlocks, testLabelBlocks, expiryStatus, watchList, dlcParts, storagePhrase, storageForAction } from '../../resources/js/helpers/dlcLabelBuilder.js';

/** Mesure factice : 0,55 × la taille de police par caractère. */
const measure = (text, font) => {
    const size = Number((font.match(/(\d+)px/) || [])[1] || 20);
    return Array.from(text).length * size * 0.55;
};

describe('wrapText', () => {
    it('coupe aux espaces sans dépasser la largeur', () => {
        const lines = wrapText('un deux trois quatre cinq six sept', 200, '700 30px x', measure);
        expect(lines.length).toBeGreaterThan(1);
        lines.forEach((l) => expect(measure(l, '700 30px x')).toBeLessThanOrEqual(200));
        expect(lines.join(' ')).toBe('un deux trois quatre cinq six sept');
    });

    it('un mot plus large que la ligne est coupé au caractère, sans rien perdre', () => {
        const lines = wrapText('Anticonstitutionnellement', 120, '700 30px x', measure);
        expect(lines.length).toBeGreaterThan(1);
        expect(lines.join('')).toBe('Anticonstitutionnellement');
    });

    it('respecte les retours à la ligne explicites', () => {
        expect(wrapText('A\nB', 500, '700 30px x', measure)).toEqual(['A', 'B']);
    });
});

describe('layoutLabel', () => {
    const blocks = [
        { t: 'band', left: 'N° A0032', right: '2/4' },
        { t: 'title', text: 'CAYENNE' },
        { t: 'line', text: 'Sauce : Algérienne' },
        { t: 'line', text: 'Crudités : Salade, Tomate', size: 'md' },
    ];

    it('ruban continu : la hauteur suit le contenu, jamais en dessous du minimum', () => {
        const r = layoutLabel(blocks, { width: 696, height: null, measure });
        expect(r.overflow).toBe(false);
        expect(r.scale).toBe(1);
        expect(r.height).toBeGreaterThanOrEqual(MIN_LABEL_DOTS);
        const small = layoutLabel([{ t: 'title', text: 'X' }], { width: 696, height: null, measure });
        expect(small.height).toBe(MIN_LABEL_DOTS);
    });

    it('plus de contenu → plus long', () => {
        const a = layoutLabel(blocks, { width: 696, measure });
        const b = layoutLabel([...blocks, { t: 'line', text: 'Sauce frites : Ketchup, Mayonnaise, Samouraï, Algérienne, Harissa' }], { width: 696, measure });
        expect(b.height).toBeGreaterThan(a.height);
    });

    it('hauteur fixe : réduit les polices pour faire tenir, et le dit', () => {
        const free = layoutLabel(blocks, { width: 696, height: null, measure });
        const fixed = layoutLabel(blocks, { width: 696, height: Math.floor(free.height * 0.85), measure });
        expect(fixed.overflow).toBe(false);
        expect(fixed.scale).toBeLessThan(1);
        expect(fixed.height).toBe(Math.floor(free.height * 0.85));
    });

    it('hauteur fixe impossible : overflow = true (on refuse d’imprimer plutôt que de tronquer)', () => {
        const r = layoutLabel(blocks, { width: 696, height: 80, measure });
        expect(r.overflow).toBe(true);
    });

    it('le bandeau part du bord haut et porte deux textes (numéro et k/N)', () => {
        const r = layoutLabel(blocks, { width: 696, measure });
        const band = r.commands.find((c) => c.op === 'rect' && c.x === 0);
        expect(band.y).toBe(0);
        expect(band.w).toBe(696);
        const texts = r.commands.filter((c) => c.op === 'text').map((c) => c.text);
        expect(texts).toContain('N° A0032');
        expect(texts).toContain('2/4');
    });

    it('un bandeau trop chargé réduit sa police au lieu de déborder', () => {
        const r = layoutLabel([{ t: 'band', left: 'N° ABCDEFGHIJKLMNOP', right: '10/12' }], { width: 696, measure });
        const t = r.commands.filter((c) => c.op === 'text');
        expect(t[0].size).toBeLessThan(SIZES.band);
        const right = t[1];
        expect(right.x + measure(right.text, right.font)).toBeLessThanOrEqual(696);
    });

    it('aucune ligne de texte ne dépasse la largeur de l’étiquette', () => {
        const long = [{ t: 'line', text: 'Sauces : Algérienne, Andalouse, Harissa, Samouraï, Ketchup, Mayonnaise, Barbecue, Blanche', size: 'md' }];
        const r = layoutLabel(long, { width: 696, measure });
        r.commands.filter((c) => c.op === 'text').forEach((c) => {
            expect(c.x + measure(c.text, c.font)).toBeLessThanOrEqual(696);
        });
        expect(r.commands.filter((c) => c.op === 'text').length).toBeGreaterThan(1);
    });
});

describe('pastille du jour (dayline)', () => {
    it('dessine un bloc noir avec le jour en blanc, puis la légende et la date', () => {
        const r = layoutLabel([{ t: 'dayline', day: 'DIM', caption: 'À CONSOMMER JUSQU’AU', text: '18/10/2026  14:32' }], { width: 696, measure });
        expect(r.overflow).toBe(false);
        const texts = r.commands.filter((c) => c.op === 'text');
        expect(texts.map((t) => t.text)).toEqual(['DIM', 'À CONSOMMER JUSQU’AU', '18/10/2026  14:32']);
        expect(texts[0].color).toBe('#fff');
        expect(r.commands.some((c) => c.op === 'rect' && c.fill === '#000')).toBe(true);
    });

    it('une date trop longue pour la place est signalée, jamais rognée en silence', () => {
        const r = layoutLabel([{ t: 'dayline', day: 'DIM', caption: 'X', text: 'Y'.repeat(60) }], { width: 696, measure });
        expect(r.overflow).toBe(true);
    });
});

describe('chevauchement et rognage signalés (revue adversariale)', () => {
    it('un bandeau dont les deux textes ne tiennent plus côte à côte est signalé, même en ruban continu', () => {
        const r = layoutLabel([{ t: 'band', left: `N° ${'X'.repeat(60)}`, right: '1/3' }], { width: 696, measure });
        expect(r.overflow).toBe(true);
    });

    it('une paire dont le texte de droite déborde est signalée', () => {
        const r = layoutLabel([{ t: 'pair', left: 'Ouvert le', right: 'D'.repeat(60), size: 'md' }], { width: 696, measure });
        expect(r.overflow).toBe(true);
    });

    it('les cas normaux ne sont jamais signalés à tort', () => {
        const r = layoutLabel([
            { t: 'band', left: 'N° A0032', right: '12/12' },
            { t: 'pair', left: 'Ouvert le', right: 'ven 16/10/2026 14:32', size: 'md' },
        ], { width: 696, measure });
        expect(r.overflow).toBe(false);
    });
});

describe('étiquette DLC', () => {
    it('formate la date dans le fuseau du restaurant, 24 h, avec le jour', () => {
        // 16/10/2026 12:32 UTC = 14:32 à Paris (heure d'été)
        expect(formatDlcDate('2026-10-16T12:32:00Z')).toBe('ven 16/10/2026 14:32');
        // minuit : jamais « 24:00 »
        expect(formatDlcDate('2026-10-16T22:00:00Z')).toBe('sam 17/10/2026 00:00');
        expect(formatDlcDate('pas une date')).toBe('—');
    });

    it('durée de conservation lisible', () => {
        expect(shelfLifeText(48, 'hours')).toBe('48 h');
        expect(shelfLifeText(1, 'days')).toBe('1 jour');
        expect(shelfLifeText(3, 'days')).toBe('3 jours');
        expect(shelfLifeText(0, 'days')).toBe('');
    });

    it('contient produit, famille, événement, DLC avec le JOUR en pastille, lot, conservation et opérateur', () => {
        const blocks = dlcLabelBlocks({
            name: 'Oignons émincés', category: 'Légumes & crudités', note: 'Fait maison', action: 'opened',
            openedAt: '2026-10-16T12:32:00Z', expiresAt: '2026-10-18T12:32:00Z',
            storage: '0 à +4 °C', by: 'Karim', lot: 'L261016-0042', allergens: ['lait', 'moutarde'],
        });
        expect(blocks[0]).toEqual({ t: 'title', text: 'OIGNONS ÉMINCÉS' });
        expect(blocks[1]).toEqual({ t: 'sub', text: 'Légumes & crudités · Fait maison' });
        expect(blocks).toContainEqual({ t: 'pair', left: 'Entamé le', right: 'ven 16/10/2026 14:32', size: 'md', stack: true });
        expect(blocks).toContainEqual({ t: 'dayline', day: 'DIM', caption: 'À CONSOMMER JUSQU’AU', text: '18/10/2026  14:32' });
        expect(blocks).toContainEqual({ t: 'line', text: 'Lait, Moutarde', prefix: 'ALLERGÈNES : ', size: 'md', box: true });
        // la conservation au modèle du guide PMS, sur sa propre ligne ; puis le numéro de l'étiquette et l'opérateur
        expect(blocks).toContainEqual({ t: 'line', text: 'À conserver entre 0 et +4 °C', size: 'sm' });
        expect(blocks[blocks.length - 1]).toEqual({ t: 'sub', text: 'N° L261016-0042 · Par Karim' });
        expect(JSON.stringify(blocks)).not.toContain('RECONGELER');
    });

    it('produit décongelé : « Mis en décongélation le », bandeau NE PAS RECONGELER, jamais « ≤ −18 °C »', () => {
        const blocks = dlcLabelBlocks({
            name: 'Steaks', action: 'thawed', openedAt: '2026-10-16T12:00:00Z', expiresAt: '2026-10-19T12:00:00Z',
            storage: storageForAction('≤ −18 °C', 'thawed'),
        });
        // sous le nom et le filet, avant toute date
        expect(blocks[1]).toEqual({ t: 'rule' });
        expect(blocks[2]).toEqual({ t: 'band', left: 'NE PAS RECONGELER', size: 'lg' });
        expect(blocks.find((b) => b.t === 'pair').left).toBe('Mis en décongélation le');
        expect(blocks).toContainEqual({ t: 'line', text: 'À conserver entre 0 et +4 °C', size: 'sm' });
        expect(JSON.stringify(blocks)).not.toContain('18 °C');
        // et le tout tient sur 62 mm sans rien rogner : le verbe long passe au-dessus de la date
        const r = layoutLabel(blocks, { width: 696, measure });
        expect(r.overflow).toBe(false);
    });

    it('conservation : phrase du guide PMS, et règle de décongélation identique au serveur', () => {
        expect(storagePhrase('0 à +3 °C')).toBe('À conserver entre 0 et +3 °C');
        expect(storagePhrase('≤ −18 °C')).toBe('À conserver à −18 °C ou moins');
        expect(storagePhrase('Ambiante')).toBe('À conserver à température ambiante');
        expect(storagePhrase('+4 °C')).toBe('À conserver à +4 °C maximum');
        expect(storagePhrase('Chambre froide n°2')).toBe('À conserver : Chambre froide n°2');
        expect(storagePhrase('')).toBe('');
        expect(storageForAction('≤ −18 °C', 'thawed')).toBe('0 à +4 °C');
        expect(storageForAction('-18°C', 'thawed')).toBe('0 à +4 °C');
        expect(storageForAction('Ambiante', 'thawed')).toBe('0 à +4 °C');
        expect(storageForAction('', 'thawed')).toBe('0 à +4 °C');
        expect(storageForAction('0 à +3 °C', 'thawed')).toBe('0 à +3 °C');
        expect(storageForAction('≤ −18 °C', 'opened')).toBe('≤ −18 °C');
    });

    it('DLC lointaine (plus de 6 jours) : pas de pastille du jour, le même jour reviendrait', () => {
        const day = (exp) => dlcLabelBlocks({ name: 'X', openedAt: '2026-10-16T12:00:00Z', expiresAt: exp }).find((b) => b.t === 'dayline').day;
        expect(day('2026-10-22T12:00:00Z')).toBe('JEU');
        expect(day('2026-10-23T12:00:00Z')).toBe('');
        const r = layoutLabel([{ t: 'dayline', day: '', caption: 'À CONSOMMER JUSQU’AU', text: '30/10/2026  12:00' }], { width: 696, measure });
        expect(r.commands.filter((c) => c.op === 'rect')).toHaveLength(0);
        expect(r.overflow).toBe(false);
    });

    it('traçabilité amont : DLC et lot du fabricant, produit d’origine, hors ligne', () => {
        const base = { name: 'Crème', openedAt: '2026-10-16T08:00:00Z', expiresAt: '2026-10-18T08:00:00Z' };
        const subs = (d) => dlcLabelBlocks({ ...base, ...d }).filter((b) => b.t === 'sub').map((b) => b.text);
        expect(subs({ originalDlc: '2026-10-30', supplierLot: '2345A', lot: 'L261016-0001' }))
            .toEqual(['DLC fabricant 30/10/2026 · Lot fab. 2345A', 'N° L261016-0001']);
        expect(subs({ parent: { lot: 'L261015-0007', name: 'Poulet' }, lot: 'L261016-0002' }))
            .toEqual(['Issu de Poulet N° L261015-0007', 'N° L261016-0002']);
        const capped = dlcLabelBlocks({ ...base, capped: true, capSource: 'parent', parent: { lot: 'L261015-0007', name: 'Poulet' } });
        expect(capped).toContainEqual({ t: 'line', text: 'Limitée à la DLC du produit d’origine (N° L261015-0007)', size: 'sm' });
        expect(subs({ offline: true, lot: null, by: 'Karim' })).toEqual(['Imprimée hors ligne · Par Karim']);
    });

    it('le verbe suit l’événement : décongelé, préparé, cuit, sous vide', () => {
        const verb = (action) => dlcLabelBlocks({ name: 'X', action, openedAt: '2026-10-16T12:00:00Z', expiresAt: '2026-10-17T12:00:00Z' })
            .find((b) => b.t === 'pair').left;
        expect(verb('thawed')).toBe('Mis en décongélation le');
        expect(verb('prepared')).toBe('Préparé le');
        expect(verb('cooked')).toBe('Cuit le');
        expect(verb('vacuum')).toBe('Mis sous vide le');
        expect(verb('inconnu')).toBe('Entamé le');
    });

    it('DLC limitée par celle du fabricant : mention explicite sur l’étiquette', () => {
        const blocks = dlcLabelBlocks({ name: 'Crème', openedAt: '2026-10-16T08:00:00Z', expiresAt: '2026-10-18T21:59:00Z', capped: true, originalDlc: '2026-10-18' });
        expect(blocks).toContainEqual({ t: 'line', text: 'Limitée à la DLC du fabricant (18/10/2026)', size: 'sm' });
    });

    it('jour de la semaine juste autour de minuit (fuseau du restaurant)', () => {
        expect(dlcParts('2026-10-17T22:30:00Z')).toEqual({ day: 'DIM', date: '18/10/2026', time: '00:30' }); // samedi 22:30 UTC = dimanche 00:30 Paris
        expect(dlcParts('nope').date).toBe('—');
    });

    it('l’étiquette de test porte le repère gauche/droite et des accents', () => {
        const flat = JSON.stringify(testLabelBlocks('c62', new Date('2026-10-16T12:00:00Z')));
        expect(flat).toContain('G <');
        expect(flat).toContain('> D');
        expect(flat).toContain('éèàçù Œuf');
    });
});

describe('DLC à surveiller (intelligence du journal)', () => {
    const now = Date.parse('2026-10-16T12:00:00Z'); // 14:00 à Paris
    const h = (over) => ({ id: 1, status: 'printed', product_name: 'Oignons', copies: 1, expires_at: '2026-10-16T18:00:00Z', ...over });

    it('texte et niveau d’urgence', () => {
        expect(expiryStatus(now - 3 * 3600000, now)).toEqual({ level: 'expired', text: 'EXPIRÉE depuis 3 h' });
        expect(expiryStatus(now - 20 * 60000, now).text).toBe('EXPIRÉE depuis 20 min');
        expect(expiryStatus(now + 30 * 60000, now)).toEqual({ level: 'today', text: 'expire dans 30 min (14:30)' });
        expect(expiryStatus(now + 5 * 3600000, now)).toEqual({ level: 'today', text: 'expire aujourd’hui à 19:00' });
        expect(expiryStatus(now + 24 * 3600000, now)).toEqual({ level: 'soon', text: 'expire demain à 14:00' });
        // 16/10 14:00 + 2 jours = dimanche 18/10 : « après‑demain », jamais « demain » (défaut relevé par le superviseur)
        expect(expiryStatus(now + 48 * 3600000, now).text).toBe('expire après‑demain à 14:00 (dim 18/10)');
        expect(expiryStatus(now + 96 * 3600000, now).text).toBe('expire le mar 20/10 à 14:00');
        // passé minuit : 23:30 → 00:30 le lendemain est « demain », pas « aujourd’hui »
        const late = Date.parse('2026-10-16T21:30:00Z'); // 23:30 Paris
        expect(expiryStatus(late + 3600000, late).text).toBe('expire demain à 00:30');
    });

    it('ne garde que les DLC proches, dédoublonne, trie par échéance', () => {
        const rows = [
            h({ id: 1, product_name: 'Tomates', expires_at: '2026-10-16T20:00:00Z' }),
            h({ id: 2, product_name: 'Oignons', expires_at: '2026-10-16T09:00:00Z' }),
            h({ id: 3, product_name: 'Oignons', expires_at: '2026-10-16T09:00:00Z', copies: 3 }), // doublon (même lot)
            h({ id: 4, product_name: 'Olives', expires_at: '2026-10-20T09:00:00Z' }),            // trop loin
            h({ id: 5, product_name: 'Vieux', expires_at: '2026-10-10T09:00:00Z' }),             // échue depuis des jours
            h({ id: 6, product_name: 'Raté', status: 'failed' }),                                  // jamais sortie
        ];
        const { items: w, more } = watchList(rows, now);
        // un lot échu depuis des jours que personne n'a sorti RESTE signalé (le serveur borne déjà à 7 jours)
        expect(w.map((x) => x.name)).toEqual(['Vieux', 'Oignons', 'Tomates']);
        expect(w[0].level).toBe('expired');
        expect(more).toBe(0);
        // le même lot imprimé deux fois : une ligne, étiquettes additionnées, tous les identifiants gardés (pour « Jeté »)
        expect(w[1].copies).toBe(4);
        expect(w[1].ids).toEqual([2, 3]);
        expect(watchList(null, now)).toEqual({ items: [], more: 0 });
    });

    it('au-delà de la limite, le nombre de lots non affichés est rendu (« + N autres »), jamais tu', () => {
        const rows = Array.from({ length: 12 }, (_, i) => h({ id: i + 1, product_name: `P${i}`, expires_at: new Date(now + i * 3600000).toISOString() }));
        const r = watchList(rows, now, 8);
        expect(r.items).toHaveLength(8);
        expect(r.more).toBe(4);
    });

    it('un lot « jeté » disparaît de la liste ; une DLC à +2 jours reste visible', () => {
        const rows = [
            h({ id: 1, product_name: 'Olives', expires_at: '2026-10-16T09:00:00Z', discarded_at: '2026-10-16T10:00:00Z' }),
            h({ id: 2, product_name: 'Fromage', expires_at: '2026-10-18T12:00:00Z' }), // +48 h pile : au bord
        ];
        expect(watchList(rows, now).items.map((x) => x.name)).toEqual(['Fromage']);
    });
});
