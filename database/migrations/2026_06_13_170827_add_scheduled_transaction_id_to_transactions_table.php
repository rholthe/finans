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
            // Sporbarhet: hvilken planlagt transaksjon som genererte denne raden.
            $table->foreignId('scheduled_transaction_id')->nullable()->after('category_id')
                ->constrained()->nullOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('transactions', function (Blueprint $table) {
            $table->dropForeign(['scheduled_transaction_id']);
            $table->dropColumn('scheduled_transaction_id');
        });
    }
};
