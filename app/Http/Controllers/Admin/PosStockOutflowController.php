<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Item;
use App\Models\StockOutflow;
use App\Services\Stock\StockService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * [OWNER REPAS-PERSONNEL/PERTES 2026-07-31] Sorties de stock hors-vente depuis la caisse :
 * enregistrer un produit consommé en « repas personnel » ou perdu/raté, avec trace horodatée
 * (qui/quoi/combien/quand/pourquoi) + décrément du stock direct si l'item en a un. Gate permission:pos.
 */
class PosStockOutflowController extends Controller
{
    public function __construct(private StockService $stockService)
    {
    }

    public function store(Request $request): JsonResponse
    {
        abort_unless(auth()->user()?->can('pos'), 403);

        $data = $request->validate([
            'item_id'  => ['required', 'integer', 'min:1'],
            'quantity' => ['required', 'integer', 'min:1', 'max:999'],
            'type'     => ['required', 'string', 'in:'.implode(',', StockOutflow::TYPES)],
            'note'     => ['nullable', 'string', 'max:255'],
        ]);

        $userId   = (int) auth()->id();
        $branchId = (int) (auth()->user()?->branch_id ?? 0);
        if ($branchId <= 0) {
            $branchId = 1; // V1 mono-resto : admin (branch 0) opère sur la branche 1
        }

        // [SEC MISSION-12 2026-07-31] Item ACTIF non supprimé uniquement (on ne trace pas une sortie sur
        // un produit retiré du catalogue). withoutGlobalScope(BranchScope) reste (admin branch 0 opère
        // cross-branche) mais le SoftDeletes global scope s'applique → les items trashed sont exclus.
        $item = Item::withoutGlobalScope(\App\Models\Scopes\BranchScope::class)
            ->where('status', \App\Enums\Status::ACTIVE)
            ->find((int) $data['item_id']);
        abort_unless($item !== null, 422, 'Article introuvable ou inactif.');

        // [SEC MISSION-12 2026-07-31] Clé = le header X-Idempotency-Key (envoyé par la modale, stable au
        // rejeu réseau) → middleware (route requise) + garde service/DB dédupent LA MÊME requête. Fallback
        // uuid pour un appel API brut hors modale (reste non-crashant, sans dédup inter-requêtes).
        $idempotencyKey = trim((string) $request->header('X-Idempotency-Key'))
            ?: 'outflow:'.$data['type'].':'.$data['item_id'].':'.$branchId.':'.$userId.':'.Str::uuid();

        // [SEC MISSION-12 2026-07-31] Décrément + trace ATOMIQUES dans UNE transaction : si l'écriture de
        // la trace échoue, le décrément est annulé — sinon on aurait du stock retiré SANS trace (viole
        // « on trace tout ce qui part »). recordManualOutflow a sa propre transaction interne → savepoint.
        $outflow = \Illuminate\Support\Facades\DB::transaction(function () use ($data, $branchId, $userId, $item, $idempotencyKey) {
            // Décrément du stock DIRECT (best-effort : true si l'item a un StockLevel, false si composite).
            // Le StockMovement porte le motif canonique 'manual_out' (contrainte enum stock_movements) ;
            // la distinction métier repas/perte vit dans stock_outflows.type.
            $decremented = $this->stockService->recordManualOutflow(
                (int) $data['item_id'],
                $branchId,
                (int) $data['quantity'],
                'manual_out',
                $userId,
                $idempotencyKey,
            );

            // La TRACE, toujours enregistrée (valable même pour les composites sans stock direct).
            return StockOutflow::create([
                'branch_id'         => $branchId,
                'item_id'           => (int) $data['item_id'],
                'item_name'         => (string) $item->name,
                'quantity'          => (int) $data['quantity'],
                'type'              => $data['type'],
                'note'              => $data['note'] ?? null,
                'user_id'           => $userId,
                'stock_decremented' => $decremented,
                'created_at'        => now(),
            ]);
        });

        return response()->json([
            'status'  => true,
            'message' => $data['type'] === StockOutflow::TYPE_STAFF_MEAL
                ? 'Repas personnel enregistré.'
                : 'Perte enregistrée.',
            'outflow' => $this->present($outflow),
        ], 201);
    }

    public function recent(): JsonResponse
    {
        abort_unless(auth()->user()?->can('pos'), 403);

        $rows = StockOutflow::query()->orderByDesc('created_at')->limit(50)->get();

        return response()->json([
            'data' => $rows->map(fn (StockOutflow $o): array => $this->present($o))->all(),
        ]);
    }

    /** Liste légère des produits actifs pour le sélecteur (id + nom). */
    public function items(): JsonResponse
    {
        abort_unless(auth()->user()?->can('pos'), 403);

        $items = Item::withoutGlobalScopes()
            ->where('status', \App\Enums\Status::ACTIVE)
            ->whereNull('deleted_at')
            ->orderBy('name')
            ->get(['id', 'name']);

        return response()->json([
            'data' => $items->map(fn (Item $i): array => ['id' => (int) $i->id, 'name' => (string) $i->name])->all(),
        ]);
    }

    private function present(StockOutflow $o): array
    {
        return [
            'id'                => $o->id,
            'item_name'         => $o->item_name,
            'quantity'          => $o->quantity,
            'type'              => $o->type,
            'type_label'        => $o->type === StockOutflow::TYPE_STAFF_MEAL ? 'Repas personnel' : 'Perte',
            'note'              => $o->note,
            'stock_decremented' => (bool) $o->stock_decremented,
            'created_at'        => optional($o->created_at)->toIso8601String(),
            'created_at_human'  => optional($o->created_at)->format('d/m H:i'),
        ];
    }
}
