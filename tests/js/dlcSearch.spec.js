import { describe, it, expect } from 'vitest';
import { fold, stem, editDistance, matchProduct, searchProducts } from '../../resources/js/helpers/dlcSearch';

// Noms du catalogue recommandé (RecommendedDlcCatalog) — la recherche est testée sur de vrais libellés.
const P = (name, category = 'Légumes & crudités') => ({ name, category });
const CATALOG = [
    P('Tomates tranchées'), P('Tomates cerises coupées'), P('Oignons rouges émincés'), P('Oignons blancs émincés'),
    P('Oignons ciselés'), P('Oignons frits (sachet ouvert)'), P('Poivrons coupés'), P('Poivrons grillés'),
    P('Champignons frais émincés'), P('Champignons cuits / sautés'), P('Champignons de Paris (boîte ouverte)', 'Conserves & bocaux'),
    P('Poulet cru découpé', 'Viandes & volailles'), P('Poulet mariné', 'Viandes & volailles'), P('Poulet cuit émincé', 'Viandes & volailles'),
    P('Poulet décongelé', 'Viandes & volailles'), P('Pommes de terre épluchées'), P('Mayonnaise (pot ouvert)', 'Sauces'),
    P('Steak haché décongelé', 'Viandes & volailles'), P('Œufs durs', 'Plats cuits'), P('Salade iceberg coupée'),
    P('Sauce tomate maison', 'Sauces'), P('Maïs (boîte ouverte)', 'Conserves & bocaux'), P('Cheddar tranché (ouvert)', 'Fromages & produits laitiers'),
];
const names = (q) => searchProducts(CATALOG, q).items.map((p) => p.name);

describe('recherche de produit DLC', () => {
    it('plie accents, majuscules, ligatures et pluriels', () => {
        expect(fold('  Œufs DURS  ')).toBe('oeufs durs');
        expect(fold('Maïs (boîte ouverte)')).toBe('mais boite ouverte');
        expect(stem('oignons')).toBe('oignon');
        expect(stem('mais')).toBe('mai'); // même règle des deux côtés : « maïs » et « mais » se retrouvent
        expect(stem('pot')).toBe('pot');
    });

    it('les mots demandés par le propriétaire donnent une fiche prête', () => {
        expect(names('oignon')).toEqual(expect.arrayContaining(['Oignons rouges émincés', 'Oignons ciselés']));
        expect(names('tomate')).toContain('Tomates tranchées');
        expect(names('poivron')).toContain('Poivrons coupés');
        expect(names('champignon')).toContain('Champignons frais émincés');
        expect(names('poulet')).toEqual(expect.arrayContaining(['Poulet mariné', 'Poulet cuit émincé']));
    });

    it('singulier ou pluriel, avec ou sans accent, c’est pareil', () => {
        expect(names('oignons')).toEqual(names('oignon'));
        expect(names('OEUF')).toContain('Œufs durs');
        expect(names('mais')).toContain('Maïs (boîte ouverte)');
        expect(names('emince')).toContain('Oignons rouges émincés');
    });

    it('pardonne les fautes de frappe (« ognion », « chompignion », « poivon », « tomatte »)', () => {
        expect(names('ognion')).toContain('Oignons rouges émincés');
        expect(names('chompignion')).toContain('Champignons frais émincés');
        expect(names('poivon')).toContain('Poivrons coupés');
        expect(names('tomatte')).toContain('Tomates tranchées');
        expect(names('pouelt')).toContain('Poulet mariné'); // transposition
    });

    it('signale que les résultats sont approchants — et pas pour une saisie juste', () => {
        expect(searchProducts(CATALOG, 'ognion').fuzzy).toBe(true);
        expect(searchProducts(CATALOG, 'oignon').fuzzy).toBe(false);
        expect(searchProducts(CATALOG, '').fuzzy).toBe(false);
    });

    it('comprend les mots du quotidien', () => {
        expect(names('champi')).toContain('Champignons frais émincés');
        expect(names('patate')).toEqual(['Pommes de terre épluchées']);
        expect(names('mayo')).toContain('Mayonnaise (pot ouvert)');
        expect(names('steack')).toContain('Steak haché décongelé');
    });

    it('plusieurs mots : tous doivent être retrouvés', () => {
        expect(names('poulet cuit')).toEqual(['Poulet cuit émincé']);
        expect(names('oignon frit')).toEqual(['Oignons frits (sachet ouvert)']);
        expect(names('tomate poulet')).toEqual([]);
    });

    it('cherche aussi dans la famille, mais passe après le nom', () => {
        const r = names('sauces');
        expect(r).toEqual(expect.arrayContaining(['Mayonnaise (pot ouvert)', 'Sauce tomate maison']));
        // « tomate » : les produits dont le NOM contient tomate avant ceux d’une famille qui la citerait
        expect(names('tomate').slice(0, 3)).toEqual(expect.arrayContaining(['Tomates tranchées']));
    });

    it('un mot exact passe avant un approchant, et l’ordre reçu est gardé à égalité', () => {
        const r = names('oignon');
        expect(r.slice(0, 4)).toEqual(['Oignons rouges émincés', 'Oignons blancs émincés', 'Oignons ciselés', 'Oignons frits (sachet ouvert)']);
        const mixed = searchProducts([P('Poivrons coupés'), P('Poivron')], 'poivro').items.map((p) => p.name);
        expect(mixed).toEqual(['Poivrons coupés', 'Poivron']);
    });

    it('ne ramène pas n’importe quoi : mots trop courts ou trop différents', () => {
        expect(names('xyz')).toEqual([]);
        expect(names('zzzzzz')).toEqual([]);
        expect(names('ab')).toEqual([]);
        expect(matchProduct('sel', P('Sauce tomate maison'))).toBeNull();
        expect(names('bacon')).toEqual([]);
        // deux fautes ne sont admises que si la 1re lettre est juste (« bovron » ≠ poivron, « ognion » = oignon)
        expect(names('bovron')).toEqual([]);
        expect(names('boivron')).toContain('Poivrons coupés'); // une seule faute : pardonnée
    });

    it('sans saisie, la liste revient telle quelle', () => {
        expect(searchProducts(CATALOG, '   ').items).toBe(CATALOG);
    });

    it('distance d’édition : substitution, insertion, transposition', () => {
        expect(editDistance('chat', 'chat')).toBe(0);
        expect(editDistance('poulet', 'pouelt')).toBe(1);
        expect(editDistance('ognion', 'oignon')).toBe(2);
        expect(editDistance('', 'abc')).toBe(3);
    });
});
