<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class AddDeliveryStatusUrlToCompanyChatAppIntegrationsTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        if (!Schema::hasTable('company_chat_app_integrations')) {
            return;
        }

        Schema::table('company_chat_app_integrations', function (Blueprint $table) {
            if (!Schema::hasColumn('company_chat_app_integrations', 'chatapp_delivery_status_url')) {
                $table->string('chatapp_delivery_status_url')->nullable()->after('chatapp_inbound_url');
            }
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        if (!Schema::hasTable('company_chat_app_integrations')) {
            return;
        }

        Schema::table('company_chat_app_integrations', function (Blueprint $table) {
            if (Schema::hasColumn('company_chat_app_integrations', 'chatapp_delivery_status_url')) {
                $table->dropColumn('chatapp_delivery_status_url');
            }
        });
    }
}

