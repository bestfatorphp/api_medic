<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class AddFieldsToCommonDatabaseTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::table('common_database', function (Blueprint $table) {
            $table->string('category', 5)->nullable()->comment('Категория участий');
            $table->string('source', )->nullable()->comment('Источник');
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::table('common_database', function (Blueprint $table) {
            //
        });
    }
}
