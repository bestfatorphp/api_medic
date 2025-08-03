<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class UpdateCommonDatabaseAndUsersMtTables extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::table('users_mt', function (Blueprint $table) {
            $table->dropColumn('new_mt_id');
        });

        Schema::table('common_database', function (Blueprint $table) {
            $table->unsignedInteger('new_mt_id')->nullable()->unique()->after('id')->comment('ID пользователя нового МТ');
            $table->unsignedInteger('old_mt_id')->nullable()->unique()->after('new_mt_id')->comment('ID пользователя старого МТ');
            $table->text('acquisition_tool')->nullable()->comment('Инструммент привлечения')->change();
        });

        Schema::table('users_mt', function (Blueprint $table) {
            $table->unsignedInteger('new_mt_id')->nullable()->unique()->after('id')->comment('ID пользователя нового МТ');
            $table->unsignedInteger('old_mt_id')->nullable()->unique()->after('new_mt_id')->comment('ID пользователя старого МТ');
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {

    }
}
