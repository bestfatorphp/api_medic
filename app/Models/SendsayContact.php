<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * Sendsay контакты
 * Class SendsayContact
 * @package App\Models
 *
 * @property integer        $id
 * @property string         $email                  E-mail
 * @property string         $email_status           Статус E-mail
 * @property string         $email_availability     Доступность E-mail
 */
class SendsayContact extends Model
{
    use HasFactory;

    protected $table = 'sendsay_contacts';

    protected $fillable = [
        'email',
        'email_status',
        'email_availability'
    ];

    public $timestamps = false;

    /**
     * Общая база
     * @return \Illuminate\Database\Eloquent\Relations\HasOne
     */
    public function common_database()
    {
        return $this->hasOne(CommonDatabase::class, 'email', 'email');
    }

    /**
     * Sendsay участия
     * @return \Illuminate\Database\Eloquent\Relations\HasMany
     */
    public function sendsay_participations()
    {
        return $this->hasMany(SendsayParticipation::class, 'email', 'email');
    }
}
