<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateSimDailyStatsTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::create('sim_daily_stats', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('sim_id')->constrained('sims');
            $table->date('stat_date');
            $table->unsignedInteger('sent_count')->default(0);
            $table->unsignedInteger('sent_chat_count')->default(0);
            $table->unsignedInteger('sent_auto_reply_count')->default(0);
            $table->unsignedInteger('sent_follow_up_count')->default(0);
            $table->unsignedInteger('sent_blast_count')->default(0);
            $table->unsignedInteger('failed_count')->default(0);
            $table->unsignedInteger('inbound_count')->default(0);
            $table->timestamps();

            $table->unique(['sim_id', 'stat_date']);
            $table->index('sim_id');
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::dropIfExists('sim_daily_stats');
    }
}
