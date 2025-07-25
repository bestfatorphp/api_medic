<?php

namespace App\Models;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;


/**
 * Sendsay рассылки
 * Class SendsayIssue
 * @package App\Models
 *
 * @property integer        $id
 * @property string         $issue_name             Наименоание рассылки
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
class SendsayIssue extends Model
{
    use HasFactory;

    protected $table = 'sendsay_issue';

    protected $guarded = [];

    public $timestamps = false;


    protected $with = [
//        'sendsay_participations',
    ];

    /**
     * Unisender участия
     * @return \Illuminate\Database\Eloquent\Relations\HasMany
     */
    public function sendsay_participations()
    {
        return $this->hasMany(SendsayParticipation::class, 'issue_id');
    }
}
