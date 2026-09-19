<?php

namespace Tests\Unit\Hardware;

use App\Services\Hardware\KitchenBundledAddonCollapser;
use PHPUnit\Framework\TestCase;

/**
 * [AUDIT-SUPERVISEUR 2026-08-25 · E-009] Le repli d'une formule ne doit jamais
 * effacer un supplément FACTURÉ — sur le TICKET IMPRIMÉ comme sur l'écran.
 *
 * LE DÉFAUT — le legs ne transmettait au parent que les consignes d'instruction.
 * Les extras de la ligne repliée étaient jetés. Un Cheddar payé disparaissait du
 * papier que le cuisinier a sous les yeux.
 *
 * POURQUOI C'EST PLUS GRAVE QUE LE DÉFAUT LUI-MÊME — ce trou annulait, sur le
 * chemin de production, le correctif posé la veille en amont. On avait bouché la
 * fuite et le repli la rouvrait plus loin. C'est le motif récurrent de cet audit :
 * un correctif vérifié sur le chemin réparé, jamais sur celui qui sert vraiment.
 *
 * Jumeau strict de `tests/js/kdsRepliNePerdPasLesExtras.spec.js`.
 */
class KitchenBundledAddonExtrasLeguesTest extends TestCase
{
    private function ligne(string $name, int $quantity, string $instruction = ''): object
    {
        $o = new \stdClass();
        $o->name = $name;
        $o->quantity = $quantity;
        $o->instruction = $instruction;

        return $o;
    }

    /** Le parent revendique sa formule par une ligne « + … », comme l'écrit le wizard. */
    private function parent(int $qty = 1): object
    {
        return $this->ligne('Cayenne', $qty, "CAYENNE\n+ Menu (Frites + Boisson) (+2,50 €)");
    }

    private function formule(int $qty = 1): object
    {
        return $this->ligne('Menu (Frites + Boisson)', $qty, 'Sauce frites: Mayonnaise');
    }

    /** Lit les extras comme le fait le rendu : instantané d'abord, ancienne colonne ensuite. */
    private function nomsVus(object $ligne): array
    {
        $snap = $ligne->composition_snapshot ?? null;
        if (is_string($snap)) {
            $snap = json_decode($snap, true);
        }
        $source = (is_array($snap) && ! empty($snap['extras']))
            ? $snap['extras']
            : (is_string($ligne->item_extras ?? null) ? json_decode($ligne->item_extras, true) : ($ligne->item_extras ?? []));

        return array_values(array_filter(array_map(
            static fn ($e) => (string) ($e['extra_name'] ?? $e['name'] ?? ''),
            is_array($source) ? $source : []
        )));
    }

    /** @test */
    public function un_extra_de_l_instantane_survit_au_repli(): void
    {
        $formule = $this->formule();
        $formule->composition_snapshot = ['schema_version' => 1, 'extras' => [
            ['extra_id' => 1, 'extra_name' => 'Cheddar', 'quantity' => 2],
        ]];

        $rendu = (new KitchenBundledAddonCollapser())->collapse([$this->parent(), $formule]);

        $this->assertCount(1, $rendu, 'La ligne de formule doit bien être repliée.');
        $this->assertContains('Cheddar', $this->nomsVus($rendu[0]), 'Le supplément payé doit survivre au repli.');
    }

    /** @test */
    public function un_extra_de_l_ancienne_forme_survit_aussi(): void
    {
        $formule = $this->formule();
        $formule->item_extras = json_encode([['id' => 9, 'name' => 'Oignons frits', 'quantity' => 1]]);

        $rendu = (new KitchenBundledAddonCollapser())->collapse([$this->parent(), $formule]);

        $this->assertCount(1, $rendu);
        $this->assertContains('Oignons frits', $this->nomsVus($rendu[0]));
    }

    /**
     * Écrire dans la mauvaise des deux sources donnerait un correctif vert et un
     * ticket toujours faux — le piège exact qui a déjà coûté un aller-retour ici.
     *
     * @test
     */
    public function l_heritage_atterrit_dans_la_source_que_le_rendu_lira(): void
    {
        $parent = $this->parent();
        $parent->composition_snapshot = ['schema_version' => 1, 'extras' => [
            ['extra_id' => 5, 'extra_name' => 'Salade', 'quantity' => 1],
        ]];

        $formule = $this->formule();
        $formule->composition_snapshot = ['schema_version' => 1, 'extras' => [
            ['extra_id' => 1, 'extra_name' => 'Cheddar', 'quantity' => 2],
        ]];

        $rendu = (new KitchenBundledAddonCollapser())->collapse([$parent, $formule]);

        $noms = $this->nomsVus($rendu[0]);
        $this->assertContains('Salade', $noms);
        $this->assertContains('Cheddar', $noms);
    }

    /** @test */
    public function un_extra_deja_porte_par_le_parent_n_est_pas_duplique(): void
    {
        $parent = $this->parent();
        $parent->item_extras = json_encode([['id' => 1, 'name' => 'Cheddar', 'quantity' => 1]]);

        $formule = $this->formule();
        $formule->item_extras = json_encode([['id' => 1, 'name' => 'Cheddar', 'quantity' => 1]]);

        $rendu = (new KitchenBundledAddonCollapser())->collapse([$parent, $formule]);

        $this->assertSame(['Cheddar'], $this->nomsVus($rendu[0]));
    }

    /** @test */
    public function aucun_extra_n_est_invente_quand_la_ligne_repliee_n_en_porte_pas(): void
    {
        $rendu = (new KitchenBundledAddonCollapser())->collapse([$this->parent(), $this->formule()]);

        $this->assertCount(1, $rendu);
        $this->assertSame([], $this->nomsVus($rendu[0]));
    }

    /**
     * Le comportement d'origine ne doit pas régresser : la sauce frites, que la
     * ligne repliée est SEULE à porter, continue d'être léguée.
     *
     * @test
     */
    public function les_consignes_de_cuisine_sont_toujours_leguees(): void
    {
        $rendu = (new KitchenBundledAddonCollapser())->collapse([$this->parent(), $this->formule()]);

        $this->assertCount(1, $rendu);
        $this->assertMatchesRegularExpression('/Mayonnaise/i', (string) $rendu[0]->instruction);
    }
}
