<?php

namespace App\Http\Controllers\Admin;

use App\Http\Resources\ItemVariationGroupByAttributeResource;
use Exception;
use App\Models\Item;
use App\Http\Requests\ItemOptionPhotoRequest;
use App\Http\Requests\PaginateRequest;
use App\Services\ItemVariationService;
use App\Http\Requests\ItemVariationRequest;
use App\Http\Resources\ItemVariationResource;
use App\Models\ItemVariation;

class ItemVariationController extends AdminController
{
    public ItemVariationService $itemVariationService;

    public function __construct(ItemVariationService $itemVariationService)
    {
        parent::__construct();
        $this->itemVariationService = $itemVariationService;
        $this->middleware(['permission:items_show'])->only('index', 'listGroupByAttribute', 'show');
        $this->middleware(['permission:items_edit'])->only('store', 'update', 'destroy');
    }

    public function index(PaginateRequest $request, Item $item) : \Illuminate\Http\Response | \Illuminate\Http\Resources\Json\AnonymousResourceCollection | \Illuminate\Contracts\Foundation\Application | \Illuminate\Contracts\Routing\ResponseFactory
    {
        try {
            return ItemVariationResource::collection($this->itemVariationService->list($request, $item));
        } catch (Exception $exception) {
            return response(['status' => false, 'message' => $exception->getMessage()], 422);
        }
    }

    public function listGroupByAttribute(PaginateRequest $request, Item $item): \Illuminate\Http\Response | \Illuminate\Http\Resources\Json\AnonymousResourceCollection | \Illuminate\Contracts\Foundation\Application | \Illuminate\Contracts\Routing\ResponseFactory
    {
        try {
            return ItemVariationGroupByAttributeResource::collection($this->itemVariationService->listGroupByAttribute($request, $item));
        } catch (Exception $exception) {
            return response(['status' => false, 'message' => $exception->getMessage()], 422);
        }
    }


    public function store(ItemVariationRequest $request, Item $item): \Illuminate\Http\Response | ItemVariationResource | \Illuminate\Contracts\Foundation\Application | \Illuminate\Contracts\Routing\ResponseFactory
    {
        try {
            return new ItemVariationResource($this->itemVariationService->store($request, $item));
        } catch (Exception $exception) {
            return response(['status' => false, 'message' => $exception->getMessage()], 422);
        }
    }


    public function update(ItemVariationRequest $request, Item $item, ItemVariation $itemVariation): \Illuminate\Http\Response | ItemVariationResource | \Illuminate\Contracts\Foundation\Application | \Illuminate\Contracts\Routing\ResponseFactory
    {
        try {
            return new ItemVariationResource($this->itemVariationService->update($request, $item, $itemVariation));
        } catch (Exception $exception) {
            return response(['status' => false, 'message' => $exception->getMessage()], 422);
        }
    }


    public function show(Item $item, ItemVariation $itemVariation): \Illuminate\Http\Response | ItemVariationResource | \Illuminate\Contracts\Foundation\Application | \Illuminate\Contracts\Routing\ResponseFactory
    {
        try {
            return new ItemVariationResource($this->itemVariationService->show($item, $itemVariation));
        } catch (Exception $exception) {
            return response(['status' => false, 'message' => $exception->getMessage()], 422);
        }
    }


    public function destroy(Item $item, ItemVariation $itemVariation): \Illuminate\Http\Response | \Illuminate\Contracts\Foundation\Application | \Illuminate\Contracts\Routing\ResponseFactory
    {
        try {
            $this->itemVariationService->destroy($item, $itemVariation);
            return response('', 202);
        } catch (Exception $exception) {
            return response(['status' => false, 'message' => $exception->getMessage()], 422);
        }
    }

    /**
     * [PILOTAGE 2026-08-09] Poser une photo sur une option depuis l'admin.
     *
     * Avant : la photo d'un supplément ou d'une variation était déduite de son
     * NOM via config/menu_images.php. Illustrer une option nouvellement créée
     * demandait donc d'éditer du code ET de déposer un fichier sur le serveur —
     * hors de portée du propriétaire. Résultat mesuré : 131 choix du wizard sur
     * 1002 en case grise, dont les deux premières étapes de la borne.
     *
     * La photo posée ici prime ; la table par nom reste le repli, donc rien de
     * ce qui fonctionnait ne change.
     */
    public function changeImage(ItemOptionPhotoRequest $request, Item $item, ItemVariation $itemVariation)
    {
        $this->assertOptionAppartientAuProduit($item, $itemVariation);

        try {
            $itemVariation->clearMediaCollection('option');
            $itemVariation->addMedia($request->file('photo'))->toMediaCollection('option');

            return new ItemVariationResource($itemVariation->refresh());
        } catch (Exception $exception) {
            return response(['status' => false, 'message' => $exception->getMessage()], 422);
        }
    }

    /**
     * Retirer la photo : l'option retombe sur la correspondance par nom.
     *
     * Pas de FormRequest ici (aucun corps à valider) — le contrôle de rôle est
     * donc explicite, sinon la suppression serait ouverte plus largement que
     * l'ajout, ce qui n'aurait aucun sens.
     */
    public function removeImage(Item $item, ItemVariation $itemVariation)
    {
        $u = request()->user();
        abort_if(! $u || (! $u->hasRole('Admin') && ! $u->hasRole('Tenant Admin')), 403);

        $this->assertOptionAppartientAuProduit($item, $itemVariation);

        try {
            $itemVariation->clearMediaCollection('option');

            return new ItemVariationResource($itemVariation->refresh());
        } catch (Exception $exception) {
            return response(['status' => false, 'message' => $exception->getMessage()], 422);
        }
    }

    /**
     * Une option appartient à UN produit. Sans ce contrôle, l'identifiant du
     * produit dans l'URL serait décoratif et on pourrait changer la photo d'une
     * option d'un autre produit en devinant son identifiant.
     */
    private function assertOptionAppartientAuProduit(Item $item, $option): void
    {
        abort_if((int) $option->item_id !== (int) $item->id, 404);
    }
}
