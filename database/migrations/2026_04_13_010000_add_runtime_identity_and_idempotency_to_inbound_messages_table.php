<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class AddRuntimeIdentityAndIdempotencyToInboundMessagesTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::table('inbound_messages', function (Blueprint $table) {
            $table->string('runtime_sim_id', 64)->nullable()->after('sim_id');
            $table->string('idempotency_key', 191)->nullable()->after('received_at');

            $table->index('runtime_sim_id');
            $table->unique(['company_id', 'idempotency_key'], 'inbound_messages_company_idempotency_unique');
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::table('inbound_messages', function (Blueprint $table) {
            $table->dropUnique('inbound_messages_company_idempotency_unique');
            $table->dropIndex(['runtime_sim_id']);

            $table->dropColumn([
                'runtime_sim_id',
                'idempotency_key',
            ]);
        });
    }
}

