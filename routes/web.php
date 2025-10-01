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
//    return CommonDatabase::whereHas('actions_mt_helios', function ($q) {
//        $q->where('old_mt_id', 1);
//    })->with(['actions_mt_helios'])->limit(1)->get()->toArray();
//    return \App\Models\ProjectTouchMT::find(10321);
    return \App\Models\SendsayIssue::where('id', 700) //c 563, c 572 и далее - есть "is sent"
        ->withCount([
            'sendsay_participations as participation_delivered_count' => function($query) {
                $query->where('result', 'delivered');
            },
            'sendsay_participations as participation_not_delivered_count' => function($query) {
                $query->where('result', 'not delivered');
            },
            'sendsay_participations as participation_is_sent_count' => function($query) {
                $query->where('result', 'is sent');
            }
        ])
        ->first()->makeHidden(['open_rate', 'ctr', 'delivery_rate', 'opened', 'open_per_unique', 'clicked', 'clicks_per_unique', 'ctor']);
});
