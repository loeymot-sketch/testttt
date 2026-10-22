<?php

namespace App\Support\Menu;

use App\Enums\Status;
use App\Models\Item;
use App\Models\ItemBranchAvailability;
use App\Models\Scopes\BranchScope;
use Closure;
use Illuminate\Support\Str;

/**
 * [VG-2026-10-21 M1-101] Boisson / accompagnement de MENU : même règle de disponibilité qu'un produit seul.
 *
 * La boisson d'une formule n'est pas une ligne de commande : elle voyage en TEXTE dans l'instruction de la ligne menu,
 * sous trois formes —
 *   - site    : « Boisson menu: Coca-Cola 33cl · Sauce frites : Mayonnaise » (api.js) ;
 *   - caisse  : « BOISSON: Coca-Cola 33cl » (pos-wizard.js) ;
 *   - borne   : « Formule : Menu complet (frites + boisson) (Coca-Cola 33cl). Sauce frites : … » (KioskWizardComponent).
 * (Mêmes formes que lisent la cuisine et le ticket : orderLabelBuilder.js, KitchenTicketSymbolicFormatter.)
 * Aucun contrôle ne lisait ce texte : un Coca en rupture, refusé seul (« n'est plus disponible »), passait comme boisson
 * de menu (commande 8084) — le client payait une boisson qui n'existe pas et le stock n'était pas décompté.
 *
 * Règle : un nom extrait de ces rubriques qui désigne EXACTEMENT un produit du catalogue (casse et accents ignorés)
 * est refusé si ce produit est inactif ou en rupture pour la branche (item_branch_availability, comme
 * AvailabilityService::assertItemsOrderableForBranch). Un texte qui ne nomme aucun produit (« frites + boisson »,
 * une note client) n'est jamais refusé. Aucun prix n'est touché.
 */
final class MenuFormulaChoiceAvailability
{
    /** « Boisson menu: X », « BOISSON: X », « ↳ Boisson: X », « Accompagnement: X ». */
    private const RUBRIQUE = '/^\s*(?:↳\s*)?(?:\*+\s*)?(boissons?|accompagnements?)(?:\s+(?:du\s+)?(?:menu|formule))?\s*:\s*(.+?)\s*\.?\s*$/iu';

    /**
     * @param  array<int, mixed>  $orderItems  lignes (tableaux ou objets) portant `instruction`
     * @param  Closure(int $itemIndex, string $message): void  $failItem
     */
    public static function validateCollectionKeyedByItemIndex(array $orderItems, Closure $failItem, ?int $branchId = null): void
    {
        $choicesByIndex = [];
        foreach ($orderItems as $index => $item) {
            $instruction = is_array($item) ? ($item['instruction'] ?? '') : (is_object($item) ? ($item->instruction ?? '') : '');
            $choices = self::choices(is_string($instruction) ? $instruction : '');
            if ($choices !== []) {
                $choicesByIndex[$index] = $choices;
            }
        }
        if ($choicesByIndex === []) {
            return;
        }

        $catalogue = [];
        foreach (Item::query()->get(['id', 'name', 'status']) as $product) {
            $catalogue[self::key((string) $product->name)][] = $product;
        }

        $unavailableIds = [];
        if ($branchId !== null && $branchId > 0) {
            // Branche explicite : la portée de branche de l'utilisateur ne doit ni élargir ni masquer la lecture.
            $unavailableIds = ItemBranchAvailability::withoutGlobalScope(BranchScope::class)
                ->where('branch_id', $branchId)
                ->where('is_available', false)
                ->pluck('item_id')
                ->map(fn ($id) => (int) $id)
                ->flip()
                ->all();
        }

        foreach ($choicesByIndex as $index => $choices) {
            foreach ($choices as [$rubrique, $name]) {
                foreach ($catalogue[self::key($name)] ?? [] as $product) {
                    $inactive = (int) $product->status !== Status::ACTIVE;
                    if ($inactive || isset($unavailableIds[(int) $product->id])) {
                        $failItem((int) $index, sprintf(
                            '%s du menu « %s » n\'est plus disponible — choisis-en un(e) autre ou retire la formule.',
                            $rubrique === 'accompagnement' ? 'L\'accompagnement' : 'La boisson',
                            (string) $product->name,
                        ));

                        continue 3;
                    }
                }
            }
        }
    }

    /**
     * @return list<array{0: string, 1: string}>  [rubrique (boisson|accompagnement), nom]
     */
    private static function choices(string $instruction): array
    {
        if (trim($instruction) === '') {
            return [];
        }

        $out = [];
        // La caisse et le site posent plusieurs rubriques sur une ligne, séparées par « · » (orderLabelBuilder.js).
        foreach (preg_split('/\R|\s*[·•]\s*/u', $instruction) ?: [] as $line) {
            if (preg_match(self::RUBRIQUE, $line, $m)) {
                $rubrique = str_starts_with(mb_strtolower($m[1]), 'accompagnement') ? 'accompagnement' : 'boisson';
                $out[] = [$rubrique, trim($m[2])];
            }
            if (preg_match('/formule\s*:/iu', $line) && preg_match_all('/\(([^()]+)\)/u', $line, $segments)) {
                foreach ($segments[1] as $segment) {
                    if (trim($segment) !== '') {
                        $out[] = ['boisson', trim($segment)];
                    }
                }
            }
        }

        return $out;
    }

    private static function key(string $name): string
    {
        return (string) preg_replace('/\s+/', ' ', trim(mb_strtolower(Str::ascii($name))));
    }
}
