<?php

namespace App\Models;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * Пользователи MT
 * Class UserMT
 * @package App\Models
 *
 * @property integer        $id
 * @property string         $full_name              ФИО
 * @property string         $email                  E-mail
 * @property string         $gender                 Пол
 * @property Carbon         $birth_date             Дата рождения
 * @property string         $specialty              Специальность
 * @property string         $interests              Интересы
 * @property string         $phone                  Телефон
 * @property string         $place_of_employment    Место работы
 * @property Carbon         $registration_date      Дата регистрации
 * @property string         $country                Страна
 * @property string         $region                 Регион
 * @property string         $city                   Город
 * @property string         $registration_website   Сайт регистрации
 * @property string         $acquisition_tool       Инструммент привлечения
 * @property string         $acquisition_method     Способ привлечения
 * @property string         $uf_utm_term            utm метка
 * @property string         $uf_utm_campaign        utm метка
 * @property string         $uf_utm_content         utm метка
 */
class UserMT extends Model
{
    use HasFactory;

    protected $table = 'users_mt';

    public $timestamps = false;

    protected $guarded = ['id'];

    protected $with = [
//        'actions_mt',
//        'common_database',
    ];

    /**
     * Действия МТ
     * @return \Illuminate\Database\Eloquent\Relations\HasMany
     */
    public function actions_mt()
    {
        return $this->hasMany(ActionMT::class, 'mt_user_id');
    }

    /**
     * Общая база
     * @return \Illuminate\Database\Eloquent\Relations\HasOne
     */
    public function common_database()
    {
        return $this->hasOne(CommonDatabase::class, 'email', 'email');
    }
}
