<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class AddMedtouchUuidJOralinkUuidJFieldsUsersMtTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::table('users_mt', function (Blueprint $table) {
            $table->jsonb('medtouch_uuids')->nullable()->default('[]')->comment('medtouch_uuids');
            $table->jsonb('oralink_uuids')->nullable()->default('[]')->comment('oralink_uuids');

            $table->rawIndex("(medtouch_uuids)", 'idx_medtouch_uuids_gin');
            $table->rawIndex("(oralink_uuids)", 'idx_oralink_uuids_gin');
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::table('users_mt', function (Blueprint $table) {
            $table->dropIndex('idx_medtouch_uuids_gin');
            $table->dropIndex('idx_oralink_uuids_gin');
            $table->dropColumn(['medtouch_uuids', 'oralink_uuids']);
        });
    }
}
