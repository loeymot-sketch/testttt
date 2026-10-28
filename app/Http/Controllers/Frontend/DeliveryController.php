<?php

namespace App\Http\Controllers\Frontend;

use App\Exceptions\Delivery\GeocodeUnavailableException;
use App\Http\Controllers\Controller;
use App\Models\Branch;
use App\Services\Delivery\AddressGeocoder;
use App\Services\Delivery\DeliveryAvailability;
use App\Services\Delivery\DeliveryQuoteService;
use App\Services\Delivery\DeliveryTariff;
use App\Services\Delivery\DeliveryWaveService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * [LIVRAISON 2026-10-20] Points d'entrée PUBLICS de la livraison pour le site et l'application :
 * statut (active / suspendue), suggestions d'adresse, devis et créneaux. Lecture seule ; aucune
 * valeur envoyée par le navigateur n'est crue — la position et la commune sortent du géocodeur serveur.
 */
class DeliveryController extends Controller
{
    public function __construct(
        private readonly DeliveryAvailability $availability,
        private readonly AddressGeocoder $geocoder,
        private readonly DeliveryTariff $tariff,
        private readonly DeliveryQuoteService $quotes,
        private readonly DeliveryWaveService $waves,
    ) {
    }

    public function status(): JsonResponse
    {
        return response()->json([
            'status'  => true,
            'enabled' => $this->availability->isEnabled(),
            'grid'    => $this->availability->publicGrid(),
        ]);
    }

    public function suggest(Request $request): JsonResponse
    {
        $request->validate(['q' => ['required', 'string', 'min:3', 'max:200']]);

        return response()->json(['status' => true, 'data' => $this->geocoder->search((string) $request->query('q'))]);
    }

    /** Devis pour une adresse saisie : prix, palier, estimation, et créneaux si livrable. */
    public function quote(Request $request): JsonResponse
    {
        $request->validate(['q' => ['required', 'string', 'min:5', 'max:300']]);

        return response()->json(app(\App\Services\Delivery\DeliveryTextQuote::class)->build((string) $request->input('q')));
    }

    public function waves(): JsonResponse
    {
        $branch = Branch::query()->orderBy('id')->first();

        return response()->json([
            'status' => true,
            'enabled' => $this->availability->isEnabled(),
            'data' => $branch ? $this->waves->availableWaves((int) $branch->id, null, 12) : [],
        ]);
    }
}
