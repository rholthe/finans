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
        Schema::table('transactions', function (Blueprint $table) {
            // Reservert (PEND) bankpost: vist i hovedboka, men ikke klarert
            // (teller ikke i avstemming). Byttes ut med en booket rad ved
            // bokføring. cleared=false følger alltid pending=true.
            $table->boolean('pending')->default(false)->after('cleared');
            $table->index(['account_id', 'pending']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('transactions', function (Blueprint $table) {
            $table->dropIndex(['account_id', 'pending']);
            $table->dropColumn('pending');
        });
    }
};
