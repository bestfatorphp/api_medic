<?php

namespace App\Models;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * WhatsApp участия (сообщения в чате)
 * Class WhatsAppParticipation
 * @package App\Models
 *
 * @property integer        $id
 * @property integer        $campaign_id            ID WhatsApp рассылки
 * @property string         $phone                  Телефон
 * @property Carbon         $send_date              Время отправки
 */
class WhatsAppParticipation extends Model
{
    use HasFactory;

    protected $table = 'whatsapp_participation';

    public $timestamps = false;

    protected $guarded = [];

    protected $with = [
      'whatsapp_contact'
//        'whatsapp_campaign'
    ];

    /**
     * WhatsApp контакт
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
     */
    public function whatsapp_contact()
    {
        return $this->belongsTo(WhatsAppContact::class, 'phone', 'phone');
    }

    /**
     * WhatsApp рассылка
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
     */
    public function whatsapp_campaign()
    {
        return $this->belongsTo(WhatsAppCampaign::class, 'campaign_id');
    }
}
