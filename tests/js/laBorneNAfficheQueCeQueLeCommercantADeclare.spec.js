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

        // [ONB-12 2026-08-28 — ÉLARGI le même jour après audit adverse]
        //
        // La première version de ce cliquet cherchait `Le Cayenne|cayenne.webp|Halal`
        // et annonçait fièrement « 0 ». Elle passait à côté de la plus grosse
        // identité en dur de l'écran : `brandLogo: ATTRACT_BASE + 'logo.webp'`, la
        // MARQUE DÉPOSÉE, mascotte comprise, en haut et en grand sur chaque borne.
        //
        // Un cliquet qui rend « 0 » en ne regardant pas l'essentiel est pire qu'aucun
        // cliquet : il rassure. C'est le motif « sentinelle au mauvais périmètre »,
        // et celle-ci était la mienne.
        //
        // On surveille désormais TOUT actif servi depuis le dossier d'attract, quel
        // que soit son nom de fichier — c'est le dossier entier qui porte l'identité
        // d'un établissement particulier.
        const marqueurs = (s.match(/Le Cayenne|>\s*Halal\s*</g) || []);

        expect(
            marqueurs.length,
            "Une identité « Le Cayenne » est réapparue dans la borne.\n"
            + `Attendu : 0. Trouvé : ${marqueurs.length} → ${marqueurs.join(', ')}\n`
            + 'Sortir la marque vers la donnée, ne jamais la réécrire en dur.',
        ).toBe(0);

        // ET SURTOUT : aucun visuel de marque servi depuis le dossier d'attract.
        const sansCommentaires = s
            .replace(/\/\*[\s\S]*?\*\//g, '')
            .replace(/^\s*\/\/.*$/gm, '')
            .replace(/<!--[\s\S]*?-->/g, '');

        const visuels = (sansCommentaires.match(/ATTRACT_BASE\s*\+\s*'[^']+'/g) || []);

        expect(
            visuels.length,
            'Un visuel de marque est servi en dur depuis `/images/kiosk-attract/`.\n'
            + `Trouvé : ${visuels.join(', ')}\n`
            + "Ce dossier porte l'identité d'un établissement précis : logo déposé,\n"
            + 'mascotte, photos de SA carte. Rien de tout cela ne doit être imposé à\n'
            + 'un autre commerçant. Le logo vient des réglages, la vitrine de la carte.',
        ).toBe(0);
    });

    it("le bandeau de marque affiche le logo du commerçant, jamais un logo livré", () => {
        const s = source();

        expect(
            s,
            '`brandLogo` doit démarrer vide : toute valeur par défaut est le logo\n'
            + "de quelqu'un d'autre sur l'écran client d'un nouveau commerçant.",
        ).toMatch(/brandLogo:\s*null/);

        // On vérifie que le logo VIENT des réglages, sans épingler l'ordre exact des
        // replis : la première version de ce banc figeait l'expression littérale et
        // rougissait dès qu'on ajoutait un cran, sur du code correct. L'ordre précis
        // est couvert par le banc de comportement, qui monte le composant.
        expect(
            s,
            'Le logo doit être lu depuis les réglages, comme le reste de la marque.',
        ).toMatch(/this\.brandLogo\s*=\s*data\./);
    });

    it("la vitrine écarte les images de substitution au lieu de les afficher en grand", () => {
        const s = source();

        // `Item::getThumbAttribute()` renvoie TOUJOURS une chaîne et retombe sur
        // `item-default.svg` : filtrer sur `i.thumb` n'écartait donc rien, et un
        // carré gris 200×200 s'affichait plein cadre (900×884), agrandi et animé.
        expect(
            s,
            "Le filtre de la vitrine ne reconnaît pas les images de substitution :\n"
            + 'un rectangle gris tournerait en vitrine à la place des plats.',
        ).toContain('item-default.svg');

        expect(
            s,
            'La vitrine doit préférer `cover` (pleine taille) à `thumb` : le cadre\n'
            + 'fait 900 px de large, une vignette 320 px y est étirée de trois fois.',
        ).toMatch(/estReelle\(article\?\.cover\)/);
    });

    it("sans produit, la légende ne promet pas des produits au-dessus du vide", () => {
        const s = source();

        // Le gabarit est en positionnement absolu : masquer la vitrine sans masquer
        // sa légende laissait « Nos incontournables » suivi d'environ 1020 px de
        // fond vide. Une promesse au-dessus d'un trou.
        expect(
            s,
            "La légende « Nos incontournables » s'affiche même sans vitrine :\n"
            + 'elle annonce des produits au-dessus d\'un millier de pixels vides.',
        ).toMatch(/<div v-if="products\.length" class="cay-eyebrow"/);
    });

    it('la vitrine vient de la carte du commerçant, pas d\'une liste écrite en dur', () => {
        const s = source();

        // LE DÉFAUT : huit produits de Le Cayenne étaient écrits ici avec leurs
        // photos. Un nouveau commerçant ouvrait sa borne sur des burgers qu'il ne
        // vend pas, et aucun écran ne permettait de les changer.
        expect(
            s,
            'La vitrine est chargée depuis les produits que le commerçant a lui-même\n'
            + 'mis en avant. Si cet appel disparaît, la borne redevient muette ou pire,\n'
            + "réaffiche une liste écrite en dur.",
        ).toContain("axios.get('frontend/item/featured-items')");

        expect(
            s,
            'La liste doit démarrer VIDE : une valeur de repli codée en dur ferait\n'
            + "réapparaître la carte d'un autre établissement.",
        ).toMatch(/products:\s*\[\s*\]/);
    });

    it("sans produit déclaré, la borne n'affiche pas de vitrine du tout", () => {
        const s = source();

        // Le choix assumé : mieux vaut un écran sobre — logo, accueil, invite —
        // que la vitrine d'un autre établissement. Le `v-if` porte ce choix.
        const hero = s.slice(s.indexOf('cay-hero"'), s.indexOf('cay-hero-glow'));

        expect(
            hero,
            "Le bloc vitrine s'affiche même sans produit : il rendrait un cadre vide.",
        ).toContain('v-if="products.length"');

        // Et la rotation ne doit pas diviser par zéro — `% 0` donne NaN et fige
        // l'index sur une diapositive qui n'existe pas.
        const rotation = s.slice(s.indexOf('startCarousel()'), s.indexOf('async chargerLaVitrine'));

        expect(
            rotation,
            'La rotation calcule `% this.products.length` sans garde : sur une carte\n'
            + "vide elle produit NaN.",
        ).toMatch(/if \(!this\.products\.length\) return;/);
    });

    it("le tampon « 100 % Halal » n'est affiché que s'il est déclaré", () => {
        const s = source();

        // C'est une affirmation sur la nourriture servie — vérifiable, engageante,
        // et propre à chaque établissement. Elle était écrite en dur : tout
        // commerçant installant le produit la portait sans l'avoir dite.
        expect(
            s,
            "Le tampon s'affiche sans condition : il affirme à la place du commerçant.",
        ).toContain('class="cay-stamp" v-if="tamponHalal"');

        expect(
            s,
            'Le réglage doit être lu depuis les paramètres, et éteint par défaut.',
        ).toContain("data.kiosk_halal_stamp ?? 0");

        expect(
            s,
            'La valeur initiale doit être `false` : on n\'affirme rien tant que le\n'
            + "commerçant ne l'a pas déclaré.",
        ).toMatch(/tamponHalal:\s*false/);
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
