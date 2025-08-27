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
 * @property integer        $mt_user_id             ID пользователя МТ
 * @property integer        $project_id             ID проекта МТ
 * @property string         $touch_type             Тип касания
 * @property boolean        $status                 Статус касания
 * @property Carbon         $date_time              Дата и время действия
 * @property boolean        $contact_verified       Контакт подтвержден
 * @property boolean        $contact_allowed        Контакт разрешен
 * @property boolean        $contact_created_at     Контакт создан
 * @property boolean        $contact_email          Email для связи с таблицей doctors
 *
 * @property UserMT         $user_mt                Пользователь МТ
 * @property Doctor         $contact                Врач
 * @property ProjectMT      $project                Проект
 */
class ProjectTouchMT extends Model
{
    use HasFactory;

    protected $table = 'project_touches_mt';

    public $timestamps = false;

    protected $guarded = ['id'];

    protected $with = [
        'user_mt',
        'contact',
        'project'
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
     * Контакт врача
     * @return BelongsTo
     */
    public function contact(): BelongsTo
    {
        return $this->belongsTo(Doctor::class, 'contact_email', 'email');
    }

    /**
     * Проект
     * @return BelongsTo
     */
    public function project(): BelongsTo
    {
        return $this->belongsTo(ProjectMT::class, 'project_id');
    }
}
