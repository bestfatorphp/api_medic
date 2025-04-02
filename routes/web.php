<?php

use App\Models\ActivityMT;
use App\Models\CommonDatabase;
use App\Models\Doctor;
use App\Models\ParsingPD;
use App\Models\UnisenderContact;
use App\Models\UserMT;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Web Routes
|--------------------------------------------------------------------------
|
| Here is where you can register web routes for your application. These
| routes are loaded by the RouteServiceProvider within a group which
| contains the "web" middleware group. Now create something great!
|
*/

Route::get('/', function () {
    return CommonDatabase::with(['user_mt', 'doctor', 'parsing_pd', 'unisender_contact', 'actions_mt'])->limit(2)->get()->toArray();
});
