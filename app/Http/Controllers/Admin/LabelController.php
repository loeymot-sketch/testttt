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
            // une étiquette annulée (imprimée par erreur) ne fait pas monter le produit en tête
            ->where(fn ($q) => $q->whereNull('discard_reason')->orWhere('discard_reason', '!=', LabelPrintLog::DISCARD_CANCELLED))
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
            'action' => ['nullable', 'string', Rule::in(DlcLabelProduct::ACTIONS)],
            // DLC imprimée par le FABRICANT sur l'emballage d'origine (AAAA-MM-JJ), et son numéro de lot.
            'original_dlc' => ['nullable', 'date_format:Y-m-d'],
            'supplier_lot' => ['nullable', 'string', 'max:40'],
            // Étiquette du produit d'origine (ex. poulet décongelé → poulet mariné), ou « produit neuf » déclaré.
            'parent_log_id' => ['nullable', 'integer', 'min:1'],
            'parent_none' => ['nullable', 'boolean'],
        ], [], [
            'product_id' => 'produit', 'copies' => 'nombre d’étiquettes', 'original_dlc' => 'DLC du fabricant',
            'supplier_lot' => 'lot du fabricant', 'parent_log_id' => 'étiquette d’origine',
        ]);

        $product = DlcLabelProduct::query()->where('is_active', true)->find($validated['product_id']);
        if (! $product) {
            return response()->json(['status' => 'unknown_product', 'message' => 'Produit introuvable ou désactivé.'], 404);
        }

        // Un événement demandé mais non prévu par la fiche n'est JAMAIS remplacé par un autre : l'étiquette dirait
        // « Entamé le » avec la durée d'ouverture alors que le produit sort du congélateur.
        $requested = $validated['action'] ?? null;
        if ($requested !== null && ! $product->hasAction($requested)) {
            $message = '« '.DlcLabelProduct::ACTION_LABELS[$requested].' » n’est pas prévu pour « '.$product->name.' » : ajoutez-le à sa fiche, ou choisissez un autre événement.';

            return response()->json(['status' => 'unknown_action', 'message' => $message, 'errors' => ['action' => [$message]]], 422);
        }

        $openedAt = now();
        $action = $product->action($requested);
        $expiresAt = $product->expiresFrom($openedAt, $action);
        $capSource = null;

        // La conservation figure sur toute étiquette d'hygiène : une fiche qui ne la dit pas n'imprime pas — et pour une
        // décongélation, c'est la conservation APRÈS décongélation, sans aucune valeur de repli.
        // Une valeur d'après‑décongélation enregistrée avant la règle (ex. « ≤ −18 °C ») ne s'imprime pas non plus.
        if ($action['key'] === 'thawed' && $product->storageFor('thawed') !== null
            && ! DlcLabelProduct::isValidThawedStorage((string) $product->storageFor('thawed'))) {
            $message = 'La conservation après décongélation de « '.$product->name.' » n’est pas du froid positif (Modifier la fiche).';

            return response()->json(['status' => 'thawed_storage_invalid', 'message' => $message, 'errors' => ['thawed_storage' => [$message]]], 422);
        }
        if ($product->storageFor($action['key']) === null) {
            [$status, $field, $what] = $action['key'] === 'thawed'
                ? ['thawed_storage_missing', 'thawed_storage', 'la conservation après décongélation']
                : ['storage_missing', 'storage', 'la conservation'];
            $message = 'Renseignez '.$what.' de « '.$product->name.' » (Modifier la fiche) : elle est imprimée sur l’étiquette.';

            return response()->json(['status' => $status, 'message' => $message, 'errors' => [$field => [$message]]], 422);
        }

        // Produit fait à partir d'un produit étiqueté : l'origine (ou « produit neuf ») est obligatoire — un appui ne
        // remet jamais l'horloge à zéro par oubli.
        if ($product->needs_parent && empty($validated['parent_log_id']) && empty($validated['parent_none'])) {
            $message = 'Indiquez le produit d’origine de « '.$product->name.' » (sa DLC ne repart pas à zéro), ou « produit neuf ».';

            return response()->json(['status' => 'parent_required', 'message' => $message, 'errors' => ['parent_log_id' => [$message]]], 422);
        }

        // Règle du plan de maîtrise sanitaire : la DLC secondaire ne dépasse JAMAIS la DLC du fabricant.
        $original = $validated['original_dlc'] ?? null;
        if ($product->ask_original_dlc && $original === null) {
            return response()->json([
                'message' => 'Indiquez la DLC du fabricant (sur l’emballage d’origine).',
                'errors' => ['original_dlc' => ['Indiquez la DLC du fabricant (sur l’emballage d’origine).']],
            ], 422);
        }
        if ($original !== null) {
            $originalEnd = \Carbon\Carbon::createFromFormat('Y-m-d', $original, config('app.timezone'))->endOfDay();
            if ($originalEnd->lt($openedAt)) {
                return response()->json([
                    'status' => 'original_expired',
                    'message' => 'DLC du fabricant dépassée : ce produit ne doit pas être utilisé.',
                    'errors' => ['original_dlc' => ['DLC du fabricant dépassée : ce produit ne doit pas être utilisé.']],
                ], 422);
            }
            if ($expiresAt->gt($originalEnd)) {
                $expiresAt = $originalEnd->copy()->startOfMinute();
                $capSource = 'original';
            }
        }

        // Produit fait À PARTIR d'un produit déjà étiqueté : sa DLC ne repart pas à zéro (GBPH § 2.7 — jamais plus longue
        // que la durée initiale, « sauf si un traitement assainissant est appliqué » : la CUISSON redonne une durée propre,
        // à condition que le produit d'origine soit encore bon au moment de la cuisson).
        $parent = null;
        if (! empty($validated['parent_log_id'])) {
            $parent = LabelPrintLog::query()
                ->where('kind', LabelPrintLog::KIND_DLC)
                ->whereIn('status', LabelPrintLog::DONE_STATUSES)
                ->whereNotNull('expires_at')
                ->find((int) $validated['parent_log_id']);
            $refuse = function (string $status, string $message) {
                return response()->json(['status' => $status, 'message' => $message, 'errors' => ['parent_log_id' => [$message]]], 422);
            };
            if (! $parent) {
                return $refuse('parent_unknown', 'Étiquette d’origine introuvable.');
            }
            $ref = $parent->product_name.($parent->lot ? ' (N° '.$parent->lot.')' : '');
            // Un bac marqué « utilisé » à l'instant reste une origine valable un court moment (on le vide, puis on étiquette
            // ce qu'on en a fait) ; jeté ou annulé, jamais.
            $justUsed = $parent->discard_reason === LabelPrintLog::DISCARD_USED
                && $parent->discarded_at !== null
                && $parent->discarded_at->gte($openedAt->copy()->subHours(LabelPrintLog::PARENT_USED_GRACE_HOURS));
            if ($parent->discarded_at !== null && ! $justUsed) {
                return $refuse('parent_closed', 'Le produit d’origine '.$ref.' n’est plus en stock (jeté, utilisé ou annulé).');
            }
            if ($parent->expires_at->lte($openedAt)) {
                return $refuse('parent_expired', 'Le produit d’origine '.$ref.' est périmé : il ne doit pas être utilisé.');
            }
            // Une SEULE remise en température : cuire un produit qui a DÉJÀ été cuit — même plusieurs étiquettes plus haut
            // (cuit → mis sous vide → recuit) — ne lui redonne pas de durée (GBPH § 2.7 ; toxines thermostables
            // B. cereus, S. aureus) : la DLC de la chaîne s'applique alors comme pour toute préparation.
            $restarts = $action['key'] === 'cooked' && ! $this->chainWasCooked($parent);
            if (! $restarts && $expiresAt->gt($parent->expires_at)) {
                $expiresAt = $parent->expires_at->copy();
                $capSource = 'parent';
            }
        }

        $user = $request->user();
        $copies = (int) ($validated['copies'] ?? $product->default_copies ?: 1);
        $storage = $product->storageFor($action['key']);
        $supplierLot = isset($validated['supplier_lot']) && trim((string) $validated['supplier_lot']) !== ''
            ? trim((string) $validated['supplier_lot']) : null;

        $log = new LabelPrintLog([
            'user_id' => $user?->id,
            'kind' => LabelPrintLog::KIND_DLC,
            'dlc_product_id' => $product->id,
            'product_name' => $product->name,
            'action' => $action['key'],
            'shelf_life_value' => $action['value'],
            'shelf_life_unit' => $action['unit'],
            'opened_at' => $openedAt,
            'expires_at' => $expiresAt,
            'original_dlc' => $original,
            'capped' => $capSource !== null,
            'cap_source' => $capSource,
            'storage' => $storage,
            'parent_log_id' => $parent?->id,
            // « produit neuf » DÉCLARÉ pour une préparation : la personne a dit qu'il n'y avait pas d'origine étiquetée
            'declared_new' => $parent === null && ! empty($validated['parent_none']),
            // fait à partir d'un lot « à vérifier » (imprimé hors ligne) : sa DLC peut être fausse, celle-ci aussi
            'anomaly' => (bool) $parent?->anomaly,
            'message' => $parent?->anomaly
                ? '⚠ À vérifier : fait à partir d’un lot imprimé hors ligne à vérifier ('.($parent->lot ? 'N° '.$parent->lot : $parent->product_name).').'
                : null,
            'supplier_lot' => $supplierLot,
            'copies' => $copies,
            'status' => LabelPrintLog::STATUS_CLAIMED,
            'claimed_at' => $openedAt,
        ]);
        // La ligne de journal appartient à la branche du PRODUIT (un administrateur sans branche voit toutes les branches).
        $log->branch_id = (int) ($product->branch_id ?: $this->branchId($request));
        $log->save();
        // Numéro de l'étiquette : lien entre ce papier, le journal et les produits faits à partir de lui (traçabilité).
        $log->forceFill(['lot' => $this->lotNumber($openedAt, $log->id)])->save();

        return response()->json($this->presentDlcLabel($product, $log, $parent, $user) + ['attempt' => 1]);
    }

    /**
     * Étiquette imprimée RÉSEAU COUPÉ (l'étiquette prime : la cuisine ne s'arrête pas) : la tablette la journalise dès que
     * le réseau revient. Le papier EXISTE déjà, posé sur un bac : le serveur l'inscrit donc TOUJOURS au journal et dans la
     * surveillance — et s'il n'aurait pas imprimé la même chose (fiche modifiée entre-temps, événement retiré, DLC plus
     * longue que la fiche, heure de tablette incohérente, produit supprimé), il le signale « à vérifier » (`anomaly`) au
     * lieu de le rejeter : un refus ferait disparaître une étiquette réelle du journal, sans que personne le sache.
     * Rejouer l'envoi (même `client_ref`) ne crée jamais de doublon.
     */
    public function dlcOffline(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'client_ref' => ['required', 'string', 'min:8', 'max:64'],
            'product_id' => ['required', 'integer'],
            'product_name' => ['nullable', 'string', 'max:120'],
            'action' => ['required', 'string', Rule::in(DlcLabelProduct::ACTIONS)],
            'copies' => ['required', 'integer', 'min:1', 'max:20'],
            'opened_at' => ['required', 'date'],
            'expires_at' => ['required', 'date'],
            'storage' => ['nullable', 'string', 'max:60'],
            'parent_none' => ['nullable', 'boolean'],
            'status' => ['required', Rule::in([LabelPrintLog::STATUS_PRINTED, LabelPrintLog::STATUS_UNCONFIRMED, LabelPrintLog::STATUS_FAILED])],
            'message' => ['nullable', 'string', 'max:300'],
        ]);

        $existing = LabelPrintLog::query()->where('client_ref', $validated['client_ref'])->first();
        if ($existing) {
            return response()->json(['status' => 'ok', 'log_id' => $existing->id, 'lot' => $existing->lot, 'replayed' => true, 'anomaly' => (bool) $existing->anomaly]);
        }

        // La tablette envoie de l'ISO avec fuseau (souvent « Z ») : ramené au fuseau de l'application avant d'être écrit,
        // sinon la base recevrait l'heure UTC comme si c'était l'heure de Paris (deux heures d'écart).
        $openedAt = \Carbon\Carbon::parse($validated['opened_at'])->setTimezone(config('app.timezone'));
        $expiresAt = \Carbon\Carbon::parse($validated['expires_at'])->setTimezone(config('app.timezone'));
        $now = now();

        $anomalies = [];
        $product = DlcLabelProduct::withTrashed()->find($validated['product_id']);
        if (! $product) {
            $anomalies[] = 'produit introuvable';
        } elseif (! $product->hasAction($validated['action'])) {
            $anomalies[] = 'événement « '.DlcLabelProduct::ACTION_LABELS[$validated['action']].' » non prévu par la fiche';
        }
        if ($openedAt->lt($now->copy()->subDays(7)) || $openedAt->gt($now->copy()->addMinutes(10))) {
            $anomalies[] = 'heure de la tablette incohérente';
        }
        if ($validated['action'] === 'thawed' && ! empty($validated['storage'])
            && ! DlcLabelProduct::isValidThawedStorage((string) $validated['storage'])) {
            $anomalies[] = 'conservation après décongélation imprimée invalide';
        }
        if ($expiresAt->lte($openedAt)) {
            $anomalies[] = 'DLC antérieure à l’impression';
        } elseif ($product && $product->hasAction($validated['action'])
            && $expiresAt->gt($product->expiresFrom($openedAt, $product->action($validated['action']))->addMinutes(2))) {
            $anomalies[] = 'DLC imprimée plus longue que la fiche';
        }
        $hours = max(1, (int) round($openedAt->diffInMinutes($expiresAt, false) / 60));
        $action = $product && $product->hasAction($validated['action'])
            ? $product->action($validated['action'])
            : ['key' => $validated['action'], 'value' => min(720, $hours), 'unit' => DlcLabelProduct::UNIT_HOURS];
        $message = $anomalies
            ? '⚠ À vérifier (imprimée hors ligne) : '.implode(' ; ', $anomalies).'.'
            : ($validated['message'] ?? 'Imprimée hors ligne, journalisée au retour du réseau.');

        $log = new LabelPrintLog([
            'user_id' => $request->user()?->id,
            'kind' => LabelPrintLog::KIND_DLC,
            'dlc_product_id' => $product?->id,
            'product_name' => $product?->name ?? (trim((string) ($validated['product_name'] ?? '')) ?: 'Produit #'.$validated['product_id']),
            'action' => $action['key'],
            'shelf_life_value' => $action['value'],
            'shelf_life_unit' => $action['unit'],
            'opened_at' => $openedAt,
            'expires_at' => $expiresAt,
            // la conservation IMPRIMÉE (celle que la tablette a mise sur le papier), sinon celle de la fiche
            'storage' => $validated['storage'] ?? $product?->storageFor($action['key']),
            'copies' => (int) $validated['copies'],
            'offline' => true,
            'declared_new' => ! empty($validated['parent_none']),
            'anomaly' => $anomalies !== [],
            'client_ref' => $validated['client_ref'],
            'status' => $validated['status'],
            'message' => mb_substr($message, 0, 300),
            'printed_pages' => $validated['status'] === LabelPrintLog::STATUS_FAILED ? 0 : (int) $validated['copies'],
            'claimed_at' => $openedAt,
            'finished_at' => $now,
        ]);
        $log->branch_id = (int) ($product?->branch_id ?: $this->branchId($request));
        try {
            $log->save();
        } catch (QueryException $e) {
            // Deux envois simultanés du même `client_ref` : le second retrouve le premier.
            if (! $this->isUniqueViolation($e)) throw $e;
            $first = LabelPrintLog::query()->where('client_ref', $validated['client_ref'])->firstOrFail();

            return response()->json(['status' => 'ok', 'log_id' => $first->id, 'lot' => $first->lot, 'replayed' => true, 'anomaly' => (bool) $first->anomaly]);
        }
        $log->forceFill(['lot' => $this->lotNumber($openedAt, $log->id)])->save();

        return response()->json(['status' => 'ok', 'log_id' => $log->id, 'lot' => $log->lot, 'replayed' => false, 'anomaly' => $anomalies !== []]);
    }

    /** Ce que la tablette imprime : exactement ce que dit le journal. */
    private function presentDlcLabel(DlcLabelProduct $product, LabelPrintLog $log, ?LabelPrintLog $parent, $user): array
    {
        return [
            'log_id' => $log->id,
            'name' => $product->name,
            'category' => $product->category,
            'action' => $log->action,
            'storage' => $log->storage,
            'note' => $product->note,
            'allergens' => array_values((array) ($product->allergens ?? [])),
            'no_allergens' => (bool) $product->no_allergens,
            'shelf_life_value' => $log->shelf_life_value,
            'shelf_life_unit' => $log->shelf_life_unit,
            'opened_at' => $log->opened_at->toIso8601String(),
            'expires_at' => $log->expires_at->toIso8601String(),
            'original_dlc' => $log->original_dlc?->format('Y-m-d'),
            'supplier_lot' => $log->supplier_lot,
            'capped' => (bool) $log->capped,
            'cap_source' => $log->cap_source,
            'parent' => $parent ? ['lot' => $parent->lot, 'name' => $parent->product_name] : null,
            'lot' => $log->lot,
            'copies' => $log->copies,
            'by' => $product->print_operator ? $this->firstName($user?->name) : null,
        ];
    }

    /** Un lot de la chaîne d'origine (le parent, son parent…) a-t-il été CUIT ? (25 niveaux au plus, sans boucle) */
    private function chainWasCooked(LabelPrintLog $parent): bool
    {
        $seen = [];
        $current = $parent;
        for ($depth = 0; $current && $depth < 25 && ! isset($seen[$current->id]); $depth++) {
            if ($current->action === 'cooked') return true;
            $seen[$current->id] = true;
            $current = $current->parent_log_id ? LabelPrintLog::query()->find($current->parent_log_id) : null;
        }

        return false;
    }

    private function lotNumber(\Carbon\CarbonInterface $at, int $id): string
    {
        return 'L'.$at->format('ymd').'-'.str_pad((string) $id, 4, '0', STR_PAD_LEFT);
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
                // la raison d'un « à vérifier » posée à la réservation n'est jamais effacée par le verdict de l'imprimante
                'message' => $model->anomaly && $model->message
                    ? mb_substr($model->message.(isset($validated['message']) ? ' · '.$validated['message'] : ''), 0, 300)
                    : ($validated['message'] ?? null),
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

    /**
     * Sort un lot DLC de la surveillance : « jeté » (échu, à la poubelle), « utilisé » (bac terminé) ou « annulée »
     * (étiquette imprimée par erreur). La ligne reste dans le journal (traçabilité) : seuls la date, la raison et la
     * personne sont posées. Idempotent ; ne concerne que les étiquettes d'hygiène.
     */
    public function discard(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'ids' => ['required', 'array', 'min:1', 'max:50'],
            'ids.*' => ['integer', 'min:1'],
            'reason' => ['nullable', Rule::in(LabelPrintLog::DISCARD_REASONS)],
            // Annuler un « Jeté » posé par erreur : remet l'alerte.
            'restore' => ['nullable', 'boolean'],
        ]);

        $restore = (bool) ($validated['restore'] ?? false);
        $base = LabelPrintLog::query()->where('kind', LabelPrintLog::KIND_DLC)->whereIn('id', $validated['ids']);
        if ($restore) {
            // L'annulation ne s'efface pas : qui l'a faite, quand, et ce qui avait été posé restent écrits au journal.
            $by = $this->firstName($request->user()?->name) ?? '—';
            $rows = (clone $base)->whereNotNull('discarded_at')->with('discardedBy:id,name')->get();
            foreach ($rows as $row) {
                $was = ['discarded' => 'jeté', 'used' => 'utilisé', 'cancelled' => 'annulée'][$row->discard_reason ?: 'discarded'] ?? 'jeté';
                $who = $this->firstName($row->discardedBy?->name);
                $trace = '« '.$was.' » du '.$row->discarded_at->format('d/m H:i').($who ? ' par '.$who : '')
                    .' annulé le '.now()->format('d/m H:i').' par '.$by;
                // la trace est gardée entière : c'est l'ancien message qui est raccourci s'il faut de la place
                $room = 300 - mb_strlen($trace) - 3;
                $old = (string) $row->message;
                $kept = $old === '' ? '' : (mb_strlen($old) > $room ? mb_substr($old, 0, max(0, $room - 1)).'…' : $old).' · ';
                $row->forceFill([
                    'discarded_at' => null, 'discarded_by' => null, 'discard_reason' => null,
                    'message' => $kept.$trace,
                ])->save();
            }
            $count = $rows->count();
        } else {
            $count = (clone $base)->whereNull('discarded_at')->update([
                'discarded_at' => now(),
                'discarded_by' => $request->user()?->id,
                'discard_reason' => $validated['reason'] ?? LabelPrintLog::DISCARD_DISCARDED,
                'updated_at' => now(),
            ]);
        }

        return response()->json(['status' => 'ok', 'discarded' => $restore ? 0 : $count, 'restored' => $restore ? $count : 0]);
    }

    /**
     * Les lots d'hygiène à SURVEILLER, tirés du journal entier (pas des 50 dernières lignes) : imprimés, ni jetés ni
     * utilisés, à échoir dans les 72 h ou échus depuis moins de 30 jours, et les étiquettes « à vérifier » quelle que soit
     * leur DLC. Un lot échu reste signalé tant que personne ne l'a sorti — il ne disparaît pas tout seul au bout de 24 h.
     */
    public function watch(): JsonResponse
    {
        $now = now();
        $rows = LabelPrintLog::query()
            ->with('user:id,name')
            ->where('kind', LabelPrintLog::KIND_DLC)
            // une étiquette réservée dont le verdict n'est jamais arrivé (réseau coupé PENDANT l'impression) a peut-être
            // été imprimée : passé 10 minutes, elle est surveillée comme « non confirmée » — jamais perdue en silence
            ->where(fn ($st) => $st->whereIn('status', LabelPrintLog::DONE_STATUSES)->orWhere(fn ($c) => $c
                ->where('status', LabelPrintLog::STATUS_CLAIMED)
                ->where('claimed_at', '<', $now->copy()->subMinutes(10))))
            ->whereNull('discarded_at')
            ->whereNotNull('expires_at')
            ->where(fn ($q) => $q
                ->whereBetween('expires_at', [
                    $now->copy()->subDays(LabelPrintLog::WATCH_BEHIND_DAYS),
                    $now->copy()->addHours(LabelPrintLog::WATCH_AHEAD_HOURS),
                ])
                // une étiquette imprimée hors ligne « à vérifier » est signalée TOUT DE SUITE, quelle que soit sa DLC
                // imprimée (elle peut être plus longue que la vraie) — jamais seulement à son échéance
                ->orWhere(fn ($a) => $a->where('anomaly', true)->where('created_at', '>=', $now->copy()->subDays(LabelPrintLog::WATCH_BEHIND_DAYS))))
            ->orderBy('expires_at')
            ->limit(300)
            ->get()
            ->map(fn (LabelPrintLog $l): array => $this->presentLog($l))
            ->values();

        return response()->json(['data' => $rows, 'meta' => $this->clock()]);
    }

    /**
     * Les lots EN COURS (origines possibles d'une préparation) : imprimés, encore bons, pas jetés ni annulés — ceux
     * marqués « utilisés » il y a moins de 2 h comptent encore (on vide le bac, puis on étiquette ce qu'on en a fait).
     * Tirés du journal entier, quelle que soit leur échéance (pas seulement les 72 h de la surveillance).
     */
    public function lots(): JsonResponse
    {
        $now = now();
        $rows = LabelPrintLog::query()
            ->with('user:id,name')
            ->where('kind', LabelPrintLog::KIND_DLC)
            ->whereIn('status', LabelPrintLog::DONE_STATUSES)
            ->where('expires_at', '>', $now)
            ->where(fn ($q) => $q->whereNull('discarded_at')->orWhere(fn ($used) => $used
                ->where('discard_reason', LabelPrintLog::DISCARD_USED)
                ->where('discarded_at', '>=', $now->copy()->subHours(LabelPrintLog::PARENT_USED_GRACE_HOURS))))
            ->orderBy('expires_at')
            ->limit(300)
            ->get();

        // « déjà cuit plus haut dans la chaîne ? » pour chaque lot (même règle que dlcPrint). On ne charge QUE les ancêtres
        // des lots affichés, niveau par niveau (≤ 25 requêtes, colonnes nues) — jamais tout le journal en mémoire.
        $graph = [];
        $frontier = $rows->pluck('parent_log_id')->filter()->unique()->values()->all();
        for ($depth = 0; $frontier !== [] && $depth < 25; $depth++) {
            $found = LabelPrintLog::query()->where('kind', LabelPrintLog::KIND_DLC)->whereIn('id', $frontier)
                ->toBase()->get(['id', 'action', 'parent_log_id']);
            $frontier = [];
            foreach ($found as $f) {
                $graph[$f->id] = $f;
                if ($f->parent_log_id && ! isset($graph[$f->parent_log_id])) $frontier[] = $f->parent_log_id;
            }
            $frontier = array_values(array_unique($frontier));
        }
        $chainCooked = function (LabelPrintLog $l) use ($graph): bool {
            if ($l->action === 'cooked') return true;
            $seen = [];
            for ($id = $l->parent_log_id, $d = 0; $id && $d < 25 && ! isset($seen[$id]) && isset($graph[$id]); $d++) {
                if ($graph[$id]->action === 'cooked') return true;
                $seen[$id] = true;
                $id = $graph[$id]->parent_log_id;
            }

            return false;
        };

        $data = $rows->map(fn (LabelPrintLog $l): array => $this->presentLog($l) + ['chain_cooked' => $chainCooked($l)])->values();

        return response()->json(['data' => $data, 'meta' => $this->clock()]);
    }

    /** Dernières impressions (traçabilité d'hygiène). */
    public function history(Request $request): JsonResponse
    {
        $kind = $request->query('kind') === LabelPrintLog::KIND_ORDER ? LabelPrintLog::KIND_ORDER : LabelPrintLog::KIND_DLC;

        $rows = LabelPrintLog::query()
            ->with(['user:id,name', 'discardedBy:id,name'])
            ->where('kind', $kind)
            ->orderByDesc('id')
            ->limit(50)
            ->get()
            ->map(fn (LabelPrintLog $l): array => $this->presentLog($l))
            ->values();

        return response()->json(['data' => $rows]);
    }

    private function presentLog(LabelPrintLog $l): array
    {
        return [
            'id' => $l->id,
            'dlc_product_id' => $l->dlc_product_id,
            'product_name' => $l->product_name,
            'action' => $l->action,
            'lot' => $l->lot,
            'capped' => (bool) $l->capped,
            'cap_source' => $l->cap_source,
            'storage' => $l->storage,
            'original_dlc' => $l->original_dlc?->format('Y-m-d'),
            'supplier_lot' => $l->supplier_lot,
            'parent_log_id' => $l->parent_log_id,
            'declared_new' => (bool) $l->declared_new,
            'offline' => (bool) $l->offline,
            'anomaly' => (bool) $l->anomaly,
            'client_ref' => $l->client_ref,
            'order_id' => $l->order_id,
            'copies' => $l->copies,
            'labels_count' => $l->labels_count,
            'opened_at' => $l->opened_at?->toIso8601String(),
            'expires_at' => $l->expires_at?->toIso8601String(),
            'status' => $l->status,
            'message' => $l->message,
            'discarded_at' => $l->discarded_at?->toIso8601String(),
            'discard_reason' => $l->discarded_at ? ($l->discard_reason ?: LabelPrintLog::DISCARD_DISCARDED) : null,
            'discarded_by' => $l->discarded_at && $l->relationLoaded('discardedBy') ? $this->firstName($l->discardedBy?->name) : null,
            'by' => $this->firstName($l->user?->name),
            'created_at' => $l->created_at?->toIso8601String(),
        ];
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
        $hasActions = is_array($request->input('actions')) && $request->input('actions') !== [];

        $data = $request->validate([
            'name' => ['required', 'string', 'min:1', 'max:120'],
            'category' => ['nullable', 'string', 'max:60'],
            // une modification partielle (masquer / réactiver) n'a pas à renvoyer la durée
            'shelf_life_value' => [$hasActions || $ignoreId ? 'nullable' : 'required', 'integer', 'min:1', 'max:'.$max],
            'shelf_life_unit' => [$hasActions || $ignoreId ? 'nullable' : 'required', Rule::in(DlcLabelProduct::UNITS)],
            'actions' => ['nullable', 'array', 'min:1', 'max:5'],
            'actions.*.key' => ['required', 'distinct', Rule::in(DlcLabelProduct::ACTIONS)],
            'actions.*.value' => ['required', 'integer', 'min:1', 'max:720'],
            'actions.*.unit' => ['required', Rule::in(DlcLabelProduct::UNITS)],
            'allergens' => ['nullable', 'array', 'max:14'],
            'allergens.*' => ['distinct', Rule::in(DlcLabelProduct::ALLERGENS)],
            // La température de conservation figure sur toute étiquette d'hygiène (GBPH § 2.7 : « À conserver à … °C ») :
            // obligatoire à la création, et jamais effacée ensuite (absente d'une modification = inchangée).
            'storage' => [$ignoreId ? 'sometimes' : 'required', 'filled', 'string', 'max:60'],
            // Conservation APRÈS décongélation (viande hachée +2 °C, poisson 0/+2 °C, pain ambiante…).
            'thawed_storage' => ['nullable', 'string', 'max:60'],
            'no_allergens' => ['nullable', 'boolean'],
            'needs_parent' => ['nullable', 'boolean'],
            // « Durées vérifiées » coché pour une durée inhabituelle : qui et quand sont gardés sur la fiche
            'durations_checked' => ['nullable', 'boolean'],
            'note' => ['nullable', 'string', 'max:80'],
            'ask_original_dlc' => ['nullable', 'boolean'],
            'print_operator' => ['nullable', 'boolean'],
            'default_copies' => ['nullable', 'integer', 'min:1', 'max:20'],
            'sort_order' => ['nullable', 'integer', 'min:0', 'max:100000'],
            'is_active' => ['nullable', 'boolean'],
        ], [
            'shelf_life_value.max' => 'La durée ne peut pas dépasser '.$max.($unit === DlcLabelProduct::UNIT_HOURS ? ' heures (30 jours).' : ' jours.'),
            'actions.*.key.distinct' => 'Chaque événement (ouverture, décongélation…) ne peut figurer qu’une fois.',
            'storage.required' => 'Indiquez la température de conservation (elle est imprimée sur l’étiquette).',
            'storage.filled' => 'Indiquez la température de conservation (elle est imprimée sur l’étiquette).',
        ], [
            'name' => 'nom',
            'category' => 'famille',
            'shelf_life_value' => 'durée',
            'shelf_life_unit' => 'unité',
            'storage' => 'conservation',
            'note' => 'mention',
        ]);

        // Durée par événement : 720 heures (30 jours) ou 365 jours au plus.
        foreach ((array) ($data['actions'] ?? []) as $i => $a) {
            $cap = $a['unit'] === DlcLabelProduct::UNIT_HOURS ? 720 : 365;
            if ((int) $a['value'] > $cap) {
                abort(response()->json([
                    'message' => 'Durée trop longue.',
                    'errors' => ["actions.$i.value" => ['La durée ne peut pas dépasser '.$cap.($a['unit'] === DlcLabelProduct::UNIT_HOURS ? ' heures (30 jours).' : ' jours.')]],
                ], 422));
            }
        }

        $data['name'] = trim((string) $data['name']);
        foreach (['storage', 'thawed_storage', 'note', 'category'] as $field) {
            if (array_key_exists($field, $data)) {
                $data[$field] = isset($data[$field]) && trim((string) $data[$field]) !== '' ? trim((string) $data[$field]) : null;
            }
        }

        // La durée « historique » suit le premier événement : les écrans et journaux plus anciens restent justes.
        if (! empty($data['actions'])) {
            $data['actions'] = array_values(array_map(fn ($a) => [
                'key' => $a['key'], 'value' => (int) $a['value'], 'unit' => $a['unit'],
            ], $data['actions']));
            $data['shelf_life_value'] = $data['actions'][0]['value'];
            $data['shelf_life_unit'] = $data['actions'][0]['unit'];
        } else {
            unset($data['actions']);
        }
        if (array_key_exists('allergens', $data)) {
            $data['allergens'] = array_values(array_unique((array) ($data['allergens'] ?? [])));
            // Des allergènes cochés l'emportent sur « aucun allergène » : jamais les deux à la fois.
            if ($data['allergens'] !== []) $data['no_allergens'] = false;
        }

        // Décongélation prévue sur un produit qui n'est pas conservé au froid positif (surgelé, ambiante…) : sa
        // conservation APRÈS décongélation doit être dite — jamais une valeur unique imposée à tous. Vérifié quand la
        // fiche est saisie (création, ou modification qui touche la conservation ou les événements), pas quand on la
        // masque seulement.
        $model = $ignoreId ? DlcLabelProduct::query()->find($ignoreId) : null;
        if (array_key_exists('thawed_storage', $data) && $data['thawed_storage'] !== null
            && ! DlcLabelProduct::isValidThawedStorage((string) $data['thawed_storage'])) {
            abort(response()->json([
                'message' => 'Conservation après décongélation invalide.',
                'errors' => ['thawed_storage' => ['Après décongélation : du froid positif (ex. 0 à +2 °C, 0 à +4 °C) ou « Ambiante » — jamais le congélateur.']],
            ], 422));
        }
        // La fiche prévoit la décongélation : sa conservation APRÈS décongélation doit être dite. Vérifié à chaque saisie
        // qui touche les événements ou la conservation (masquer / réactiver seulement ne redemande rien).
        if (! $ignoreId || array_key_exists('storage', $data) || array_key_exists('thawed_storage', $data) || array_key_exists('actions', $data)) {
            $effectiveStorage = array_key_exists('storage', $data) ? $data['storage'] : $model?->storage;
            $effectiveActions = $data['actions'] ?? ($model ? $model->actionList() : []);
            $effectiveThawed = array_key_exists('thawed_storage', $data) ? $data['thawed_storage'] : $model?->thawed_storage;
            if (DlcLabelProduct::needsThawedStorage($effectiveStorage, $effectiveActions) && ($effectiveThawed === null || $effectiveThawed === '')) {
                abort(response()->json([
                    'message' => 'Indiquez la conservation après décongélation.',
                    'errors' => ['thawed_storage' => ['Indiquez la conservation après décongélation (ex. 0 à +2 °C pour une viande hachée, 0 à +4 °C, ambiante pour un pain).']],
                ], 422));
            }
        }
        if (! empty($data['durations_checked'])) {
            $data['durations_checked_at'] = now();
            $data['durations_checked_by'] = $request->user()?->id;
        }
        unset($data['durations_checked']);

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

        foreach (['is_active', 'sort_order', 'ask_original_dlc', 'print_operator', 'default_copies', 'shelf_life_value', 'shelf_life_unit', 'needs_parent', 'no_allergens'] as $optional) {
            if (! array_key_exists($optional, $data) || $data[$optional] === null) {
                unset($data[$optional]);
            }
        }

        return $data;
    }

    private function presentProduct(DlcLabelProduct $p): array
    {
        return [
            'id' => $p->id,
            'name' => $p->name,
            'category' => $p->category,
            'shelf_life_value' => $p->shelf_life_value,
            'shelf_life_unit' => $p->shelf_life_unit,
            'actions' => $p->actionList(),
            'allergens' => array_values((array) ($p->allergens ?? [])),
            'storage' => $p->storage,
            'thawed_storage' => $p->thawed_storage,
            'no_allergens' => (bool) $p->no_allergens,
            'needs_parent' => (bool) $p->needs_parent,
            'durations_checked_at' => $p->durations_checked_at?->toIso8601String(),
            'note' => $p->note,
            'ask_original_dlc' => (bool) $p->ask_original_dlc,
            'print_operator' => $p->print_operator === null ? true : (bool) $p->print_operator,
            'default_copies' => (int) ($p->default_copies ?: 1),
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
