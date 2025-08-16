<?php

namespace App\Models;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * WhatsApp рассылки (чаты)
 * Class WhatsAppChatCampaign
 * @package App\Models
 *
 * @property string         $id
 * @property string         $campaign_name          Наименоание рассылки
 * @property Carbon         $send_date              Время отправки
 * @property integer        $sent                   Количество отправленных
 * @property integer        $delivered              Количество доставленных
 * @property float          $delivery_rate          Скорость доставки
 * @property integer        $opened                 Количество открытий
 * @property float          $open_rate              Открытия в процентах
 *
 */
class WhatsAppCampaign extends Model
{
    use HasFactory;

    /**
     * @var string
     */
    protected $table = 'whatsapp_campaign';

    /**
     * @var bool
     */
    public $timestamps = false;

    /**
     * @var array
     */
    protected $guarded = [];

    /**
     * @var string[]
     */
    protected $casts = [
        //
    ];

    protected $with = [
//        'whatsapp_participations',
    ];

    /**
     * WhatsApp участия
     * @return \Illuminate\Database\Eloquent\Relations\HasMany
     */
    public function whatsapp_participations()
    {
        return $this->hasMany(WhatsAppParticipation::class, 'campaign_id');
    }
}
