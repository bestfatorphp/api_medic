<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class UpdateProjectTouchesMtTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        //удаляем, чтобы создать и собрать данные заново, т.к. добавились поля, а update делать нельзя, можем вылететь по памяти
        Schema::dropIfExists('project_touches_mt');

        Schema::create('project_touches_mt', function (Blueprint $table) {
            $table->increments('id');
            $table->unsignedInteger('mt_user_id')->comment('ID пользователя МТ');
            $table->unsignedInteger('project_id')->comment('ID проекта МТ (волны)');
            $table->string('touch_type')->comment('Тип касания');
            $table->boolean('status')->comment('Статус касания');
            $table->dateTime('date_time')->nullable()->comment('Дата и время');
            $table->boolean('contact_verified')->default(false)->comment('Контакт подтвержден');
            $table->boolean('contact_allowed')->default(false)->comment('Контакт разрешен');
            $table->dateTime('contact_created_at')->nullable()->comment('Контакт создан');
            $table->string('contact_email')->nullable()->comment('Email для связи с таблицей doctors');

            $table->index('mt_user_id');
            $table->index('project_id');
            $table->index('contact_email');
            //уникальный индекс для предотвращения дублирования при пакетной вставке
            $table->unique(['mt_user_id', 'project_id', 'touch_type', 'date_time'], 'project_touches_mt_unique_touch');
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::dropIfExists('project_touches_mt');
    }
}
