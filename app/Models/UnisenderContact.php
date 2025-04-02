<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * Unisender контакты
 * Class UnisenderContact
 * @package App\Models
 *
 * @property integer        $id
 * @property string         $email                  E-mail
 * @property string         $contact_status         Статус контакта
 * @property string         $email_status           Статус E-mail
 * @property string         $email_availability     Доступность E-mail
 */
class UnisenderContact extends Model
{
    use HasFactory;

    protected $table = 'unisender_contacts';

    public $timestamps = false;

    protected $guarded = ['id'];

    protected $with = [
//        'common_database',
        'unisender_participations',
    ];

    /**
     * Общая база
     * @return \Illuminate\Database\Eloquent\Relations\HasOne
     */
    public function common_database()
    {
        return $this->hasOne(CommonDatabase::class, 'email', 'email');
    }

    /**
     * Unisender участия
     * @return \Illuminate\Database\Eloquent\Relations\HasMany
     */
    public function unisender_participations()
    {
        return $this->hasMany(UnisenderParticipation::class, 'email', 'email');
    }
}
