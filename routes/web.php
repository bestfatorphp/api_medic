<?php

use App\Models\ActivityMT;
use App\Models\CommonDatabase;
use App\Models\Doctor;
use App\Models\ParsingPD;
use App\Models\UnisenderContact;
use App\Models\UserMT;
use App\Models\WhatsAppCampaign;
use App\Models\WhatsAppContact;
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
//тесты
Route::get('/', function () {
//    return CommonDatabase::with(['user_mt', 'doctor', 'parsing_pd', 'unisender_contact', 'actions_mt'])->limit(2)->get()->toArray();
//    return WhatsAppCampaign::with(['whatsapp_participations'])->limit(1)->get()->toArray();
    return ActivityMT::whereHas('actions_mt', function ($q) {
        $q->where('mt_user_id', 2);
    })->with(['actions_mt' => function ($q) {
        $q->where('mt_user_id', 2);
    }])->limit(10)->get()->toArray();
});
