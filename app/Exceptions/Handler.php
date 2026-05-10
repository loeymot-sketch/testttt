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
            return new JsonResponse(
                [
                    'success' => false,
                    'message' => $e->getMessage()
                ],
                422
            );
        }

        if ($e instanceof QueryException) {
            return new JsonResponse(
                [
                    'success' => false,
                    'message' => $e->getMessage()
                ],
                422
            );
        }

        return parent::render($request, $e);
    }
}
