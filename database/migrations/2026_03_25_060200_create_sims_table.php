<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateSimsTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::create('sims', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('company_id')->constrained('companies');
            $table->foreignId('modem_id')->nullable()->constrained('modems')->nullOnDelete();
            $table->string('slot_name')->nullable();
            $table->string('phone_number', 30)->unique();
            $table->string('carrier', 50)->nullable();
            $table->string('sim_label')->nullable();
            $table->enum('status', ['active', 'cooldown', 'disabled', 'error', 'offline'])->default('active');
            $table->enum('mode', ['NORMAL', 'BURST', 'COOLDOWN'])->default('NORMAL');
            $table->unsignedInteger('daily_limit')->default(4000);
            $table->unsignedInteger('recommended_limit')->default(3000);
            $table->unsignedInteger('burst_limit')->default(30);
            $table->unsignedInteger('burst_interval_min_seconds')->default(2);
            $table->unsignedInteger('burst_interval_max_seconds')->default(3);
            $table->unsignedInteger('normal_interval_min_seconds')->default(5);
            $table->unsignedInteger('normal_interval_max_seconds')->default(8);
            $table->unsignedInteger('cooldown_min_seconds')->default(60);
            $table->unsignedInteger('cooldown_max_seconds')->default(120);
            $table->unsignedInteger('burst_count')->default(0);
            $table->timestamp('cooldown_until')->nullable();
            $table->timestamp('last_sent_at')->nullable();
            $table->timestamp('last_received_at')->nullable();
            $table->timestamp('last_error_at')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index('company_id');
            $table->index('status');
            $table->index(['company_id', 'status']);
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::dropIfExists('sims');
    }
}
