<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('sync_events', function (Blueprint $table) {
            $table->string('trigger')->default('auto')->after('status'); // auto | manual
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('sync_events', function (Blueprint $table) {
            $table->dropColumn('trigger');
        });
    }
};
