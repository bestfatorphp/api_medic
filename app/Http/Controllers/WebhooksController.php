<?php

namespace App\Http\Controllers;

use App\Facades\IntellectDialog;
use Illuminate\Support\Facades\Log;
use App\Models\WhatsAppCampaign;
use App\Models\WhatsAppContact;
use App\Models\WhatsAppParticipation;
use App\Traits\WriteLockTrait;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class WebhooksController extends Controller
{

    use WriteLockTrait;

    /**
     * Получаем сообщения, по которым статус ошибка
     * @param Request $request
     * @return string
     */
    public function intellectDialogErrors(Request $request): string
    {
//        $data = $request->all();
//
//        Log::channel('commands')->warning('IntellectDialog webhook error message', $data);
//
//        $validator = Validator::make($request->all(), [
//            'message_id' => 'required|string',
//            'phone'      => 'nullable|string',
//            'error_at'   => 'nullable|string',
//            'detail'     => 'nullable|string',
//        ]);
//
//        if ($validator->fails()) {
//            Log::channel('commands')->warning('IntellectDialog webhook validation failed', [
//                'errors' => $validator->errors(),
//                'payload' => $data,
//            ]);
//            return response('error', 200);
//        }
//
//        try {
//            $message = IntellectDialog::message($data['message_id']);
//
//            Log::channel('commands')->warning('IntellectDialog webhook message by id', $message);
//
//            if ($message['type_name'] !== "Исходящее") {
//                return response('ok', 200);
//            }
//
//            $text = $message['text'];
//            $phone = $message['person_phone'];
//
//            /** @var WhatsAppCampaign $campaign */
//            $campaign = WhatsAppCampaign::query()
//                ->where('campaign_name', $text)
//                ->first();
//
//            if (empty($campaign)) {
//                $campaign = $this->withTableLock('whatsapp_campaign', function () use ($message, $text) {
//                    return WhatsAppCampaign::query()->create([
//                        'campaign_name' => $text,
//                        'send_date' => Carbon::parse($message['created_at']),
//                    ]);
//                });
//            }
//
//            $contact = WhatsAppContact::query()->where('phone', $message['person_phone'])->first();
//
//            if (empty($contact)) {
//                $this->withTableLock('whatsapp_contacts', function () use ($phone) {
//                    WhatsAppContact::query()->create(['phone' => $phone]);
//                });
//            }
//
//            $campaignId = $campaign->id;
//
//            $participation = WhatsAppParticipation::query()
//                ->where('campaign_id', $campaignId)
//                ->where('phone', $phone)
//                ->first();
//
//            if (empty($participation)) {
//                $this->withTableLock('whatsapp_participation', function () use ($campaignId, $phone, $message) {
//                    WhatsAppParticipation::create([
//                        'campaign_id' => $campaignId,
//                        'phone' => $phone,
//                        'delivered_at' => null,
//                        'opened_at' => null,
//                        'send_date' => $message['created_at'],
//                        'error' => true
//                    ]);
//                });
//            }
//        } catch (\Throwable $e) {
//            Log::channel('commands')->error('Unexpected error in IntellectDialog webhook', [
//                'exception' => $e->getMessage(),
//                'trace' => $e->getTraceAsString(),
//                'payload' => $request->all(),
//            ]);
//            return response('error', 200);
//        }

        return response('ok', 200);
    }
}
