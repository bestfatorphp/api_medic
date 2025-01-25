<?php

namespace App\Models;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * Unisender участия
 * Class UnisenderParticipation
 * @package App\Models
 *
 * @property integer        $id
 * @property integer        $campaign_id            ID unisender рассылки
 * @property string         $email                  E-mail
 * @property string         $result                 Результат отправки
 * @property Carbon         $update_time            Время обновления
 */
class UnisenderParticipation extends Model
{
    use HasFactory;

    protected $table = 'unisender_participation';

    public $timestamps = false;

    protected $guarded = ['id'];

    /**
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
     */
    public function unisender_participations()
    {
        return $this->belongsTo(UnisenderContact::class, 'email', 'email');
    }

    /**
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
     */
    public function campaign()
    {
        return $this->belongsTo(UnisenderCampaign::class, 'campaign_id');
    }
}
