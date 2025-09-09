<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateSendsayParticipationDelivTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::table('sendsay_participation', function (Blueprint $table) {
            $table->dropColumn('sendsay_key');
        });

        Schema::create('sendsay_participation_deliv', function (Blueprint $table) {
            $table->increments('id');
            $table->unsignedInteger('issue_id')->comment('ID sendsay рассылки');
            $table->string('email')->comment('E-mail');
            $table->string('result')->nullable()->comment('Результат отправки');
            $table->dateTime('update_time')->nullable()->comment('Время обновления');

            $table->index('issue_id');
            $table->index('email');
            $table->index('result');

            $table->unique(['issue_id', 'email', 'update_time'], 'sendsay_participation_deliv_unique_action');
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::dropIfExists('sendsay_participation_deliv');
        Schema::table('sendsay_participation', function (Blueprint $table) {
            $table->string('sendsay_key', 15)->nullable()->comment('Ключ SendSay');
        });
    }
}
