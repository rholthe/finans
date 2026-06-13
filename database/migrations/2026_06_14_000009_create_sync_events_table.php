<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sync_events', function (Blueprint $table) {
            $table->id();
            $table->string('status'); // processing / completed_new / completed_no_new / completed_with_errors / failed
            $table->string('trigger')->default('auto'); // auto | manual
            $table->unsignedInteger('imported_count')->default(0);
            $table->unsignedInteger('days_synced')->nullable();
            $table->json('report')->nullable(); // linjer: [{status, message}]
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sync_events');
    }
};
