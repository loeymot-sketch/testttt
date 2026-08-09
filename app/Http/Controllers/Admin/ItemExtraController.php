<?php

namespace App\Http\Controllers\Admin;

use Exception;
use App\Models\Item;
use App\Http\Requests\ItemOptionPhotoRequest;
use App\Models\ItemExtra;
use App\Services\ItemExtraService;
use App\Http\Requests\PaginateRequest;
use App\Http\Requests\ItemExtraRequest;
use App\Http\Resources\ItemExtraResource;

class ItemExtraController extends AdminController
{
    public ItemExtraService $itemExtraService;

    public function __construct(ItemExtraService $itemExtraService)
    {
        parent::__construct();
        $this->itemExtraService = $itemExtraService;
        $this->middleware(['permission:items_show'])->only('index', 'show');
        $this->middleware(['permission:items_edit'])->only('store', 'update', 'destroy');
    }

    public function index(PaginateRequest $request, Item $item) : \Illuminate\Http\Response | \Illuminate\Http\Resources\Json\AnonymousResourceCollection | \Illuminate\Contracts\Foundation\Application | \Illuminate\Contracts\Routing\ResponseFactory
    {
        try {
            return ItemExtraResource::collection($this->itemExtraService->list($request, $item));
        } catch (Exception $exception) {
            return response(['status' => false, 'message' => $exception->getMessage()], 422);
        }
    }


    public function store(ItemExtraRequest $request, Item $item) : ItemExtraResource | \Illuminate\Http\Response | \Illuminate\Contracts\Foundation\Application | \Illuminate\Contracts\Routing\ResponseFactory
    {
        try {
            return new ItemExtraResource($this->itemExtraService->store($request, $item));
        } catch (Exception $exception) {
            return response(['status' => false, 'message' => $exception->getMessage()], 422);
        }
    }


    public function update(ItemExtraRequest $request, Item $item, ItemExtra $itemExtra) : ItemExtraResource | \Illuminate\Http\Response | \Illuminate\Contracts\Foundation\Application | \Illuminate\Contracts\Routing\ResponseFactory
    {
        try {
            return new ItemExtraResource($this->itemExtraService->update($request, $item, $itemExtra));
        } catch (Exception $exception) {
            return response(['status' => false, 'message' => $exception->getMessage()], 422);
        }
    }


    public function show(Item $item, ItemExtra $itemExtra) : ItemExtraResource | \Illuminate\Http\Response | \Illuminate\Contracts\Foundation\Application | \Illuminate\Contracts\Routing\ResponseFactory
    {
        try {
            return new ItemExtraResource($this->itemExtraService->show($item, $itemExtra));
        } catch (Exception $exception) {
            return response(['status' => false, 'message' => $exception->getMessage()], 422);
        }
    }

    public function destroy(Item $item, ItemExtra $itemExtra) : \Illuminate\Http\Response | \Illuminate\Contracts\Foundation\Application | \Illuminate\Contracts\Routing\ResponseFactory
    {
        try {
            $this->itemExtraService->destroy($item, $itemExtra);
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
    public function changeImage(ItemOptionPhotoRequest $request, Item $item, ItemExtra $itemExtra)
    {
        $this->assertOptionAppartientAuProduit($item, $itemExtra);

        try {
            $itemExtra->clearMediaCollection('option');
            $itemExtra->addMedia($request->file('photo'))->toMediaCollection('option');

            return new ItemExtraResource($itemExtra->refresh());
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
    public function removeImage(Item $item, ItemExtra $itemExtra)
    {
        $u = request()->user();
        abort_if(! $u || (! $u->hasRole('Admin') && ! $u->hasRole('Tenant Admin')), 403);

        $this->assertOptionAppartientAuProduit($item, $itemExtra);

        try {
            $itemExtra->clearMediaCollection('option');

            return new ItemExtraResource($itemExtra->refresh());
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
