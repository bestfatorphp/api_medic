<?php

namespace App\Models;

use App\Traits\MutatorsHelper;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * WhatsApp контакты
 * Class WhatsAppContact
 * @package App\Models
 *
 * @property integer                    $id
 * @property string                     $phone                  Телефон
 *
 * @property CommonDatabase             $common_database
 * @property WhatsAppParticipation      $whatsapp_participations
 */
class WhatsAppContact extends Model
{
    use HasFactory, MutatorsHelper;

    protected $table = 'whatsapp_contacts';

    public $timestamps = false;

    protected $guarded = ['id'];

    protected $fillable = ['phone'];

    protected $with = [
//        'common_database',
//        'whatsapp_participations',
    ];

    /**
     * Общая база
     * @return \Illuminate\Database\Eloquent\Relations\HasOne
     */
    public function common_database()
    {
        return $this->hasOne(CommonDatabase::class, 'phone', 'phone');
    }

    /**
     * WhatsApp участия
     * @return \Illuminate\Database\Eloquent\Relations\HasMany
     */
    public function whatsapp_participations()
    {
        return $this->hasMany(WhatsAppParticipation::class, 'phone', 'phone');
    }

    /**
     * Мутатор для phone
     */
    public function setPhoneAttribute($value)
    {
        $this->attributes['phone'] = $this->parsePhone($value, $this->attributes['phone'] ?? null);
    }
}
