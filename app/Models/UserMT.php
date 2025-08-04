<?php

namespace App\Models;

use App\Traits\MutatorsHelper;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * Пользователи MT
 * Class UserMT
 * @package App\Models
 *
 * @property integer                $id
 * @property integer                $new_mt_id              ID пользователя нового МТ
 * @property integer                $old_mt_id              ID пользователя старого МТ
 * @property string                 $full_name              ФИО
 * @property string                 $email                  E-mail
 * @property string                 $gender                 Пол
 * @property Carbon                 $birth_date             Дата рождения
 * @property string                 $specialty              Специальность
 * @property string                 $interests              Интересы
 * @property string                 $phone                  Телефон
 * @property string                 $place_of_employment    Место работы
 * @property Carbon                 $registration_date      Дата регистрации
 * @property string                 $country                Страна
 * @property string                 $region                 Регион
 * @property string                 $city                   Город
 * @property string                 $registration_website   Сайт регистрации
 * @property string                 $acquisition_tool       Инструммент привлечения
 * @property string                 $acquisition_method     Способ привлечения
 * @property string                 $uf_utm_term            utm метка
 * @property string                 $uf_utm_campaign        utm метка
 * @property string                 $uf_utm_content         utm метка
 *
 * @property CommonDatabase         $common_database        Общая база
 * @property ActionMT               $actions_mt             Действия МТ
 */
class UserMT extends Model
{
    use HasFactory, MutatorsHelper;

    protected $table = 'users_mt';

    public $timestamps = false;

    protected $guarded = ['id'];

    protected $with = [
//        'actions_mt',
//        'actions_new_mt',
//        'common_database',
    ];

//    protected static function boot()
//    {
//        parent::boot();
//        static::deleting(function ($user) {
//            $user->actions_mt()->delete();
//            $user->actions_new_mt()->delete();
//            $user->common_database()->delete();
//        });
//    }

    /**
     * Действия МТ
     * @return HasMany
     */
    public function actions_mt(): HasMany
    {
        return $this->hasMany(ActionMT::class, 'mt_user_id');
    }

    /**
     * Общая база
     * @return HasOne
     */
    public function common_database(): HasOne
    {
        return $this->hasOne(CommonDatabase::class, 'email', 'email');
    }

    /**
     * Мутатор для email
     */
    public function setEmailAttribute($value)
    {
        if ($this->shouldUpdateFieldIfNotNull($value, $this->attributes['email'] ?? null)) {
            $this->attributes['email'] = $value;
        }
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
     * Мутатор для new_mt_id
     */
    public function setNewMtIdAttribute($value)
    {
        if ($this->shouldUpdateFieldIfNotNull($value, $this->attributes['new_mt_id'] ?? null)) {
            $this->attributes['new_mt_id'] = $value;
        }
    }

    /**
     * Мутатор для old_mt_id
     */
    public function setOldMtIdAttribute($value)
    {
        if ($this->shouldUpdateFieldIfNotNull($value, $this->attributes['old_mt_id'] ?? null)) {
            $this->attributes['old_mt_id'] = $value;
        }
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
        $this->attributes['registration_date'] = Carbon::parse($value)->format('Y-m-d');
    }
}
