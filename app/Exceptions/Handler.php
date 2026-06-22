<?php

namespace App\Exceptions;


use HttpException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Exceptions\Handler as ExceptionHandler;
use Illuminate\Http\JsonResponse;
use Illuminate\Validation\UnauthorizedException;
use Symfony\Component\HttpKernel\Exception\MethodNotAllowedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Throwable;

class Handler extends ExceptionHandler
{
    /**
     * A list of exception types with their corresponding custom log levels.
     *
     * @var array<class-string<\Throwable>, \Psr\Log\LogLevel::*>
     */
    protected $levels = [ //
    ];

    /**
     * A list of the exception types that are not reported.
     *
     * @var array<int, class-string<\Throwable>>
     */
    protected $dontReport = [ //
    ];

    /**
     * A list of the inputs that are never flashed to the session on validation exceptions.
     *
     * @var array<int, string>
     */
    protected $dontFlash = [
        'current_password',
        'password',
        'password_confirmation',
    ];

    /**
     * Register the exception handling callbacks for the application.
     *
     * @return void
     */
    public function register(): void
    {
        $this->reportable(function (Throwable $e) {

        });
    }


    public function render($request, Throwable $e): \Illuminate\Http\Response|JsonResponse|\Symfony\Component\HttpFoundation\Response
    {
        if ($e instanceof UnauthorizedException) {
            return new JsonResponse(
                [
                    'success' => false,
                    'message' => __('all.message.unauthorized'),
                ],
                403
            );
        }

        // [GOAL-K2-HEAL-01 2026-05-24] Phase K.4 H9 P1 + J-CASCADE H9 —
        // Defense-in-depth render mapping. The primary 409 conversion lives
        // in the counter-collect route closure (routes/api.php) ABOVE the
        // generic Exception→422 catch so the typed exception never reaches
        // Handler::render on that path. This branch covers any future
        // non-route call paths (queue jobs, console commands, alternative
        // controllers) that might bubble the exception up to the global
        // handler. Extends \RuntimeException (not HttpException) because
        // the HttpException branch below hardcodes 422 and would defeat
        // the 409.
        if ($e instanceof \App\Exceptions\Payment\PaymentAlreadyCollectedException) {
            return new JsonResponse(
                [
                    'status' => false,
                    'message' => $e->getMessage(),
                    'error_code' => 'payment_already_collected',
                    'order_id' => $e->orderId,
                    'collected_by_user_id' => $e->collectedByUserId,
                    'collected_at' => $e->collectedAt,
                ],
                409
            );
        }

        // [test-e2e fix E-004 round-3] i18n leak — Laravel ModelNotFoundException
        // surfaced raw English ("No query results for model.") into French POS
        // toasts. Translate via lang/{fr,en,ar}.all.message.order_not_found and
        // expose a stable error code for frontend mapping.
        if ($e instanceof ModelNotFoundException) {
            return new JsonResponse(
                [
                    'success' => false,
                    'code'    => 'ORDER_NOT_FOUND',
                    'message' => __('all.message.order_not_found'),
                ],
                404
            );
        }

        if ($e instanceof MethodNotAllowedHttpException) {
            return new JsonResponse(
                [
                    'success' => false,
                    'message' => __('all.message.method_not_supported'),
                ],
                405
            );
        }

        if ($e instanceof NotFoundHttpException) {
            return new JsonResponse(
                [
                    'success' => false,
                    'message' => __('all.message.url_not_found'),
                ],
                404
            );
        }

        if ($e instanceof HttpException) {
            // [GENIE A1-bis note] the hardcoded 422 here flattens a real 403/404/429 at the GLOBAL handler
            // (same class as the controller-level KDS-02 fix). Left as-is intentionally: route-level closures
            // already convert the important typed cases (e.g. 409 counter-collect) ABOVE this fallback, and a
            // global status change is broad-blast — deferred to a dedicated gated change. The message is an
            // intentional abort() string (not a raw leak), so it stays.
            return new JsonResponse(
                [
                    'success' => false,
                    'message' => $e->getMessage()
                ],
                422
            );
        }

        if ($e instanceof QueryException) {
            // [GENIE A1-bis 2026-06-16] A QueryException's getMessage() carries raw SQL + constraint/table
            // names — NEVER leak that to the client. Log it server-side, return a generic translated 422.
            \Illuminate\Support\Facades\Log::error('[A1-bis] unhandled QueryException at the global handler', [
                'message'   => $e->getMessage(),
                'exception' => get_class($e),
            ]);
            return new JsonResponse(
                [
                    'success' => false,
                    'message' => __('all.message.something_wrong'),
                ],
                422
            );
        }

        return parent::render($request, $e);
    }
}
