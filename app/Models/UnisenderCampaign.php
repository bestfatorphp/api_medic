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
 * @property bool           $statistics_received    Статистика была получена
 *
 */
class UnisenderCampaign extends Model
{
    use HasFactory;

    /**
     * @var string
     */
    protected $table = 'unisender_campaign';

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
        'statistics_received' => 'boolean',
    ];

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
