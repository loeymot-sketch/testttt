<?php

namespace App\Exceptions\Delivery;

use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpKernel\Exception\HttpException;

final class GeocodeUnavailableException extends HttpException
{
    public const ERROR_CODE = 'GEOCODE_FAILED';
    public const CUSTOMER_MESSAGE = 'Adresse invalide. Veuillez en saisir une autre.';

    // [test-e2e stores S3-02 · 2026-11-12] Panne du service d'adresses (api-adresse.data.gouv.fr en 503 / redirigé) :
    //   ce n'est PAS l'adresse du client qui est fausse. Lui dire « Adresse invalide » le faisait retaper une adresse
    //   juste, en boucle. Code et message distincts. Statut 422 conservé : tous les clients (site, application, caisse)
    //   affichent déjà le message d'un 422, alors qu'un 503 déclencherait l'écran « service indisponible » général.
    public const SERVICE_DOWN_CODE = 'GEOCODE_SERVICE_DOWN';
    public const SERVICE_DOWN_MESSAGE = 'Le service de vérification des adresses est momentanément indisponible. '
        . 'Réessayez dans quelques minutes, ou choisissez « À emporter ».';

    public readonly bool $panne;

    public function __construct(?\Throwable $previous = null, bool $panne = false)
    {
        $this->panne = $panne;
        parent::__construct(422, $panne ? self::SERVICE_DOWN_MESSAGE : self::CUSTOMER_MESSAGE, $previous);
    }

    public function code(): string
    {
        return $this->panne ? self::SERVICE_DOWN_CODE : self::ERROR_CODE;
    }

    public function render($request): JsonResponse
    {
        return response()->json([
            'status' => false,
            'code' => $this->code(),
            'message' => $this->getMessage(),
        ], $this->getStatusCode());
    }
}
