<?php


namespace App\Traits;


use Illuminate\Http\JsonResponse;

trait ApiResponsible
{
    /**
     * Successful response.
     *
     * @param      $item
     * @param int  $httpStatusCode
     *
     * @return JsonResponse
     */
    protected function responseData($item, int $httpStatusCode = 200): JsonResponse
    {
        return response()->json([
            'success' => true,
            'item'    => $item,
        ], $httpStatusCode);
    }

    /**
     * @param        $item
     * @param string|null $message
     * @param int    $httpStatusCode
     * @return JsonResponse
     */
    protected function responseItem($item, string $message = null, int $httpStatusCode = 200): JsonResponse
    {
        $data = [
            'success' => true,
            'item'    => $item,
        ];

        if ($message) {
            $data['message'] = $message;
        }
        return response()->json($data, $httpStatusCode);
    }

    /**
     * Successful response.
     *
     * @param array $items
     * @param int   $total
     * @param int   $httpStatusCode
     *
     * @return JsonResponse
     */
    protected function responseList(array $items, int $total, int $httpStatusCode = 200): JsonResponse
    {
        return response()->json([
            'success' => true,
            'items'   => $items,
            'total'   => $total,
            'count'   => count($items),
        ], $httpStatusCode);
    }

    /**
     * @param array $items
     * @param int|null $total
     * @param int $httpStatusCode
     * @return JsonResponse
     */
    protected function responseListWithTotal(array $items, int $total = null, int $httpStatusCode = 200): JsonResponse
    {
        $count = count($items);
        return response()->json([
            'success' => true,
            'items'   => $items,
            'totalCount'   => $total ?? $count,
            'count'   => $count,
        ], $httpStatusCode);
    }

    /**
     * @param array $items
     * @param int $httpStatusCode
     * @return JsonResponse
     */
    protected function responseItems(array $items, int $httpStatusCode = 200): JsonResponse
    {
        return response()->json([
            'success' => true,
            'items'   => $items
        ], $httpStatusCode);
    }

    /**
     * Failure response.
     *
     * @param string|array $error
     * @param null         $errorCode
     * @param array        $fieldsErrors
     * @param int          $httpStatusCode
     *
     * @return JsonResponse
     */
    protected function responseError(string|array $error, $errorCode = null, array $fieldsErrors = [], int $httpStatusCode = 500): JsonResponse
    {
        $response = [
            'success' => false,
            'error'   => $error,
        ];

        if (!is_null($errorCode)) {
            $response['code'] = $errorCode;
        }

        if (!empty($fieldsErrors)) {
            $response['fieldsErrors'] = $fieldsErrors;
        }

        return response()->json($response, $httpStatusCode);
    }

    /**
     * @param array $items
     * @param string $message
     * @param int $total
     * @param int $httpStatusCode
     * @return JsonResponse
     */
    protected function responseListWithMessage(array $items, string $message, int $total, int $httpStatusCode = 200): JsonResponse
    {
        return response()->json([
            'success' => true,
            'message' => $message,
            'items'   => $items,
            'total'   => $total,
            'count'   => count($items),
        ], $httpStatusCode);
    }
}
