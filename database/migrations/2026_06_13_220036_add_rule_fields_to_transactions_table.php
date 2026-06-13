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
            // Rå info-tekst fra banken, bevart som matchegrunnlag for re-kjøring.
            $table->text('bank_description')->nullable()->after('external_id');
            // Hvilken regel som satte payee/memo/kategori (sporbarhet, «auto»-merke).
            $table->foreignId('rule_id')->nullable()->after('bank_description')
                ->constrained()->nullOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('transactions', function (Blueprint $table) {
            $table->dropForeign(['rule_id']);
            $table->dropColumn(['bank_description', 'rule_id']);
        });
    }
};
