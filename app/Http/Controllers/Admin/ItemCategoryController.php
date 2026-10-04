<?php

namespace App\Http\Controllers\Admin;


use App\Exports\ItemCategoryExport;
use App\Http\Requests\ItemCategoryImportRequest;
use App\Imports\ItemCategoryImport;
use Exception;
use Response;
use App\Models\ItemCategory;
use Illuminate\Http\Request;
use Maatwebsite\Excel\Facades\Excel;
use App\Services\ItemCategoryService;
use App\Http\Requests\PaginateRequest;
use App\Http\Requests\ItemCategoryRequest;
use App\Http\Resources\ItemCategoryResource;

class ItemCategoryController extends AdminController
{
    private ItemCategoryService $itemCategoryService;

    public function __construct(ItemCategoryService $itemCategory)
    {
        parent::__construct();
        $this->itemCategoryService = $itemCategory;
        $this->middleware(['permission:settings'])->only(
            'store',
            'update',
            'destroy',
            'index',
            'show',
            'sortCategory',
            'export',
            'downloadSample',
            'import'
        );
    }

    public function index(
        PaginateRequest $request
    ): \Illuminate\Http\Response | \Illuminate\Http\Resources\Json\AnonymousResourceCollection | \Illuminate\Contracts\Foundation\Application | \Illuminate\Contracts\Routing\ResponseFactory {
        try {
            return ItemCategoryResource::collection($this->itemCategoryService->list($request));
        } catch (Exception $exception) {
            return response(['status' => false, 'message' => $exception->getMessage()], 422);
        }
    }


    public function store(
        ItemCategoryRequest $request
    ): \Illuminate\Http\Response | ItemCategoryResource | \Illuminate\Contracts\Foundation\Application | \Illuminate\Contracts\Routing\ResponseFactory {
        try {
            return new ItemCategoryResource($this->itemCategoryService->store($request));
        } catch (Exception $exception) {
            return response(['status' => false, 'message' => $exception->getMessage()], 422);
        }
    }

    public function show(
        ItemCategory $itemCategory
    ): \Illuminate\Http\Response | ItemCategoryResource | \Illuminate\Contracts\Foundation\Application | \Illuminate\Contracts\Routing\ResponseFactory {
        try {
            return new ItemCategoryResource($this->itemCategoryService->show($itemCategory));
        } catch (Exception $exception) {
            return response(['status' => false, 'message' => $exception->getMessage()], 422);
        }
    }

    public function update(
        ItemCategoryRequest $request,
        ItemCategory $itemCategory
    ): \Illuminate\Http\Response | ItemCategoryResource | \Illuminate\Contracts\Foundation\Application | \Illuminate\Contracts\Routing\ResponseFactory {
        try {
            return new ItemCategoryResource($this->itemCategoryService->update($request, $itemCategory));
        } catch (Exception $exception) {
            return response(['status' => false, 'message' => $exception->getMessage()], 422);
        }
    }

    public function destroy(
        ItemCategory $itemCategory
    ): \Illuminate\Http\Response | \Illuminate\Contracts\Foundation\Application | \Illuminate\Contracts\Routing\ResponseFactory {
        try {
            $this->itemCategoryService->destroy($itemCategory);
            return response('', 202);
        } catch (Exception $exception) {
            return response(['status' => false, 'message' => $exception->getMessage()], 422);
        }
    }

    public function sortCategory(
        Request $request
    ) {
        try {
            $this->itemCategoryService->sortCategory($request);
            return response('', 202);
        } catch (Exception $exception) {
            return response(['status' => false, 'message' => $exception->getMessage()], 422);
        }
    }

    public function export(PaginateRequest $request) : \Illuminate\Http\Response | \Symfony\Component\HttpFoundation\BinaryFileResponse | \Illuminate\Contracts\Foundation\Application | \Illuminate\Contracts\Routing\ResponseFactory
    {
        try {
            return Excel::download(new ItemCategoryExport($this->itemCategoryService, $request), 'Item.xlsx');
        } catch (Exception $exception) {
            return response(['status' => false, 'message' => $exception->getMessage()], 422);
        }
    }

    public function downloadSample()
    {
        try {
            return Response::download(public_path('/file/CategoryImportSample.xlsx'));
        } catch (Exception $exception) {
            return response(['status' => false, 'message' => $exception->getMessage()], 422);
        }
    }

    /**
     * [ONB 2026-08-28] Ce controleur repondait un `202` VIDE, quoi qu'il arrive.
     *
     * L'instance d'import etait construite EN LIGNE dans l'appel a `Excel::import()`,
     * donc jetee aussitot : les echecs collectes par `SkipsFailures` n'etaient jamais
     * lus. Combine a l'absence d'alias d'en-tetes (corrigee dans
     * `AccepteLesEnTetesExportes`), cela donnait le pire enchainement possible :
     * le commercant exportait ses categories, en corrigeait une, redeposait le
     * fichier — TOUTES les lignes echouaient sur `name required`, `SkipsOnFailure`
     * les avalait, et l'ecran affichait un succes. Zero categorie creee, zero mot.
     *
     * Le meme defaut avait ete ferme cote PRODUITS le meme jour. Il etait reste
     * intact ici : c'est le motif « une correction appliquee a un seul des deux
     * jumeaux », que le trait partage doit desormais empecher de revenir.
     */
    public function import(ItemCategoryImportRequest $request)
    {
        try {
            $import = new ItemCategoryImport($request->file('file'));
            Excel::import($import, $request->file('file'));

            $brut = collect($import->failures());

            $lignesDeja = $brut
                ->filter(fn ($echec) => str_contains(
                    implode(' ', $echec->errors()),
                    ItemCategoryImport::MARQUEUR_DEJA_PRESENT
                ))
                ->map(fn ($echec) => $echec->row())
                ->unique();

            $enPlace = $brut
                ->filter(fn ($echec) => $lignesDeja->contains($echec->row()))
                ->groupBy(fn ($echec) => $echec->row())
                ->map(fn ($groupe, $ligne) => [
                    'ligne'  => (int) $ligne,
                    'raison' => trim(str_replace(
                        ItemCategoryImport::MARQUEUR_DEJA_PRESENT,
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

            // `model()` n'est appelee QUE par les lignes qui ont passe la
            // validation : le compteur est deja net des refus et des lignes
            // deja presentes. Les soustraire une seconde fois ramenait a zero.
            $creees = (int) $import->creees;

            return response()->json([
                'status'        => true,
                'creees'        => $creees,
                'deja_presents' => $enPlace,
                'echecs'        => $echecs,
                'message'       => $this->resumeImport($creees, count($echecs), count($enPlace)),
            ], 202);
        } catch (Exception $exception) {
            return response(['status' => false, 'message' => $exception->getMessage()], 422);
        }
    }

    /** Une phrase que le commercant peut lire, plutot que trois listes a recouper. */
    private function resumeImport(int $creees, int $echecs, int $dejaPresents = 0): string
    {
        if ($creees === 0 && $echecs === 0 && $dejaPresents === 0) {
            return 'Votre fichier ne contenait aucune ligne exploitable.';
        }

        $phrase = $creees > 0
            ? $creees . ' categorie' . ($creees > 1 ? 's ajoutees.' : ' ajoutee.')
            : 'Aucune categorie ajoutee.';

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
}
