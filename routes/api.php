<?php

use App\Http\Controllers\PrivateFileController;
use App\Http\Controllers\WebhooksController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| API Routes
|--------------------------------------------------------------------------
|
| Here is where you can register API routes for your application. These
| routes are loaded by the RouteServiceProvider within a group which
| is assigned the "api" middleware group. Enjoy building your API!
|
*/

Route::middleware('auth:sanctum')->get('/user', function (Request $request) {
    return $request->user();
});

Route::middleware('private.disk')->get('/private/{path}', [PrivateFileController::class, 'streamFile'])
    ->where('path', '.*');

Route::post('/webhook/id-errors', [WebhooksController::class, 'intellectDialogErrors']);
