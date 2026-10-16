<?php

namespace App\Http\Controllers\Admin;

use App\Http\Resources\KDSOrderDetailsResource;
use App\Models\DlcLabelProduct;
use App\Models\LabelPrintLog;
use App\Models\Order;
use App\Services\KitchenDisplaySystemOrderService;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\Rule;

/**
 * [ETIQUETTES 2026-10-16 · owner] Imprimante d'étiquettes Brother QL-820NWB, branchée en Bluetooth sur la
 * tablette : étiquettes d'hygiène (DLC) et une étiquette par produit de commande.
 *
 * CE QUE FAIT LE SERVEUR, ET CE QU'IL NE FAIT PAS
 * -----------------------------------------------
 * Le serveur ne parle JAMAIS à l'imprimante : il tourne dans le cloud, l'imprimante est posée à côté de la
 * tablette. C'est le Chrome de la tablette qui écrit sur le Bluetooth (voir resources/js/helpers/labelPrinter.js).
 * Le serveur est la SOURCE DE VÉRITÉ de trois choses :
 *   · le catalogue des produits DLC et leurs durées de conservation ;
 *   · l'HORLOGE : la date d'ouverture et la DLC imprimées sont calculées ici, une seule fois, et journalisées —
 *     le papier est identique au journal d'hygiène ;
 *   · le VERROU d'impression automatique : deux tablettes ne doivent jamais imprimer deux fois la même commande.
 *
 * Porte : permission:pos-orders|pos|kitchen-display-system — le personnel qui tient la tablette, comme l'écran
 * « Uber photo » et l'écran cuisine. Une permission neuve ne serait portée par aucun rôle tant qu'un seeder ne
 * l'aurait pas distribuée (l'écran serait inaccessible à tout le monde, propriétaire compris).
 *
 * Domaine NEUF, ADDITIF, HORS NF525 : aucun prix, aucune séquence fiscale, aucune chaîne d'audit touchée.
 */
class LabelController extends AdminController
{
    public function __construct(private readonly KitchenDisplaySystemOrderService $kitchen)
    {
        parent::__construct();

        $this->middleware(['permission:pos-orders|pos|kitchen-display-system']);
    }

    // ───────────────────────────── Produits DLC ─────────────────────────────

    public function products(): JsonResponse
    {
        // Étiquettes réellement imprimées sur 30 jours, par produit : l'écran met en tête ce qu'on utilise le plus.
        $uses = LabelPrintLog::query()
            ->where('kind', LabelPrintLog::KIND_DLC)
            ->whereIn('status', LabelPrintLog::DONE_STATUSES)
            ->whereNotNull('dlc_product_id')
            ->where('created_at', '>=', now()->subDays(30))
            ->selectRaw('dlc_product_id, SUM(copies) as total')
            ->groupBy('dlc_product_id')
            ->pluck('total', 'dlc_product_id');

        $products = DlcLabelProduct::query()
            ->orderBy('sort_order')
            ->orderBy('name')
            ->get()
            ->map(fn (DlcLabelProduct $p): array => $this->presentProduct($p) + ['uses_30d' => (int) ($uses[$p->id] ?? 0)])
            ->values();

        return response()->json([
            'data' => $products,
            'meta' => $this->clock(),
        ]);
    }

    public function storeProduct(Request $request): JsonResponse
    {
        $data = $this->validateProduct($request);

        $product = new DlcLabelProduct($data);
        $product->branch_id = $this->branchId($request);
        $product->save();

        // refresh : les valeurs par défaut posées par la base (is_active, sort_order) doivent figurer dans la réponse.
        return response()->json(['data' => $this->presentProduct($product->refresh())], 201);
    }

    public function updateProduct(Request $request, string $product): JsonResponse
    {
        $model = DlcLabelProduct::query()->findOrFail((int) $product);
        $model->fill($this->validateProduct($request, $model->id))->save();

        return response()->json(['data' => $this->presentProduct($model->refresh())]);
    }

    public function destroyProduct(string $product): JsonResponse
    {
        // Suppression douce : le journal d'hygiène garde le nom et la durée copiés à l'impression.
        DlcLabelProduct::query()->findOrFail((int) $product)->delete();

        return response()->json(['status' => 'ok']);
    }

    // ───────────────────────────── Impression DLC ─────────────────────────────

    /**
     * Prépare l'étiquette : calcule ouverture et DLC avec l'horloge du serveur, journalise, et rend ce que la
     * tablette doit imprimer. L'impression elle-même se confirme ensuite par `printResult`.
     */
    public function dlcPrint(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'product_id' => ['required', 'integer'],
            'copies' => ['nullable', 'integer', 'min:1', 'max:20'],
        ], [], ['product_id' => 'produit', 'copies' => 'nombre d’étiquettes']);

        $product = DlcLabelProduct::query()->where('is_active', true)->find($validated['product_id']);
        if (! $product) {
            return response()->json(['status' => 'unknown_product', 'message' => 'Produit introuvable ou désactivé.'], 404);
        }

        $openedAt = now();
        $expiresAt = $product->expiresFrom($openedAt);
        $user = $request->user();

        $log = new LabelPrintLog([
            'user_id' => $user?->id,
            'kind' => LabelPrintLog::KIND_DLC,
            'dlc_product_id' => $product->id,
            'product_name' => $product->name,
            'shelf_life_value' => $product->shelf_life_value,
            'shelf_life_unit' => $product->shelf_life_unit,
            'opened_at' => $openedAt,
            'expires_at' => $expiresAt,
            'copies' => (int) ($validated['copies'] ?? 1),
            'status' => LabelPrintLog::STATUS_CLAIMED,
            'claimed_at' => $openedAt,
        ]);
        // La ligne de journal appartient à la branche du PRODUIT (un administrateur sans branche voit toutes les branches).
        $log->branch_id = (int) ($product->branch_id ?: $this->branchId($request));
        $log->save();

        return response()->json([
            'log_id' => $log->id,
            'attempt' => 1,
            'name' => $product->name,
            'storage' => $product->storage,
            'shelf_life_value' => $product->shelf_life_value,
            'shelf_life_unit' => $product->shelf_life_unit,
            'opened_at' => $openedAt->toIso8601String(),
            'expires_at' => $expiresAt->toIso8601String(),
            'copies' => $log->copies,
            'by' => $this->firstName($user?->name),
        ]);
    }

    /**
     * Verdict de l'impression, rendu par la tablette. Idempotent : le même verdict deux fois ne change rien.
     *
     * `attempt` (rendu par `claim`) identifie LA tentative qui répond. Une tablette lente dont la réclamation a été
     * rendue à la file puis reprise par une autre ne doit pas clore la tentative de l'autre : sa réponse tardive est
     * ignorée (200, `ignored: true`), jamais appliquée.
     */
    public function printResult(Request $request, string $log): JsonResponse
    {
        $validated = $request->validate([
            'status' => ['required', Rule::in([
                LabelPrintLog::STATUS_PRINTED,
                LabelPrintLog::STATUS_FAILED,
                LabelPrintLog::STATUS_UNCONFIRMED,
            ])],
            'message' => ['nullable', 'string', 'max:300'],
            'labels_count' => ['nullable', 'integer', 'min:0', 'max:1000'],
            'printed_pages' => ['nullable', 'integer', 'min:0', 'max:1000'],
            'attempt' => ['nullable', 'integer', 'min:1', 'max:255'],
        ]);

        $model = LabelPrintLog::query()->findOrFail($log);

        // Un verdict définitif ne se réécrit pas : une réponse tardive d'un autre onglet ne doit pas
        // transformer un « imprimé » en « échec ».
        $applied = LabelPrintLog::query()
            ->whereKey($model->id)
            ->where('status', LabelPrintLog::STATUS_CLAIMED)
            ->when(isset($validated['attempt']), fn ($q) => $q->where('attempts', (int) $validated['attempt']))
            ->update([
                'status' => $validated['status'],
                'message' => $validated['message'] ?? null,
                'labels_count' => $validated['labels_count'] ?? $model->labels_count,
                'printed_pages' => $validated['printed_pages']
                    ?? ($validated['status'] === LabelPrintLog::STATUS_FAILED ? 0 : ($validated['labels_count'] ?? $model->labels_count ?? 0)),
                'finished_at' => now(),
                'updated_at' => now(),
            ]);

        $model->refresh();

        return response()->json([
            'status' => 'ok',
            'log_status' => $model->status,
            'ignored' => $applied === 0 && $model->status === LabelPrintLog::STATUS_CLAIMED,
        ]);
    }

    /** Dernières impressions (traçabilité d'hygiène). */
    public function history(Request $request): JsonResponse
    {
        $kind = $request->query('kind') === LabelPrintLog::KIND_ORDER ? LabelPrintLog::KIND_ORDER : LabelPrintLog::KIND_DLC;

        $rows = LabelPrintLog::query()
            ->with('user:id,name')
            ->where('kind', $kind)
            ->orderByDesc('id')
            ->limit(50)
            ->get()
            ->map(fn (LabelPrintLog $l): array => [
                'id' => $l->id,
                'product_name' => $l->product_name,
                'order_id' => $l->order_id,
                'copies' => $l->copies,
                'labels_count' => $l->labels_count,
                'opened_at' => $l->opened_at?->toIso8601String(),
                'expires_at' => $l->expires_at?->toIso8601String(),
                'status' => $l->status,
                'message' => $l->message,
                'by' => $this->firstName($l->user?->name),
                'created_at' => $l->created_at?->toIso8601String(),
            ])->values();

        return response()->json(['data' => $rows]);
    }

    // ───────────────────────────── Commandes ─────────────────────────────

    /**
     * Les commandes « en cours » = EXACTEMENT celles de l'écran cuisine (même service, même ressource) :
     * ce qui est à étiqueter est ce qui est à préparer, produits et compositions comprises.
     * `meta.states` dit, par commande, si ses étiquettes sont sorties, en cours, ou ont échoué.
     */
    public function orders(Request $request): JsonResponse
    {
        try {
            $orders = $this->kitchen->list($request);
        } catch (\Throwable $e) {
            Log::warning('[Labels] liste des commandes indisponible', ['error' => $e->getMessage()]);

            return response()->json(['status' => false, 'message' => 'Liste des commandes indisponible.'], 422);
        }

        $ids = collect($orders)->pluck('id')->all();
        $logs = $ids === []
            ? collect()
            : LabelPrintLog::query()->where('kind', LabelPrintLog::KIND_ORDER)->whereIn('order_id', $ids)->orderBy('id')->get();

        $states = [];
        foreach ($logs->groupBy('order_id') as $orderId => $rows) {
            $done = $rows->whereIn('status', LabelPrintLog::DONE_STATUSES)->where('partial', false);
            $fresh = $rows->first(fn (LabelPrintLog $l): bool => $l->status === LabelPrintLog::STATUS_CLAIMED
                && $l->claimed_at !== null
                && $l->claimed_at->gt(now()->subSeconds(LabelPrintLog::CLAIM_TTL_SECONDS)));
            $last = $rows->last();
            $states[$orderId] = [
                'printed' => $done->isNotEmpty(),
                'print_count' => $done->count(),
                'in_progress' => $fresh !== null,
                'last_printed_at' => $done->last()?->finished_at?->toIso8601String(),
                'failed' => $last->status === LabelPrintLog::STATUS_FAILED && $done->isEmpty(),
                'printed_pages' => $last->status === LabelPrintLog::STATUS_FAILED ? (int) $last->printed_pages : null,
                'last_message' => $last->status === LabelPrintLog::STATUS_FAILED ? $last->message : null,
            ];
        }

        return response()->json([
            'data' => KDSOrderDetailsResource::collection($orders)->resolve($request),
            'meta' => ['states' => (object) $states, 'overflow' => $this->kitchen->lastListOverflow()] + $this->clock(),
        ]);
    }

    /**
     * Réclame l'impression des étiquettes d'une commande.
     *   mode=manual — toujours accordé (réimprimer est permis), une ligne de journal de plus.
     *   mode=auto   — accordé UNE seule fois par commande (verrou `claim_key`), sauf réclamation périmée
     *                 (tablette fermée en pleine impression) qui est rendue à la file.
     */
    public function claim(Request $request, string $order): JsonResponse
    {
        $validated = $request->validate([
            'mode' => ['required', Rule::in(['auto', 'manual'])],
            'labels_count' => ['nullable', 'integer', 'min:0', 'max:1000'],
            // Réimpression de QUELQUES étiquettes seulement : ne vaut pas « commande imprimée ».
            'partial' => ['nullable', 'boolean'],
        ]);

        $orderModel = Order::query()->findOrFail((int) $order);
        $branchId = (int) ($orderModel->branch_id ?: $this->branchId($request));
        $auto = $validated['mode'] === 'auto';
        $now = now();

        $attributes = [
            'user_id' => $request->user()?->id,
            'kind' => LabelPrintLog::KIND_ORDER,
            'order_id' => $orderModel->id,
            'labels_count' => $validated['labels_count'] ?? null,
            'partial' => ! $auto && (bool) ($validated['partial'] ?? false),
            'status' => LabelPrintLog::STATUS_CLAIMED,
            'claimed_at' => $now,
            'attempts' => 1,
            'claim_key' => $auto ? 'order:'.$orderModel->id.':auto' : null,
        ];

        // Automatique : jamais pour une commande dont les étiquettes sont DÉJÀ sorties (même réimprimées à la main
        // par un autre appareil) ni pendant qu'une impression récente est en cours.
        if ($auto) {
            $alreadyHandled = LabelPrintLog::query()
                ->where('kind', LabelPrintLog::KIND_ORDER)
                ->where('order_id', $orderModel->id)
                ->where('partial', false)
                ->where(function ($q) use ($now) {
                    $q->whereIn('status', LabelPrintLog::DONE_STATUSES)
                        ->orWhere(function ($fresh) use ($now) {
                            $fresh->where('status', LabelPrintLog::STATUS_CLAIMED)
                                ->where('claimed_at', '>=', $now->copy()->subSeconds(LabelPrintLog::CLAIM_TTL_SECONDS));
                        });
                })
                ->exists();
            if ($alreadyHandled) {
                return response()->json(['claimed' => false]);
            }
        }

        try {
            $log = new LabelPrintLog($attributes);
            $log->branch_id = $branchId;
            $log->save();

            return response()->json(['claimed' => true, 'log_id' => $log->id, 'attempt' => 1]);
        } catch (QueryException $e) {
            if (! $auto || ! $this->isUniqueViolation($e)) {
                throw $e;
            }
        }

        // Déjà réclamée en automatique. Rendue à la file dans deux cas seulement, par UNE mise à jour conditionnelle
        // atomique (un seul des deux appelants la gagne) :
        //   · réclamation restée sans verdict au-delà du délai (onglet fermé en pleine impression) ;
        //   · échec SANS aucune étiquette sortie, après un court délai, dans la limite des tentatives.
        // Un échec APRÈS sortie d'étiquettes n'est jamais retenté seul : ce serait un doublon.
        $key = 'order:'.$orderModel->id.':auto';
        $reclaimed = LabelPrintLog::query()
            ->where('claim_key', $key)
            ->where(function ($q) use ($now) {
                $q->where(function ($stale) use ($now) {
                    $stale->where('status', LabelPrintLog::STATUS_CLAIMED)
                        ->where('claimed_at', '<', $now->copy()->subSeconds(LabelPrintLog::CLAIM_TTL_SECONDS));
                })->orWhere(function ($failed) use ($now) {
                    $failed->where('status', LabelPrintLog::STATUS_FAILED)
                        ->where('printed_pages', 0)
                        ->where('attempts', '<', LabelPrintLog::MAX_AUTO_ATTEMPTS)
                        ->where('finished_at', '<', $now->copy()->subSeconds(LabelPrintLog::RETRY_AFTER_SECONDS));
                });
            })
            ->update([
                'status' => LabelPrintLog::STATUS_CLAIMED,
                'claimed_at' => $now,
                'finished_at' => null,
                'message' => null,
                'user_id' => $request->user()?->id,
                'attempts' => DB::raw('attempts + 1'),
                'updated_at' => $now,
            ]);

        if ($reclaimed === 1) {
            $log = LabelPrintLog::query()->where('claim_key', $key)->first();

            return response()->json(['claimed' => true, 'log_id' => $log?->id, 'attempt' => $log?->attempts, 'reclaimed' => true]);
        }

        return response()->json(['claimed' => false]);
    }

    // ───────────────────────────── Utilitaires ─────────────────────────────

    private function validateProduct(Request $request, ?int $ignoreId = null): array
    {
        $rawUnit = $request->input('shelf_life_unit');
        $unit = is_string($rawUnit) ? $rawUnit : DlcLabelProduct::UNIT_DAYS;
        $max = $unit === DlcLabelProduct::UNIT_HOURS ? 720 : 365;

        $data = $request->validate([
            'name' => ['required', 'string', 'min:1', 'max:120'],
            'shelf_life_value' => ['required', 'integer', 'min:1', 'max:'.$max],
            'shelf_life_unit' => ['required', Rule::in(DlcLabelProduct::UNITS)],
            'storage' => ['nullable', 'string', 'max:60'],
            'sort_order' => ['nullable', 'integer', 'min:0', 'max:100000'],
            'is_active' => ['nullable', 'boolean'],
        ], [
            'shelf_life_value.max' => 'La durée ne peut pas dépasser '.$max.($unit === DlcLabelProduct::UNIT_HOURS ? ' heures (30 jours).' : ' jours.'),
        ], [
            'name' => 'nom',
            'shelf_life_value' => 'durée',
            'shelf_life_unit' => 'unité',
            'storage' => 'conservation',
        ]);

        $data['name'] = trim((string) $data['name']);
        $data['storage'] = isset($data['storage']) && trim((string) $data['storage']) !== '' ? trim((string) $data['storage']) : null;

        $duplicate = DlcLabelProduct::query()
            ->whereRaw('LOWER(name) = ?', [mb_strtolower($data['name'])])
            ->when($ignoreId, fn ($q) => $q->where('id', '!=', $ignoreId))
            ->exists();
        if ($duplicate) {
            abort(response()->json([
                'message' => 'Ce produit existe déjà.',
                'errors' => ['name' => ['Un produit porte déjà ce nom.']],
            ], 422));
        }

        if (! array_key_exists('is_active', $data) || $data['is_active'] === null) {
            unset($data['is_active']);
        }
        if (! array_key_exists('sort_order', $data) || $data['sort_order'] === null) {
            unset($data['sort_order']);
        }

        return $data;
    }

    private function presentProduct(DlcLabelProduct $p): array
    {
        return [
            'id' => $p->id,
            'name' => $p->name,
            'shelf_life_value' => $p->shelf_life_value,
            'shelf_life_unit' => $p->shelf_life_unit,
            'storage' => $p->storage,
            'sort_order' => $p->sort_order,
            'is_active' => $p->is_active,
        ];
    }

    /** L'heure du serveur et son fuseau : la tablette s'y recale, quelle que soit sa propre horloge. */
    private function clock(): array
    {
        return [
            'server_now' => now()->toIso8601String(),
            'timezone' => config('app.timezone'),
        ];
    }

    private function branchId(Request $request): int
    {
        return (int) ($request->user()?->branch_id ?: config('labels.branch_id', 1));
    }

    private function firstName(?string $name): ?string
    {
        $first = trim((string) strtok(trim((string) $name), ' '));

        return $first !== '' ? $first : null;
    }

    private function isUniqueViolation(QueryException $e): bool
    {
        // SQLSTATE 23000 (MySQL, SQLite) / 23505 (PostgreSQL) = violation de contrainte d'unicité.
        $sqlState = (string) ($e->errorInfo[0] ?? $e->getCode());

        return $sqlState === '23000' || $sqlState === '23505';
    }
}
