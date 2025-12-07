<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class SetUniqueIndexFromActionsMt extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::table('actions_mt', function (Blueprint $table) {
            $table->unique(['mt_user_id', 'old_mt_id', 'activity_id', 'date_time'], 'actions_mt_unique_action');
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
