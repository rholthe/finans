<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('bank_accounts', function (Blueprint $table): void {
            // Bankens egen saldo, hentet ved hver synk. `available` inkluderer
            // reserverte poster; begge er signert (negativ = gjeld).
            $table->decimal('balance_booked', 15, 2)->nullable()->after('rate_limit_reset_at');
            $table->decimal('balance_available', 15, 2)->nullable()->after('balance_booked');
            $table->timestamp('balance_synced_at')->nullable()->after('balance_available');
        });
    }

    public function down(): void
    {
        Schema::table('bank_accounts', function (Blueprint $table): void {
            $table->dropColumn(['balance_booked', 'balance_available', 'balance_synced_at']);
        });
    }
};
