<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateInboundMessagesTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::create('inbound_messages', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('company_id')->constrained('companies');
            $table->foreignId('sim_id')->constrained('sims');
            $table->string('customer_phone', 30);
            $table->text('message');
            $table->timestamp('received_at');
            $table->boolean('relayed_to_chat_app')->default(false);
            $table->timestamp('relayed_at')->nullable();
            $table->enum('relay_status', ['pending', 'success', 'failed'])->default('pending');
            $table->text('relay_error')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->index('company_id');
            $table->index('sim_id');
            $table->index('customer_phone');
            $table->index(['company_id', 'sim_id', 'received_at']);
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::dropIfExists('inbound_messages');
    }
}
