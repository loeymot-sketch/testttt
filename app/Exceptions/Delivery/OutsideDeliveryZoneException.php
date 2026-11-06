<?php

namespace App\Exceptions\Delivery;

use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * [GOAL ROBUSTESSE 2026-07-27 · durcissement livraison pré-lancement]
 * Le point de livraison (coords fournies par le CLIENT via saveAddress) est
 * HORS du polygone de zone de la branche (`branches.zone`). Miroir exact de
 * GeocodeUnavailableException (422 + code + message client, render() dédié).
 */
final class OutsideDeliveryZoneException extends HttpException
{
    public const ERROR_CODE = 'OUTSIDE_DELIVERY_ZONE';
    public const CUSTOMER_MESSAGE = 'Adresse hors de la zone de livraison.';
    public const BEYOND_RADIUS_MESSAGE = 'Cette adresse est à plus de 10 km : commandez-nous sur Uber Eats.';

    /** Vrai quand le refus vient du rayon maximal : le client doit alors être orienté vers Uber Eats. */
    public readonly bool $suggestUberEats;

    public function __construct(?\Throwable $previous = null, bool $suggestUberEats = false)
    {
        $this->suggestUberEats = $suggestUberEats;
        parent::__construct(422, $suggestUberEats ? self::BEYOND_RADIUS_MESSAGE : self::CUSTOMER_MESSAGE, $previous);
    }

    public function render($request): JsonResponse
    {
        return response()->json([
            'status' => false,
            'code' => self::ERROR_CODE,
            'message' => $this->getMessage(),
            'suggest_uber_eats' => $this->suggestUberEats,
        ], 422);
    }
}
