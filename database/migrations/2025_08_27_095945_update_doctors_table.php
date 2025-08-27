<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class UpdateDoctorsTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::table('doctors', function (Blueprint $table) {
            $table->string('city')->nullable()->comment('Город')->change();
            $table->string('region')->nullable()->comment('Регион')->change();
            $table->string('country')->nullable()->comment('Страна')->change();
            $table->string('interests')->nullable()->comment('Интересы')->change();
            $table->string('phone')->nullable()->comment('Телефон')->change();

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
        //
    }
}
