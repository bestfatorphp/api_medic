<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class AddErrorToWhatsappParticipationTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::table('whatsapp_participation', function (Blueprint $table) {
            $table->boolean('error')->default(false)->comment('Сообщение небыло отправлено из-за ошибки');
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::table('whatsapp_participation', function (Blueprint $table) {
            //
        });
    }
}
