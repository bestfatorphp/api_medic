<?php

namespace App\Models;

use App\Traits\MutatorsHelper;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Общая база
 * Class CommonDatabase
 * @package App\Models
 *
 * @property integer            $id
 * @property string             $full_name              ФИО
 * @property string             $email                  E-mail
 * @property string             $username               Никнэйм
 * @property string             $specialization         Название чата
 * @property string             $channel                Канал
 * @property Carbon             $registration_date      Дата регистрации
 * @property CommonDatabase     $common_database        Общие данные
 */
class UserChat extends Model
{
    use HasFactory, MutatorsHelper;

    protected $table = 'users_chats';

    public $timestamps = false;

    protected $guarded = ['id'];


    /**
     * Пользователь МТ
     * @return BelongsTo
     */
    public function common_database(): BelongsTo
    {
        return $this->belongsTo(CommonDatabase::class, 'email', 'email');
    }

    /**
     * Мутатор для full_name
     */
    public function setFullNameAttribute($value)
    {
        if ($this->shouldUpdateFieldByLength($value, $this->attributes['full_name'] ?? null)) {
            $this->attributes['full_name'] = $this->toUpperCase(
                $value,
                $this->attributes['full_name'] ?? null
            );
        }
    }
}
