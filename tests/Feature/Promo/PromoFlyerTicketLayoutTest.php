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
            'greeting' => 'Merci',
            // Les VRAIES clés lues par le rendu. Avec `greeting_name`/`greeting_civility` la
            // salutation sortait VIDE et toute cette suite passait sans jamais exercer la ligne
            // « Merci M. Dorian ! » — précisément celle que le propriétaire citait. Un jeu de
            // données aux mauvaises clés est un test creux qui ne se voit pas.
            'civility' => 'M.',
            'customer_name' => 'Dorian',
            'quality_note' => $d['quality_note'],
            'intro' => $d['intro'],
            'order_phone' => '03 65 67 82 91',
            'discount_percent' => 10,
            'code' => 'DORIAN-TH2P',
            'valid_until' => '07/09/2026',
            'qr_url' => 'https://www.lecayenne.fr/?promo=DORIAN-TH2P',
            'site_url' => 'www.lecayenne.fr',
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
    public function test_la_recompense_est_dans_un_bandeau_noir_pleine_largeur(): void
    {
        $bandeaux = array_values(array_filter($this->rendu(), fn ($l) => $l['inverse']));

        $this->assertNotEmpty($bandeaux,
            'la remise n\'est plus en inversion vidéo : sur une imprimante thermique c\'est le SEUL '
            . 'contraste disponible, sans lui rien ne ressort du ticket');

        $porteLaRemise = false;
        foreach ($bandeaux as $b) {
            $attendu = $b['double'] ? intdiv(self::LARGEUR, 2) : self::LARGEUR;
            $this->assertSame($attendu, strlen($b['texte']),
                'bandeau de ' . strlen($b['texte']) . ' au lieu de ' . $attendu . ' colonnes : le '
                . 'fond noir ne toucherait pas les bords — |' . $b['texte'] . '|');
            if (str_contains($b['texte'], '-10%')) {
                $porteLaRemise = true;
            }
        }

        $this->assertTrue($porteLaRemise, 'le bandeau noir doit contenir la remise elle-même');
    }

    /** L'ACTION FACILE AVANT LE REPLI COÛTEUX : scanner d'abord, taper ensuite. */
    public function test_le_scan_est_propose_AVANT_la_saisie_manuelle_du_code(): void
    {
        $t = $this->textes();

        $iScan = null;
        $iSaisie = null;
        foreach ($t as $i => $l) {
            if ($iScan === null && str_contains($l, 'SCANNE')) { $iScan = $i; }
            if ($iSaisie === null && str_contains($l, 'tape ce code')) { $iSaisie = $i; }
        }

        $this->assertNotNull($iScan, 'l\'appel à scanner a disparu — c\'est le geste le moins coûteux');
        $this->assertNotNull($iSaisie, 'le repli « taper le code » doit rester offert');
        $this->assertLessThan($iSaisie, $iScan,
            'la saisie manuelle passe avant le scan : on met le chemin le PLUS coûteux en premier');
    }

    /** Le code ne doit pas concurrencer la remise : sinon plus rien ne ressort. */
    public function test_le_code_n_est_PAS_en_double_taille(): void
    {
        // Le code est imprimé ESPACÉ (« D O R I A N - T H 2 P ») pour être recopié sans erreur :
        // chercher « DORIAN-TH2P » ne matchait donc plus RIEN et la boucle ne s'exécutait pas.
        // PHPUnit a signalé le test « risky » — c'est-à-dire creux. On cherche la forme réellement
        // imprimée, et on EXIGE de l'avoir trouvée : sans ce compteur, le test redeviendrait vide
        // à la première évolution du format.
        $vues = 0;
        foreach ($this->rendu() as $l) {
            if (! str_contains(str_replace(' ', '', $l['texte']), 'DORIAN-TH2P')) {
                continue;
            }
            $vues++;
            $this->assertFalse($l['double'],
                'le code repasse en double taille : il crie aussi fort que la remise, et deux '
                . 'éléments qui crient également fort ne laissent rien ressortir');
        }

        $this->assertGreaterThan(0, $vues, 'le code n\'a pas été trouvé sur le ticket');
    }

    /** LA PROMESSE PRODUIT, demandée par le propriétaire — et placée AVANT l'offre. */
    public function test_la_promesse_produit_est_affichee_avant_l_offre(): void
    {
        $t = $this->textes();

        $iProduit = null;
        $iRemise = null;
        foreach ($this->rendu() as $i => $l) {
            if ($iProduit === null && str_contains($l['texte'], 'FRAICHE')) { $iProduit = $i; }
            if ($iRemise === null && $l['inverse'] && str_contains($l['texte'], '%')) { $iRemise = $i; }
        }

        $this->assertNotNull($iProduit, 'la promesse produit a disparu : c\'est la seule ligne du '
            . 'ticket qui parle de ce qu\'on mange, et une remise ne donne pas faim');
        $this->assertNotNull($iRemise, 'la remise a disparu du bandeau');
        $this->assertLessThan($iRemise, $iProduit,
            'la remise passe avant le produit : on lève le frein avant d\'avoir donné envie');

        $joint = implode(' ', $t);
        foreach (['VIANDE', 'FRITES', 'GRILLADES'] as $mot) {
            $this->assertStringContainsString($mot, $joint, "« $mot » manque à la promesse produit");
        }
    }

    /**
     * UN SEUL bloc noir : le sous-titre doit être DEDANS. Séparé, le bandeau du haut se lisait
     * comme un trait parasite posé au-dessus de la remise (remarque du propriétaire).
     */
    public function test_le_sous_titre_de_la_remise_est_DANS_le_bloc_noir(): void
    {
        $dedans = false;
        foreach ($this->rendu() as $l) {
            if ($l['inverse'] && str_contains($l['texte'], 'prochaine commande')) {
                $dedans = true;
            }
        }

        $this->assertTrue($dedans,
            'le sous-titre est ressorti du bloc noir : le bandeau redevient un trait flottant '
            . 'au-dessus de la remise au lieu d\'un tampon d\'un seul morceau');
    }

    /** Le code doit être DANS un cadre fermé, et espacé pour être recopié sans erreur. */
    public function test_le_code_est_encadre_et_espace_pour_la_recopie(): void
    {
        $t = $this->textes();

        $bords = array_values(array_filter($t, fn ($l) => preg_match('/^\+-+\+$/', $l)));
        $this->assertGreaterThanOrEqual(2, count($bords),
            'le cadre du code n\'est plus fermé : sans cadre, le code est une ligne de texte parmi '
            . 'd\'autres et ne dit pas « voici ce qu\'il faut recopier »');
        foreach ($bords as $b) {
            $this->assertSame(self::LARGEUR, strlen($b), 'bord de cadre à la mauvaise largeur');
        }

        $interieur = array_values(array_filter($t, fn ($l) => str_starts_with($l, '|') && str_ends_with($l, '|')));
        $this->assertNotEmpty($interieur, 'les barres latérales du cadre ont disparu');
        $this->assertStringContainsString('D O R I A N', $interieur[0],
            'le code n\'est plus espacé : recopié depuis un bloc serré, les O passent pour des 0');
    }

    /** LE TÉLÉPHONE, demandé par le propriétaire : troisième chemin pour qui ne scanne pas. */
    public function test_le_numero_pour_commander_est_affiche_et_lisible(): void
    {
        $joint = implode(' | ', $this->textes());

        $this->assertMatchesRegularExpression('/telephone/i', $joint,
            'l\'invitation à appeler a disparu');
        $this->assertStringContainsString('03 65 67 82 91', $joint,
            'le numéro n\'est plus affiché, ou plus formaté par paires — un numéro collé se recopie mal');
    }

    /**
     * ON N'IMPRIME JAMAIS UN NUMÉRO GABARIT. `settings.company_phone` valait « +33600000000 » sur
     * cette installation. Un faux numéro est PIRE que pas de numéro : le client appelle, tombe dans
     * le vide, et c'est la commande ET la confiance qui sont perdues.
     */
    public function test_un_numero_gabarit_n_est_JAMAIS_imprime(): void
    {
        $service = app(PromoFlyerService::class);
        $m = new \ReflectionMethod($service, 'resolveOrderPhone');
        $m->setAccessible(true);

        foreach (['+33600000000', '0000000000', '0123456789', '01 23 45 67 89'] as $gabarit) {
            $this->assertSame('', $m->invoke($service, $gabarit, 1),
                'le gabarit « ' . $gabarit . ' » serait imprimé sur le ticket');
        }

        // Contre-preuve : un vrai numéro doit passer ET être formaté par paires.
        $this->assertSame('03 65 67 82 91', $m->invoke($service, '0365678291', 1),
            'un numéro valide doit passer, sinon on a échangé un faux numéro contre aucun numéro');
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

        $this->assertContains('Merci M. Dorian !', $t,
            'la salutation nominative a disparu ou s\'est coupée : c\'est le premier contact du '
            . 'ticket, et la seule chose qui le distingue d\'un prospectus');
    }

    // ── CÂBLAGE ──────────────────────────────────────────────────────────────────────────────

    public function test_la_largeur_par_defaut_vient_de_la_config_et_non_de_48_en_dur(): void
    {
        $r = new \ReflectionMethod(app(PromoFlyerService::class), 'renderBytes');

        $this->assertSame(0, $r->getParameters()[1]->getDefaultValue(),
            'la largeur par défaut doit être 0 = « résous-la depuis la config », jamais 48 en dur : '
            . 'c\'est ce 48 qui imprimait un ticket de 48 colonnes sur une caisse de 42');
    }
}
