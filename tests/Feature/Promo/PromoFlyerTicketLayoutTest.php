<?php

namespace Tests\Feature\Promo;

use App\Services\Promo\PromoFlyerEscPosRenderer;
use App\Services\Promo\PromoFlyerService;
use Tests\TestCase;

/**
 * TICKET PROMO — géométrie ET hiérarchie de conversion, prouvées sur les OCTETS envoyés
 * à l'imprimante. C'est le seul niveau qui prouve quelque chose : un aperçu écran ne dit rien de
 * ce que le papier montrera.
 *
 * ── Historique des défauts que cette suite verrouille ────────────────────────────────────────
 *
 * [PHOTO OWNER IMG_2090 · 2026-08-08] Ticket mal centré et mots coupés. Deux causes :
 *   1. LARGEUR : `renderBytes` avait 48 en dur et le contrôleur n'admettait que `[32, 48]` — donc
 *      42, la largeur réelle de la caisse (`RECEIPT_WIDTH_CHARS=42`, calée en juillet sur la photo
 *      IMG_1709 pour le ticket de COMMANDE), était ramenée à 48. Le ticket promo n'avait jamais été
 *      branché sur ce réglage. L'imprimante réenroule au CARACTÈRE : « rien que pour toi » devenait
 *      « pour t » / « oi », et les 48 « = » débordaient en 42 + 6.
 *   2. CENTRAGE DEUX FOIS : le pilote est en mode centré pour tout le ticket, et `centerLine()`
 *      ajoutait EN PLUS des espaces à gauche → la ligne partait vers la DROITE.
 *
 * [CONVERSION 2026-08-08] Refonte de la hiérarchie. Ce qui est testé ici est du DESIGN, mais du
 * design vérifiable :
 *   · la récompense est en INVERSION VIDÉO pleine largeur — seul contraste dont dispose une
 *     imprimante thermique (ni couleur, ni graisse variable) ;
 *   · l'ACTION FACILE (scanner) passe AVANT le repli coûteux (taper le code) ;
 *   · le code n'est plus en double taille : deux éléments qui crient aussi fort qu'une remise,
 *     c'est une remise qu'on ne voit plus ;
 *   · le texte n'annonce plus « jusqu'a -30% » au-dessus d'un code à -10% — deux nombres
 *     contradictoires sur le même papier détruisent la promesse.
 */
class PromoFlyerTicketLayoutTest extends TestCase
{
    private const LARGEUR = 42;

    private function donnees(): array
    {
        $d = PromoFlyerService::DEFAULTS;

        return [
            'headline' => 'LE CAYENNE',
            'greeting' => 'Bonsoir',
            // Les VRAIES clés lues par le rendu. Avec `greeting_name`/`greeting_civility` la
            // salutation sortait VIDE et toute cette suite passait sans jamais exercer la ligne
            // « Merci M. Dorian ! » — précisément celle que le propriétaire citait. Un jeu de
            // données aux mauvaises clés est un test creux qui ne se voit pas.
            'civility' => 'M.',
            'customer_name' => 'Dorian',
            'intro' => $d['intro'],
            'discount_percent' => 10,
            'code' => 'DORIAN-TH2P',
            'valid_until' => '07/09/2026',
            'qr_url' => 'https://www.lecayenne.fr/?promo=DORIAN-TH2P',
            'site_url' => 'www.lecayenne.fr',
            'order_phone' => '03 65 67 82 91',
            'savings_note' => $d['savings_note'],
            'footer_note' => $d['footer_note'],
        ];
    }

    /**
     * Dépouilleur ESC/POS RÉEL. Il consomme chaque séquence de commande selon sa longueur connue,
     * au lieu de « filtrer les octets non imprimables » — un filtre naïf laisse passer la LETTRE de
     * la commande (`GS B` devient « B ») et colle ce parasite en tête de ligne. Une assertion
     * « la ligne ne commence pas par un espace » passait alors pour la mauvaise raison : c'est
     * exactement le piège du test creux, et il s'était refermé sur moi.
     *
     * @return array<int, array{texte: string, inverse: bool, double: bool}>
     */
    private function lignes(string $octets): array
    {
        $lignes = [];
        $courant = '';
        $inverse = false;
        $double = false;

        $n = strlen($octets);
        for ($i = 0; $i < $n; $i++) {
            $c = $octets[$i];

            if ($c === "\x1B") { // ESC
                $suite = $octets[$i + 1] ?? '';
                if ($suite === '@') { $i += 1; continue; }              // init
                if ($suite === 'E') {                                    // gras
                    $i += 2; continue;
                }
                $i += 2; continue;                                       // ESC x n (a, t, d, -, …)
            }

            if ($c === "\x1D") { // GS
                $suite = $octets[$i + 1] ?? '';
                if ($suite === 'B') {                                    // inversion vidéo
                    $inverse = ord($octets[$i + 2] ?? "\x00") === 1;
                    $i += 2; continue;
                }
                if ($suite === '!') {
                    // `GS ! n` : quartet HAUT = largeur, quartet BAS = hauteur
                    // (`(($w-1) << 4) | ($h-1)`). Seule la LARGEUR consomme deux colonnes — une
                    // double HAUTEUR laisse les 42 colonnes intactes, le pilote le documente
                    // lui-même. Confondre les deux faisait échouer des lignes parfaitement bonnes.
                    $double = (ord($octets[$i + 2] ?? "\x00") >> 4) > 0;
                    $i += 2; continue;
                }
                if ($suite === '(') {                                    // bloc QR : longueur portée
                    $pL = ord($octets[$i + 3] ?? "\x00");
                    $pH = ord($octets[$i + 4] ?? "\x00");
                    $i += 4 + ($pL + $pH * 256);
                    continue;
                }
                if ($suite === 'v') {                                    // image raster
                    return $lignes; // pas de logo dans ce banc : on s'arrête proprement si présent
                }
                $i += 2; continue;                                       // GS x n (V, …)
            }

            if ($c === "\n") {
                // On enregistre l'état COURANT : les commandes qui referment un effet
                // (`GS B 0`, `GS ! 0`) arrivent APRÈS le saut de ligne, donc l'état en vigueur au
                // moment du LF est bien celui sous lequel le texte a été imprimé. Un drapeau
                // « collant » par ligne faisait fuir l'effet sur la ligne suivante — c'est ce qui
                // faisait prendre un bandeau pleine largeur pour un bandeau double largeur.
                $lignes[] = ['texte' => $courant, 'inverse' => $inverse, 'double' => $double];
                $courant = '';
                continue;
            }

            if (ord($c) >= 0x20) {
                $courant .= $c;
            }
        }

        return array_values(array_filter($lignes, fn ($l) => $l['texte'] !== ''));
    }

    /** @return array<int, array{texte: string, inverse: bool, double: bool}> */
    private function rendu(): array
    {
        return $this->lignes(
            app(PromoFlyerEscPosRenderer::class)->render($this->donnees(), self::LARGEUR)
        );
    }

    private function textes(): array
    {
        return array_map(fn ($l) => rtrim($l['texte']), $this->rendu());
    }

    // ── GÉOMÉTRIE ────────────────────────────────────────────────────────────────────────────

    public function test_aucune_ligne_ne_depasse_la_largeur_de_l_imprimante(): void
    {
        $lignes = $this->rendu();
        $this->assertGreaterThan(10, count($lignes), 'le ticket doit avoir du contenu à mesurer');

        foreach ($lignes as $l) {
            // Une ligne en double largeur consomme 2 colonnes par caractère.
            $max = $l['double'] ? intdiv(self::LARGEUR, 2) : self::LARGEUR;
            $this->assertLessThanOrEqual($max, strlen(rtrim($l['texte'])),
                'ligne de ' . strlen(rtrim($l['texte'])) . ' car. pour un maximum de ' . $max
                . ' : elle sera réenroulée EN PLEIN MOT — |' . $l['texte'] . '|');
        }
    }

    public function test_les_lignes_ordinaires_ne_sont_pas_pre_centrees_a_coups_d_espaces(): void
    {
        foreach ($this->rendu() as $l) {
            if ($l['inverse']) {
                continue; // un bandeau EST fait d'espaces — voir le test dédié juste après
            }
            $this->assertStringStartsNotWith(' ', $l['texte'],
                'ligne pré-centrée alors que le pilote est déjà en mode centré : elle partira vers '
                . 'la DROITE — |' . $l['texte'] . '|');
        }
    }

    // ── CONVERSION ───────────────────────────────────────────────────────────────────────────

    /**
     * LE DISPOSITIF D'ATTENTION. En inversion vidéo le fond noir ne couvre que les caractères
     * imprimés : un bandeau qui ne remplit pas la ligne devient une étiquette noire au milieu du
     * papier. On exige donc la largeur EXACTE, bords compris.
     */
    public function test_la_recompense_est_dans_une_carte_encadree_pleine_largeur(): void
    {
        $t = $this->textes();

        $haut = null;
        $bas = null;
        foreach ($t as $i => $l) {
            if ($haut === null && str_starts_with($l, "\xC9")) { $haut = $i; }   // ╔ en CP858
            if (str_starts_with($l, "\xC8")) { $bas = $i; }                      // ╚ en CP858
        }

        $this->assertNotNull($haut, 'le filet HAUT du coupon a disparu : sans cadre, la remise se '
            . 'confond avec le reste du ticket');
        $this->assertNotNull($bas, 'le filet BAS du coupon a disparu');
        $this->assertGreaterThan($haut, $bas, 'le coupon n\'est pas refermé');

        $this->assertSame(self::LARGEUR, strlen($t[$haut]),
            'le filet ne fait pas la largeur du papier : le cadre ne toucherait pas les bords');
        $this->assertSame(self::LARGEUR, strlen($t[$bas]));

        $dedans = implode(' | ', array_slice($t, $haut + 1, $bas - $haut - 1));
        $this->assertStringContainsString('-10%', $dedans,
            'la remise doit se trouver DANS le coupon, pas à côté');
        $this->assertStringContainsString('DORIAN-TH2P', $dedans,
            'le code doit se trouver DANS le coupon : c\'est le même cadeau, pas deux éléments');
    }

    /**
     * [OWNER 2026-08-09] « mieux que 10% en bloc noir ».
     *
     * L'inversion vidéo pleine largeur avait été choisie la veille comme seul contraste d'une
     * thermique. Le propriétaire l'a REFUSÉE sur le papier réel : un aplat noir bave, chauffe la
     * tête et se lit comme une erreur d'impression, pas comme un cadeau. Décision d'exploitant,
     * prise devant le résultat imprimé — elle prime sur le raisonnement de conception.
     *
     * Ce test empêche le retour silencieux du pavé.
     */
    public function test_aucun_aplat_noir_ne_revient_sur_le_ticket(): void
    {
        $inverses = array_values(array_filter($this->rendu(), fn ($l) => $l['inverse']));

        $this->assertSame([], $inverses,
            'un bandeau en inversion vidéo est de retour alors que le propriétaire l\'a refusé '
            . 'sur le papier réel : le contraste doit venir de la TAILLE et du CADRE');
    }

    /**
     * Le geste facile reste offert, et il reste explicite.
     *
     * [OWNER 2026-08-09] L'ordre « scanner AVANT le code » a été inversé : le propriétaire veut
     * le code — qui porte le PRÉNOM du client — bien visible, et c'est lui qui rend le ticket
     * personnel. Le code vit donc dans le coupon, en haut ; le QR reste juste en dessous comme
     * raccourci. On ne teste plus un ordre, on teste que les DEUX chemins sont proposés.
     */
    public function test_les_deux_chemins_sont_proposes_scan_et_code(): void
    {
        $joint = implode(' | ', $this->textes());

        $this->assertStringContainsString('SCANNE', $joint,
            'l\'appel à scanner a disparu — c\'est le geste le moins coûteux');
        $this->assertStringContainsString('DORIAN-TH2P', $joint,
            'le code a disparu — sans lui, pas de repli pour qui ne scanne pas');
    }

    /**
     * [OWNER 2026-08-09] « le code promo avec son nom plus visible ».
     *
     * La veille, le code avait été volontairement réduit pour ne pas concurrencer la remise. Le
     * propriétaire a tranché l'inverse : le code CONTIENT le prénom du client, c'est lui qui
     * transforme un bon de réduction anonyme en cadeau adressé. Il doit se voir.
     */
    public function test_le_code_est_bien_agrandi(): void
    {
        $agrandi = false;
        foreach ($this->rendu() as $l) {
            if (str_contains($l['texte'], 'DORIAN-TH2P') && $l['double']) {
                $agrandi = true;
            }
        }

        $this->assertTrue($agrandi,
            'le code est repassé en taille normale : il porte le prénom du client, c\'est '
            . 'l\'élément qui rend le ticket personnel');
    }

    /** Rareté + échéance sur une seule ligne, collées à la récompense. */
    public function test_l_urgence_est_affichee_avec_la_date_limite(): void
    {
        $joint = implode(' | ', $this->textes());

        $this->assertMatchesRegularExpression('/1 seule fois/i', $joint,
            'l\'usage unique n\'est plus dit : c\'est la rareté qui empêche de remettre à plus tard');
        $this->assertStringContainsString('07/09/2026', $joint,
            'la date limite n\'est plus dite : une remise sans date se remet à plus tard, et plus '
            . 'tard ne revient jamais');
    }

    /**
     * LE DÉFAUT DE PROMESSE : deux pourcentages différents sur le même papier. Le client lisait
     * « jusqu'a -30% » puis recevait -10%.
     */
    public function test_aucun_pourcentage_ne_contredit_la_remise_annoncee(): void
    {
        $joint = implode(' ', $this->textes());

        preg_match_all('/-?\s?(\d{1,2})\s?%/', $joint, $m);
        $pourcentages = array_values(array_unique(array_map('intval', $m[1] ?? [])));

        $this->assertNotEmpty($pourcentages, 'le ticket doit annoncer une remise');
        $this->assertSame([10], $pourcentages,
            'le ticket annonce plusieurs pourcentages ' . json_encode($pourcentages) . ' : le client '
            . 'retient le plus élevé et se sent floué en recevant l\'autre');
    }

    /**
     * LA LIGNE CITÉE PAR LE PROPRIÉTAIRE. Elle est le premier contact : c'est elle qui achète la
     * seconde d'attention pendant laquelle tout le reste sera lu. On exige qu'elle soit présente,
     * personnalisée, sur UNE ligne, et jamais pré-centrée à coups d'espaces.
     */
    public function test_la_salutation_nominative_est_bien_rendue_sur_une_ligne(): void
    {
        $t = $this->textes();

        // [OWNER 2026-08-09] « au debut bonsoir (prenom) ». La salutation d'ouverture remplace
        // le « Merci » : elle s'adresse au client avant de lui vendre quoi que ce soit. Virgule
        // finale, pas point d'exclamation — elle ouvre une phrase que l'introduction termine.
        $this->assertContains('Bonsoir M. Dorian,', $t,
            'la salutation nominative a disparu ou s\'est coupée : c\'est le premier contact du '
            . 'ticket, et la seule chose qui le distingue d\'un prospectus');
    }

    /**
     * [OWNER 2026-08-08 puis 2026-08-09] « Enlève le trait au-dessus du -10% ».
     *
     * Le trait parasite était un bandeau noir VIDE. La refonte du 09 supprime tout aplat (voir
     * `test_aucun_aplat_noir_ne_revient_sur_le_ticket`) ; il reste à garantir qu'aucune ligne
     * vide ne flotte à l'intérieur du coupon, ce qui recréerait le même effet de trait perdu.
     */
    public function test_aucune_ligne_vide_ne_flotte_dans_le_coupon(): void
    {
        $t = $this->textes();

        $haut = null;
        $bas = null;
        foreach ($t as $i => $l) {
            if ($haut === null && str_starts_with($l, "\xC9")) { $haut = $i; }
            if (str_starts_with($l, "\xC8")) { $bas = $i; }
        }
        $this->assertNotNull($haut);
        $this->assertNotNull($bas);

        foreach (array_slice($t, $haut + 1, $bas - $haut - 1) as $ligne) {
            $this->assertNotSame('', trim($ligne),
                'une ligne vide flotte dans le coupon : sur le papier elle se lit comme un trou');
        }
    }
}
