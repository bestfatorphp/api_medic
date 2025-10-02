<?php

namespace App\Models;

use App\Traits\MutatorsHelper;
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
 * @property Carbon         $delivered_at           Время доставки
 * @property Carbon         $opened_at              Время открытия
 * @property boolean        $error                  Сообщение небыло отправлено из-за ошибки
 */
class WhatsAppParticipation extends Model
{
    use HasFactory, MutatorsHelper;

    protected $table = 'whatsapp_participation';

    public $timestamps = false;

    protected $guarded = [];

    protected $fillable = [
        'id',
        'campaign_id',
        'phone',
        'send_date',
        'delivered_at',
        'opened_at',
        'error'
    ];

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

    /**
     * Мутатор для phone
     */
    public function setPhoneAttribute($value)
    {
        $this->attributes['phone'] = $value ? preg_replace('/[^0-9]/', '', $value) : null;
    }
}
