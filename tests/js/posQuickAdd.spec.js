import { describe, it, expect } from 'vitest';

/**
 * [T-CAISSE-1TAP 2026-08-19 · GOAL owner] Le propriétaire : « l'interface n'est
 * pas si dynamique, pas si rapide, il faudra vraiment beaucoup de travail côté
 * chargement, rapidité et fluidité ».
 *
 * OBSERVÉ EN DIRECT (navigateur réel, /admin/pos, 2026-08-19) : ajouter un simple
 * Coca-Cola 33cl — un produit qui n'a AUCUNE option — ouvrait une modale
 * PLEIN ÉCRAN ne contenant qu'un champ « Instruction spéciale » vide et un aperçu
 * ticket, puis exigeait un SECOND clic sur « Ajouter au panier ». Soit deux fois
 * le travail et une modale bloquante pour chaque boisson, chaque dessert, chaque
 * accompagnement simple — en plein coup de feu.
 *
 * Cette suite verrouille la détection « ce produit n'a rien à demander ». Un
 * produit qui a la moindre option doit continuer d'ouvrir le wizard : ajouter un
 * sandwich sans laisser choisir la viande serait bien pire que deux clics.
 *
 * ÉCHAPPATOIRE conservée : le caissier peut toujours rouvrir n'importe quelle
 * ligne du panier via le crayon ✎ pour y ajouter une consigne.
 */
import { itemHasNoChoices } from '../../resources/js/helpers/posQuickAdd';

const item = (over = {}) => ({
    id: 52,
    name: 'Coca-Cola 33cl',
    itemAttributes: [],
    extras: [],
    addons: [],
    ...over,
});

describe('itemHasNoChoices — quels produits peuvent être ajoutés en un appui', () => {
    it('cas réel : Coca-Cola 33cl n\'a aucune option', () => {
        expect(itemHasNoChoices(item())).toBe(true);
    });

    it('SÛRETÉ : un produit avec des attributs ouvre le wizard', () => {
        // Un sandwich sans choix de viande partirait en cuisine incomplet.
        expect(itemHasNoChoices(item({
            itemAttributes: [{ id: 1, name: 'Viande' }],
        }))).toBe(false);
    });

    it('SÛRETÉ : un produit avec des extras ouvre le wizard', () => {
        expect(itemHasNoChoices(item({
            extras: [{ id: 49, name: 'Salade' }],
        }))).toBe(false);
    });

    it('SÛRETÉ : un produit avec des formules ouvre le wizard', () => {
        // Sans ça, on perdrait la possibilité de passer le produit en menu.
        expect(itemHasNoChoices(item({
            addons: [{ id: 25, name: 'Menu (Frites + Boisson)' }],
        }))).toBe(false);
    });

    it('SÛRETÉ : une seule option suffit à ouvrir le wizard', () => {
        expect(itemHasNoChoices(item({ itemAttributes: [{ id: 1 }], extras: [{ id: 2 }] }))).toBe(false);
    });

    it('SÛRETÉ : forme inattendue → on ouvre le wizard (jamais d\'ajout aveugle)', () => {
        // Si le produit n'a pas la forme normalisée attendue, on ne prend AUCUN
        // risque : mieux vaut un clic de trop qu'un produit envoyé sans ses choix.
        expect(itemHasNoChoices(null)).toBe(false);
        expect(itemHasNoChoices(undefined)).toBe(false);
        expect(itemHasNoChoices({})).toBe(false);
        expect(itemHasNoChoices({ itemAttributes: [], extras: [] })).toBe(false);
        expect(itemHasNoChoices({ itemAttributes: null, extras: [], addons: [] })).toBe(false);
    });
});
