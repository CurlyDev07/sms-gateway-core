<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateCompanyChatAppIntegrationsTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::create('company_chat_app_integrations', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('company_id')->constrained('companies')->cascadeOnDelete();
            $table->string('chatapp_company_id')->unique();
            $table->uuid('chatapp_company_uuid')->nullable()->unique();
            $table->string('chatapp_inbound_url');
            $table->string('chatapp_tenant_key')->unique();
            $table->text('chatapp_inbound_secret_encrypted');
            $table->enum('status', ['active', 'disabled'])->default('active')->index();
            $table->timestamp('outbound_rotated_at')->nullable();
            $table->timestamp('inbound_rotated_at')->nullable();
            $table->timestamps();

            $table->unique('company_id');
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
        Schema::dropIfExists('company_chat_app_integrations');
    }
}
