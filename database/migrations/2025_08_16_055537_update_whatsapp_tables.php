<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class UpdateWhatsappTables extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::table('whatsapp_campaign', function (Blueprint $table) {
            $table->integer('sent')->nullable()->comment('Количество отправленных');
            $table->integer('delivered')->nullable()->comment('Количество доставленных');
            $table->float('delivery_rate')->nullable()->comment('Доставка, значение в процентах');
            $table->integer('opened')->nullable()->comment('Количество открытий');
            $table->float('open_rate')->nullable()->comment('Открытия, значение в процентах');
        });

        Schema::table('whatsapp_participation', function (Blueprint $table) {
            $table->dateTime('delivered_at')->nullable()->comment('Дата доставки сообщения');
            $table->dateTime('opened_at')->nullable()->comment('Дата открытия сообщения');

            $table->unique(['campaign_id', 'phone', 'send_date'], 'whatsapp_participation_unique_action');
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        //
    }
}
