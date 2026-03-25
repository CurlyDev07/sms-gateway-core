<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateCustomerSimAssignmentsTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::create('customer_sim_assignments', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('company_id')->constrained('companies');
            $table->string('customer_phone', 30);
            $table->foreignId('sim_id')->constrained('sims');
            $table->enum('status', ['active', 'migrated', 'disabled'])->default('active');
            $table->timestamp('assigned_at');
            $table->timestamp('last_used_at')->nullable();
            $table->timestamp('last_inbound_at')->nullable();
            $table->timestamp('last_outbound_at')->nullable();
            $table->boolean('has_replied')->default(false);
            $table->boolean('safe_to_migrate')->default(false);
            $table->boolean('migration_locked')->default(false);
            $table->timestamps();

            $table->unique(['company_id', 'customer_phone']);
            $table->index('company_id');
            $table->index('sim_id');
            $table->index('status');
            $table->index('customer_phone');
            $table->index(['company_id', 'sim_id']);
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::dropIfExists('customer_sim_assignments');
    }
}
