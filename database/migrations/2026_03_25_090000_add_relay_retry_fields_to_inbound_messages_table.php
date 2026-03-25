<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class AddRelayRetryFieldsToInboundMessagesTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::table('inbound_messages', function (Blueprint $table) {
            $table->unsignedInteger('relay_retry_count')->default(0)->after('relay_status');
            $table->timestamp('relay_next_attempt_at')->nullable()->after('relay_retry_count');
            $table->timestamp('relay_failed_at')->nullable()->after('relay_next_attempt_at');
            $table->timestamp('relay_locked_at')->nullable()->after('relay_failed_at');

            $table->index('relay_retry_count');
            $table->index('relay_next_attempt_at');
            $table->index('relay_locked_at');
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
            $table->dropIndex(['relay_retry_count']);
            $table->dropIndex(['relay_next_attempt_at']);
            $table->dropIndex(['relay_locked_at']);

            $table->dropColumn([
                'relay_retry_count',
                'relay_next_attempt_at',
                'relay_failed_at',
                'relay_locked_at',
            ]);
        });
    }
}
