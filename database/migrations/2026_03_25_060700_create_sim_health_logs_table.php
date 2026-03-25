<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateSimHealthLogsTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::create('sim_health_logs', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('sim_id')->constrained('sims');
            $table->enum('status', ['online', 'offline', 'error', 'cooldown', 'disabled']);
            $table->integer('signal_strength')->nullable();
            $table->string('network_name')->nullable();
            $table->text('error_message')->nullable();
            $table->timestamp('logged_at');
            $table->timestamps();

            $table->index('sim_id');
            $table->index('status');
            $table->index(['sim_id', 'logged_at']);
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::dropIfExists('sim_health_logs');
    }
}
