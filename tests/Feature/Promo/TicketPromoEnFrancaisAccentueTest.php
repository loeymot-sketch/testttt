<?php

namespace Tests\Feature\Promo;

use Tests\TestCase;

/**
 * LE TICKET REMIS AU CLIENT DOIT ÊTRE ÉCRIT EN FRANÇAIS CORRECT.
 *
 * D-005, mesuré par le superviseur adverse à la ronde 3 (2026-08-25). Du français sans
 * accents, à l'écran ET sur le papier :
 *
 *   interface — « TICKETS IMPRIMES », « CODES UTILISES », « CHIFFRE RAMENE », « annule »,
 *               « Code annule avant impression »
 *   ticket    — « c'est le meme restaurant, mais moins cher », « A emporter et en
 *               livraison. A tres vite ! », « Meme cuisine, meme equipe », « Des points
 *               fidelite a chaque commande », « Paiement en ligne securise »
 *
 * Aucune justification technique : le constructeur de commandes ESC/POS convertit
 * l'UTF-8 en CP858, un jeu qui porte tous les accents français — et les clés voisines du
 * même bloc sont, elles, correctement accentuées (« Création impossible. Réessayez. »).
 * C'était une faute de frappe généralisée, pas une contrainte d'imprimante.
 *
 * Ce qui rend ce défaut différent des autres de cette ronde : un ticket papier distribué au
 * client porte l'image du restaurant, et ne se corrige pas après impression.
 */
class TicketPromoEnFrancaisAccentueTest extends TestCase
{
    /**
     * Mots français qui ne peuvent PAS s'écrire sans accent. On ne cherche pas « des
     * accents quelque part » — on cherche les fautes précises qui étaient là.
     */
    private const FAUTES = [
        'imprimes', 'utilises', 'ramene', 'fidelite', 'securise',
        'deja', 'cree', 'prenom', 'tres vite', 'meme restaurant', 'meme equipe',
    ];

    private function chercherFautes(string $texte, string $ou): array
    {
        $trouvees = [];
        foreach (self::FAUTES as $mot) {
            // Insensible à la casse : « IMPRIMES » en capitales est la même faute.
            if (mb_stripos($texte, $mot) !== false) {
                $trouvees[] = "« {$mot} » dans {$ou}";
            }
        }

        return $trouvees;
    }

    /** @test */
    public function les_libelles_du_ticket_promo_portent_leurs_accents(): void
    {
        $chemin = resource_path('js/languages/fr.json');
        $this->assertFileExists($chemin);

        $libelles = json_decode(file_get_contents($chemin), true);
        $this->assertIsArray($libelles, 'fr.json doit rester du JSON valide');

        // On ne balaie QUE le bloc des tickets promo : le reste du fichier n'est pas
        // l'objet de ce constat, et un balayage global rendrait ce test ingérable.
        $bloc = '';
        foreach (($libelles['label'] ?? []) as $cle => $valeur) {
            if (is_string($valeur) && str_starts_with($cle, 'flyer_')) {
                $bloc .= ' ' . $valeur;
            }
        }
        $this->assertNotSame('', trim($bloc), 'aucun libellé « flyer_* » trouvé — clés renommées ?');

        $fautes = $this->chercherFautes($bloc, 'fr.json (bloc flyer_*)');
        $this->assertSame(
            [],
            $fautes,
            "Français sans accents dans l'interface du ticket promo :\n  " . implode("\n  ", $fautes)
        );
    }

    /**
     * LE PLUS IMPORTANT : le texte qui part sur le papier.
     *
     * @test
     */
    public function le_texte_imprime_sur_le_ticket_client_porte_ses_accents(): void
    {
        $chemin = app_path('Services/Promo/PromoFlyerService.php');
        $this->assertFileExists($chemin);

        $source = file_get_contents($chemin);

        // On isole les chaînes littérales : les noms de variables (`$imprimes`) ne sont pas
        // du texte affiché et n'ont pas à porter d'accent.
        preg_match_all("/'((?:[^'\\\\]|\\\\.)*)'/", $source, $m);
        $chaines = implode(' ', $m[1] ?? []);

        $fautes = $this->chercherFautes($chaines, 'PromoFlyerService (texte imprimé)');
        $this->assertSame(
            [],
            $fautes,
            "Français sans accents sur le TICKET REMIS AU CLIENT :\n  " . implode("\n  ", $fautes)
            . "\nUn ticket papier ne se corrige pas après impression."
        );
    }

    /** @test */
    public function les_messages_du_controleur_portent_leurs_accents(): void
    {
        $chemin = app_path('Http/Controllers/Admin/PromoFlyerController.php');
        $this->assertFileExists($chemin);

        preg_match_all("/'((?:[^'\\\\]|\\\\.)*)'/", file_get_contents($chemin), $m);

        /*
         * On ne garde que ce qui ressemble a du LANGAGE NATUREL.
         *
         * Deux filtres, et le second vient d'une erreur de ce test : mon extracteur apparie
         * les guillemets simples de proche en proche, ce qui lui fait avaler le CODE situe
         * ENTRE deux chaines — par exemple `=> round((float) $utilises->sum(` entre
         * 'revenue' et 'order_total'. Il signalait alors « utilises » comme une faute de
         * francais, alors que c'est un nom de variable. Toute bribe portant `$`, `->` ou
         * `(` est donc du code mal decoupe, pas une phrase : on l'ecarte.
         *
         * Ce test ne pretend pas analyser PHP. Il couvre les messages affiches, et il le dit.
         */
        $phrases = array_filter(
            $m[1] ?? [],
            fn ($c) => str_contains($c, ' ')
                && mb_strlen($c) > 12
                && ! preg_match('/[$(){}]|->|=>/', $c)
        );

        $fautes = $this->chercherFautes(implode(' ', $phrases), 'PromoFlyerController');
        $this->assertSame(
            [],
            $fautes,
            "Français sans accents dans les messages :\n  " . implode("\n  ", $fautes)
        );
    }
}
