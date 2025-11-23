<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class AddMedtouchOralinkUuidFieldsUsersMtTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::table('users_mt', function (Blueprint $table) {
            $table->uuid('medtouch_uuid')->nullable()->comment('medtouch_uuid');
            $table->uuid('oralink_uuid')->nullable()->comment('oralink_uuid');
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
