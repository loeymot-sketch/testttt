import { describe, it, expect } from 'vitest';
import fs from 'node:fs';
import path from 'node:path';

/**
 * L'HISTORIQUE DOIT MONTRER LE STATUT, ET NE PAS MENTIR SUR LE PAIEMENT.
 *
 * Deux constats du superviseur adverse à la ronde 3 (2026-08-25), qui se nourrissent
 * l'un l'autre — et c'est ce qui les rend coûteux ensemble.
 *
 * C-001 — la colonne ACTION, collante à droite, RECOUVRAIT la colonne STATUT. Mesuré au
 * pixel : l'en-tête se lisait « S· ACTION », et chaque badge de statut était réduit à un
 * croissant coloré de 2-3 px avant d'être mangé. Déclencheur : des numéros de commande de
 * 17 caractères (« AUDC-RICHE-7GFAQZ ») décalent les colonnes d'environ 55 px.
 *
 * J'avais « corrigé » ça au round précédent en resserrant les marges à 6 px, en écrivant
 * que « la table tient sur les deux gabarits du parc ». C'était vrai des données que
 * j'avais sous les yeux. J'ai remesuré sur celles d'aujourd'hui : 0 recouvrement, 0
 * débordement, aucun numéro de plus de 12 caractères. Le défaut ne se reproduit pas — il
 * attend juste le bon jeu de données. Un correctif qui dépend de la longueur des chaînes
 * stockées en base n'est pas un correctif.
 *
 * C-002 — SEPT lignes portaient simultanément « À encaisser » et « Annulée ». Une commande
 * annulée ne peut pas être à encaisser, et l'écran d'encaissement capturé huit secondes
 * plus tôt n'en contenait aucune : c'est le libellé qui mentait, pas la file.
 *
 * Les deux ensemble : la colonne qui désambiguïse (« Annulée ») était précisément celle que
 * C-001 rendait illisible. À l'écran, le caissier ne lisait QUE « À encaisser », sept fois.
 */

const VUE = path.resolve(
    __dirname,
    '../../resources/js/components/admin/orderHistory/HistoriqueListComponent.vue'
);
const FR = path.resolve(__dirname, '../../resources/js/languages/fr.json');

const source = () => fs.readFileSync(VUE, 'utf8');

describe('historique — statut lisible', () => {
    it('C-001 : la colonne STATUT est ÉPINGLÉE, pas seulement mieux espacée', () => {
        const src = source();

        expect(
            /\.hist-statut-col\s*\{[^}]*position:\s*sticky/.test(src),
            'RÉGRESSION C-001 : la colonne STATUT n\'est plus épinglée. Resserrer les marges '
            + 'ne suffit pas — le recouvrement revient dès qu\'un numéro de commande est long. '
            + 'Une colonne collante ne doit JAMAIS pouvoir recouvrir du contenu.'
        ).toBe(true);

        // Calée juste à gauche d'ACTION, par une variable et non par un nombre recopié.
        expect(
            /\.hist-statut-col\s*\{[^}]*right:\s*var\(--hist-action-w/.test(src),
            'le calage de STATUT doit passer par la variable de largeur d\'ACTION : un nombre '
            + 'recopié à la main dérive au premier changement de largeur, et les deux colonnes '
            + 'se chevauchent de nouveau — sans que rien ne le signale.'
        ).toBe(true);
    });

    it('C-001 : STATUT passe SOUS action, jamais au-dessus', () => {
        const src = source();
        const statut = src.match(/\.hist-statut-col\s*\{([^}]*)\}/);
        const action = src.match(/\n\.hist-action-col\s*\{([^}]*)\}/);
        expect(statut, 'règle .hist-statut-col introuvable').not.toBeNull();
        expect(action, 'règle .hist-action-col introuvable').not.toBeNull();

        const zStatut = Number((statut[1].match(/z-index:\s*(\d+)/) || [])[1]);
        const zAction = Number((action[1].match(/z-index:\s*(\d+)/) || [])[1]);
        expect(Number.isFinite(zStatut) && Number.isFinite(zAction)).toBe(true);
        expect(
            zStatut < zAction,
            `STATUT (z=${zStatut}) doit rester SOUS ACTION (z=${zAction}) : sinon c'est le `
            + 'statut qui recouvre les boutons, et on a juste inversé le défaut.'
        ).toBe(true);
    });

    it('C-001 : la place du groupe collant est RÉSERVÉE, et la table peut s\'élargir', () => {
        const src = source();

        // Épingler STATUT ne suffisait pas : mesuré à l'écran, ça déplaçait simplement le
        // recouvrement sur la DATE (« 18:34, 25-08-2026 » → « 25-0 »). Un élément collant se
        // pose sur ce qui passe dessous, et il y aura toujours une colonne « dessous ».
        expect(
            /nth-last-child\(3\)[^}]*padding-right:\s*calc\(var\(--hist-action-w\)\s*\+\s*var\(--hist-statut-w\)/
                .test(src.replace(/\s+/g, ' ')),
            'RÉGRESSION : la dernière colonne non collante ne réserve plus la place des deux '
            + 'colonnes épinglées. Elles se poseront de nouveau sur du texte.'
        ).toBe(true);

        // Et la réserve ne vaut QUE si la table peut s'élargir. Contrainte à 100 %, une marge
        // droite ne l'élargit pas : elle VOLE de la place au texte, et aggrave la troncature.
        // C'est exactement ce que la mesure a montré avant l'ajout de min-width.
        expect(
            /\.hist-table\s*\{[^}]*min-width:\s*max-content/.test(src),
            'RÉGRESSION : sans `min-width: max-content`, la réserve se retourne contre '
            + 'elle-même — elle rétrécit le contenu au lieu d\'élargir la table.'
        ).toBe(true);
    });

    it('C-001 : les DEUX colonnes épinglées portent les mêmes fonds (aucune couture)', () => {
        const src = source();

        // Chaque règle de fond posée pour ACTION doit couvrir STATUT — sinon la colonne
        // nouvellement collante laisse voir le texte à travers, ce qui est le défaut
        // C-018/C-019 déjà corrigé une fois sur sa voisine.
        [
            ['en-tête', /\.db-table-head \.hist-statut-col/],
            ['zébrure impaire', /nth-child\(odd\) \.hist-statut-col/],
            ['survol', /:hover \.hist-statut-col/],
        ].forEach(([quoi, motif]) => {
            expect(
                motif.test(src),
                `la règle de fond « ${quoi} » ne couvre pas la colonne STATUT épinglée : le `
                + 'contenu défilant se verra À TRAVERS.'
            ).toBe(true);
        });
    });
});

describe('historique — le badge de paiement ne ment pas', () => {
    it('C-002 : le badge consulte le STATUT DE LA COMMANDE, pas seulement le paiement', () => {
        const src = source();

        expect(
            /paymentLabel:\s*function\s*\(ps,\s*order\)/.test(src),
            'RÉGRESSION C-002 : `paymentLabel` ne reçoit plus la commande. Sans `order.status`, '
            + 'il ne peut pas savoir qu\'elle est annulée et affiche « À encaisser » sur une '
            + 'commande qui ne sera jamais encaissée.'
        ).toBe(true);

        expect(
            /paymentBadgeClass:\s*function\s*\(ps,\s*order\)/.test(src),
            'la COULEUR doit suivre le même raisonnement que le libellé, sinon on obtient un '
            + 'badge « Sans objet » peint en rouge de dette à recouvrer.'
        ).toBe(true);
    });

    it('C-002 : annulée ET rejetée sont toutes deux sans objet de paiement', () => {
        const src = source();
        const f = src.match(/commandeSansObjetDePaiement:\s*function\s*\(order\)\s*\{([\s\S]*?)\n        \},/);
        expect(f, 'le prédicat commandeSansObjetDePaiement a disparu').not.toBeNull();

        expect(f[1]).toContain('orderStatusEnum.CANCELED');
        expect(
            f[1].includes('orderStatusEnum.REJECTED'),
            'une commande REJETÉE n\'est pas plus encaissable qu\'une annulée : les deux '
            + 'doivent être couvertes.'
        ).toBe(true);

        // Par l'énumération, jamais par un nombre en dur : 16 et 19 ne veulent rien dire.
        expect(
            /===\s*1[69]\b/.test(f[1]),
            'le prédicat compare des valeurs numériques en dur au lieu de l\'énumération'
        ).toBe(false);
    });

    it('C-002 : le libellé « Sans objet » existe et le badge a sa couleur neutre', () => {
        const l = JSON.parse(fs.readFileSync(FR, 'utf8')).label;
        expect(l['payment_moot'], 'libellé payment_moot manquant').toBeTruthy();
        expect(String(l['payment_moot']).length).toBeGreaterThan(3);

        const src = source();
        expect(src).toContain("return 'pay-moot';");
        expect(
            /\.pay-moot\s*\{[^}]*background/.test(src),
            'la classe pay-moot n\'a pas de style : le badge sortirait sans fond, illisible.'
        ).toBe(true);
    });

    it('C-002 : les appels SANS commande gardent leur comportement d\'avant', () => {
        const src = source();
        const f = src.match(/paymentLabel:\s*function\s*\(ps,\s*order\)\s*\{([\s\S]*?)\n        \},/);
        expect(f).not.toBeNull();

        // Le second paramètre est optionnel : la garde doit tester sa présence avant de
        // l'utiliser, sinon tout appel à un argument casse.
        expect(
            /if\s*\(order\s*&&/.test(f[1]),
            'la garde doit vérifier la présence de `order` : sans ça, les appels existants à '
            + 'un seul argument lèveraient.'
        ).toBe(true);
    });
});
