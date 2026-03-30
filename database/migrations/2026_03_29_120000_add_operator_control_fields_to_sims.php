<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class AddOperatorControlFieldsToSims extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::table('sims', function (Blueprint $table) {
            $table->enum('operator_status', ['active', 'paused', 'blocked'])
                ->default('active')
                ->after('mode');

            $table->boolean('accept_new_assignments')
                ->default(false)
                ->after('operator_status');

            $table->boolean('disabled_for_new_assignments')
                ->default(false)
                ->after('accept_new_assignments');

            $table->timestamp('last_success_at')
                ->nullable()
                ->after('last_error_at');

            $table->index(['company_id', 'operator_status']);
            $table->index(['company_id', 'disabled_for_new_assignments']);
            $table->index(['last_success_at']);
        });

        // Preserve current rollout behavior for existing SIM rows.
        DB::table('sims')->update([
            'accept_new_assignments' => true,
        ]);
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::table('sims', function (Blueprint $table) {
            $table->dropIndex(['company_id', 'operator_status']);
            $table->dropIndex(['company_id', 'disabled_for_new_assignments']);
            $table->dropIndex(['last_success_at']);

            $table->dropColumn([
                'operator_status',
                'accept_new_assignments',
                'disabled_for_new_assignments',
                'last_success_at',
            ]);
        });
    }
}

