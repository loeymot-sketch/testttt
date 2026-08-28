import { describe, it, expect } from 'vitest';
import fs from 'fs';
import path from 'path';

/**
 * [ONB-01 2026-08-28] La ROTATION D'ACCUEIL de la borne ne nomme plus un autre
 * établissement.
 *
 * ⚠️ Portée volontairement étroite, et dite comme telle : deux identités « Le
 * Cayenne » restent en dur ailleurs dans ce fichier (voir le cliquet en bas).
 * Un titre qui promettrait « la borne n'affiche que ce que le commerçant a
 * déclaré » serait faux, et refermerait la question.
 *
 * ═══ LE DÉFAUT ═══
 *
 * `KioskIdleScreenComponent.vue`, rotation d'accueil :
 *
 *     (this.welcomeTitle || 'Bienvenue chez') + ' <span class="cay-accent">Le Cayenne</span>',
 *     ...
 *     '<span class="cay-accent">Halal</span> · Frais · Préparé minute',
 *
 * Le nom du restaurant était **concaténé en dur APRÈS** le titre réglable : même en
 * réglant son titre d'accueil, le commerçant voyait « Le Cayenne » s'y ajouter. Et la
 * troisième ligne n'avait ni clé de traduction ni repli — elle AFFIRMAIT que
 * l'établissement est halal.
 *
 * Pour une « publication vierge », c'est le défaut le plus visible qui soit : le
 * premier écran que voit un client porte le nom de quelqu'un d'autre, et fait à la
 * place du commerçant une déclaration qu'il n'a peut-être pas le droit de faire.
 *
 * ═══ CE QUI EXISTAIT DÉJÀ, À VINGT-CINQ LIGNES DE LÀ ═══
 *
 * `this.restaurantName = data.company_name || data.site_name || <défaut traduit>`.
 * La donnée était là. C'est le motif de la nuit : la chaîne est complète partout sauf
 * à l'endroit où on s'en sert.
 *
 * ⚠️ Ce fichier n'est PAS dans les zones gelées (§7 ne gèle que `KioskWizard`,
 * `KioskApp` et `KioskUpsell`).
 */
describe("la rotation d'accueil de la borne ne nomme plus un autre établissement", () => {
    const racine = process.cwd();
    const BORNE = 'resources/js/components/frontend/kiosk/KioskIdleScreenComponent.vue';
    const source = () => fs.readFileSync(path.join(racine, BORNE), 'utf8');

    it('le relevé mord — sinon ce banc serait vert en ne lisant rien', () => {
        const s = source();

        expect(s.length, "L'écran d'accueil est vide ou introuvable.").toBeGreaterThan(10000);
        // Témoin : la rotation existe toujours.
        expect(s).toContain('headlines()');
        expect(s).toContain('this.restaurantName');
    });

    /** La rotation seule, commentaires de bloc retirés — on mesure le CODE. */
    const rotation = () => {
        const s = source();
        const brut = s.slice(s.indexOf('headlines()'), s.indexOf('ctaTitle()'));

        // Sans ça, ce banc buterait sur son propre commentaire explicatif, qui cite
        // forcément la chaîne qu'il interdit.
        return brut.replace(/\/\*[\s\S]*?\*\//g, '').replace(/\/\/[^\n]*/g, '');
    };

    it("aucun nom d'établissement n'est écrit en dur dans la rotation", () => {
        const code = rotation();

        expect(code.length, 'La rotation est introuvable.').toBeGreaterThan(150);

        expect(
            code,
            "Le nom d'un établissement est écrit en dur dans la rotation d'accueil.\n"
            + "Un nouveau commerçant afficherait le nom d'un autre sur le premier écran\n"
            + 'que voit son client.',
        ).not.toContain('Le Cayenne');

        expect(
            code,
            'Le nom doit venir du réglage (`restaurantName`, alimenté par `company_name`).',
        ).toContain('this.restaurantName');
    });

    it("la rotation ne fait plus la déclaration « Halal » à la place du commerçant", () => {
        const code = rotation();

        // « Halal » est une déclaration réglementée : la borne ne peut pas la faire à
        // la place d'un établissement qui ne l'a pas déclarée. Cette ligne n'avait
        // même pas de clé de traduction — donc aucun moyen de la retirer.
        expect(code).not.toContain('Halal');

        // Passer par une clé ne suffirait pas si le repli affirmait la même chose.
        expect(code).toContain("lc('kiosk.idle_screen.line_claims'");
    });

    /**
     * ⚠️ CE QUE CE BANC NE COUVRE PAS — dit ici plutôt que passé sous silence.
     *
     * Deux identités « Le Cayenne » restent EN DUR dans ce fichier, hors rotation :
     *
     *   1. un tampon « 100 % Halal » dans le gabarit (`<div class="cay-stamp">`) ;
     *   2. les HUIT produits du carrousel d'attente (`terminator.webp`,
     *      `cayenne.webp`, `double-cheese.webp`…) — la borne d'un nouveau commerçant
     *      cycle sur les produits d'un autre restaurant.
     *
     * Les corriger exige `KioskSetup`, qui appartient à une autre voie (§2.2), et
     * retirer le tampon serait une régression pour Le Cayenne, qui y a droit. La
     * bonne forme est un RÉGLAGE avec la valeur actuelle par défaut — « sortir la
     * marque du code vers la donnée » (§0.2 du programme). Fiche de renvoi :
     * `reports/audit/onboarding-commercant-2026-08-26/FICHES_DE_RENVOI.md`.
     *
     * En attendant, un CLIQUET : le nombre d'identités en dur ne peut pas augmenter.
     * Sans lui, un banc vert sur la seule rotation laisserait croire le problème réglé.
     */
    it("le nombre d'identités en dur ne peut pas augmenter (cliquet)", () => {
        const s = source()
            .replace(/\/\*[\s\S]*?\*\//g, '')
            .replace(/\/\/[^\n]*/g, '')
            .replace(/<!--[\s\S]*?-->/g, '');

        const marqueurs = (s.match(/Le Cayenne|cayenne\.webp|Halal/g) || []);

        expect(
            marqueurs.length,
            "Une identité « Le Cayenne » de plus est apparue dans la borne.\n"
            + `Connues et documentées : 3 (tampon Halal ×1, produit « Le Cayenne » ×1,\n`
            + `image cayenne.webp ×1). Trouvées : ${marqueurs.length} → ${marqueurs.join(', ')}\n`
            + 'Sortir la marque vers la donnée, ne pas en ajouter.',
        ).toBeLessThanOrEqual(3);
    });

    it("le nom réglable est échappé avant d'entrer dans du HTML", () => {
        const s = source();

        // `restaurantName` vient d'un réglage LIBRE et est concaténé dans une chaîne
        // HTML. Le composant assainit les titres plus bas via DOMPurify, mais une
        // injection ne doit pas dépendre de l'ordre dans lequel deux protections se
        // rencontrent.
        const rotation = s.slice(s.indexOf('headlines()'), s.indexOf('ctaTitle()'));

        expect(
            rotation,
            "Le nom d'établissement entre dans du HTML sans être échappé.",
        ).toMatch(/replace\(\/&\/g, '&amp;'\)/);

        expect(rotation).toMatch(/replace\(\/</);
    });
});
