<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('transactions', function (Blueprint $table) {
            // Tidspunkt for når raden ble avstemt. null = ikke avstemt.
            // Avstemte rader markeres i UI og varsles før redigering/sletting.
            $table->timestamp('reconciled_at')
                ->nullable()
                ->after('cleared');
        });
    }

    public function down(): void
    {
        Schema::table('transactions', function (Blueprint $table) {
            $table->dropColumn('reconciled_at');
        });
    }
};
