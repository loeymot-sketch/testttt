<?php

namespace App\Services\Purchasing;

use App\Enums\Status;
use App\Models\Item;
use App\Models\PurchaseLine;
use App\Models\RawMaterial;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * [ARCH_STOCK_INTELLIGENT_BOM_2026-07-23 / P3b] Classification des lignes lues.
 *
 * Pour chaque ligne brute extraite ({@see Vision\InvoiceVisionContract}), PROPOSE
 * une cible stock — SANS jamais écrire (aucune mutation de stock ici). L'ordre de
 * résolution :
 *   1. fuzzy-match du libellé contre `raw_materials.name` (matière) ;
 *   2. sinon fuzzy-match contre les items de catégorie « Boissons » (stock_item) ;
 *      (le meilleur des deux scores l'emporte s'il dépasse le seuil) ;
 *   3. sinon mots-clés charge (sac, gobelet, gaz, emballage…) → charge (target null) ;
 *   4. sinon repli `charge` NON confirmé (score 0, `matched=false`) — à confirmer owner.
 *
 * `target_type` est un enum NON-nullable côté DB (raw_material|stock_item|charge) :
 * une ligne inconnue est donc rangée en `charge` (repli SANS impact stock si
 * validée par erreur), mais `matched=false` + score 0 signalent à l'UI (P3c) qu'elle
 * demande une confirmation/redirection owner AVANT validation.
 *
 * Les propositions sortent toujours en statut `proposed` : la validation
 * (application au stock) est faite plus tard par PurchaseService::validateDocument
 * sur décision explicite de l'owner. « Jamais d'écriture auto » (plan / garde-fou NF525).
 *
 * Branch : hard-scope explicite (matières branch-scopées ; catalogue items global V1).
 */
class InvoiceClassificationService
{
    /** Score minimal (0..1) pour retenir une cible matière/boisson. */
    private const MATCH_THRESHOLD = 0.5;

    /**
     * Mots-clés « charge / consommable » (emballages, énergie, entretien). Normalisés
     * (sans accent, minuscule). Détectés comme token entier dans le libellé.
     */
    private const CHARGE_KEYWORDS = [
        'sac', 'sacs', 'gobelet', 'gobelets', 'couvercle', 'couvercles', 'barquette',
        'barquettes', 'serviette', 'serviettes', 'papier', 'film', 'emballage',
        'emballages', 'carton', 'cartons', 'gaz', 'nettoyant', 'detergent', 'entretien',
        'consommable', 'consommables', 'kraft', 'aluminium', 'essuie',
    ];

    /**
     * Classe une liste de lignes extraites → propositions.
     *
     * @param  array<int, array{raw_label:string, qty:float, unit:string, unit_price:float|null, tva_rate:float|null}>  $lines
     * @return array<int, array{raw_label:string, qty:float, unit:string, unit_price:float|null, tva_rate:float|null, target_type:string, target_id:int|null, score:float, matched:bool}>
     */
    public function propose(array $lines, int $branchId = 1): array
    {
        $materials = $this->materials($branchId);
        $drinks = $this->drinkItems();

        return array_map(
            fn (array $line): array => $this->classifyLine($line, $materials, $drinks),
            array_values($lines)
        );
    }

    /**
     * @param  array{raw_label:string, qty:float, unit:string, unit_price:float|null, tva_rate:float|null}  $line
     * @param  Collection<int, RawMaterial>  $materials
     * @param  Collection<int, Item>  $drinks
     * @return array{raw_label:string, qty:float, unit:string, unit_price:float|null, tva_rate:float|null, target_type:string, target_id:int|null, score:float, matched:bool}
     */
    private function classifyLine(array $line, Collection $materials, Collection $drinks): array
    {
        $label = (string) ($line['raw_label'] ?? '');

        [$materialId, $materialScore] = $this->bestMatch($label, $materials);
        [$drinkId, $drinkScore] = $this->bestMatch($label, $drinks);

        // 1/2 — meilleure cible fuzzy (matière prioritaire à score égal).
        if ($materialScore >= self::MATCH_THRESHOLD && $materialScore >= $drinkScore) {
            return $this->proposal($line, PurchaseLine::TARGET_RAW_MATERIAL, $materialId, $materialScore, true);
        }

        if ($drinkScore >= self::MATCH_THRESHOLD) {
            return $this->proposal($line, PurchaseLine::TARGET_STOCK_ITEM, $drinkId, $drinkScore, true);
        }

        // 3 — charge par mots-clés (matched, mais target null : pas de stock).
        if ($this->looksLikeCharge($label)) {
            return $this->proposal($line, PurchaseLine::TARGET_CHARGE, null, 0.75, true);
        }

        // 4 — inconnu : repli charge NON confirmé (score 0), à rediriger par l'owner.
        return $this->proposal($line, PurchaseLine::TARGET_CHARGE, null, 0.0, false);
    }

    /**
     * @param  array{raw_label:string, qty:float, unit:string, unit_price:float|null, tva_rate:float|null}  $line
     * @return array{raw_label:string, qty:float, unit:string, unit_price:float|null, tva_rate:float|null, target_type:string, target_id:int|null, score:float, matched:bool}
     */
    private function proposal(array $line, string $targetType, ?int $targetId, float $score, bool $matched): array
    {
        return [
            'raw_label' => (string) ($line['raw_label'] ?? ''),
            'qty' => (float) ($line['qty'] ?? 0),
            'unit' => (string) ($line['unit'] ?? 'piece'),
            'unit_price' => isset($line['unit_price']) ? (float) $line['unit_price'] : null,
            'tva_rate' => isset($line['tva_rate']) ? (float) $line['tva_rate'] : null,
            'target_type' => $targetType,
            'target_id' => $targetId,
            'score' => round($score, 3),
            'matched' => $matched,
        ];
    }

    /**
     * Meilleur candidat (id, score) d'une collection {id, name} pour un libellé.
     *
     * @param  Collection<int, RawMaterial|Item>  $candidates
     * @return array{0:int|null, 1:float}
     */
    private function bestMatch(string $label, Collection $candidates): array
    {
        $bestId = null;
        $bestScore = 0.0;

        foreach ($candidates as $candidate) {
            $score = $this->similarity($label, (string) $candidate->name);
            if ($score > $bestScore) {
                $bestScore = $score;
                $bestId = (int) $candidate->id;
            }
        }

        return [$bestId, $bestScore];
    }

    /**
     * Similarité déterministe (0..1) libellé ↔ nom candidat. Combine, en gardant le max :
     *  - containment : nom candidat présent tel quel dans le libellé → 1.0 ;
     *  - token significatif : plus long token alpha (≥3) du candidat présent → 0.9 ;
     *  - rappel de tokens : |tokens candidat ∩ libellé| / |tokens candidat| ;
     *  - similar_text global (%).
     * Robuste aux noms partiels réels (« Coca 33cl » ↔ « Coca cola 24 canettes »).
     */
    private function similarity(string $label, string $candidate): float
    {
        $a = $this->normalize($label);
        $b = $this->normalize($candidate);

        if ($a === '' || $b === '') {
            return 0.0;
        }

        if (str_contains(" {$a} ", " {$b} ")) {
            return 1.0;
        }

        $labelTokens = array_values(array_filter(explode(' ', $a)));
        $candTokens = array_values(array_filter(explode(' ', $b)));

        $sig = 0.0;
        foreach ($candTokens as $token) {
            if (strlen($token) >= 3 && ctype_alpha($token) && in_array($token, $labelTokens, true)) {
                $sig = 0.9;
                break;
            }
        }

        $common = array_intersect($candTokens, $labelTokens);
        $recall = count($candTokens) > 0 ? count($common) / count($candTokens) : 0.0;

        similar_text($a, $b, $percent);

        return max($sig, $recall, $percent / 100.0);
    }

    /** Normalise : sans accent, minuscule, ponctuation → espaces, espaces compactés. */
    private function normalize(string $value): string
    {
        $ascii = Str::lower(Str::ascii($value));
        $ascii = preg_replace('/[^a-z0-9]+/', ' ', $ascii) ?? '';

        return trim(preg_replace('/\s+/', ' ', $ascii) ?? '');
    }

    private function looksLikeCharge(string $label): bool
    {
        $tokens = array_filter(explode(' ', $this->normalize($label)));

        foreach ($tokens as $token) {
            if (in_array($token, self::CHARGE_KEYWORDS, true)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Matières actives de la branche (hard-scope explicite — RawMaterial n'a pas de BranchScope global).
     *
     * @return Collection<int, RawMaterial>
     */
    private function materials(int $branchId): Collection
    {
        return RawMaterial::query()
            ->where('branch_id', $branchId)
            ->where('is_active', true)
            ->get(['id', 'name']);
    }

    /**
     * Items de la catégorie « Boissons » (revendus à l'unité → stock_item).
     * Catalogue global V1 (Item sans branch_id). Repérage robuste : slug
     * commençant par « boisson » OU nom contenant « boisson » (tolère le slug
     * randomisé des tests).
     *
     * @return Collection<int, Item>
     */
    private function drinkItems(): Collection
    {
        return Item::query()
            ->where('status', Status::ACTIVE)
            ->whereHas('category', function ($query): void {
                // Starts-with (pas « contains ») → « Boissons » matche, « Poissons » NON.
                $query->where('slug', 'like', 'boisson%')
                    ->orWhere('name', 'like', 'Boisson%');
            })
            ->get(['id', 'name']);
    }
}
