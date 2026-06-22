<?php

namespace App\Exceptions\Delivery;

use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpKernel\Exception\HttpException;

final class GeocodeUnavailableException extends HttpException
{
    public const ERROR_CODE = 'GEOCODE_FAILED';
    public const CUSTOMER_MESSAGE = 'Adresse invalide. Veuillez en saisir une autre.';

    public function __construct(?\Throwable $previous = null)
    {
        parent::__construct(422, self::CUSTOMER_MESSAGE, $previous);
    }

    public function render($request): JsonResponse
    {
        return response()->json([
            'status' => false,
            'code' => self::ERROR_CODE,
            'message' => self::CUSTOMER_MESSAGE,
        ], 422);
    }
}
