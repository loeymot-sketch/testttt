<?php

namespace App\Http\Controllers\Admin;

use App\Http\Requests\RawMaterialRequest;
use App\Models\RawMaterial;
use App\Models\RawMaterialStock;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * [ONB-08 2026-08-28] Déclarer ses matières premières.
 *
 * ═══ CE DOMAINE N'AVAIT AUCUN CRUD ═══
 *
 * `routes/api.php:436-441` n'exposait que `movements` (lecture) et `adjust`
 * (correction de quantité). Les seules sources de création étaient
 * `RawMaterialBaselineSeeder` et une commande console.
 *
 * **Un nouveau commerçant ne pouvait déclarer aucun ingrédient.** Tout le domaine
 * lui arrivait pré-rempli avec celui de Le Cayenne, sans moyen d'en ajouter, d'en
 * retirer, ni de corriger une unité. C'est le blocage le plus lourd de la mission
 * « depuis zéro » — et il n'apparaissait dans aucun constat de reconnaissance.
 *
 * ═══ CE QUE CE CONTRÔLEUR FERME AU PASSAGE ═══
 *
 * `threshold_low` n'avait aucun chemin d'écriture : 55/55 `stock_levels` et 20/20
 * `raw_materials` à NULL, alors que le tableau de bord de rupture et le listener
 * d'alerte filtrent tous deux `whereNotNull('threshold_low')`. **100 % des lignes
 * étaient exclues** : l'alerte de stock bas était structurellement muette.
 *
 * ═══ CE QU'IL NE FAIT PAS ═══
 *
 * Il ne touche NI aux quantités (`adjust` reste la seule porte, avec sa traçabilité
 * par mouvement) NI aux recettes. Déclarer une matière et corriger un stock sont
 * deux gestes distincts, et les confondre ferait perdre la trace de l'un des deux.
 */
class RawMaterialController extends AdminController
{
    public function __construct()
    {
        parent::__construct();

        // Mêmes gardes que l'ajustement (`RawMaterialAdjustController:55-56`).
        $this->middleware(['permission:items_show'])->only('index');
        $this->middleware(['permission:items_create'])->only('store', 'update', 'destroy');
    }

    /** La liste des matières de la branche, avec leur stock courant. */
    public function index(Request $request): JsonResponse
    {
        $branchId = $this->branche($request);

        $matieres = RawMaterial::query()
            ->where('branch_id', $branchId)
            ->when(
                $request->filled('name'),
                fn ($q) => $q->where('name', 'like', '%' . $request->get('name') . '%')
            )
            ->orderBy('name')
            ->get()
            ->map(function (RawMaterial $m) use ($branchId): array {
                $stock = RawMaterialStock::query()
                    ->where('raw_material_id', $m->id)
                    ->where('branch_id', $branchId)
                    ->value('on_hand');

                return [
                    'id'             => (int) $m->id,
                    'name'           => (string) $m->name,
                    'unit'           => (string) $m->unit,
                    'piece_weight_g' => $m->piece_weight_g === null ? null : (float) $m->piece_weight_g,
                    'threshold_low'  => $m->threshold_low === null ? null : (float) $m->threshold_low,
                    'avg_cost'       => $m->avg_cost === null ? null : (float) $m->avg_cost,
                    'is_active'      => (bool) $m->is_active,
                    'on_hand'        => $stock === null ? null : (float) $stock,
                ];
            })
            ->values();

        return response()->json([
            'data'             => $matieres,
            'unites_acceptees' => RawMaterialRequest::UNITES_ACCEPTEES,
        ]);
    }

    public function store(RawMaterialRequest $request): JsonResponse
    {
        $donnees = $request->validated();
        $donnees['branch_id'] = $this->branche($request);
        $donnees['is_active'] = $request->boolean('is_active', true);

        $matiere = RawMaterial::create($donnees);

        return response()->json(['status' => true, 'id' => (int) $matiere->id], 201);
    }

    public function update(RawMaterialRequest $request, RawMaterial $rawMaterial): JsonResponse
    {
        $this->assertMemeBranche($request, $rawMaterial);

        /*
         * ⚠️ CHANGER L'UNITÉ D'UNE MATIÈRE QUI A DU STOCK.
         *
         * Le stock est stocké en NOMBRE, sans son unité (`raw_material_stocks.on_hand`).
         * Passer une matière de « kg » à « g » ne convertit rien : 3 deviendrait 3
         * grammes au lieu de 3 000. C'est exactement le facteur mille qui a mis onze
         * matières sur quatorze en négatif via les factures d'achat.
         *
         * On refuse donc, en nommant le stock en cause. Convertir automatiquement
         * serait pire : le commerçant ne verrait pas que ses chiffres ont bougé.
         */
        $stock = (float) (RawMaterialStock::query()
            ->where('raw_material_id', $rawMaterial->id)
            ->sum('on_hand') ?? 0);

        if ($request->input('unit') !== $rawMaterial->unit && abs($stock) > 0.0001) {
            return response()->json([
                'status'  => false,
                'message' => trans('all.message.unite_non_modifiable_avec_stock', [
                    'stock'  => rtrim(rtrim(number_format($stock, 3, ',', ' '), '0'), ','),
                    'unite'  => $rawMaterial->unit,
                ]),
            ], 422);
        }

        $rawMaterial->update($request->validated() + [
            'is_active' => $request->boolean('is_active', (bool) $rawMaterial->is_active),
        ]);

        return response()->json(['status' => true]);
    }

    /**
     * Retire une matière — en suppression douce, et jamais si elle sert encore.
     */
    public function destroy(Request $request, RawMaterial $rawMaterial): JsonResponse
    {
        $this->assertMemeBranche($request, $rawMaterial);

        /*
         * Une matière référencée par une recette nourrit la déduction de stock à
         * chaque commande. La retirer laisserait des lignes de recette pointant dans
         * le vide — le motif du `tax_id` orphelin corrigé ce matin, qui facturait à
         * 0 % en silence. On refuse, en nommant le nombre de recettes.
         */
        $recettes = DB::table('raw_material_recipe_lines')
            ->where('raw_material_id', $rawMaterial->id)
            ->count();

        if ($recettes > 0) {
            return response()->json([
                'status'  => false,
                'message' => trans('all.message.matiere_encore_dans_une_recette', ['n' => $recettes]),
            ], 422);
        }

        $rawMaterial->delete();

        return response()->json(['status' => true]);
    }

    // ────────────────────────────────────────────────────────────── périmètre

    /**
     * `RawMaterial` n'a PAS de `BranchScope` global — c'est une exemption déclarée
     * (`BranchScopeCoverageSentinelTest`), et le hard-scope est à la charge des
     * appelants. On le fait donc explicitement, sur chaque verbe.
     */
    private function branche(Request $request): int
    {
        $utilisateur = $request->user();
        $branche = (int) ($utilisateur?->branch_id ?: 0);

        return $branche > 0 ? $branche : 1;
    }

    private function assertMemeBranche(Request $request, RawMaterial $matiere): void
    {
        abort_if(
            (int) $matiere->branch_id !== $this->branche($request),
            403,
            trans('all.message.matiere_dune_autre_branche')
        );
    }
}
