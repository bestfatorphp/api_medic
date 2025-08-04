<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class UpdateActionsMtTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::table('actions_mt', function (Blueprint $table) {
            $table->dropUnique('actions_mt_unique_action');
            $table->unsignedInteger('mt_user_id')
                ->default(0)
                ->comment('ID пользователя МТ')
                ->change();

            $table->unsignedInteger('old_mt_id')
                ->default(0)
                ->comment('Старый ID пользователя МТ')
                ->after('mt_user_id');

            $table->unique(
                ['mt_user_id', 'old_mt_id', 'activity_id', 'date_time'],
                'actions_mt_unique_action'
            );
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::table('actions_mt', function (Blueprint $table) {
            $table->dropUnique('actions_mt_unique_action');
            $table->dropColumn('old_mt_id');

            $table->unsignedInteger('mt_user_id')
                ->comment('ID пользователя МТ')
                ->change();

            $table->unique(
                ['mt_user_id', 'activity_id', 'date_time'],
                'actions_mt_unique_action'
            );
        });
    }
}
