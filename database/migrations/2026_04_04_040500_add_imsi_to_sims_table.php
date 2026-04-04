<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up(): void
    {
        Schema::table('sims', function (Blueprint $table) {
            if (!Schema::hasColumn('sims', 'imsi')) {
                $table->string('imsi', 32)->nullable()->index('sims_imsi_index');
            }
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down(): void
    {
        Schema::table('sims', function (Blueprint $table) {
            if (Schema::hasColumn('sims', 'imsi')) {
                $table->dropIndex('sims_imsi_index');
                $table->dropColumn('imsi');
            }
        });
    }
};

