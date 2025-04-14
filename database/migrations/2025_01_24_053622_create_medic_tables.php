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
            $table->string('type')->nullable()->comment('Тип активности');
            $table->string('name')->nullable()->comment('Название активности');
            $table->dateTime('date_time')->nullable()->comment('Дата и время');
            $table->boolean('is_online')->default(false)->comment('Очное');
        });

        //Пользователи MT
        Schema::create('users_mt', function (Blueprint $table) {
            $table->increments('id');
            $table->string('full_name')->nullable()->comment('ФИО');
            $table->string('email')->unique()->comment('E-mail');
            $table->string('gender')->nullable()->comment('Пол');
            $table->date('birth_date')->nullable()->comment('Дата рождения');
            $table->string('specialty')->nullable()->comment('Специальность');
            $table->string('interests')->nullable()->comment('Интересы');
            $table->string('phone')->nullable()->comment('Телефон');
            $table->string('place_of_employment')->nullable()->comment('Место работы');
            $table->date('registration_date')->nullable()->comment('Дата регистрации');
            $table->string('country')->nullable()->comment('Страна');
            $table->string('region')->nullable()->comment('Регион');
            $table->string('city')->nullable()->comment('Город');
            $table->string('registration_website')->nullable()->comment('Сайт регистрации');
            $table->string('acquisition_tool')->nullable()->comment('Инструммент привлечения');
            $table->string('acquisition_method')->nullable()->comment('Способ привлечения');
            $table->string('uf_utm_term')->nullable()->comment('utm метка');
            $table->string('uf_utm_campaign')->nullable()->comment('utm метка');
            $table->string('uf_utm_content')->nullable()->comment('utm метка');
        });

        //Действия МТ
        Schema::create('actions_mt', function (Blueprint $table) {
            $table->increments('id');
            $table->unsignedInteger('mt_user_id')->comment('ID пользователя МТ');
            $table->unsignedInteger('activity_id')->comment('ID активности МТ');
            $table->dateTime('date_time')->nullable()->comment('Дата и время');
            $table->float('duration')->nullable()->comment('Продолжительность');
            $table->float('result')->nullable()->comment('Результат');

            //уникальный индекс для предотвращения дублирования при пакетной записи
            $table->unique(['mt_user_id', 'activity_id', 'date_time'], 'unique_action');
        });

        //Unisender контакты
        Schema::create('unisender_contacts', function (Blueprint $table) {
            $table->increments('id');
            $table->string('email')->unique()->comment('E-mail');
            $table->string('contact_status')->nullable()->comment('Статус контакта');
            $table->string('email_status')->nullable()->comment('Статус E-mail');
            $table->boolean('email_availability')->nullable()->comment('Доступность E-mail');
        });

        //Unisender рассылки
        Schema::create('unisender_campaign', function (Blueprint $table) {
            $table->increments('id');
            $table->string('campaign_name')->nullable()->comment('Наименоание рассылки');
            $table->dateTime('send_date')->nullable()->comment('Дата отправки');
            $table->float('open_rate')->nullable()->comment('Открытия, значение в процентах');
            $table->float('ctr')->nullable()->comment('Число переходов по ссылкам писем/число доставленных в процентах');
            $table->integer('sent')->nullable()->comment('Количество отправленных');
            $table->integer('delivered')->nullable()->comment('Количество доставленных');
            $table->float('delivery_rate')->nullable()->comment('Доставка, значение в процентах');
            $table->integer('opened')->nullable()->comment('Количество открытий');
            $table->integer('open_per_unique')->nullable()->comment('Количество уникальных открытий');
            $table->integer('clicked')->nullable()->comment('Количество кликов');
            $table->integer('clicks_per_unique')->nullable()->comment('Количество уникальных кликов');
            $table->float('ctor')->nullable()->comment('Число переходов по ссылкам из писем/число открытых в процентах');
            $table->boolean('statistics_received')->default(false)->comment('Статсистика по кампании была получена');
        });

        //Unisender участия
        Schema::create('unisender_participation', function (Blueprint $table) {
            $table->increments('id');
            $table->unsignedInteger('campaign_id')->comment('ID unisender рассылки');
            $table->string('email')->comment('E-mail');
            $table->string('result')->nullable()->comment('Результат отправки');
            $table->dateTime('update_time')->nullable()->comment('Время обновления');
        });

        //Whatsapp контакты
        Schema::create('whatsapp_contacts', function (Blueprint $table) {
            $table->increments('id');
            $table->string('phone')->unique()->comment('Телефон');
        });

        //Whatsapp рассылки (чаты)
        Schema::create('whatsapp_campaign', function (Blueprint $table) {
            $table->increments('id');
            $table->text('campaign_name')->nullable()->comment('Наименоание чата или сообщение рассылки');
            $table->dateTime('send_date')->nullable()->comment('Время отправки первого сообщения');
        });

        //Whatsapp участия (сообщения чата)
        Schema::create('whatsapp_participation', function (Blueprint $table) {
            $table->increments('id');
            $table->integer('campaign_id')->comment('ID чата рассылки');
            $table->string('phone')->comment('Телефон');
            $table->dateTime('send_date')->nullable()->comment('Дата отправки сообщения');
        });

        //Общая база
        Schema::create('common_database', function (Blueprint $table) {
            $table->increments('id');
            $table->string('email')->unique()->comment('E-mail');
            $table->string('full_name')->nullable()->comment('ФИО');
            $table->string('city')->nullable()->comment('Город');
            $table->string('region')->nullable()->comment('Регион');
            $table->string('country')->nullable()->comment('Страна');
            $table->string('specialty')->nullable()->comment('Специальность');
            $table->string('interests')->nullable()->comment('Интересы');
            $table->string('phone')->nullable()->comment('Телефон');
            $table->unsignedInteger('mt_user_id')->nullable()->unique()->comment('ID полльзователя MT');
            $table->dateTime('registration_date')->nullable()->comment('Дата регистрации');
            $table->string('gender')->nullable()->comment('Пол');
            $table->dateTime('birth_date')->nullable()->comment('Дата рождения');
            $table->string('registration_website')->nullable()->comment('Сайт регистрации');
            $table->string('acquisition_tool')->nullable()->comment('Инструмент привлечения');
            $table->string('acquisition_method')->nullable()->comment('Способ привлечения');
            $table->string('username')->nullable()->comment('Никнэйм');
            $table->text('specialization')->nullable()->comment('Название чатов в которых состоит пользователь');
            $table->integer('planned_actions')->nullable()->comment('Запланированные действия');
            $table->integer('resulting_actions')->nullable()->comment('Результативные действия');
            $table->string('verification_status')->nullable()->comment('Статус верификации');
            $table->boolean('pharma')->default(false)->comment('Фарма');
            $table->string('email_status')->nullable()->comment('Статус e-mail');
        });

        //Парсинг PD
        Schema::create('parsing_pd', function (Blueprint $table) {
            $table->increments('id');
            $table->unsignedInteger('mt_user_id')->comment('ID полльзователя MT');
            $table->string('difference')->nullable()->comment('Различие');
            $table->string('pd_workplace')->nullable()->comment('Место работы PD');
            $table->string('pd_address_workplace')->nullable()->comment('Адрес места работы PD');
        });
    }

    public function down()
    {
        if (Schema::hasTable('actions_mt')) {
            Schema::table('actions_mt', function (Blueprint $table) {
                $table->dropUnique('unique_action');
            });
        }

        Schema::dropIfExists('parsing_pd');
        Schema::dropIfExists('common_database');
        Schema::dropIfExists('unisender_participation');
        Schema::dropIfExists('unisender_campaign');
        Schema::dropIfExists('unisender_contacts');
        Schema::dropIfExists('whatsapp_participation');
        Schema::dropIfExists('whatsapp_campaign');
        Schema::dropIfExists('whatsapp_contacts');
        Schema::dropIfExists('actions_mt');
        Schema::dropIfExists('users_mt');
        Schema::dropIfExists('activities_mt');
        Schema::dropIfExists('pharma');
        Schema::dropIfExists('doctors');
    }
}
