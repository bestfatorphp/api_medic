<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Carbon\Carbon;

/**
 * Пректы MT
 * Class ProjectMT
 * @package App\Models
 *
 * @property integer        $id
 * @property string         $project            Проект
 * @property string         $wave               Волна
 * @property Carbon         $date_time          Дата и время активности
 */
class ProjectMT extends Model
{
    use HasFactory;

    protected $table = 'projects_mt';

    public $timestamps = false;

    protected $guarded = [];

    protected $with = [
//        'touches_mt'
    ];

    /**
     * Действия МТ
     * @return \Illuminate\Database\Eloquent\Relations\hasMany
     */
    public function touches_mt()
    {
        return $this->hasMany(ProjectTouchMT::class, 'project_id');
    }
}
