<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateModemsTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::create('modems', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->string('device_name')->nullable();
            $table->string('vendor')->nullable();
            $table->string('model')->nullable();
            $table->string('chipset')->nullable();
            $table->string('serial_number')->nullable()->index();
            $table->string('usb_path')->nullable();
            $table->string('control_port')->nullable()->index();
            $table->enum('status', ['online', 'offline', 'disabled', 'error'])->default('offline')->index();
            $table->timestamp('last_seen_at')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::dropIfExists('modems');
    }
}
