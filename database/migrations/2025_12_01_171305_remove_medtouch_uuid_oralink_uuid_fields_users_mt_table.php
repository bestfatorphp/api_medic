<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class RemoveMedtouchUuidOralinkUuidFieldsUsersMtTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::table('users_mt', function (Blueprint $table) {
            $columns = Schema::getColumnListing('users_mt');

            if (in_array('medtouch_uuid', $columns)) {
                $table->dropColumn('medtouch_uuid');
            }

            if (in_array('oralink_uuid', $columns)) {
                $table->dropColumn('oralink_uuid');
            }
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
