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
        Schema::table('rules', function (Blueprint $table) {
            // Hva regelen gjør: sette kategori (default), RTA, eller overføring.
            $table->string('target_type')->default('category')->after('category_id');
            // Mottakerkonto når target_type = transfer (må være en ikke-synket konto).
            $table->foreignId('transfer_account_id')
                ->nullable()
                ->after('target_type')
                ->constrained('accounts')
                ->nullOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('rules', function (Blueprint $table) {
            $table->dropConstrainedForeignId('transfer_account_id');
            $table->dropColumn('target_type');
        });
    }
};
