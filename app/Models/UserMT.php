<?php

namespace App\Models;

use App\Traits\MutatorsHelper;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Facades\Log;

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
 * @property array                  $medtouch_uuids         medtouch_uuids пользователя, в связи с дубликатами
 * @property array                  $oralink_uuids          oralink_uuids пользователя, в связи с дубликатами
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

    protected $casts = [
        'medtouch_uuids' => 'array',
        'oralink_uuids' => 'array',
    ];

    protected $attributes = [
        'medtouch_uuids' => '[]',
        'oralink_uuids' => '[]',
    ];

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


    protected $appends = [
        'verification_status',
        'category'
    ];

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
     * Мутатор для medtouch_uuids
     */
    public function setMedtouchUuidsAttribute($value)
    {
        $this->addUuidToArray('medtouch_uuids', $value);
    }

    /**
     * Мутатор для oralink_uuids
     */
    public function setOralinkUuidsAttribute($value)
    {
        $this->addUuidToArray('oralink_uuids', $value);
    }

    /**
     * @param string $field
     * @param $value
     */
    protected function addUuidToArray(string $field, $value): void
    {
        if (!$value) {
            return;
        }

        $currentJson = $this->getAttributeFromArray($field) ?? '[]';
        $currentUuids = $this->jsonToArray($currentJson);

        $uuidsToAdd = $this->extractValidUuids($value);

        if (empty($uuidsToAdd)) {
            return;
        }

        $newUuids = array_unique(array_merge($currentUuids, $uuidsToAdd));

        if (count($newUuids) !== count($currentUuids)) {
            $this->attributes[$field] = json_encode(array_values($newUuids));
        }
    }

    /**
     * @param $value
     * @return array
     */
    protected function jsonToArray($value): array
    {
        if (is_array($value)) {
            return $value;
        }

        if (is_string($value) && !empty($value)) {
            $decoded = json_decode($value, true);
            return is_array($decoded) ? $decoded : [];
        }

        return [];
    }

    /**
     * @param $value
     * @return array
     */
    protected function extractValidUuids($value): array
    {
        $uuids = [];

        if (is_string($value)) {
            if (str_starts_with($value, '[')) {
                $decoded = json_decode($value, true);
                if (is_array($decoded)) {
                    foreach ($decoded as $uuid) {
                        if ($this->isValidUuid($uuid)) {
                            $uuids[] = $uuid;
                        }
                    }
                }
            } elseif ($this->isValidUuid($value)) {
                $uuids[] = $value;
            }
        } elseif (is_array($value)) {
            foreach ($value as $uuid) {
                if ($this->isValidUuid($uuid)) {
                    $uuids[] = $uuid;
                }
            }
        }

        return $uuids;
    }

    /**
     * @param $value
     * @return bool
     */
    public function isValidUuid($value): bool
    {
        if (!is_string($value) || strlen($value) !== 36) {
            return false;
        }

        if (!preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $value)) {
            Log::error("Не валидный uuid: {$value}");
            return false;
        }

        return $value[8] === '-' && $value[13] === '-' && $value[18] === '-' && $value[23] === '-';
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

    /**
     * Гетер verification_status
     */
    public function getVerificationStatusAttribute(): ?string
    {
        return $this->common_database->verification_status;
    }

    /**
     * Гетер category
     * @param $value
     * @return string|null
     */
    public function getCategoryAttribute($value): ?string
    {
        return $this->common_database->category;
    }
}
