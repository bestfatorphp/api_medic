<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Carbon\Carbon;


/**
 * Действия MT
 * Class ActionMT
 * @package App\Models
 *
 * @property integer        $id
 * @property integer        $mt_user_id         ID пользователя МТ
 * @property integer        $activity_id        ID активности МТ
 * @property Carbon         $date_time          Дата и время действия
 * @property float          $duration           Продолжительность
 * @property float          $result             Результат
 */
class ActionMT extends Model
{
    use HasFactory;

    protected $table = 'actions_mt';

    public $timestamps = false;

    protected $guarded = ['id'];
}
