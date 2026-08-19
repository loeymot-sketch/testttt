<?php

namespace Tests\Unit\Hardware;

use App\Services\Hardware\KitchenBundledAddonCollapser;
use PHPUnit\Framework\TestCase;

/**
 * [T-KDS-MENU-DOUBLON 2026-08-19 · GOAL owner, arbitrage owner « fusionner »]
 *
 * Jumeau PHP STRICT de resources/js/helpers/kdsBundledAddons.js. Les deux
 * doivent rester alignés : l'écran cuisine et le ticket imprimé sont documentés
 * comme jumeaux, et le propriétaire a signalé le doublon SUR LES DEUX.
 *
 * PREUVE TERRAIN — ticket cuisine réellement rendu pour la commande 6598
 * (avant correctif) :
 *
 *     | S | CAY | P | ST | ALG
 *     |   FRITES : MAY               <- la sauce frites, sur la ligne du sandwich
 *     |   ** BOISSON: Coca-Cola 33cl
 *     | MENU : MAY                   <- LA MÊME, une seconde fois
 *     | Coca-Cola 33cl               <- vraie 2e boisson, commandée à part
 *
 * Le commentaire d'en-tête de OrderReceiptEscPosRenderer annonçait déjà
 * « Un item Menu/Formule séparé est FUSIONNÉ comme ligne 2 du produit précédent »,
 * mais l'implémentation renonçait explicitement (« Pas de fusion devinée : le menu
 * n'est pas forcément adjacent à son produit »). Le signal manquant existe
 * pourtant : le parent revendique lui-même « + <nom de la formule> » dans son
 * instruction, écrite par le wizard. On ne devine plus, on lit.
 *
 * SÛRETÉ : une formule commandée SEULE n'est jamais repliée — sinon la cuisine ne
 * la préparerait pas.
 */
class KitchenBundledAddonCollapserTest extends TestCase
{
    private const INSTRUCTION_PARENT = "CAYENNE\n"
        ."Pain Viandes : Poulet mariné - Salade, Tomate Sauce : Algérienne\n"
        ."+ Menu (Frites + Boisson) (+2,50 €)\n"
        ."↳ Sauce frites: Mayonnaise\n"
        ."BOISSON: Coca-Cola 33cl";

    private function ligne(string $name, int $quantity, string $instruction = ''): object
    {
        $o = new \stdClass();
        $o->name = $name;
        $o->quantity = $quantity;
        $o->instruction = $instruction;

        return $o;
    }

    private function sandwich(int $qty = 1, ?string $instruction = null): object
    {
        return $this->ligne('Cayenne', $qty, $instruction ?? self::INSTRUCTION_PARENT);
    }

    private function formule(int $qty = 1): object
    {
        return $this->ligne('Menu (Frites + Boisson)', $qty, 'Sauce frites: Mayonnaise');
    }

    private function boisson(int $qty = 1): object
    {
        return $this->ligne('Coca-Cola 33cl', $qty, 'COCA-COLA 33CL');
    }

    /** @param array<int, object> $rows */
    private function libelles(array $rows): array
    {
        return array_map(static fn ($r) => $r->quantity.'x '.$r->name, $rows);
    }

    private function collapser(): KitchenBundledAddonCollapser
    {
        return new KitchenBundledAddonCollapser();
    }

    public function test_cas_reel_commande_6598_la_ligne_formule_disparait(): void
    {
        $out = $this->collapser()->collapse([$this->sandwich(), $this->formule(), $this->boisson()]);

        $this->assertSame(['1x Cayenne', '1x Coca-Cola 33cl'], $this->libelles($out));
    }

    public function test_surete_une_formule_commandee_seule_reste_affichee(): void
    {
        $out = $this->collapser()->collapse([$this->formule(), $this->boisson()]);

        $this->assertSame(
            ['1x Menu (Frites + Boisson)', '1x Coca-Cola 33cl'],
            $this->libelles($out),
            'une formule que personne ne revendique doit rester visible en cuisine'
        );
    }

    public function test_deux_sandwichs_en_menu_replient_une_ligne_de_quantite_deux(): void
    {
        $out = $this->collapser()->collapse([$this->sandwich(2), $this->formule(2)]);

        $this->assertSame(['2x Cayenne'], $this->libelles($out));
    }

    public function test_surete_un_menu_attache_et_un_menu_seul_en_laissent_un(): void
    {
        $out = $this->collapser()->collapse([$this->sandwich(1), $this->formule(2)]);

        $this->assertSame(['1x Cayenne', '1x Menu (Frites + Boisson)'], $this->libelles($out));
    }

    public function test_la_revendication_tolere_casse_et_accents(): void
    {
        $out = $this->collapser()->collapse([
            $this->sandwich(1, "CAYENNE\n+ MENU (FRITES + BOISSON) (+2,50 €)"),
            $this->formule(),
        ]);

        $this->assertSame(['1x Cayenne'], $this->libelles($out));
    }

    public function test_un_supplement_ne_masque_rien(): void
    {
        // « + Cheddar » est décrit sur la ligne du parent et n'existe pas comme
        // article séparé : rien ne doit disparaître.
        $out = $this->collapser()->collapse([
            $this->sandwich(1, "CAYENNE\n+ Cheddar (+1,00 €)"),
            $this->boisson(),
        ]);

        $this->assertSame(['1x Cayenne', '1x Coca-Cola 33cl'], $this->libelles($out));
    }

    public function test_une_ligne_ne_peut_pas_se_replier_elle_meme(): void
    {
        $auto = $this->ligne('Menu (Frites + Boisson)', 1, '+ Menu (Frites + Boisson) (+2,50 €)');

        $out = $this->collapser()->collapse([$auto]);

        $this->assertSame(['1x Menu (Frites + Boisson)'], $this->libelles($out));
    }

    public function test_valeurs_degenerees(): void
    {
        $this->assertSame([], $this->collapser()->collapse([]));

        $sansInstruction = new \stdClass();
        $sansInstruction->name = 'Frites';
        $sansInstruction->quantity = 1;

        $this->assertSame(['1x Frites'], $this->libelles($this->collapser()->collapse([$sansInstruction])));
    }

    public function test_la_source_n_est_jamais_mutee(): void
    {
        $formule = $this->formule(2);
        $src = [$this->sandwich(1), $formule];

        $out = $this->collapser()->collapse($src);

        $this->assertSame(2, $formule->quantity, 'la ligne source ne doit pas être mutée');
        $this->assertSame(1, $out[1]->quantity);
    }
}
