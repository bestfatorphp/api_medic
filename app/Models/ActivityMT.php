<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Carbon\Carbon;

/**
 * Активности MT
 * Class ActivityMT
 * @package App\Models
 *
 * @property integer        $id
 * @property string         $type               Тип активности
 * @property string         $name               Название активности
 * @property Carbon         $date_time          Дата и время активности
 * @property boolean        $is_online          Очное
 */
class ActivityMT extends Model
{
    use HasFactory;

    protected $table = 'activities_mt';

    public $timestamps = false;

    protected $guarded = ['id'];

    protected $with = [
//        'actions_mt'
    ];

    /**
     * Действия МТ
     * @return \Illuminate\Database\Eloquent\Relations\hasMany
     */
    public function actions_mt()
    {
        return $this->hasMany(ActionMT::class, 'activity_id');
    }
}
