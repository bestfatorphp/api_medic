<?php

namespace App\Models;

use App\Traits\MutatorsHelper;
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
 * @property integer                $id
 * @property integer                $new_mt_id              ID пользователя нового МТ
 * @property integer                $old_mt_id              ID пользователя старого МТ
 * @property string                 $email                  E-mail
 * @property string                 $full_name              ФИО
 * @property string                 $city                   Город
 * @property string                 $region                 Регион
 * @property string                 $country                Страна
 * @property string                 $specialty              Специальность
 * @property string                 $interests              Интересы
 * @property string                 $phone                  Телефон
 * @property integer                $mt_user_id             ID полльзователя MT
 * @property Carbon                 $registration_date      Дата регистрации
 * @property string                 $gender                 Пол
 * @property Carbon                 $birth_date             Дата рождения
 * @property string                 $registration_website   Сайт регистрации
 * @property string                 $acquisition_tool       Инструмент привлечения
 * @property string                 $acquisition_method     Способ привлечения
 * @property string                 $username               Никнэйм
 * @property string                 $specialization         Название чатов в которых состоит пользователь
 * @property integer                $planned_actions        Запланированные действия
 * @property integer                $resulting_actions      Результативные действия
 * @property string                 $verification_status    Статус верификации
 * @property boolean                $pharma                 Фарма
 * @property string                 $email_status           Статус e-mail
 *
 * @property UserMT                 $user_mt
 * @property Doctor                 $doctor
 * @property ParsingPD              $parsing_pd
 * @property UnisenderContact       $unisender_contact
 * @property UserChat               $user_chats
 * @property WhatsAppContact        $whatsapp_contact
 * @property ActionMT               $actions_mt
 * @property ActionMT               $actions_mt_helios
 */
class CommonDatabase extends Model
{
    use HasFactory, MutatorsHelper;

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
     * Чаты пользователя
     * @return BelongsTo
     */
    public function user_chats(): BelongsTo
    {
        return $this->belongsTo(UserChat::class, 'email', 'email');
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

    /**
     * Действия МТ Helios
     * @return HasMany
     */
    public function actions_mt_helios(): HasMany
    {
        return $this->hasMany(ActionMT::class, 'old_mt_id', 'old_mt_id');
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

    /**
     * Мутатор для city
     */
    public function setCityAttribute($value)
    {
        if ($this->shouldUpdateFieldByLength($value, $this->attributes['city'] ?? null)) {
            $this->attributes['city'] = $this->toUpperCase(
                $value,
                $this->attributes['city'] ?? null
            );
        }
    }

    /**
     * Мутатор для region
     */
    public function setRegionAttribute($value)
    {
        if ($this->shouldUpdateFieldByLength($value, $this->attributes['region'] ?? null)) {
            $this->attributes['region'] = $this->toUpperCase(
                $value,
                $this->attributes['region'] ?? null
            );
        }
    }

    /**
     * Мутатор для country
     */
    public function setCountryAttribute($value)
    {
        if ($this->shouldUpdateFieldByLength($value, $this->attributes['country'] ?? null)) {
            $this->attributes['country'] = $this->toUpperCase(
                $value,
                $this->attributes['country'] ?? null
            );
        }
    }

    /**
     * Мутатор для specialty
     */
    public function setSpecialtyAttribute($value)
    {
        if ($this->shouldUpdateFieldByLength($value, $this->attributes['specialty'] ?? null)) {
            $this->attributes['specialty'] = $this->toUpperCase(
                $value,
                $this->attributes['specialty'] ?? null
            );
        }
    }

    /**
     * Мутатор для specialization
     */
    public function setSpecializationAttribute($value)
    {
        $this->attributes['specialization'] = $this->mergeCommaSeparatedValues(
            $value,
            $this->attributes['specialization'] ?? null
        );
    }

    /**
     * Мутатор для acquisition_tool
     */
    public function setAcquisitionToolAttribute($value)
    {
        $this->attributes['acquisition_tool'] = $this->mergeCommaSeparatedValues(
            $value,
            $this->attributes['acquisition_tool'] ?? null
        );
    }

    /**
     * Мутатор для phone
     */
    public function setPhoneAttribute($value)
    {
        $this->attributes['phone'] = $value ? preg_replace('/[^0-9]/', '', $value) : null;
    }

    /**
     * Мутатор для registration_date
     */
    public function setRegistrationDateAttribute($value)
    {
        $this->attributes['registration_date'] = Carbon::parse($value);
    }
}
