<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Relations\BelongsTo;


/**
 * Касания проектов MT
 * Class ProjectTouchMT
 * @package App\Models
 *
 * @property integer        $id
 * @property integer        $mt_user_id         ID пользователя МТ
 * @property integer        $project_id         ID проекта МТ
 * @property string         $touch_type         Тип касания
 * @property boolean        $status             Статус касания
 * @property Carbon         $date_time          Дата и время действия
 */
class ProjectTouchMT extends Model
{
    use HasFactory;

    protected $table = 'project_touches_mt';

    public $timestamps = false;

    protected $guarded = ['id'];

    protected $with = [
      'user_mt'
    ];

    /**
     * Пользователь МТ
     * @return BelongsTo
     */
    public function user_mt(): BelongsTo
    {
        return $this->belongsTo(UserMT::class, 'mt_user_id');
    }
}
