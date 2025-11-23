<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Services\UserMtDifferencesService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;

class UserMtController extends Controller
{
    private UserMtDifferencesService $searchService;

    public function __construct(UserMtDifferencesService $searchService)
    {
        $this->searchService = $searchService;
    }

    public function search(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'name' => 'nullable|string|max:255',
            'email' => 'nullable|email',
            'phone' => 'nullable|string|max:20',
            'medtouch_uuid' => 'nullable|string|max:255',
            'oralink_uuid' => 'nullable||string|max:255',
        ]);

        if ($validator->fails()) {
            Log::error('VALIDATION FAILED', $validator->errors()->toArray());
            return response()->json([
                'error' => 'Validation failed',
                'messages' => $validator->errors()
            ], 422);
        }

        try {
            $result = $this->searchService->searchUsers($request->all());

            return response()->json($result);

        } catch (\Exception $e) {
            return response()->json([
                'error' => 'Произошла ошибка при поиске',
                'message' => $e->getMessage()
            ], 500);
        }
    }
}
