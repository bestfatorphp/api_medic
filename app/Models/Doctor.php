<?php

namespace App\Models;

use App\Traits\MutatorsHelper;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * Врачи
 * Class Doctor
 * @package App\Models
 *
 * @property integer        $id
 * @property string         $email          E-mail
 * @property string         $full_name      ФИО
 * @property string         $city           Город
 * @property string         $region         Регион
 * @property string         $country        Страна
 * @property string         $specialty      Специальность
 * @property string         $interests      Интересы
 * @property string         $phone          Телефон
 */
class Doctor extends Model
{
    use HasFactory, MutatorsHelper;

    protected $table = 'doctors';

    public $timestamps = false;

    protected $guarded = ['id'];

    protected $with = [
//        'common_database'
    ];

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
        if ($this->shouldUpdateFieldIfNull($value, $this->attributes['email'] ?? null)) {
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
     * Мутатор для phone
     */
    public function setPhoneAttribute($value)
    {
        $value = $value ? preg_replace('/[^0-9]/', '', $value) : null;
        if ($this->shouldUpdateFieldByLength($value, $this->attributes['phone'] ?? null)) {
            $this->attributes['phone'] = $value;
        }
    }

}
