<?php

namespace Tests\Unit\Hardware;

use App\Services\Hardware\KitchenBundledAddonCollapser;
use App\Services\Hardware\KitchenTicketSymbolicFormatter;
use PHPUnit\Framework\TestCase;

/**
 * [SUPERVISION 2026-08-19] DEUX MENUS, DEUX BOISSONS — CHACUN GARDE LA SIENNE.
 *
 * Ce test naît d'une FAUSSE ALERTE que j'ai moi-même produite, et il vaut surtout pour ça.
 *
 * En rendant le ticket cuisine de vraies commandes de production, ma sonde a annoncé que la
 * commande **#356** perdait sa seconde boisson :
 *
 *   ligne #671  TERMINATOR + Menu   BOISSON: Coca-Cola 33cl
 *   ligne #673  TERMINATOR + Menu   BOISSON: Hawaï 33cl
 *   ligne #675  OASIS TROPICAL 33CL (boisson commandée à part)
 *
 * Le ticket porte en réalité les DEUX. Ma sonde ne trouvait pas la seconde pour deux raisons
 * cumulées : le **ï** de « Hawaï » est ré-encodé pour la table de caractères de l'imprimante
 * (il ne correspond plus à la chaîne cherchée), et `grep` traitait la sortie comme un binaire
 * et tronquait la ligne. Une recherche de texte sur un flux ESC/POS ment ; il faut compter les
 * occurrences dans le rendu, pas chercher un mot accentué.
 *
 * Le cas à DEUX menus n'était pourtant couvert nulle part : `KitchenFormuleVisibleTest` ne
 * traite qu'un seul menu. On le fige donc ici — c'est le cas d'une commande familiale, le plus
 * coûteux si un jour il se casse (la cuisine prépare une boisson au lieu de plusieurs, sans
 * qu'aucune alarme ne se déclenche).
 */
class KitchenDeuxMenusDeuxBoissonsTest extends TestCase
{
    private KitchenBundledAddonCollapser $collapser;

    private KitchenTicketSymbolicFormatter $formatter;

    protected function setUp(): void
    {
        parent::setUp();
        $this->collapser = new KitchenBundledAddonCollapser();
        $this->formatter = new KitchenTicketSymbolicFormatter();
    }

    private function ligne(string $nom, string $instruction): object
    {
        return (object) [
            'name' => $nom,
            'item_name' => $nom,
            'quantity' => 1,
            'instruction' => $instruction,
        ];
    }

    /** Les notes réellement rendues pour une ligne, comme le ticket les écrit. */
    private function notes(object $ligne): array
    {
        return array_values(array_filter(array_map(
            'trim',
            explode("\n", $this->formatter->cleanInstruction($ligne->instruction, $ligne->name, []))
        )));
    }

    public function test_chaque_menu_garde_SA_boisson(): void
    {
        // Forme EXACTE de la commande réelle #356, relevée en base : les lignes repliées
        // portent une instruction VIDE (et non « MENU », comme je l'avais d'abord supposé —
        // ma première reproduction passait donc au vert sur une structure inventée).
        $out = $this->collapser->collapse([
            $this->ligne('Terminator', "TERMINATOR\nPain Viandes : Viande Hachée, Poulet mariné - Oignon Sauce : Andalouse\n+ Menu (Frites + Boisson) (+2,50 €)\nBOISSON: Coca-Cola 33cl"),
            $this->ligne('Menu (Frites + Boisson)', ''),
            $this->ligne('Terminator', "TERMINATOR\nPain Viandes : Viande Hachée, Poulet mariné - Salade, Tomate, Oignon Sauce : Hannibal\n+ Menu (Frites + Boisson) (+2,50 €)\nBOISSON: Hawaï 33cl"),
            $this->ligne('Menu (Frites + Boisson)', ''),
            $this->ligne('Oasis Tropical 33cl', 'OASIS TROPICAL 33CL'),
        ]);

        $rendus = array_map(fn ($l) => implode(' / ', $this->notes($l)), $out);
        $tout = implode(" || ", $rendus);

        $this->assertStringContainsString(
            'BOISSON: Coca-Cola 33cl',
            $tout,
            'La boisson du PREMIER menu doit arriver en cuisine.'
        );
        $this->assertStringContainsString(
            'BOISSON: Hawaï 33cl',
            $tout,
            "La boisson du SECOND menu doit arriver en cuisine AUSSI — c'est le défaut mesuré "
            .'sur la commande réelle #356 : une seule des deux boissons sortait, la cuisine en '
            .'préparait une au lieu de deux, sans aucune alarme.'
        );
    }
}
