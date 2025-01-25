<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

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
    use HasFactory;

    protected $table = 'doctors';

    public $timestamps = false;

    protected $guarded = ['id'];

    protected $with = [
//        'common_database'
    ];

    /**
     * Общая база
     * @return \Illuminate\Database\Eloquent\Relations\HasOne
     */
    public function common_database()
    {
        return $this->hasOne(CommonDatabase::class, 'email', 'email');
    }

}
