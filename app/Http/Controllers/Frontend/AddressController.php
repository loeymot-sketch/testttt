<?php

namespace App\Http\Controllers\Frontend;

use App\Http\Requests\PaginateRequest;
use Exception;
use App\Models\Address;
use App\Services\AddressService;
use App\Http\Controllers\Controller;
use App\Http\Requests\AddressRequest;
use App\Http\Resources\AddressResource;


class AddressController extends Controller
{

    public AddressService $addressService;

    public function __construct(AddressService $addressService)
    {
        $this->addressService = $addressService;
    }

    public function index(PaginateRequest $request) : \Illuminate\Http\Response | \Illuminate\Http\Resources\Json\AnonymousResourceCollection | \Illuminate\Contracts\Foundation\Application | \Illuminate\Contracts\Routing\ResponseFactory
    {
        try {
            return AddressResource::collection($this->addressService->list($request));
        } catch (Exception $exception) {
            return response(['status' => false, 'message' => $exception->getMessage()], 422);
        }
    }


    public function show(Address $address)
    {
        // [SECURITY FIX] IDOR Prevention - Verify ownership
        if ($address->user_id !== auth()->id()) {
            abort(403, __('all.message.unauthorized'));
        }
        
        try {
            return new AddressResource($address);
        } catch (Exception $exception) {
            return response(['status' => false, 'message' => $exception->getMessage()], 422);
        }
    }

    public function store(AddressRequest $request)
    {
        try {
            return new AddressResource($this->addressService->store($request));
        } catch (Exception $exception) {
            return response(['status' => false, 'message' => $exception->getMessage()], 422);
        }
    }

    public function update(AddressRequest $request, Address $address)
    {
        // [SECURITY FIX] IDOR Prevention - Verify ownership
        if ($address->user_id !== auth()->id()) {
            abort(403, __('all.message.unauthorized'));
        }
        
        try {
            return new AddressResource($this->addressService->update($request, $address));
        } catch (Exception $exception) {
            return response(['status' => false, 'message' => $exception->getMessage()], 422);
        }
    }

    /**
     * [PROFIL APP 2026-11-12] `POST /api/frontend/address/{address}/default` — l'adresse proposée en premier au paiement
     * en livraison. Un client ne peut désigner QUE l'une de ses adresses (403 sinon, même message que show/update).
     */
    public function setDefault(Address $address)
    {
        if ((int) $address->user_id !== (int) auth()->id()) {
            abort(403, __('all.message.unauthorized'));
        }

        try {
            return new AddressResource($this->addressService->setDefault($address));
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException) {
            // [test-e2e stores P15-001 · 2026-11-13] Supprimée depuis un autre appareil pendant la demande.
            return response(['status' => false, 'code' => 'ADDRESS_GONE', 'message' => 'Cette adresse a été supprimée depuis un autre appareil.'], 404);
        } catch (Exception $exception) {
            return response(['status' => false, 'message' => $exception->getMessage()], 422);
        }
    }

    public function destroy(Address $address)
    {
        // [SECURITY FIX] IDOR Prevention - Verify ownership
        if ($address->user_id !== auth()->id()) {
            abort(403, __('all.message.unauthorized'));
        }
        
        try {
            $this->addressService->destroy($address);
            return response('', 202);
        } catch (Exception $exception) {
            return response(['status' => false, 'message' => $exception->getMessage()], 422);
        }
    }
}
