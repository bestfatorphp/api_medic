<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class CreateWriteLocksTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        //таблица для управления блокировками, чтобы не было конфликтов при пакетной вставке данных
        Schema::create('write_locks', function (Blueprint $table) {
            $table->string('table_name')->primary()->comment('Название таблицы БД');
            $table->boolean('is_writing')->default(false)->comment('Флаг активности записи');
            $table->timestamp('locked_at')->nullable()->comment('Время захвата блокировки');
            $table->timestamps();

            $table->index('is_writing', 'write_locks_is_writing_index');
            $table->index('locked_at', 'write_locks_locked_at_index');

            //$table->index(['is_writing', 'locked_at'], 'write_lock_status_index');
        });

        if (config('database.default') === 'pgsql') {
            DB::statement('ALTER TABLE write_locks SET (fillfactor = 90)'); //установливаем fillfactor 90% для уменьшения блокировок
        }
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::dropIfExists('write_locks');
    }
}
