<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateTgChatsTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::create('users_chats', function (Blueprint $table) {
            $table->id();
            $table->string('full_name')->comment('ФИО');
            $table->string('email')->comment('E-mail');
            $table->string('username')->nullable()->comment('Никнэйм');
            $table->string('specialization')->comment('Название чатов в которых состоит пользователь');
            $table->string('channel')->nullable()->comment('Канал');
            $table->dateTime('registration_date')->nullable()->comment('Время регистрации в чате');

            $table->index('email');
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::dropIfExists('users_chats');
    }
}
