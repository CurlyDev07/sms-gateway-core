<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class PruneUnusedGatewayColumnsPhase1 extends Migration
{
    private function isSqlite(): bool
    {
        return Schema::getConnection()->getDriverName() === 'sqlite';
    }

    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        // SQLite test environments in this project do not include Doctrine DBAL.
        // Skip destructive column drops there; production MySQL still applies this cleanup.
        if ($this->isSqlite()) {
            return;
        }

        if (Schema::hasTable('api_clients') && Schema::hasColumn('api_clients', 'allowed_ips')) {
            Schema::table('api_clients', function (Blueprint $table) {
                $table->dropColumn('allowed_ips');
            });
        }

        if (Schema::hasTable('outbound_messages') && Schema::hasColumn('outbound_messages', 'campaign_id')) {
            Schema::table('outbound_messages', function (Blueprint $table) {
                $table->dropColumn('campaign_id');
            });
        }

        if (Schema::hasTable('outbound_messages') && Schema::hasColumn('outbound_messages', 'conversation_ref')) {
            Schema::table('outbound_messages', function (Blueprint $table) {
                $table->dropColumn('conversation_ref');
            });
        }

        if (Schema::hasTable('sims') && Schema::hasColumn('sims', 'last_received_at')) {
            Schema::table('sims', function (Blueprint $table) {
                $table->dropColumn('last_received_at');
            });
        }

        if (Schema::hasTable('sims') && Schema::hasColumn('sims', 'notes')) {
            Schema::table('sims', function (Blueprint $table) {
                $table->dropColumn('notes');
            });
        }

        if (Schema::hasTable('sim_health_logs') && Schema::hasColumn('sim_health_logs', 'signal_strength')) {
            Schema::table('sim_health_logs', function (Blueprint $table) {
                $table->dropColumn('signal_strength');
            });
        }

        if (Schema::hasTable('sim_health_logs') && Schema::hasColumn('sim_health_logs', 'network_name')) {
            Schema::table('sim_health_logs', function (Blueprint $table) {
                $table->dropColumn('network_name');
            });
        }
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        if ($this->isSqlite()) {
            return;
        }

        if (Schema::hasTable('api_clients') && !Schema::hasColumn('api_clients', 'allowed_ips')) {
            Schema::table('api_clients', function (Blueprint $table) {
                $table->text('allowed_ips')->nullable()->after('status');
            });
        }

        if (Schema::hasTable('outbound_messages') && !Schema::hasColumn('outbound_messages', 'campaign_id')) {
            Schema::table('outbound_messages', function (Blueprint $table) {
                $table->unsignedBigInteger('campaign_id')->nullable()->after('client_message_id');
            });
        }

        if (Schema::hasTable('outbound_messages') && !Schema::hasColumn('outbound_messages', 'conversation_ref')) {
            Schema::table('outbound_messages', function (Blueprint $table) {
                $table->string('conversation_ref')->nullable()->after('campaign_id');
            });
        }

        if (Schema::hasTable('sims') && !Schema::hasColumn('sims', 'last_received_at')) {
            Schema::table('sims', function (Blueprint $table) {
                $table->timestamp('last_received_at')->nullable()->after('last_sent_at');
            });
        }

        if (Schema::hasTable('sims') && !Schema::hasColumn('sims', 'notes')) {
            Schema::table('sims', function (Blueprint $table) {
                $table->text('notes')->nullable()->after('last_error_at');
            });
        }

        if (Schema::hasTable('sim_health_logs') && !Schema::hasColumn('sim_health_logs', 'signal_strength')) {
            Schema::table('sim_health_logs', function (Blueprint $table) {
                $table->integer('signal_strength')->nullable()->after('status');
            });
        }

        if (Schema::hasTable('sim_health_logs') && !Schema::hasColumn('sim_health_logs', 'network_name')) {
            Schema::table('sim_health_logs', function (Blueprint $table) {
                $table->string('network_name')->nullable()->after('signal_strength');
            });
        }
    }
}
