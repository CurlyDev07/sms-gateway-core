<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateOutboundMessagesTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::create('outbound_messages', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('company_id')->constrained('companies');
            $table->foreignId('sim_id')->nullable()->constrained('sims')->nullOnDelete();
            $table->string('customer_phone', 30);
            $table->text('message');
            $table->enum('message_type', ['CHAT', 'AUTO_REPLY', 'FOLLOW_UP', 'BLAST']);
            $table->unsignedTinyInteger('priority')->default(10);
            $table->enum('status', ['pending', 'queued', 'sending', 'sent', 'failed', 'cancelled'])->default('pending');
            $table->timestamp('scheduled_at')->nullable();
            $table->timestamp('queued_at')->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->timestamp('failed_at')->nullable();
            $table->timestamp('locked_at')->nullable();
            $table->text('failure_reason')->nullable();
            $table->unsignedInteger('retry_count')->default(0);
            $table->string('client_message_id')->nullable();
            $table->unsignedBigInteger('campaign_id')->nullable();
            $table->string('conversation_ref')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->index('company_id');
            $table->index('sim_id');
            $table->index('status');
            $table->index('priority');
            $table->index('scheduled_at');
            $table->index('locked_at');
            $table->index('customer_phone');
            $table->index('client_message_id');
            $table->index(['company_id', 'sim_id', 'status']);
            $table->index(['sim_id', 'priority', 'status', 'scheduled_at']);
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::dropIfExists('outbound_messages');
    }
}
