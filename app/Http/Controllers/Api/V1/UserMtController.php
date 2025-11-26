<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Services\UserMtService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class UserMtController extends Controller
{
    private UserMtService $searchService;

    public function __construct(UserMtService $searchService)
    {
        $this->searchService = $searchService;
    }

    /**
     * Отдаём список юзеров по полю medtouch_uuid или oralink_uuid
     * @throws \Exception
     */
    public function listByUuid(string $field, Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'uuids' => 'required|array',
            'uuids.*' => 'required|uuid',
        ]);

        if ($validator->fails()) {
            throw new ValidationException($validator);
        }

        return $this->responseItems($this->searchService->listByUuid($field, $request->all()));
    }

    /**
     * Отдаём одного юзера по полю medtouch_uuid или oralink_uuid
     * @throws \Exception
     */
    public function oneByUuid(string $field, string $uuid): JsonResponse
    {
        return $this->responseItem($this->searchService->oneByUuid($field, $uuid));
    }

    /**
     * Ищём пользователя и различия его данных с данными запроса
     * @throws \Exception
     */
    public function differences(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'name' => 'nullable|string|max:255',
            'email' => 'nullable|email',
            'phone' => 'nullable|string|max:20',
            'medtouch_uuid' => 'nullable|uuid',
            'oralink_uuid' => 'nullable||uuid',
        ]);

        if ($validator->fails()) {
            throw new ValidationException($validator);
        }

        return $this->responseItem($this->searchService->differences($request->all()));
    }
}
