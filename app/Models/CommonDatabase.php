<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Общая база
 * Class CommonDatabase
 * @package App\Models
 *
 * @property integer        $id
 * @property string         $email                  E-mail
 * @property string         $full_name              ФИО
 * @property string         $city                   Город
 * @property string         $region                 Регион
 * @property string         $country                Страна
 * @property string         $specialty              Специальность
 * @property string         $interests              Интересы
 * @property string         $phone                  Телефон
 * @property integer        $mt_user_id             ID полльзователя MT
 * @property Carbon         $registration_date      Дата регистрации
 * @property string         $gender                 Пол
 * @property Carbon         $birth_date             Дата рождения
 * @property string         $registration_website   Сайт регистрации
 * @property string         $acquisition_tool       Инструмент привлечения
 * @property string         $acquisition_method     Способ привлечения
 * @property string         $username               Никнэйм
 * @property string         $specialization         Название чатов в которых состоит пользователь
 * @property integer        $planned_actions        Запланированные действия
 * @property integer        $resulting_actions      Результативные действия
 * @property string         $verification_status    Статус верификации
 * @property boolean        $pharma                 Фарма
 * @property string         $email_status           Статус e-mail
 */
class CommonDatabase extends Model
{
    use HasFactory;

    protected $table = 'common_database';

    public $timestamps = false;

    protected $guarded = ['id'];

//    protected static function boot()
//    {
//        parent::boot();
//        static::deleting(function ($cd) {
//            $cd->doctor()->delete();
//            $cd->parsing_pd()->delete();
//            $cd->unisender_contact()->delete();
//            $cd->whatsapp_contact()->delete();
//        });
//    }


    /**
     * Пользователь МТ
     * @return BelongsTo
     */
    public function user_mt(): BelongsTo
    {
        return $this->belongsTo(UserMT::class, 'email', 'email');
    }

    /**
     * Врач
     * @return BelongsTo
     */
    public function doctor(): BelongsTo
    {
        return $this->belongsTo(Doctor::class, 'email', 'email');
    }

    /**
     * Парсинг PD
     * @return BelongsTo
     */
    public function parsing_pd(): BelongsTo
    {
        return $this->belongsTo(ParsingPD::class, 'mt_user_id', 'mt_user_id');
    }

    /**
     * Unisender контакт
     * @return BelongsTo
     */
    public function unisender_contact(): BelongsTo
    {
        return $this->belongsTo(UnisenderContact::class, 'email', 'email');
    }

    /**
     * WhatsApp контакт
     * @return BelongsTo
     */
    public function whatsapp_contact(): BelongsTo
    {
        return $this->belongsTo(WhatsAppContact::class, 'phone', 'phone');
    }

    /**
     * Действия МТ
     * @return HasMany
     */
    public function actions_mt(): HasMany
    {
        return $this->hasMany(ActionMT::class, 'mt_user_id', 'mt_user_id');
    }
}
