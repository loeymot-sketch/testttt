<?php

namespace Tests\Feature\Hardware;

use App\Models\OrderItem;
use App\Services\Hardware\KitchenBundledAddonCollapser;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * [CHEF 2026-09-02 · NF525 §8] LE REPLI CUISINE N'ÉCRIT JAMAIS L'INSTANTANÉ FISCAL.
 *
 * `KitchenBundledAddonCollapser::avecExtrasHerites()` contient la ligne
 * `$parent->composition_snapshot = $snap`. La sentinelle Zone5 PR03 la signale —
 * et elle a RAISON de la signaler : une expression régulière ne peut pas savoir
 * que la variable nommée `$parent` est en réalité un clone.
 *
 * Ce test est la contrepartie de l'exemption inscrite dans cette sentinelle. Il
 * prouve par le COMPORTEMENT ce que la relecture prétend : la ligne comptable
 * source ressort intacte, et le repli n'émet aucune écriture.
 *
 * Il mord dans les deux sens. Remplacer `clone $parent` par `$parent` au site
 * d'appel (ligne 182) fait tomber le premier test ; ajouter un `->save()` fait
 * tomber le second. Sans lui, l'exemption de la sentinelle serait une simple
 * affirmation.
 */
class CollapserNePersistePasLInstantaneTest extends TestCase
{
    /** @test */
    public function la_ligne_source_ressort_avec_son_instantane_intact(): void
    {
        [$parent, $enfant] = $this->lignesDUneFormuleRevendiquee();

        $avant = $parent->composition_snapshot;
        $this->assertCount(1, $avant['extras'], 'garde-fou du montage : le parent porte 1 extra');

        $rendu = (new KitchenBundledAddonCollapser)->collapse([$parent, $enfant]);

        // Le repli doit AVOIR EU LIEU, sinon ce test ne prouverait rien.
        $this->assertCount(1, $rendu, 'la ligne revendiquée est bien repliée');
        $this->assertCount(
            2,
            $rendu[0]->composition_snapshot['extras'],
            "le rendu hérite bien de l'extra de la ligne repliée (le chemin est atteint)"
        );

        // Le cœur : la source n'a pas bougé.
        $this->assertNotSame($parent, $rendu[0], 'le rendu est un clone, pas la source');
        $this->assertCount(
            1,
            $parent->composition_snapshot['extras'],
            "NF525 §8 : l'instantané de la ligne comptable source reste figé"
        );
        $this->assertSame(
            $avant,
            $parent->composition_snapshot,
            "NF525 §8 : l'instantané source est identique à l'octet près"
        );
        $this->assertFalse(
            $parent->isDirty('composition_snapshot'),
            "NF525 §8 : la source ne porte aucune modification en attente d'écriture"
        );
    }

    /** @test */
    public function le_repli_nemet_aucune_ecriture_en_base(): void
    {
        [$parent, $enfant] = $this->lignesDUneFormuleRevendiquee();

        $ecritures = [];
        DB::listen(function ($query) use (&$ecritures) {
            if (preg_match('~^\s*(insert|update|delete|replace|truncate)\b~i', $query->sql)) {
                $ecritures[] = $query->sql;
            }
        });

        (new KitchenBundledAddonCollapser)->collapse([$parent, $enfant]);

        $this->assertSame(
            [],
            $ecritures,
            "NF525 §8 : le repli est un chemin de RENDU ; il ne doit émettre aucune écriture. Vues : "
            . implode(' | ', $ecritures)
        );
    }

    /**
     * Une formule « Menu Méga » qui revendique sa ligne « Frites » (puce « + Frites »
     * écrite par le wizard). Les deux lignes portent un extra dans leur instantané :
     * c'est la condition pour atteindre `avecExtrasHerites()` par la branche
     * `$snapPorte`, celle qui contient l'affectation signalée.
     *
     * @return array{0: OrderItem, 1: OrderItem}
     */
    private function lignesDUneFormuleRevendiquee(): array
    {
        $parent = (new OrderItem)->forceFill([
            'quantity' => 1,
            'total_price' => 9.90,
            'instruction' => "+ Frites (+2.00)",
            'composition_snapshot' => [
                'lines' => [],
                'extras' => [
                    ['extra_name' => 'Cheddar', 'quantity' => 1, 'unit_price' => 0.9, 'line_total' => 0.9],
                ],
                'addons' => [],
            ],
        ]);
        $parent->name = 'Menu Méga';
        $parent->syncOriginal();

        $enfant = (new OrderItem)->forceFill([
            'quantity' => 1,
            'total_price' => 2.00,
            'instruction' => '',
            'composition_snapshot' => [
                'lines' => [],
                'extras' => [
                    ['extra_name' => 'Sauce Andalouse', 'quantity' => 1, 'unit_price' => 0.5, 'line_total' => 0.5],
                ],
                'addons' => [],
            ],
        ]);
        $enfant->name = 'Frites';
        $enfant->syncOriginal();

        return [$parent, $enfant];
    }
}
