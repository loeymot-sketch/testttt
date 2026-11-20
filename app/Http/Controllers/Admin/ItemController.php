<?php

namespace App\Http\Controllers\Admin;

use App\Exports\ItemExport;
use App\Http\Requests\ItemImportRequest;
use App\Http\Resources\NormalItemResource;
use App\Http\Resources\SimpleItemResource;
use App\Imports\ItemImport;
use Exception;
use App\Models\Item;
use App\Services\Catalog\CatalogWarningService;
use App\Services\ItemService;
use App\Http\Requests\ItemRequest;
use App\Http\Resources\ItemResource;
use Illuminate\Support\Facades\Log;
use Maatwebsite\Excel\Facades\Excel;
use App\Http\Requests\PaginateRequest;
use App\Http\Requests\ChangeImageRequest;
use Illuminate\Support\Facades\DB;
use Response;

class ItemController extends AdminController
{
    public ItemService $itemService;

    public function __construct(ItemService $itemService)
    {
        parent::__construct();
        $this->itemService = $itemService;
        $this->middleware(['permission:items'])->only('export');
        $this->middleware(['permission:items_create'])->only('store', 'import', 'duplicate');
        $this->middleware(['permission:items_edit'])->only('update', 'changeImage');
        $this->middleware(['permission:items_delete'])->only('destroy');
        $this->middleware(['permission:items_show'])->only('show', 'downloadSample');
        $this->middleware(function ($request, $next) {
            $user = $request->user();
            // [GOAL RUPTURE-CARNET 2026-07-15 / W6 heal P1] `availability_toggle`
            // ajouté : le Chef (KDS) doit pouvoir LISTER les items pour le panel
            // rupture (86) — il portait le droit de toggle mais pas celui de
            // charger la liste → panel mort (403) pour son persona cible.
            abort_unless($user && $user->canAny(['items_show', 'pos', 'availability_toggle']), 403);

            return $next($request);
        })->only('index', 'itemDetails', 'lookupBarcode');
    }

    public function index(PaginateRequest $request) : \Illuminate\Http\Response | \Illuminate\Http\Resources\Json\AnonymousResourceCollection | \Illuminate\Contracts\Foundation\Application | \Illuminate\Contracts\Routing\ResponseFactory
    {
        $forcedBranchId = $this->forcePosRuntimeBranchScope($request);
        $branchId = $forcedBranchId ?? ($request->filled('branch_id') ? (int) $request->get('branch_id') : null);

        if ($branchId !== null) {
            $this->authorizeBranchScope($request, $branchId);
        }

        // [CV1-POS-AVAILABILITY-LIVE-001] surface=pos sans branch_id ⇒ overlay
        // availability inopérant ; refuser la requête plutôt que projeter is_available
        // global (false-positive cliquabilité tile = perte argent + 422 au checkout).
        // Restreint aux callers ayant la perm `pos` pour éviter de casser tooling admin
        // (ex : éditeur catalogue filtré "POS-visible") qui appellerait surface=pos sans branch.
        // Cf docs/audit/CV1-POS-AVAILABILITY-LIVE-001_INVESTIGATION_2026-05-08.md §5.3.
        $surface = strtolower(trim((string) $request->get('surface', '')));
        if ($surface === 'pos'
            && ($branchId === null || $branchId < 1)
            && $request->user() && $request->user()->can('pos')) {
            return response(['status' => false, 'message' => 'POS catalog requires branch_id'], 422);
        }

        // CV1 catalog convergence (audit CLAUDE_ULTRA_REVIEW_REQUEST_MISSION_1 §A.1 #3):
        // POS-only runtime callers (permission `pos` without catalog `items_show`) must get
        // `?surface=pos` semantics by default so `/api/admin/item` never leaks kiosk-only SKUs.
        // Same heuristic as forcePosRuntimeBranchScope(). Client-provided surface wins.
        $this->applyDefaultPosSurfaceForPosRuntimeUser($request);

        // [MISSION FIX D4 2026-05-21] Admin catalog (no explicit branch_id) must
        // honour per-branch ItemBranchAvailability so `/admin/items` and
        // `/admin/stock-rupture-dashboard` show identical state. Without this,
        // simpleList's applyBranchAvailabilityOverlay() short-circuits (branchId<1)
        // and the listing reports global is_available only — admin sees "Actif"
        // while the dashboard correctly flags "RUPTURE". Mirror the
        // StockRuptureDashboardController::scopedBranches() admin fallback (first
        // active branch). Staff users already have $user->branch_id>0 piped
        // through forcePosRuntimeBranchScope or future explicit selection.
        if ($branchId === null) {
            $user = $request->user();
            if ($user && (int) $user->branch_id === 0
                && ($user->hasRole('Admin') || $user->hasRole('Tenant Admin'))) {
                $defaultBranchId = (int) \App\Models\Branch::query()
                    ->whereNull('deleted_at')
                    ->whereIn('status', [\App\Enums\Status::ACTIVE, 1])
                    ->orderBy('id')
                    ->value('id');
                if ($defaultBranchId > 0) {
                    $branchId = $defaultBranchId;
                    $request->merge(['branch_id' => $branchId]);
                    $request->query->set('branch_id', (string) $branchId);
                }
            }
        }

        try {
            // [ONB-11 2026-08-28] `false` = back-office : le commerçant voit AUSSI ses
            // articles désactivés. Sans ce paramètre, il ne pouvait plus jamais en
            // réactiver un — l'écran offrait un filtre « Inactif » qui ne rendait rien.
            $paginator = $this->itemService->simpleList($request, false);
            // [ONB-11 2026-08-28] La requête est transmise : les tuiles comptent
            // désormais la SÉLECTION affichée, pas toute la carte. Sans elle, filtrer
            // sur « Burgers » donnait « 5 Produits » à côté de « 57 Actifs ».
            $meta = $this->itemService->availabilityCounts($branchId, $request);

            return SimpleItemResource::collection($paginator)->additional([
                'meta' => $meta,
            ]);
        } catch (Exception $exception) {
            return response(['status' => false, 'message' => $exception->getMessage()], 422);
        }
    }


    public function show(Item $item) : \Illuminate\Http\Response | ItemResource | \Illuminate\Contracts\Foundation\Application | \Illuminate\Contracts\Routing\ResponseFactory
    {
        if (request()->filled('branch_id')) {
            $this->authorizeBranchScope(request(), (int) request()->get('branch_id'));
        }

        try {
            $loaded   = $this->itemService->show($item);
            $resource = new ItemResource($loaded);

            if (config('catalog_v15.warnings.expose_to_admin_show', true)) {
                $branchId = request()->filled('branch_id') ? (int) request()->get('branch_id') : null;
                $warnings = app(CatalogWarningService::class)->forItem($loaded, $branchId);

                return $resource->additional(['warnings' => $warnings]);
            }

            return $resource;
        } catch (Exception $exception) {
            return response(['status' => false, 'message' => $exception->getMessage()], 422);
        }
    }

    public function store(ItemRequest $request) : \Illuminate\Http\Response | ItemResource | \Illuminate\Contracts\Foundation\Application | \Illuminate\Contracts\Routing\ResponseFactory
    {
        try {
            // [ULTRA-AUDIT 2026-07-02] Retrait du if(env('DEMO')){...}else{...} : les deux branches
            // étaient IDENTIQUES (code mort) et env() hors config renvoie null après config:cache.
            return new ItemResource($this->itemService->store($request));
        } catch (Exception $exception) {
            return response(['status' => false, 'message' => $exception->getMessage()], 422);
        }
    }

    public function update(ItemRequest $request, Item $item) : \Illuminate\Http\Response | ItemResource | \Illuminate\Contracts\Foundation\Application | \Illuminate\Contracts\Routing\ResponseFactory
    {
        try {
            return new ItemResource($this->itemService->update($request, $item));
        } catch (Exception $exception) {
            return response(['status' => false, 'message' => $exception->getMessage()], 422);
        }
    }

    public function duplicate(Item $item) : \Illuminate\Http\Response | ItemResource | \Illuminate\Contracts\Foundation\Application | \Illuminate\Contracts\Routing\ResponseFactory
    {
        try {
            $copy = $this->itemService->duplicate($item);
            return new ItemResource($copy);
        } catch (Exception $exception) {
            return response(['status' => false, 'message' => $exception->getMessage()], 422);
        }
    }

    public function destroy(Item $item) : \Illuminate\Http\Response | \Illuminate\Contracts\Foundation\Application | \Illuminate\Contracts\Routing\ResponseFactory | \Illuminate\Http\JsonResponse
    {
        $forceDelete = request()->boolean('force');

        try {
            $this->itemService->destroy($item, $forceDelete);
            return response('', 202);
        } catch (Exception $exception) {
            if ((int) $exception->getCode() === 409) {
                return response()->json([
                    'status' => false,
                    'message' => $exception->getMessage(),
                    'error' => 'errors.item.cannot_force_delete_with_history',
                ], 409);
            }

            return response(['status' => false, 'message' => $exception->getMessage()], 422);
        }
    }

    public function changeImage(ChangeImageRequest $request, Item $item) : \Illuminate\Http\Response | ItemResource | \Illuminate\Contracts\Foundation\Application | \Illuminate\Contracts\Routing\ResponseFactory
    {
        $user = $request->user();
        abort_if(! $user || (! $user->hasRole('Admin') && ! $user->hasRole('Tenant Admin')), 403);

        try {
            return new ItemResource($this->itemService->changeImage($request, $item));
        } catch (Exception $exception) {
            return response(['status' => false, 'message' => $exception->getMessage()], 422);
        }
    }

    public function export(PaginateRequest $request) : \Illuminate\Http\Response | \Symfony\Component\HttpFoundation\BinaryFileResponse | \Illuminate\Contracts\Foundation\Application | \Illuminate\Contracts\Routing\ResponseFactory
    {
        try {
            return Excel::download(new ItemExport($this->itemService, $request), 'Item.xlsx');
        } catch (Exception $exception) {
            return response(['status' => false, 'message' => $exception->getMessage()], 422);
        }
    }

    public function downloadSample()
    {
        try {
            return Response::download(public_path('/file/itemImportSample.xlsx'));
        } catch (Exception $exception) {
            return response(['status' => false, 'message' => $exception->getMessage()], 422);
        }
    }

    /**
     * [ONB-02 2026-08-28] L'import annoncait un succes sans jamais dire ce qu'il
     * avait fait.
     *
     * L'instance d'import etait construite EN LIGNE dans l'appel a `Excel::import()`,
     * donc jetee aussitot : les echecs collectes par `SkipsFailures` n'etaient jamais
     * lus. Et une ligne dont la categorie n'existait pas etait sautee en silence par
     * Maatwebsite. Le controleur repondait `202` vide dans tous les cas.
     *
     * Consequence pour le commercant : il deposait son fichier de 45 lignes, l'ecran
     * fermait la fenetre et affichait une bulle verte, et il pouvait avoir 0, 12 ou 45
     * produits crees — sans aucun moyen de savoir lequel, ni quelle ligne corriger.
     * C'est exactement la soiree que cette mission promet de lui sauver.
     *
     * On garde l'instance, on lit ses echecs, et on rend un compte rendu.
     */
    /** Une phrase que le commercant peut lire, plutot que trois listes a recouper. */
    private function resumeImport(int $creees, int $echecs, int $dejaPresents = 0): string
    {
        if ($creees === 0 && $echecs === 0 && $dejaPresents === 0) {
            return "Votre fichier ne contenait aucune ligne exploitable.";
        }

        $phrase = $creees > 0
            ? $creees . ' produit' . ($creees > 1 ? 's ajoutes.' : ' ajoute.')
            : 'Aucun produit ajoute.';

        /*
         * [ONB 2026-08-28] Dire « deja dans votre carte » AVANT « a corriger ».
         * Sans cette phrase, redeposer un fichier corrige affichait « Aucun produit
         * ajoute. 40 lignes a corriger », ce qui donne exactement l'impression
         * inverse de ce qui s'est passe.
         */
        if ($dejaPresents > 0) {
            $phrase .= ' ' . $dejaPresents . ' ligne' . ($dejaPresents > 1 ? 's' : '')
                . ' deja dans votre carte, ' . ($dejaPresents > 1 ? 'ignorees' : 'ignoree')
                . ' sans modification.';
        }

        if ($echecs > 0) {
            $phrase .= ' ' . $echecs . ' ligne' . ($echecs > 1 ? 's' : '')
                . ' a corriger : voir le detail ci-dessous.';
        }

        return $phrase;
    }

    public function import(ItemImportRequest $request)
    {
        try {
            $import = new ItemImport($request->file('file'));
            Excel::import($import, $request->file('file'));

            /*
             * [ONB 2026-08-28] Une ligne DEJA PRESENTE n'est pas une ligne fautive.
             *
             * Le parcours normal de correction consiste a reparer quelques lignes
             * dans SON fichier puis a redeposer le fichier entier. Toutes les lignes
             * deja creees ressortent alors de `Rule::unique`, et etaient presentees
             * comme 40 erreurs — la ou elles prouvent justement que le premier depot
             * a fonctionne.
             *
             * On les separe. Ce que l'import accepte ou refuse est INCHANGE : seule
             * la restitution l'est.
             */
            $brut = collect($import->failures());

            $lignesDeja = $brut
                ->filter(fn ($echec) => str_contains(
                    implode(' ', $echec->errors()),
                    ItemImport::MARQUEUR_DEJA_PRESENT
                ))
                ->map(fn ($echec) => $echec->row())
                ->unique();

            $enPlace = $brut
                ->filter(fn ($echec) => $lignesDeja->contains($echec->row()))
                ->groupBy(fn ($echec) => $echec->row())
                ->map(fn ($groupe, $ligne) => [
                    'ligne'  => (int) $ligne,
                    'raison' => trim(str_replace(
                        ItemImport::MARQUEUR_DEJA_PRESENT,
                        '',
                        implode(' ', $groupe->first()->errors())
                    )),
                ])
                ->values()
                ->all();

            $echecs = $brut
                ->reject(fn ($echec) => $lignesDeja->contains($echec->row()))
                ->map(fn ($echec) => [
                    'ligne'   => $echec->row(),
                    'colonne' => $echec->attribute(),
                    'raison'  => implode(' ', $echec->errors()),
                ])
                ->values()
                ->all();

            return response()->json([
                'status'        => true,
                'creees'        => $import->creees,
                'deja_presents' => $enPlace,
                'echecs'        => $echecs,
                'message'       => $this->resumeImport(
                    $import->creees,
                    count($echecs),
                    count($enPlace)
                ),
            ], 202);
        } catch (Exception $exception) {
            return response(['status' => false, 'message' => $exception->getMessage()], 422);
        }
    }

    /**
     * [ONB 2026-08-28] Les 14 allergenes de l'Annexe II du Reglement UE 1169/2011.
     *
     * Lus depuis la table plutot que codes en dur : `AllergensSeeder` fait deja
     * autorite, et `ItemRequest` valide `allergen_flags.*` contre ces memes codes
     * (`Rule::in(Allergen::pluck('code'))`). Une liste ecrite a la main ici aurait
     * pu proposer un code que la validation refuse.
     */
    public function allergens(): \Illuminate\Http\JsonResponse
    {
        $allergenes = \App\Models\Allergen::query()
            ->orderBy('sort')
            ->get(['code', 'name_key', 'icon'])
            ->map(fn ($a) => [
                'code' => (string) $a->code,
                'icon' => (string) ($a->icon ?? ''),
                /*
                 * [ONB 2026-08-28] On renvoie la CLE, pas une traduction.
                 *
                 * Premiere version : `trans($a->name_key)`. Elle renvoyait
                 * « allergens.gluten » tel quel — le sous-arbre `allergens.*` n'existe
                 * que dans `resources/js/languages/*.json`, cote NAVIGATEUR, pas dans
                 * les fichiers de langue PHP. L'ecran aurait affiche la cle brute.
                 *
                 * Les traductions vivent deja la ou l'ecran les lit : on lui laisse
                 * les resoudre avec son propre `$t`.
                 */
                'cle'  => (string) $a->name_key,
            ])
            ->values();

        return response()->json(['data' => $allergenes]);
    }

    public function itemDetails(Item $item)
    {
        $forcedBranchId = $this->forcePosRuntimeBranchScope(request());
        $branchId = $forcedBranchId ?? (request()->filled('branch_id') ? (int) request()->get('branch_id') : null);

        if ($branchId !== null) {
            $this->authorizeBranchScope(request(), $branchId);
        }

        $surface = strtolower(trim((string) request()->get('surface', '')));
        if (in_array($surface, ['pos', 'kiosk', 'web'], true)) {
            $item->loadMissing('category');
            abort_unless($item->isVisibleOn($surface), 404);
            abort_unless(! $item->category || $item->category->isVisibleOn($surface), 404);
        }

        try {
           // [F-DETAILS-BRANCH-AVAIL 2026-07-15] $branchId déjà résolu ci-dessus (surface/forced) :
           // le passer pour que les détails POS reflètent la rupture par branche comme la liste.
           return new NormalItemResource($this->itemService->itemDetails($item, $branchId));
        } catch (Exception $exception) {
            return response(['status' => false, 'message' => $exception->getMessage()], 422);
        }
    }

    /**
     * POS: resolve an item by exact barcode (available + visible on pos channel).
     */
    public function lookupBarcode(string $code)
    {
        try {
            $code = rawurldecode($code);
            $base = Item::query()
                ->with('media', 'category', 'offer')
                ->where('barcode', $code)
                ->where('is_available', true)
                ->where(function ($q) {
                    $q->whereNull('channels');
                    if (DB::connection()->getDriverName() === 'sqlite') {
                        $q->orWhere('channels', 'like', '%"pos"%');
                        return;
                    }
                    $q->orWhereJsonContains('channels', 'pos');
                });
            $count = (clone $base)->count();
            $item = (clone $base)->orderBy('id')->first();
            if (! $item) {
                return response()->json(['error' => 'not_found'], 404);
            }
            if ($count > 1) {
                Log::warning('POS barcode lookup: multiple available items share barcode', [
                    'barcode' => $code,
                    'count' => $count,
                ]);
            }

            return (new SimpleItemResource($item))->additional([
                'meta' => [
                    'duplicate_barcode' => $count > 1,
                ],
            ]);
        } catch (Exception $exception) {
            return response(['status' => false, 'message' => $exception->getMessage()], 422);
        }
    }

    private function forcePosRuntimeBranchScope($request): ?int
    {
        $user = $request->user();
        if (! $user || $user->can('items_show') || ! $user->can('pos')) {
            return null;
        }

        $branchId = (int) $user->branch_id;
        abort_if($branchId < 1, 403);

        $request->merge(['branch_id' => $branchId]);
        $request->query->set('branch_id', $branchId);

        return $branchId;
    }

    /**
     * DefaultAccessService only tracks saved defaults (branch, etc.). POS vs admin "surface intent"
     * is inferred here from the same Spatie gates as POS runtime branch forcing: `pos` without
     * `items_show` means menu-only callers that must not see kiosk-scoped SKUs unless they pass ?surface=kiosk.
     */
    private function applyDefaultPosSurfaceForPosRuntimeUser(\Illuminate\Http\Request $request): void
    {
        $user = $request->user();
        if (! $user || $user->can('items_show') || ! $user->can('pos')) {
            return;
        }
        if ($request->filled('surface')) {
            return;
        }

        $request->merge(['surface' => 'pos']);
        $request->query->set('surface', 'pos');
    }
}
