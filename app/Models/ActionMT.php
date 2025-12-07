<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;


/**
 * Действия MT
 * Class ActionMT
 * @package App\Models
 *
 * @property integer        $id
 * @property string         $email              E-mail
 * @property integer        $mt_user_id         ID пользователя МТ
 * @property integer        $activity_id        ID активности МТ
 * @property Carbon         $date_time          Дата и время действия
 * @property float          $duration           Продолжительность
 * @property float          $result             Результат
 * @property string         $format             Формат регистрации на мероприятие
 * @property Carbon         $registered_at      Дата и время регистрации на мероприятие
 *
 * @property UserMT         $user_mt
 * @property CommonDatabase $common_database
 * @property CommonDatabase $common_database_helios
 */
class ActionMT extends Model
{
    use HasFactory;

    protected $table = 'actions_mt';

    public $timestamps = false;

    protected $guarded = ['id'];

    protected $with = [
//        'user_mt',
//        'activity'
    ];

    /**
     * Пользователь МТ
     * @return BelongsTo
     */
    public function user_mt(): BelongsTo
    {
        return $this->belongsTo(UserMT::class, 'mt_user_id');
    }

    /**
     * Общие данные пользователя
     * @return BelongsTo
     */
    public function common_database(): BelongsTo
    {
        return $this->belongsTo(CommonDatabase::class, 'mt_user_id', 'mt_user_id');
    }

    /**
     * Общие данные пользователя Helios
     * @return BelongsTo
     */
    public function common_database_helios(): BelongsTo
    {
        return $this->belongsTo(CommonDatabase::class, 'old_mt_id', 'old_mt_id');
    }

    /**
     * @return BelongsTo
     */
    public function activity(): BelongsTo
    {
        return $this->belongsTo(ActivityMT::class, 'activity_id');
    }
}
