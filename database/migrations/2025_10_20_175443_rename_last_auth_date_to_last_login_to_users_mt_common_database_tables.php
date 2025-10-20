<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class RenameLastAuthDateToLastLoginToUsersMtCommonDatabaseTables extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        // Переименование поля в таблице users_mt
        Schema::table('users_mt', function (Blueprint $table) {
            $table->renameColumn('last_auth_date', 'last_login');
        });

        // Переименование поля в таблице common_database
        Schema::table('common_database', function (Blueprint $table) {
            $table->renameColumn('last_auth_date', 'last_login');
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::table('last_login_to_users_mt_common_database_tables', function (Blueprint $table) {
            //
        });
    }
}
