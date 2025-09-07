<?php

namespace App\Models;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * Sendsay участия
 * Class SendsayParticipation
 * @package App\Models
 *
 * @property integer        $id
 * @property integer        $issue_id               ID sendsay рассылки
 * @property string         $email                  E-mail
 * @property string         $result                 Результат отправки
 * @property Carbon         $update_time            Время обновления
 * @property string         $sendsay_key             Ключ
 */
class SendsayParticipation extends Model
{
    use HasFactory;

    protected $table = 'sendsay_participation';

    protected $fillable = [
        'issue_id',
        'email',
        'result',
        'update_time',
        'sendsay_key'
    ];

    public $timestamps = false;

    /**
     * Sendsay контакт
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
     */
    public function sendsay_contact()
    {
        return $this->belongsTo(SendsayContact::class, 'email', 'email');
    }

    /**
     * Sendsay рассылка
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
     */
    public function sendsay_issue()
    {
        return $this->belongsTo(SendsayIssue::class, 'issue_id');
    }
}
