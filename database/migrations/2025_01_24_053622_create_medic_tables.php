<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateMedicTables extends Migration
{
    /**
     * Run the migrations.
     * foreign не назначал, нужно разобраться, что к чему
     * @return void
     */
    public function up()
    {
        //Врачи
        Schema::create('doctors', function (Blueprint $table) {
            $table->increments('id');
            $table->string('email')->unique()->comment('E-mail');
            $table->string('full_name')->comment('ФИО');
            $table->string('city')->comment('Город');
            $table->string('region')->comment('Регион');
            $table->string('country')->comment('Страна');
            $table->string('specialty')->comment('Специальность');
            $table->string('interests')->comment('Интересы');
            $table->string('phone')->comment('Телефон');
        });

        //Фарма
        Schema::create('pharma', function (Blueprint $table) {
            $table->string('domain')->primary()->comment('Домен');
            $table->string('name')->comment('Название Фарма');
        });

        //Активности МТ
        Schema::create('activities_mt', function (Blueprint $table) {
            $table->increments('id');
            $table->string('type')->comment('Тип активности');
            $table->string('name')->comment('Название активности');
            $table->dateTime('date_time')->comment('Дата и время');
            $table->boolean('is_online')->comment('Очное');
        });

        //Пользователи MT
        Schema::create('users_mt', function (Blueprint $table) {
            $table->increments('id');
            $table->string('full_name')->comment('ФИО');
            $table->string('email')->unique()->comment('E-mail');
            $table->string('gender')->comment('Пол');
            $table->date('birth_date')->comment('Дата рождения');
            $table->string('specialty')->comment('Специальность');
            $table->string('interests')->comment('Интересы');
            $table->string('phone')->comment('Телефон');
            $table->string('place_of_employment')->comment('Место работы');
            $table->date('registration_date')->comment('Дата регистрации');
            $table->string('country')->comment('Страна');
            $table->string('region')->comment('Регион');
            $table->string('city')->comment('Город');
            $table->string('registration_website')->comment('Сайт регистрации');
            $table->string('acquisition_tool')->comment('Инструммент привлечения');
            $table->string('acquisition_method')->comment('Способ привлечения');
            $table->string('uf_utm_term')->comment('utm метка');
            $table->string('uf_utm_campaign')->comment('utm метка');
            $table->string('uf_utm_content')->comment('utm метка');
        });

        //Действия МТ
        Schema::create('actions_mt', function (Blueprint $table) {
            $table->increments('id');
            $table->unsignedInteger('mt_user_id')->comment('ID пользователя МТ');
            $table->unsignedInteger('activity_id')->comment('ID активности МТ');
            $table->dateTime('date_time')->comment('Дата и время');
            $table->float('duration')->comment('Продолжительность');
            $table->float('result')->comment('Результат');
        });

        //Unisender контакты
        Schema::create('unisender_contacts', function (Blueprint $table) {
            $table->increments('id');
            $table->string('email')->comment('E-mail');
            $table->string('contact_status')->comment('Статус контакта');
            $table->string('email_status')->comment('Статус E-mail');
            $table->boolean('email_availability')->comment('Доступность E-mail');
        });

        //Unisender рассылки
        Schema::create('unisender_campaign', function (Blueprint $table) {
            $table->increments('id');
            $table->string('campaign_name')->comment('Наименоание рассылки');
            $table->dateTime('send_date')->comment('Дата отправки');
            $table->float('open_rate')->comment('Открытая ставка');
            $table->float('ctr')->comment('Сtr');
            $table->integer('sent')->comment('Количество отправленных');
            $table->integer('delivered')->comment('Количество доставленных');
            $table->float('delivery_rate')->comment('Скорость доставки');
            $table->integer('opened')->comment('Количество открытий');
            $table->integer('open_per_unique')->comment('Количество уникальных открытий');
            $table->integer('clicked')->comment('Количество кликов');
            $table->integer('clicks_per_unique')->comment('Количество уникальных кликов');
            $table->float('ctor')->comment('CTOR');
        });

        //Unisender участия
        Schema::create('unisender_participation', function (Blueprint $table) {
            $table->increments('id');
            $table->unsignedInteger('campaign_id')->comment('ID unisender рассылки');
            $table->string('email')->comment('E-mail');
            $table->string('result')->comment('Результат отправки');
            $table->dateTime('update_time')->comment('Время обновления');
        });

        //Общая база
        Schema::create('common_database', function (Blueprint $table) {
            $table->increments('id');
            $table->string('email')->unique()->comment('E-mail');
            $table->string('full_name')->comment('ФИО');
            $table->string('city')->comment('Город');
            $table->string('region')->comment('Регион');
            $table->string('country')->comment('Страна');
            $table->string('specialty')->comment('Специальность');
            $table->string('interests')->comment('Интересы');
            $table->string('phone')->comment('Телефон');
            $table->unsignedInteger('mt_user_id')->unique()->comment('ID полльзователя MT');
            $table->dateTime('registration_date')->comment('Дата регистрации');
            $table->string('gender')->comment('Пол');
            $table->dateTime('birth_date')->comment('Дата рождения');
            $table->string('registration_website')->comment('Сайт регистрации');
            $table->string('acquisition_tool')->comment('Инструмент привлечения');
            $table->string('acquisition_method')->comment('Способ привлечения');
            $table->integer('planned_actions')->comment('Запланированные действия');
            $table->integer('resulting_actions')->nullable()->comment('Результативные действия');
            $table->string('verification_status')->comment('Статус верификации');
            $table->boolean('pharma')->comment('Фарма');
            $table->string('email_status')->comment('Статус e-mail');
        });

        //Парсинг PD
        Schema::create('parsing_pd', function (Blueprint $table) {
            $table->increments('id');
            $table->unsignedInteger('mt_user_id')->comment('ID полльзователя MT');
            $table->string('difference')->comment('Различие');
            $table->string('pd_workplace')->comment('Место работы PD');
            $table->string('pd_address_workplace')->comment('Адрес места работы PD');
        });
    }

    public function down()
    {
        Schema::dropIfExists('parsing_pd');
        Schema::dropIfExists('common_database');
        Schema::dropIfExists('unisender_participation');
        Schema::dropIfExists('unisender_campaign');
        Schema::dropIfExists('unisender_contacts');
        Schema::dropIfExists('actions_mt');
        Schema::dropIfExists('users_mt');
        Schema::dropIfExists('activities_mt');
        Schema::dropIfExists('pharma');
        Schema::dropIfExists('doctors');
    }
}
