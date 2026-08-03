<?php

namespace App\Exceptions;

use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * [Sprint 2B / DEL-2] OrderAddressOwnershipException
 *
 * Raised inside the DELIVERY order pipeline when the client-supplied
 * `address_id` resolves to an Address row whose `user_id` does NOT match
 * the authenticated buyer (IDOR attempt).
 *
 * Previously (`FrontendOrderService.php:526-544`) the address mismatch
 * was silently skipped — i.e. the OrderAddress snapshot row was not
 * written, but the DELIVERY order still persisted without an attached
 * shipping address. This left orders un-deliverable AND blocked auditing
 * (no trace of the attempted IDOR).
 *
 * Throwing this exception INSIDE the DB::transaction block at
 * FrontendOrderService line 176 causes Laravel to roll back the
 * FrontendOrder row, the OrderItem rows, the OrderCoupon row and the
 * Stock decrement that all share the same transaction — so a refused
 * address mismatch never produces a partial order. The exception
 * bubbles through the `catch (HttpException)` re-throw at line 590-591
 * so Laravel's exception handler emits the JSON 403 below.
 *
 * Status: 403 Forbidden (not 422) — the request is well-formed, but
 * the caller does not own the referenced address.
 */
final class OrderAddressOwnershipException extends HttpException
{
    public const ERROR_CODE = 'ORDER_ADDRESS_FORBIDDEN';
    public const CUSTOMER_MESSAGE = "Cette adresse ne vous appartient pas. Veuillez en sélectionner une autre.";

    public function __construct(?\Throwable $previous = null)
    {
        parent::__construct(403, self::CUSTOMER_MESSAGE, $previous);
    }

    public function render($request): JsonResponse
    {
        return response()->json([
            'status'  => false,
            'code'    => self::ERROR_CODE,
            'message' => self::CUSTOMER_MESSAGE,
        ], 403);
    }
}
