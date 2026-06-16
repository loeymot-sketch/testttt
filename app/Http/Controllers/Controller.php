<?php

namespace App\Http\Controllers;

use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Foundation\Bus\DispatchesJobs;
use Illuminate\Foundation\Validation\ValidatesRequests;
use Illuminate\Routing\Controller as BaseController;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;

class Controller extends BaseController
{
    use AuthorizesRequests, DispatchesJobs, ValidatesRequests;

    /**
     * [GENIE Wave 2 — A1 jsonError trait 2026-06-16] Single safe error responder for admin/frontend
     * controller catch-blocks. The legacy idiom `return response([...'message'=>$e->getMessage()], 422)`
     * (404 sites / 113 controllers, 97 with no logging) leaked raw exception text — SQL, constraint
     * names, class names — to the client AND recorded nothing for monitoring.
     *
     * DISCRIMINATOR (load-bearing): services throw ~76 intentional, already-translated user messages via
     * `throw new \Exception(trans('all.message.x'), 422)`, plus `abort()` (HttpException) and validation
     * errors — these MUST pass through unchanged or coupon/OTP/order-transition UX regresses. Only the
     * UNEXPECTED (QueryException, generic Exception with code 0, etc.) is genericized + logged, so raw
     * internal text never reaches the client and prod failures stop being invisible.
     *
     * @param  string|null  $context  short call-site tag for the log (e.g. "coupon.store").
     */
    protected function jsonError(\Throwable $e, int $status = 422, ?string $context = null): \Illuminate\Http\Response
    {
        $isUserFacing = (int) $e->getCode() === 422
            || $e instanceof HttpException
            || $e instanceof ValidationException;

        if ($e instanceof HttpException) {
            $status = $e->getStatusCode();
        }

        if ($isUserFacing) {
            return response(['status' => false, 'message' => $e->getMessage()], $status);
        }

        Log::error('[jsonError] ' . ($context ?? 'controller'), [
            'message'   => $e->getMessage(),
            'exception' => get_class($e),
            'context'   => $context,
        ]);

        return response(['status' => false, 'message' => __('all.message.something_wrong')], $status);
    }
}
