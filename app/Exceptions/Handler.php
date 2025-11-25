<?php

namespace App\Exceptions;

use App\Traits\ApiResponsible;
use Exception;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Exceptions\Handler as ExceptionHandler;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Route;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Symfony\Component\HttpKernel\Exception\MethodNotAllowedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Throwable;

class Handler extends ExceptionHandler
{

    use ApiResponsible;
    /**
     * A list of the exception types that are not reported.
     *
     * @var array<int, class-string<Throwable>>
     */
    protected $dontReport = [
        //
    ];

    /**
     * A list of the inputs that are never flashed for validation exceptions.
     *
     * @var array<int, string>
     */
    protected $dontFlash = [
        'current_password',
        'password',
        'password_confirmation',
    ];

    /**
     * Report or log an exception.
     *
     * @param Throwable $e
     *
     * @return void
     * @throws Throwable
     */
    public function report(Throwable $e)
    {
        parent::report($e);
    }

    /**
     * Render an exception into an HTTP response.
     *
     * @param Request $request
     * @param Throwable $e
     *
     * @return Response
     * @throws Throwable
     */
    public function render($request, Throwable $e): Response
    {
        if ($request->is('api/*')) {
            return $this->handleException($request, $e);
        }
        return parent::render($request, $e);
    }

    /**
     * @param           $request
     * @param Throwable $exception
     *
     * @return Response
     */
    public function handleException($request, Throwable $exception): Response
    {
        $code = $exception->getCode();
        $error = $exception->getMessage();
        $data = [];
        $fieldsErrors = [];
        $httpStatusCode = Response::HTTP_INTERNAL_SERVER_ERROR;

        if ($exception instanceof MethodNotAllowedHttpException) {
            $httpStatusCode = Response::HTTP_METHOD_NOT_ALLOWED;
            $error = 'Для данного запроса метод недоступен';
        } elseif ($exception instanceof AuthenticationException) {
            $httpStatusCode = Response::HTTP_UNAUTHORIZED;
        }  elseif ($exception instanceof ForbiddenException) {
            $httpStatusCode = Response::HTTP_FORBIDDEN;
            $code = $httpStatusCode;
        } elseif ($exception instanceof NotFoundHttpException) {
            $httpStatusCode = Response::HTTP_NOT_FOUND;
            $code = $httpStatusCode;
        } elseif ($exception instanceof HttpException) {
            $httpStatusCode = $exception->getStatusCode();
            $code = $httpStatusCode;
        } elseif ($exception instanceof ValidationException) {
            $httpStatusCode = Response::HTTP_BAD_REQUEST;
            $error = 'Ошибка валидации полей';
            $fieldsErrors = $exception->errors();
            $code = $httpStatusCode;
        } elseif ($exception instanceof ModelNotFoundException) {
            $httpStatusCode = Response::HTTP_BAD_REQUEST;
            $code = $httpStatusCode;
        } elseif ($exception instanceof RequestException) {
            $data = $exception->response->json();
        } elseif ($exception instanceof Exception) {
            $httpStatusCode = $this->getValidHttpStatusCode($code);
            $code = $httpStatusCode;
        }

        Log::error('[' . Route::currentRouteName() . '] ' . get_class($exception), [
            'error' => $error,
            'code' => $code,
            'fieldsErrors' => $fieldsErrors,
            'httpStatusCode' => $httpStatusCode,
            'data' => $data,
        ]);

        return $this->responseError($error, $code, $fieldsErrors, $httpStatusCode);
    }

    /**
     * Валидация HTTP-статуса кода
     */
    private function getValidHttpStatusCode($code): int
    {
        $validCodes = [
            100, 101, 102, 103,
            200, 201, 202, 203, 204, 205, 206, 207, 208, 226,
            300, 301, 302, 303, 304, 305, 306, 307, 308,
            400, 401, 402, 403, 404, 405, 406, 407, 408, 409, 410,
            411, 412, 413, 414, 415, 416, 417, 418, 421, 422, 423,
            424, 425, 426, 428, 429, 431, 451,
            500, 501, 502, 503, 504, 505, 506, 507, 508, 510, 511
        ];

        return in_array($code, $validCodes) ? $code : 500;
    }

}
