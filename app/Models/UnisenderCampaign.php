<?php

namespace App\Models;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * Unisender контакты
 * Class UnisenderCampaign
 * @package App\Models
 *
 * @property integer        $id
 * @property string         $campaign_name          Наименоание рассылки
 * @property Carbon         $send_date              Дата отправки
 * @property float          $open_rate              Открытая ставка
 * @property float          $ctr                    Сtr
 * @property integer        $sent                   Количество отправленных
 * @property integer        $delivered              Количество доставленных
 * @property float          $delivery_rate          Скорость доставки
 * @property integer        $opened                 Количество открытий
 * @property integer        $open_per_unique        Количество уникальных открытий
 * @property integer        $clicked                Количество кликов
 * @property integer        $clicks_per_unique      Количество уникальных кликов
 * @property float          $ctor                   CTOR
 *
 */
class UnisenderCampaign extends Model
{
    use HasFactory;

    protected $table = 'unisender_campaign';

    public $timestamps = false;

    protected $guarded = ['id'];

    protected $with = [
//        'unisender_participations',
    ];

    /**
     * Unisender участия
     * @return \Illuminate\Database\Eloquent\Relations\HasMany
     */
    public function unisender_participations()
    {
        return $this->hasMany(UnisenderParticipation::class, 'campaign_id');
    }
}
