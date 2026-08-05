<?php

namespace Tests\Feature\Frontend;

use Tests\TestCase;

/**
 * [PROCUREUR cycle 7/8 · GOAL_WEB_ADVERSARIAL_UX_TOTAL 2026-08-05 · P2 F-G]
 *
 * Une commande LIVRAISON n'imprimait NI ticket cuisine NI ticket comptoir : les deux listeners
 * filtraient sur `['kiosk', 'web', 'online']` et la surface `'delivery'` — que
 * `FrontendOrder::creating` force dès que `order_type === DELIVERY` — n'y figurait pas.
 *
 * On exerce la DÉCISION de chaque listener : sa liste blanche réelle, lue dans le fichier, est
 * appliquée aux surfaces du site. On ne se contente pas de vérifier qu'une chaîne est présente
 * quelque part — c'est exactement le genre d'assertion qui est resté vert sur des correctifs
 * morts pendant cette campagne. Ici, si quelqu'un retire `'delivery'` de la liste, le test
 * rougit ; s'il ajoute une surface de trop, il rougit aussi.
 */
class DeliverySurfacePrintsTicketsTest extends TestCase
{
    /** Extrait la liste blanche RÉELLE du listener, telle qu'elle est écrite dans le code. */
    private function whitelistOf(string $relativePath): array
    {
        $source = file_get_contents(base_path($relativePath));

        $ok = preg_match(
            "/in_array\(\(string\) \(\\\$order->source_surface \?\? ''\), \[([^\]]+)\], true\)/",
            $source,
            $m
        );
        $this->assertSame(1, $ok, "Liste blanche de surfaces introuvable dans {$relativePath} — le garde a été réécrit, ce test doit être revu.");

        return array_map(
            static fn (string $v) => trim($v, " '\""),
            explode(',', $m[1])
        );
    }

    public static function listeners(): array
    {
        return [
            'ticket CUISINE'  => ['app/Listeners/PrintKioskKitchenTicketOnOrderCreated.php'],
            'ticket COMPTOIR' => ['app/Listeners/PrintKioskOrderToCounter.php'],
        ];
    }

    /**
     * @dataProvider listeners
     *
     * Les DEUX surfaces du site doivent imprimer. Elles désignent la même chose — une commande
     * passée depuis le site — et c'est cette équivalence non écrite qui a produit le trou.
     */
    public function test_both_website_surfaces_are_printed(string $path): void
    {
        $whitelist = $this->whitelistOf($path);

        foreach (['web', 'delivery'] as $surface) {
            $this->assertContains(
                $surface,
                $whitelist,
                "La surface « {$surface} » n'imprime pas ({$path}) : une commande passée depuis le site "
                . "ne sortirait ni en cuisine ni au comptoir."
            );
        }
    }

    /**
     * @dataProvider listeners
     *
     * Contre-épreuve : la caisse ne doit PAS être dans la liste (elle imprime à son propre
     * checkout). Sans ce cas, on pourrait « corriger » le test en élargissant la liste à tout.
     */
    public function test_pos_surface_is_never_printed_by_these_listeners(string $path): void
    {
        $this->assertNotContains('pos', $this->whitelistOf($path), "La caisse imprime à son checkout : elle ne doit pas passer par ce listener ({$path}).");
    }
}
