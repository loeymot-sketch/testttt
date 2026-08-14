<?php

namespace App\Http\Controllers\Admin;

use App\Models\RawMaterial;
use App\Models\RawMaterialMovement;
use App\Models\RawMaterialStock;
use App\Services\RawMaterials\RawMaterialStockService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * [GOAL_CAYENNE_FINITION_2026-08-13 / §6 Vague 5] Écran d'AJUSTEMENT INVENTAIRE
 * matière première — la seule porte d'écriture manuelle du domaine « matières
 * premières ». Jusqu'ici {@see RawMaterialStockService::adjust()} était écrite,
 * testée (unitairement) mais SANS AUCUN APPELANT : le scan de facture fait
 * entrer le stock, les ventes le font sortir, RIEN ne le redresse après une
 * casse, un vol ou une pesée fausse (3 141 mouvements écrits par les ventes,
 * 0 correction manuelle possible — mesuré).
 *
 * Deux actions :
 *  - history() : LECTURE des derniers ajustements manuels d'une matière
 *    (traçabilité qui/quand/pourquoi). Gate `items_show` (même famille que
 *    {@see StockRuptureDashboardController} / {@see UnifiedStockViewController}).
 *  - adjust()  : ÉCRITURE — positionne le stock théorique sur une CIBLE absolue
 *    (« on a compté X »), raison obligatoire. Gate `items_create` (même famille
 *    que {@see PurchasingScanController} — l'autre porte d'écriture stock matière).
 *
 * Traçabilité : `raw_material_movements` n'a pas de colonne `user_id` (append-only,
 * schema gelé au sens où on ne modifie pas les migrations existantes — plan
 * amendement §7 discipline scope-minimal). Le « qui » est donc porté dans
 * `meta` (adjusted_by_user_id/name), le « quand » dans `created_at` (colonne
 * native), le « pourquoi » dans `reason` (colonne native, obligatoire ici par
 * VALIDATION APPLICATIVE — le service lui-même n'impose aucune contrainte de
 * non-vacuité sur `$reason`, c'est un choix délibéré de ce contrôleur).
 *
 * Branch : V1 mono-branche — la matière porte son propre `branch_id` (pas de
 * BranchScope global sur RawMaterial, cf. modèle). `authorizeWritableBranchScope`
 * (AdminController) protège quand même le cas multi-poste futur.
 *
 * NF525 : domaine ADDITIF, hors chaîne fiscale — aucune écriture sur
 * audit_logs/z_reports/fiscal_sequence, aucune assertion fiscale.
 */
class RawMaterialAdjustController extends AdminController
{
    /** Cible de comptage — jamais négative en usage réel (contrainte UI, pas service). */
    private const MIN_TARGET = 0;

    private const MAX_TARGET = 999999.999;

    public function __construct()
    {
        parent::__construct();

        $this->middleware(['permission:items_show'])->only('history');
        $this->middleware(['permission:items_create'])->only('adjust');
    }

    /**
     * GET /api/admin/raw-materials/{rawMaterial}/movements — derniers
     * ajustements MANUELS de cette matière (source_type='manual_adjustment'),
     * les plus récents d'abord. Lecture seule, hors NF525.
     */
    public function history(Request $request, RawMaterial $rawMaterial): JsonResponse
    {
        $this->authorizeBranchScope($request, (int) $rawMaterial->branch_id);

        $movements = RawMaterialMovement::query()
            ->where('raw_material_id', $rawMaterial->id)
            ->where('branch_id', $rawMaterial->branch_id)
            ->where('source_type', 'manual_adjustment')
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->limit(20)
            ->get();

        return response()->json([
            'ok' => true,
            'raw_material_id' => (int) $rawMaterial->id,
            'movements' => $movements->map(function (RawMaterialMovement $movement): array {
                $meta = (array) ($movement->meta ?? []);

                return [
                    'id' => (int) $movement->id,
                    'delta' => (float) $movement->delta,
                    'reason' => (string) $movement->reason,
                    'note' => $meta['note'] ?? null,
                    'adjusted_by_name' => $meta['adjusted_by_name'] ?? null,
                    'previous_on_hand' => array_key_exists('previous_on_hand', $meta)
                        ? (float) $meta['previous_on_hand']
                        : null,
                    'target_on_hand' => array_key_exists('target_on_hand', $meta)
                        ? (float) $meta['target_on_hand']
                        : null,
                    'created_at' => optional($movement->created_at)->toIso8601String(),
                    'created_at_human' => optional($movement->created_at)->diffForHumans(),
                ];
            })->values(),
        ]);
    }

    /**
     * POST /api/admin/raw-materials/{rawMaterial}/adjust — AJUSTEMENT INVENTAIRE.
     *
     * Body : { target_on_hand: number (>=0), reason: string (3-64), note?: string (<=255) }
     *
     * `target_on_hand` est une CIBLE ABSOLUE (« on a compté X »), pas un delta —
     * c'est la sémantique de {@see RawMaterialStockService::adjust()} (choix
     * documenté dans le service : usage métier réel = comptage correcteur).
     * `reason` va directement dans `raw_material_movements.reason` (colonne
     * varchar(64)) ; `note` (optionnelle, texte plus long) est reléguée à `meta`.
     */
    public function adjust(Request $request, RawMaterial $rawMaterial, RawMaterialStockService $service): JsonResponse
    {
        $this->authorizeWritableBranchScope($request, (int) $rawMaterial->branch_id);

        $validated = $request->validate([
            'target_on_hand' => ['required', 'numeric', 'min:'.self::MIN_TARGET, 'max:'.self::MAX_TARGET],
            'reason' => ['required', 'string', 'min:3', 'max:64'],
            'note' => ['nullable', 'string', 'max:255'],
        ]);

        $branchId = (int) $rawMaterial->branch_id;
        $user = $request->user();

        $previousOnHand = (float) (RawMaterialStock::query()
            ->where('raw_material_id', $rawMaterial->id)
            ->where('branch_id', $branchId)
            ->value('on_hand') ?? 0);

        $targetOnHand = round((float) $validated['target_on_hand'], 3);

        $meta = [
            'adjusted_by_user_id' => $user?->id,
            'adjusted_by_name' => $user?->name,
            'previous_on_hand' => $previousOnHand,
            'target_on_hand' => $targetOnHand,
            'channel' => 'admin_raw_material_adjust_ui',
        ];
        if (! empty($validated['note'])) {
            $meta['note'] = (string) $validated['note'];
        }

        // sourceType renseigné (filtrable / traçable) MAIS sourceId volontairement
        // NULL : l'idempotence du service ne s'active QUE si les deux sont non-nuls
        // (RawMaterialStockService::isDuplicateSource). Un ajustement manuel doit
        // TOUJOURS s'appliquer, même si l'owner corrige deux fois de suite la même
        // matière (double-submit HTTP protégé séparément par le middleware
        // `idempotency` posé sur la route, opt-in via X-Idempotency-Key).
        $stock = $service->adjust(
            (int) $rawMaterial->id,
            $targetOnHand,
            (string) $validated['reason'],
            'manual_adjustment',
            null,
            $meta,
            $branchId
        );

        return response()->json([
            'ok' => true,
            'raw_material_id' => (int) $rawMaterial->id,
            'previous_on_hand' => $previousOnHand,
            'on_hand' => (float) $stock->on_hand,
            'delta' => round($targetOnHand - $previousOnHand, 3),
        ]);
    }
}
