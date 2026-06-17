<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('bank_connections', function (Blueprint $table) {
            // Når samtykket utløper (vanligvis 90 dager), og når vi sist varslet
            // brukeren om forestående utløp (nullstilles ved fornying).
            $table->timestamp('valid_until')->nullable()->after('status');
            $table->timestamp('expiry_notified_at')->nullable()->after('valid_until');
        });
    }

    public function down(): void
    {
        Schema::table('bank_connections', function (Blueprint $table) {
            $table->dropColumn(['valid_until', 'expiry_notified_at']);
        });
    }
};
