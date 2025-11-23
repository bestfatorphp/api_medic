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
 * @property Carbon                 $last_login             Последняя авторизация на портале
 * @property string                 $medtouch_uuid
 * @property string                 $oralink_uuid
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
        return $this->hasMany(ActionMT::class, 'email');
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
     * Мутатор для new_mt_id
     */
    public function setNewMtIdAttribute($value)
    {
        if ($this->shouldUpdateFieldIfNull($value, $this->attributes['new_mt_id'] ?? null)) {
            $this->attributes['new_mt_id'] = $value;
        }
    }

    /**
     * Мутатор для old_mt_id
     */
    public function setOldMtIdAttribute($value)
    {
        if ($this->shouldUpdateFieldIfNull($value, $this->attributes['old_mt_id'] ?? null)) {
            $this->attributes['old_mt_id'] = $value;
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
     * Мутатор для email
     */
    public function setEmailAttribute($value)
    {
        if ($this->shouldUpdateFieldIfNull($value, $this->attributes['email'] ?? null)) {
            $this->attributes['email'] = strtolower($value);
        }
    }

    /**
     * Мутатор для gender
     */
    public function setGenderAttribute($value)
    {
        $this->attributes['gender'] = $this->genderCommon($value);
    }

    /**
     * Мутатор для birth_date
     */
    public function setBirthDateAttribute($value)
    {
        if ($this->shouldUpdateFieldIfNull($value, $this->attributes['birth_date'] ?? null)) {
            $this->attributes['birth_date'] = $value;
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
     * Мутатор для phone
     */
    public function setPhoneAttribute($value)
    {
        $this->attributes['phone'] = $this->parsePhone($value, $this->attributes['phone'] ?? null);
    }

    /**
     * Мутатор для place_of_employment
     */
    public function setPlaceOfEmploymentAttribute($value)
    {
        if ($this->shouldUpdateFieldByLength($value, $this->attributes['place_of_employment'] ?? null)) {
            $this->attributes['place_of_employment'] = $this->toUpperCase(
                $value,
                $this->attributes['place_of_employment'] ?? null
            );
        }
    }

    /**
     * Мутатор для registration_date
     */
    public function setRegistrationDateAttribute($value)
    {
        $this->attributes['registration_date'] = Carbon::parse($value)->format('Y-m-d');
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
     * Мутатор для registration_website
     */
    public function setRegistrationWebsiteAttribute($value)
    {
        if ($this->shouldUpdateFieldIfNull($value, $this->attributes['registration_website'] ?? null)) {
            $this->attributes['registration_website'] = $value;
        }
    }

    /**
     * Мутатор для uf_utm_term
     */
    public function setUfUtmTermAttribute($value)
    {
        if ($this->shouldUpdateFieldIfNull($value, $this->attributes['uf_utm_term'] ?? null)) {
            $this->attributes['uf_utm_term'] = $value;
        }
    }

    /**
     * Мутатор для uf_utm_campaign
     */
    public function setUfUtmCampaignAttribute($value)
    {
        if ($this->shouldUpdateFieldIfNull($value, $this->attributes['uf_utm_campaign'] ?? null)) {
            $this->attributes['uf_utm_campaign'] = $value;
        }
    }

    /**
     * Мутатор для uf_utm_content
     */
    public function setUfUtmContentAttribute($value)
    {
        if ($this->shouldUpdateFieldIfNull($value, $this->attributes['uf_utm_content'] ?? null)) {
            $this->attributes['uf_utm_content'] = $value;
        }
    }
}
